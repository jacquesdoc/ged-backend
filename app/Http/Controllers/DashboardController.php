<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $service) {}

    // ── Liste ──────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = Document::with(['creator', 'tags', 'folder'])
            ->where(function($q) use ($request) {
                if (!$request->user()->hasRole('admin')) {
                    $q->where('created_by', $request->user()->id);
                }
            });

        if ($request->filled('folder_id')) {
            $query->where('folder_id', $request->folder_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('archived')) {
            $query->where('is_archived', true);
        } else {
            $query->where('is_archived', false);
        }

        $documents = $query->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        return response()->json($documents);
    }

    // ── Créer ──────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'folder_id'   => 'nullable|exists:folders,id',
            'file'        => 'nullable|file|max:51200',
            'tag_ids'     => 'nullable|array',
            'tag_ids.*'   => 'exists:tags,id',
        ]);

        $document = Document::create([
            'name'        => $request->name,
            'description' => $request->description,
            'folder_id'   => $request->folder_id,
            'created_by'  => $request->user()->id,
            'status'      => 'draft',
            'file_path'   => '',
            'file_name'   => '',
            'file_size'   => 0,
            'mime_type'   => '',
            'extension'   => '',
        ]);

        if ($request->hasFile('file')) {
            $this->service->storeFile($document, $request->file('file'));
        }

        if ($request->filled('tag_ids')) {
            $document->tags()->sync($request->tag_ids);
        }

        activity('document')
            ->causedBy($request->user())
            ->performedOn($document)
            ->log('Document créé');

        return response()->json([
            'message'  => 'Document créé avec succès.',
            'document' => $document->load(['creator', 'tags', 'folder']),
        ], 201);
    }

    // ── Détail ─────────────────────────────────────────────────────────────
    public function show(Document $document): JsonResponse
    {
        $document->load([
            'creator', 'tags', 'folder',
            'versions.creator',
            'comments.user',
        ]);

        return response()->json($document);
    }

    // ── Modifier ───────────────────────────────────────────────────────────
    public function update(Request $request, Document $document): JsonResponse
    {
        $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'folder_id'   => 'nullable|exists:folders,id',
            'status'      => 'sometimes|in:draft,review,approved,rejected,published,archived',
            'tag_ids'     => 'nullable|array',
        ]);

        $document->update($request->only([
            'name', 'description', 'folder_id', 'status'
        ]));

        if ($request->has('tag_ids')) {
            $document->tags()->sync($request->tag_ids);
        }

        activity('document')
            ->causedBy($request->user())
            ->performedOn($document)
            ->log('Document modifié');

        return response()->json([
            'message'  => 'Document mis à jour.',
            'document' => $document->load(['creator', 'tags', 'folder']),
        ]);
    }

    // ── Supprimer ──────────────────────────────────────────────────────────
    public function destroy(Document $document): JsonResponse
    {
        $this->service->deleteFile($document);

        activity('document')
            ->causedBy(auth()->user())
            ->performedOn($document)
            ->log('Document supprimé');

        $document->delete();

        return response()->json(['message' => 'Document supprimé.']);
    }

    // ── Télécharger ────────────────────────────────────────────────────────
    public function download(Document $document): mixed
    {
        if (!$document->file_path || !Storage::exists($document->file_path)) {
            return response()->json(['message' => 'Fichier introuvable.'], 404);
        }

        activity('document')
            ->causedBy(auth()->user())
            ->performedOn($document)
            ->log('Document téléchargé');

        return Storage::download($document->file_path, $document->file_name);
    }

    // ── Prévisualiser ──────────────────────────────────────────────────────
    public function preview(Document $document): mixed
    {
        if (!$document->file_path || !Storage::exists($document->file_path)) {
            return response()->json(['message' => 'Fichier introuvable.'], 404);
        }

        return response()->file(
            Storage::path($document->file_path),
            ['Content-Type' => $document->mime_type]
        );
    }

    // ── Archiver ───────────────────────────────────────────────────────────
    public function archive(Document $document): JsonResponse
    {
        $document->update([
            'is_archived' => true,
            'archived_at' => now(),
            'status'      => 'archived',
        ]);

        return response()->json(['message' => 'Document archivé.']);
    }

    // ── Restaurer ──────────────────────────────────────────────────────────
    public function restore(Document $document): JsonResponse
    {
        $document->update([
            'is_archived' => false,
            'archived_at' => null,
            'status'      => 'draft',
        ]);

        return response()->json([
            'message'  => 'Document restauré.',
            'document' => $document,
        ]);
    }

    // ── Ajouter commentaire ────────────────────────────────────────────────
    public function addComment(Request $request, Document $document): JsonResponse
    {
        $request->validate(['content' => 'required|string|max:1000']);

        $comment = $document->comments()->create([
            'user_id' => $request->user()->id,
            'content' => $request->content,
        ]);

        return response()->json($comment->load('user'), 201);
    }
}