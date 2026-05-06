<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Folder;
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

        // Activité récente — admin voit tout, autres voient seulement les leurs
        $recentActivity = [];
        try {
            $activityQuery = \Spatie\Activitylog\Models\Activity::with('causer')
                ->latest()
                ->limit(10);

            if (!$isAdmin) {
                $activityQuery->where('causer_id', $user->id);
            }

            $recentActivity = $activityQuery->get()->map(fn($a) => [
                'description' => $a->description,
                'user'        => $a->causer?->name ?? 'Système',
                'created_at'  => $a->created_at->toISOString(),
            ]);
        } catch (\Exception $e) {
            $recentActivity = [];
        }

        // Documents récents
        $recentDocuments = [];
        try {
            $recentDocuments = Document::with(['creator', 'tags'])
                ->where('created_by', $user->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();
        } catch (\Exception $e) {
            $recentDocuments = [];
        }

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
            'recent_documents' => $recentDocuments,
            'recent_activity'  => $recentActivity,
        ]);
    }
}