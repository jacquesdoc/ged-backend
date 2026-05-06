<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\UserGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserGroupController extends Controller
{
    // ── Liste des groupes ──────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $groups = UserGroup::with(['members', 'creator'])
            ->withCount('members')
            ->orderBy('name')
            ->get();

        return response()->json($groups);
    }

    // ── Créer un groupe ────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255|unique:user_groups',
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:7',
            'user_ids'    => 'nullable|array',
            'user_ids.*'  => 'exists:users,id',
        ]);

        $group = UserGroup::create([
            'name'        => $request->name,
            'description' => $request->description,
            'color'       => $request->color ?? '#2E7D32',
            'created_by'  => $request->user()->id,
        ]);

        if ($request->filled('user_ids')) {
            $group->members()->sync($request->user_ids);
        }

        return response()->json([
            'message' => 'Groupe créé.',
            'group'   => $group->load('members'),
        ], 201);
    }

    // ── Détail ─────────────────────────────────────────────────────────────
    public function show(UserGroup $userGroup): JsonResponse
    {
        return response()->json($userGroup->load(['members', 'folders']));
    }

    // ── Modifier ───────────────────────────────────────────────────────────
    public function update(Request $request, UserGroup $userGroup): JsonResponse
    {
        $request->validate([
            'name'       => 'sometimes|string|max:255',
            'description'=> 'nullable|string',
            'color'      => 'nullable|string|max:7',
            'user_ids'   => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $userGroup->update($request->only(['name', 'description', 'color']));

        if ($request->has('user_ids')) {
            $userGroup->members()->sync($request->user_ids);
        }

        return response()->json([
            'message' => 'Groupe mis à jour.',
            'group'   => $userGroup->load('members'),
        ]);
    }

    // ── Supprimer ──────────────────────────────────────────────────────────
    public function destroy(UserGroup $userGroup): JsonResponse
    {
        $userGroup->delete();
        return response()->json(['message' => 'Groupe supprimé.']);
    }

    // ── Donner accès à un dossier ──────────────────────────────────────────
    public function grantFolderAccess(Request $request, UserGroup $userGroup): JsonResponse
    {
        $request->validate([
            'folder_id'  => 'required|exists:folders,id',
            'permission' => 'required|in:view,edit',
        ]);

        $user      = $request->user();
        $isAdmin   = $user->hasRole('admin');

        // Admin approuve directement, autres soumettent pour validation
        $status = $isAdmin ? 'approved' : 'pending';

        $userGroup->folders()->syncWithoutDetaching([
            $request->folder_id => [
                'permission'  => $request->permission,
                'status'      => $status,
                'approved_by' => $isAdmin ? $user->id : null,
                'approved_at' => $isAdmin ? now() : null,
            ]
        ]);

        activity('folder')
            ->causedBy($user)
            ->log($isAdmin
                ? "Accès dossier accordé au groupe {$userGroup->name}"
                : "Demande d'accès dossier soumise pour le groupe {$userGroup->name}"
            );

        return response()->json([
            'message' => $isAdmin
                ? 'Accès accordé au groupe.'
                : 'Demande soumise pour validation administrateur.',
        ]);
    }

    // ── Approuver accès dossier (admin seulement) ──────────────────────────
    public function approveFolderAccess(Request $request, UserGroup $userGroup, Folder $folder): JsonResponse
    {
        \DB::table('folder_group_access')
            ->where('folder_id', $folder->id)
            ->where('user_group_id', $userGroup->id)
            ->update([
                'status'      => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

        return response()->json(['message' => 'Accès approuvé.']);
    }

    // ── Demandes en attente (admin seulement) ──────────────────────────────
    public function pendingAccess(): JsonResponse
    {
        $pending = \DB::table('folder_group_access')
            ->where('status', 'pending')
            ->join('user_groups', 'user_groups.id', '=', 'folder_group_access.user_group_id')
            ->join('folders', 'folders.id', '=', 'folder_group_access.folder_id')
            ->select(
                'folder_group_access.*',
                'user_groups.name as group_name',
                'folders.name as folder_name'
            )
            ->get();

        return response()->json($pending);
    }
}