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

        $token = $user->createToken('ged-token')->plainTextToken;

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
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'roles'      => $user->getRoleNames(),
            'permissions'=> $user->getAllPermissions()->pluck('name'),
            'created_at' => $user->created_at->toISOString(),
        ];
    }
}