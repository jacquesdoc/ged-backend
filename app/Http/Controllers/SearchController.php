<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:255']);

        $query   = trim($request->q);
        $user    = $request->user();
        $isAdmin = $user->hasRole('admin');

        // ── Documents ──────────────────────────────────────────────────────
        $docsQuery = Document::with(['creator', 'folder'])
            ->where('is_archived', false)
            ->where(function($q) use ($query) {
                $q->where('name',         'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%");
            });

        if (!$isAdmin) {
            $docsQuery->where(function($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhereIn('status', ['approved', 'published']);
            });
        }

        $documents = $docsQuery->limit(5)->get()->map(fn($d) => [
            'id'       => $d->id,
            'type'     => 'document',
            'title'    => $d->name,
            'subtitle' => $d->folder ? "📁 {$d->folder->name}" : '📁 Racine',
            'status'   => $d->status,
            'meta'     => $d->creator?->name,
            'icon'     => '📄',
            'url'      => '/documents',
        ]);

        // ── Dossiers ───────────────────────────────────────────────────────
        $foldersQuery = Folder::where(function($q) use ($query) {
            $q->where('name',         'like', "%{$query}%")
              ->orWhere('description', 'like', "%{$query}%");
        });

        if (!$isAdmin) {
            $foldersQuery->where('created_by', $user->id);
        }

        $folders = $foldersQuery->withCount('documents')->limit(5)->get()->map(fn($f) => [
            'id'       => $f->id,
            'type'     => 'folder',
            'title'    => $f->name,
            'subtitle' => "{$f->documents_count} document(s)",
            'color'    => $f->color,
            'icon'     => '📁',
            'url'      => '/folders',
        ]);

        // ── Workflows ──────────────────────────────────────────────────────
        $workflowsQuery = Workflow::with(['document', 'requester'])
            ->whereHas('document', fn($q) =>
                $q->where('name', 'like', "%{$query}%")
            );

        if (!$isAdmin) {
            $workflowsQuery->where('requested_by', $user->id);
        }

        $workflows = $workflowsQuery->limit(5)->get()->map(fn($w) => [
            'id'       => $w->id,
            'type'     => 'workflow',
            'title'    => $w->document?->name ?? 'Document supprimé',
            'subtitle' => "Demandé par {$w->requester?->name}",
            'status'   => $w->status,
            'icon'     => '🔄',
            'url'      => '/workflows',
        ]);

        // ── Utilisateurs (Admin seulement) ─────────────────────────────────
        $users = collect();
        if ($isAdmin) {
            $users = User::with('roles')
                ->where(function($q) use ($query) {
                    $q->where('name',  'like', "%{$query}%")
                      ->orWhere('email','like', "%{$query}%");
                })
                ->limit(5)
                ->get()
                ->map(fn($u) => [
                    'id'       => $u->id,
                    'type'     => 'user',
                    'title'    => $u->name,
                    'subtitle' => $u->email,
                    'meta'     => $u->getRoleNames()->first(),
                    'icon'     => '👤',
                    'url'      => '/users',
                ]);
        }

        $total = $documents->count() + $folders->count()
               + $workflows->count() + $users->count();

        return response()->json([
            'query'   => $query,
            'total'   => $total,
            'results' => [
                'documents' => $documents,
                'folders'   => $folders,
                'workflows' => $workflows,
                'users'     => $users,
            ],
        ]);
    }
}