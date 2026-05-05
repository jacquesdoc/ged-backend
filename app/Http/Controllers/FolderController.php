<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $folders = Folder::where('created_by', $request->user()->id)
            ->whereNull('parent_id')
            ->with(['children', 'creator'])
            ->withCount('documents')
            ->get();

        return response()->json($folders);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id'   => 'nullable|exists:folders,id',
            'color'       => 'nullable|string|max:7',
        ]);

        $folder = Folder::create([
            'name'        => $request->name,
            'description' => $request->description,
            'parent_id'   => $request->parent_id,
            'created_by'  => $request->user()->id,
            'color'       => $request->color ?? '#2E7D32',
        ]);

        $folder->updatePath();

        return response()->json([
            'message' => 'Dossier créé.',
            'folder'  => $folder->load('creator'),
        ], 201);
    }

    public function show(Folder $folder): JsonResponse
    {
        $folder->load(['creator', 'children', 'documents.tags', 'documents.creator']);
        $folder->loadCount('documents');
        return response()->json($folder);
    }

    public function update(Request $request, Folder $folder): JsonResponse
    {
        $request->validate([
            'name'  => 'sometimes|string|max:255',
            'color' => 'nullable|string|max:7',
        ]);

        $folder->update($request->only(['name', 'description', 'color']));
        $folder->updatePath();

        return response()->json(['message' => 'Dossier modifié.', 'folder' => $folder]);
    }

    public function destroy(Folder $folder): JsonResponse
    {
        if ($folder->children()->count() > 0 || $folder->documents()->count() > 0) {
            return response()->json([
                'message' => 'Impossible de supprimer un dossier non vide.',
            ], 422);
        }

        $folder->delete();
        return response()->json(['message' => 'Dossier supprimé.']);
    }

    public function tree(Request $request): JsonResponse
    {
        $tree = Folder::whereNull('parent_id')
            ->where('created_by', $request->user()->id)
            ->with(['children.children'])
            ->withCount('documents')
            ->get();

        return response()->json($tree);
    }
}