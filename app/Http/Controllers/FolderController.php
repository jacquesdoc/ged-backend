<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FolderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $isAdmin = $user->hasRole('admin');

        if ($isAdmin) {
            // Admin voit tous les dossiers racine
            $folders = Folder::whereNull('parent_id')
                ->with(['children', 'creator'])
                ->withCount('documents')
                ->get()
                ->map(fn($f) => array_merge(
                    $f->toArray(),
                    ['access_type' => 'owner']
                ));
        } else {
            // Dossiers créés par l'utilisateur
            $ownFolders = Folder::whereNull('parent_id')
                ->where('created_by', $user->id)
                ->with(['children', 'creator'])
                ->withCount('documents')
                ->get()
                ->map(fn($f) => array_merge(
                    $f->toArray(),
                    ['access_type' => 'owner']
                ));

            // Dossiers accessibles via les groupes (approuvés)
            $groupFolderIds = DB::table('folder_group_access')
                ->join('user_group_members', 'user_group_members.user_group_id', '=', 'folder_group_access.user_group_id')
                ->where('user_group_members.user_id', $user->id)
                ->where('folder_group_access.status', 'approved')
                ->pluck('folder_group_access.folder_id')
                ->toArray();

            $groupFolders = Folder::whereIn('id', $groupFolderIds)
                ->whereNull('parent_id')
                ->orWhereIn('id', $groupFolderIds)
                ->with(['children', 'creator'])
                ->withCount('documents')
                ->get()
                ->map(fn($f) => array_merge(
                    $f->toArray(),
                    ['access_type' => 'group']
                ));

            $folders = $ownFolders->merge($groupFolders)->unique('id')->values();
        }

        return response()->json($folders);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id'   => 'nullable|exists:folders,id',
            'color'       => 'nullable|string|max:7',
            'group_ids'   => 'nullable|array',
            'group_ids.*' => 'exists:user_groups,id',
        ]);

        $folder = Folder::create([
            'name'        => $request->name,
            'description' => $request->description,
            'parent_id'   => $request->parent_id,
            'created_by'  => $request->user()->id,
            'color'       => $request->color ?? '#2E7D32',
        ]);

        $folder->updatePath();

        // Assigner des groupes si admin ou éditeur
        if ($request->filled('group_ids') && !$request->user()->hasRole('reader')) {
            foreach ($request->group_ids as $groupId) {
                DB::table('folder_group_access')->insertOrIgnore([
                    'folder_id'     => $folder->id,
                    'user_group_id' => $groupId,
                    'permission'    => 'view',
                    'status'        => 'approved',
                    'approved_by'   => $request->user()->id,
                    'approved_at'   => now(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }

        activity('folder')
            ->causedBy($request->user())
            ->performedOn($folder)
            ->log('Dossier créé');

        return response()->json([
            'message' => 'Dossier créé.',
            'folder'  => $folder->load('creator'),
        ], 201);
    }

    public function show(Folder $folder): JsonResponse
    {
        $folder->load(['creator', 'children', 'documents.tags', 'documents.creator']);
        $folder->loadCount('documents');

        // Groupes assignés à ce dossier
        $groups = DB::table('folder_group_access')
            ->join('user_groups', 'user_groups.id', '=', 'folder_group_access.user_group_id')
            ->where('folder_group_access.folder_id', $folder->id)
            ->select('user_groups.id', 'user_groups.name', 'user_groups.color',
                     'folder_group_access.permission', 'folder_group_access.status')
            ->get();

        $data = $folder->toArray();
        $data['assigned_groups'] = $groups;

        return response()->json($data);
    }

    public function update(Request $request, Folder $folder): JsonResponse
    {
        $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:7',
            'group_ids'   => 'nullable|array',
            'group_ids.*' => 'exists:user_groups,id',
        ]);

        $folder->update($request->only(['name', 'description', 'color']));
        $folder->updatePath();

        // Mettre à jour les groupes assignés
        if ($request->has('group_ids') && !$request->user()->hasRole('reader')) {
            // Supprimer les anciens
            DB::table('folder_group_access')
                ->where('folder_id', $folder->id)
                ->delete();

            // Ajouter les nouveaux
            foreach ($request->group_ids as $groupId) {
                DB::table('folder_group_access')->insert([
                    'folder_id'     => $folder->id,
                    'user_group_id' => $groupId,
                    'permission'    => 'view',
                    'status'        => 'approved',
                    'approved_by'   => $request->user()->id,
                    'approved_at'   => now(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Dossier modifié.',
            'folder'  => $folder,
        ]);
    }

    public function destroy(Folder $folder): JsonResponse
    {
        if ($folder->children()->count() > 0 || $folder->documents()->count() > 0) {
            return response()->json([
                'message' => 'Impossible de supprimer un dossier non vide.'
            ], 422);
        }

        DB::table('folder_group_access')->where('folder_id', $folder->id)->delete();
        $folder->delete();

        return response()->json(['message' => 'Dossier supprimé.']);
    }

    public function tree(Request $request): JsonResponse
    {
        $user    = $request->user();
        $isAdmin = $user->hasRole('admin');

        if ($isAdmin) {
            $tree = Folder::whereNull('parent_id')
                ->with(['children.children'])
                ->withCount('documents')
                ->get();
        } else {
            // Dossiers propres
            $ownIds = Folder::where('created_by', $user->id)
                ->pluck('id')->toArray();

            // Dossiers via groupes
            $groupIds = DB::table('folder_group_access')
                ->join('user_group_members', 'user_group_members.user_group_id', '=', 'folder_group_access.user_group_id')
                ->where('user_group_members.user_id', $user->id)
                ->where('folder_group_access.status', 'approved')
                ->pluck('folder_group_access.folder_id')
                ->toArray();

            $allIds = array_unique(array_merge($ownIds, $groupIds));

            $tree = Folder::whereIn('id', $allIds)
                ->whereNull('parent_id')
                ->with(['children.children'])
                ->withCount('documents')
                ->get();
        }

        return response()->json($tree);
    }

    public function myAccessibleFolders(Request $request): JsonResponse
    {
        $user = $request->user();

        $ownFolders = Folder::where('created_by', $user->id)
            ->withCount('documents')
            ->get()
            ->map(fn($f) => array_merge($f->toArray(), ['access_type' => 'owner']));

        $groupFolders = DB::table('folder_group_access')
            ->join('folders', 'folders.id', '=', 'folder_group_access.folder_id')
            ->join('user_group_members', 'user_group_members.user_group_id', '=', 'folder_group_access.user_group_id')
            ->join('user_groups', 'user_groups.id', '=', 'folder_group_access.user_group_id')
            ->where('user_group_members.user_id', $user->id)
            ->where('folder_group_access.status', 'approved')
            ->select('folders.*', 'folder_group_access.permission', 'user_groups.name as group_name')
            ->get()
            ->map(fn($f) => array_merge((array)$f, ['access_type' => 'group']));

        return response()->json([
            'own_folders'   => $ownFolders,
            'group_folders' => $groupFolders,
        ]);
    }

    // ── Lecteur : demander accès à un dossier ──────────────────────────────
    public function requestAccess(Request $request, Folder $folder): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $user = $request->user();

        // Vérifier si demande déjà en attente
        $existing = DB::table('folder_access_requests')
            ->where('folder_id', $folder->id)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Une demande est déjà en attente pour ce dossier.'
            ], 422);
        }

        DB::table('folder_access_requests')->insert([
            'folder_id'  => $folder->id,
            'user_id'    => $user->id,
            'reason'     => $request->reason,
            'status'     => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Notifier les admins
        $admins = \App\Models\User::role('admin')->get();
        foreach ($admins as $admin) {
            $admin->notify(new \App\Notifications\WorkflowNotification(
                'submitted',
                "📁 Demande d'accès dossier : \"{$folder->name}\" par {$user->name}",
                new \App\Models\Workflow(['id' => 0, 'document_id' => 0])
            ));
        }

        return response()->json([
            'message' => 'Demande d\'accès envoyée à l\'administrateur.'
        ]);
    }

    // ── Admin : liste des demandes d'accès dossiers ────────────────────────
    public function accessRequests(): JsonResponse
    {
        $requests = DB::table('folder_access_requests')
            ->join('folders', 'folders.id', '=', 'folder_access_requests.folder_id')
            ->join('users', 'users.id', '=', 'folder_access_requests.user_id')
            ->where('folder_access_requests.status', 'pending')
            ->select(
                'folder_access_requests.*',
                'folders.name as folder_name',
                'folders.color as folder_color',
                'users.name as user_name',
                'users.email as user_email'
            )
            ->orderByDesc('folder_access_requests.created_at')
            ->get();

        return response()->json($requests);
    }

    // ── Admin : approuver demande d'accès ──────────────────────────────────
    public function approveAccessRequest(Request $request, int $requestId): JsonResponse
    {
        $accessRequest = DB::table('folder_access_requests')
            ->where('id', $requestId)
            ->first();

        if (!$accessRequest) {
            return response()->json(['message' => 'Demande introuvable.'], 404);
        }

        DB::table('folder_access_requests')
            ->where('id', $requestId)
            ->update([
                'status'      => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at'  => now(),
            ]);

        // Donner accès direct au dossier pour cet utilisateur
        DB::table('folder_shares')->insertOrIgnore([
            'folder_id'  => $accessRequest->folder_id,
            'user_id'    => $accessRequest->user_id,
            'permission' => 'view',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Notifier l'utilisateur
        $user = \App\Models\User::find($accessRequest->user_id);
        $folder = Folder::find($accessRequest->folder_id);
        $user?->notify(new \App\Notifications\WorkflowNotification(
            'approved',
            "✅ Accès accordé au dossier \"{$folder->name}\"",
            new \App\Models\Workflow(['id' => 0, 'document_id' => 0])
        ));

        return response()->json(['message' => 'Accès accordé.']);
    }

    // ── Admin : rejeter demande d'accès ────────────────────────────────────
    public function rejectAccessRequest(Request $request, int $requestId): JsonResponse
    {
        $request->validate(['reason' => 'required|string']);

        $accessRequest = DB::table('folder_access_requests')
            ->where('id', $requestId)
            ->first();

        DB::table('folder_access_requests')
            ->where('id', $requestId)
            ->update([
                'status'      => 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at'  => now(),
            ]);

        // Notifier l'utilisateur
        $user   = \App\Models\User::find($accessRequest->user_id);
        $folder = Folder::find($accessRequest->folder_id);
        $user?->notify(new \App\Notifications\WorkflowNotification(
            'rejected',
            "❌ Accès refusé au dossier \"{$folder->name}\" : {$request->reason}",
            new \App\Models\Workflow(['id' => 0, 'document_id' => 0])
        ));

        return response()->json(['message' => 'Demande refusée.']);
    }
}