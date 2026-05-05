<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tags = Tag::withCount('documents')
            ->when($request->search, fn($q) => $q->where('name', 'like', '%'.$request->search.'%'))
            ->orderBy('name')
            ->get();

        return response()->json($tags);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'  => 'required|string|max:50|unique:tags',
            'color' => 'nullable|string|max:7',
        ]);

        $tag = Tag::create([
            'name'       => $request->name,
            'color'      => $request->color ?? '#'.substr(md5(rand()), 0, 6),
            'created_by' => $request->user()->id,
        ]);

        return response()->json($tag, 201);
    }

    public function show(Tag $tag): JsonResponse
    {
        return response()->json($tag->load('documents'));
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        $request->validate([
            'name'  => 'sometimes|string|max:50|unique:tags,name,'.$tag->id,
            'color' => 'nullable|string|max:7',
        ]);

        $tag->update($request->only(['name', 'color']));
        return response()->json($tag);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $tag->documents()->detach();
        $tag->delete();
        return response()->json(['message' => 'Tag supprimé.']);
    }
}