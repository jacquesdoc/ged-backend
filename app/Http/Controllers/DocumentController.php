<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    // ── Liste des documents ────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
        {
            $user    = $request->user();
            $isAdmin = $user->hasRole('admin');

            $query = Document::with(['creator', 'tags', 'folder'])
                ->where('is_archived', false);

            if ($isAdmin) {
                // Admin voit tout
            } else {
                // Dossiers accessibles via groupes
                $groupFolderIds = \DB::table('folder_group_access')
                    ->join('user_group_members', 'user_group_members.user_group_id', '=', 'folder_group_access.user_group_id')
                    ->where('user_group_members.user_id', $user->id)
                    ->where('folder_group_access.status', 'approved')
                    ->pluck('folder_group_access.folder_id')
                    ->toArray();

                // Dossiers partagés directement
                $sharedFolderIds = \DB::table('folder_shares')
                    ->where('user_id', $user->id)
                    ->pluck('folder_id')
                    ->toArray();

                $accessibleFolderIds = array_unique(
                    array_merge($groupFolderIds, $sharedFolderIds)
                );

                $query->where(function($q) use ($user, $accessibleFolderIds) {
                    // Ses propres documents
                    $q->where('created_by', $user->id);

                    // Documents approuvés/publiés
                    $q->orWhereIn('status', ['approved', 'published']);

                    // Documents dans les dossiers accessibles
                    if (!empty($accessibleFolderIds)) {
                        $q->orWhereIn('folder_id', $accessibleFolderIds);
                    }
                });
            }

            if ($request->filled('folder_id')) {
                $query->where('folder_id', $request->folder_id);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('search')) {
                $query->where('name', 'like', '%' . $request->search . '%');
            }

            $documents = $query->orderByDesc('created_at')
                ->paginate($request->get('per_page', 20));

            return response()->json($documents);
        }

    // ── Créer un document ──────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'folder_id'   => 'nullable|exists:folders,id',
            'file'        => 'nullable|file|max:51200',
            'tag_ids'     => 'nullable|array',
            'tag_ids.*'   => 'exists:tags,id',
        ]);

        $data = [
            'name'        => $request->name,
            'description' => $request->description,
            'folder_id'   => $request->folder_id,
            'created_by'  => $request->user()->id,
            'status'      => 'draft',
        ];

        if ($request->hasFile('file')) {
            $file              = $request->file('file');
            $path              = $file->store('documents', 'local');
            $data['file_path'] = $path;
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_size'] = $file->getSize();
            $data['mime_type'] = $file->getMimeType();
            $data['extension'] = $file->getClientOriginalExtension();
            $data['checksum']  = hash_file('sha256', $file->getRealPath());
        }

        $document = Document::create($data);

        if ($request->has('tag_ids')) {
            $document->tags()->sync($request->tag_ids);
        }

        activity('document')
            ->causedBy($request->user())
            ->performedOn($document)
            ->log('Document créé');

        return response()->json([
            'message'  => 'Document créé avec succès.',
            'document' => $document->load(['creator', 'tags', 'folder']),
        ], 201);
    }

    // ── Détail d'un document ───────────────────────────────────────────────
    public function show(Document $document): JsonResponse
    {
        $document->load([
            'creator', 'tags', 'folder',
            'versions.creator', 'comments.user',
            'workflows.approvals.approver'
        ]);

        return response()->json($document);
    }

    // ── Modifier un document ───────────────────────────────────────────────
    public function update(Request $request, Document $document): JsonResponse
    {
        $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'folder_id'   => 'nullable|exists:folders,id',
            'status'      => 'sometimes|in:draft,review,approved,rejected,published,archived',
            'tag_ids'     => 'nullable|array',
        ]);

        $document->update($request->only([
            'name', 'description', 'folder_id', 'status'
        ]));

        if ($request->has('tag_ids')) {
            $document->tags()->sync($request->tag_ids);
        }

        activity('document')
            ->causedBy($request->user())
            ->performedOn($document)
            ->log('Document modifié');

        return response()->json([
            'message'  => 'Document mis à jour.',
            'document' => $document->load(['creator', 'tags', 'folder']),
        ]);
    }

    // ── Supprimer un document ──────────────────────────────────────────────
    public function destroy(Document $document): JsonResponse
    {
        activity('document')
            ->causedBy(auth()->user())
            ->performedOn($document)
            ->log('Document supprimé');

        $document->delete();

        return response()->json(['message' => 'Document supprimé.']);
    }

    // ── Télécharger ────────────────────────────────────────────────────────
    public function download(Document $document): mixed
    {
        if (!$document->file_path || !Storage::exists($document->file_path)) {
            return response()->json(['message' => 'Fichier introuvable.'], 404);
        }

        activity('document')
            ->causedBy(auth()->user())
            ->performedOn($document)
            ->log('Document téléchargé');

        return Storage::download($document->file_path, $document->file_name);
    }

    // ── Prévisualiser ──────────────────────────────────────────────────────
    public function preview(Document $document): mixed
    {
        if (!$document->file_path || !Storage::exists($document->file_path)) {
            return response()->json(['message' => 'Fichier introuvable.'], 404);
        }

        $path     = Storage::path($document->file_path);
        $mimeType = $document->mime_type ?: mime_content_type($path);

        return response()->file($path, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $document->file_name . '"',
            'Cache-Control'       => 'no-cache',
        ]);
    }

    // ── Archiver ───────────────────────────────────────────────────────────
    public function archive(Document $document): JsonResponse
    {
        $document->update([
            'is_archived' => true,
            'archived_at' => now(),
            'status'      => 'archived',
        ]);

        return response()->json(['message' => 'Document archivé.']);
    }

    // ── Restaurer ──────────────────────────────────────────────────────────
    public function restore(Document $document): JsonResponse
    {
        $document->update([
            'is_archived' => false,
            'archived_at' => null,
            'status'      => 'draft',
        ]);

        return response()->json([
            'message'  => 'Document restauré.',
            'document' => $document,
        ]);
    }

    // ── Ajouter un commentaire ─────────────────────────────────────────────
    public function addComment(Request $request, Document $document): JsonResponse
    {
        $request->validate(['content' => 'required|string|max:1000']);

        $comment = $document->comments()->create([
            'user_id' => $request->user()->id,
            'content' => $request->content,
        ]);

        return response()->json($comment->load('user'), 201);
    }
}