<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user    = $request->user();
        $isAdmin = $user->hasRole('admin');

        $docsQuery = $isAdmin
            ? Document::query()
            : Document::where('created_by', $user->id);

        return response()->json([
            'documents' => [
                'total'    => (clone $docsQuery)->count(),
                'draft'    => (clone $docsQuery)->where('status', 'draft')->count(),
                'review'   => (clone $docsQuery)->where('status', 'review')->count(),
                'approved' => (clone $docsQuery)->where('status', 'approved')->count(),
                'archived' => (clone $docsQuery)->where('is_archived', true)->count(),
            ],
            'folders'   => Folder::where('created_by', $user->id)->count(),
            'workflows' => [
                'pending'  => Workflow::where('status', 'pending')->count(),
                'my_tasks' => Workflow::where('requested_by', $user->id)->count(),
            ],
            'recent_documents' => Document::with(['creator', 'tags'])
                ->where('created_by', $user->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(),
            'recent_activity' => \Spatie\Activitylog\Models\Activity::with('causer')
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn($a) => [
                    'description' => $a->description,
                    'user'        => $a->causer?->name ?? 'Système',
                    'created_at'  => $a->created_at->toISOString(),
                ]),
        ]);
    }
}