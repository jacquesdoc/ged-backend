<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // ── Liste toutes les notifications ─────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn($n) => [
                'id'         => $n->id,
                'data'       => is_string($n->data)
                                ? json_decode($n->data, true)
                                : $n->data,
                'read_at'    => $n->read_at,
                'created_at' => $n->created_at,
            ]);

        return response()->json($notifications);
    }

    // ── Notifications non lues ─────────────────────────────────────────────
    public function unread(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->unreadNotifications()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn($n) => [
                'id'         => $n->id,
                'data'       => is_string($n->data)
                                ? json_decode($n->data, true)
                                : $n->data,
                'read_at'    => $n->read_at,
                'created_at' => $n->created_at,
            ]);

        return response()->json([
            'count'         => $request->user()->unreadNotifications()->count(),
            'notifications' => $notifications,
        ]);
    }

    // ── Marquer une notification comme lue ─────────────────────────────────
    public function markRead(Request $request, string $id): JsonResponse
    {
        $request->user()
            ->notifications()
            ->where('id', $id)
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Notification lue.']);
    }

    // ── Marquer toutes comme lues ──────────────────────────────────────────
    public function markAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'Toutes les notifications lues.']);
    }
}