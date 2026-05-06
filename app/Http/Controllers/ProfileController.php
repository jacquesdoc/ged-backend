<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

class ProfileController extends Controller
{
    // ── Obtenir le profil complet ──────────────────────────────────────────
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles');

        // Statistiques personnelles
        $stats = [
            'documents_created'  => Document::where('created_by', $user->id)->count(),
            'documents_approved' => Document::where('created_by', $user->id)
                ->where('status', 'approved')->count(),
            'documents_rejected' => Document::where('created_by', $user->id)
                ->where('status', 'rejected')->count(),
            'workflows_submitted'=> Workflow::where('requested_by', $user->id)->count(),
            'workflows_approved' => Workflow::where('requested_by', $user->id)
                ->where('status', 'approved')->count(),
        ];

        // Activité récente
        $activity = Activity::with('causer')
            ->where('causer_id', $user->id)
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn($a) => [
                'description' => $a->description,
                'created_at'  => $a->created_at->toISOString(),
                'log_name'    => $a->log_name,
            ]);

        // Sessions actives
        $sessions = $user->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn($t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'last_used_at' => $t->last_used_at?->toISOString(),
                'created_at'   => $t->created_at->toISOString(),
                'is_current'   => $t->id === $request->user()->currentAccessToken()->id,
            ]);

        return response()->json([
            'user'     => $this->formatUser($user),
            'stats'    => $stats,
            'activity' => $activity,
            'sessions' => $sessions,
        ]);
    }

    // ── Mettre à jour les infos personnelles ───────────────────────────────
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|unique:users,email,' . $user->id,
            'department' => 'nullable|string|max:255',
            'position'   => 'nullable|string|max:255',
        ]);

        $user->update($request->only(['name', 'email', 'department', 'position']));

        activity('profile')
            ->causedBy($user)
            ->log('Profil mis à jour');

        return response()->json([
            'message' => 'Profil mis à jour avec succès.',
            'user'    => $this->formatUser($user->fresh()),
        ]);
    }

    // ── Uploader un avatar ─────────────────────────────────────────────────
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,gif,webp|max:2048',
        ]);

        $user = $request->user();

        // Supprimer l'ancien avatar
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar' => $path]);

        return response()->json([
            'message'    => 'Avatar mis à jour.',
            'avatar_url' => $user->fresh()->avatar_url,
        ]);
    }

    // ── Changer le mot de passe ────────────────────────────────────────────
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $user->update(['password' => Hash::make($request->password)]);

        // Révoquer les autres sessions
        $user->tokens()
            ->where('id', '!=', $request->user()->currentAccessToken()->id)
            ->delete();

        activity('profile')
            ->causedBy($user)
            ->log('Mot de passe modifié');

        return response()->json([
            'message' => 'Mot de passe modifié avec succès. Les autres sessions ont été déconnectées.',
        ]);
    }

    // ── Mettre à jour les préférences de notification ──────────────────────
    public function updateNotifications(Request $request): JsonResponse
    {
        $request->validate([
            'email_notifications' => 'required|boolean',
        ]);

        $user = $request->user();
        $user->update(['email_notifications' => $request->email_notifications]);

        return response()->json([
            'message'             => 'Préférences mises à jour.',
            'email_notifications' => $user->email_notifications,
        ]);
    }

    // ── Révoquer une session ───────────────────────────────────────────────
    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $user = $request->user();

        if ($tokenId === $user->currentAccessToken()->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas révoquer votre session actuelle.'
            ], 422);
        }

        $user->tokens()->where('id', $tokenId)->delete();

        return response()->json(['message' => 'Session révoquée.']);
    }

    // ── Révoquer toutes les autres sessions ────────────────────────────────
    public function revokeAllSessions(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->tokens()
            ->where('id', '!=', $user->currentAccessToken()->id)
            ->delete();

        return response()->json(['message' => 'Toutes les autres sessions ont été révoquées.']);
    }

    // ── Formater l'utilisateur ─────────────────────────────────────────────
    private function formatUser($user): array
    {
        return [
            'id'                      => $user->id,
            'name'                    => $user->name,
            'email'                   => $user->email,
            'avatar'                  => $user->avatar,
            'avatar_url'              => $user->avatar_url,
            'department'              => $user->department,
            'position'                => $user->position,
            'last_login_at'           => $user->last_login_at?->toISOString(),
            'roles'                   => $user->getRoleNames(),
            'storage_used'            => $user->storage_used,
            'storage_quota'           => $user->storage_quota,
            'storage_used_percent'    => $user->storage_used_percent,
            'formatted_storage_used'  => $user->formatted_storage_used,
            'formatted_storage_quota' => $user->formatted_storage_quota,
            'email_notifications'     => $user->email_notifications,
            'created_at'              => $user->created_at->toISOString(),
        ];
    }
}