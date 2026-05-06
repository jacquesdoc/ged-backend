<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    // ── Liste des utilisateurs ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $users = User::with('roles')
            ->when($request->filled('search'), fn($q) =>
                $q->where('name',  'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%')
            )
            ->orderBy('name')
            ->paginate($request->get('per_page', 20));

        return response()->json($users);
    }

    // ── Créer un utilisateur ───────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role'     => 'required|in:admin,editor,reader',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $user->assignRole($request->role);

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('Utilisateur créé');

        return response()->json([
            'message' => 'Utilisateur créé avec succès.',
            'user'    => $user->load('roles'),
        ], 201);
    }

    // ── Détail d'un utilisateur ────────────────────────────────────────────
    public function show(User $user): JsonResponse
    {
        return response()->json($user->load('roles'));
    }

    // ── Modifier un utilisateur ────────────────────────────────────────────
    public function update(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'name'  => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'role'  => 'sometimes|in:admin,editor,reader',
        ]);

        $user->update($request->only(['name', 'email']));

        if ($request->filled('role')) {
            $user->syncRoles([$request->role]);
        }

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('Utilisateur modifié');

        return response()->json([
            'message' => 'Utilisateur mis à jour.',
            'user'    => $user->load('roles'),
        ]);
    }

    // ── Supprimer un utilisateur ───────────────────────────────────────────
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas supprimer votre propre compte.'
            ], 422);
        }

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('Utilisateur supprimé');

        $user->delete();

        return response()->json(['message' => 'Utilisateur supprimé.']);
    }

    // ── Changer le mot de passe ────────────────────────────────────────────
    public function changePassword(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Mot de passe modifié.']);
    }

    // ── Révoquer les sessions ──────────────────────────────────────────────
    public function toggleStatus(User $user): JsonResponse
    {
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Sessions de l\'utilisateur révoquées.'
        ]);
    }
}