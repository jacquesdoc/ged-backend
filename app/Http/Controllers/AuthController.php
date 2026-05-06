<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // ── Connexion ──────────────────────────────────────────────────────────
        public function login(Request $request): JsonResponse
        {
            $request->validate([
                'email'    => 'required|email',
                'password' => 'required|string',
            ]);

            $user = User::where('email', $request->email)->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Les identifiants sont incorrects.'],
                ]);
            }

            // Enregistrer la date de dernière connexion
            $user->update(['last_login_at' => now()]);

            $token = $user->createToken('ged-token')->plainTextToken;

            activity('auth')
                ->causedBy($user)
                ->log('Connexion réussie');

            return response()->json([
                'user'  => $this->formatUser($user),
                'token' => $token,
            ]);
        }

    // ── Déconnexion ────────────────────────────────────────────────────────
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté avec succès.']);
    }

    // ── Profil connecté ────────────────────────────────────────────────────
    public function me(Request $request): JsonResponse
    {
        return response()->json(
            $this->formatUser($request->user()->load('roles'))
        );
    }

    // ── Mise en forme de l'utilisateur ─────────────────────────────────────
    private function formatUser(User $user): array
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
            'permissions'             => $user->getAllPermissions()->pluck('name'),
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