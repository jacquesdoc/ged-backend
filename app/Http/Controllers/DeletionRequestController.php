<?php

namespace App\Http\Controllers;

use App\Models\DeletionRequest;
use App\Models\Document;
use App\Notifications\WorkflowNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DeletionRequestController extends Controller
{
    // ── Liste des demandes (admin) ─────────────────────────────────────────
    public function index(): JsonResponse
    {
        $requests = DeletionRequest::with([
            'document', 'requester', 'reviewer'
        ])
        ->orderByDesc('created_at')
        ->get();

        return response()->json($requests);
    }

    // ── Demandes en attente (admin) ────────────────────────────────────────
    public function pending(): JsonResponse
    {
        $requests = DeletionRequest::with(['document', 'requester'])
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($requests);
    }

    // ── Créer une demande (lecteur) ────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'document_id' => 'required|exists:documents,id',
            'reason'      => 'required|string|min:10|max:500',
        ]);

        // Vérifier qu'il n'y a pas déjà une demande en attente
        $existing = DeletionRequest::where('document_id', $request->document_id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Une demande de suppression est déjà en attente pour ce document.'
            ], 422);
        }

        $document = Document::find($request->document_id);

        $deletionRequest = DeletionRequest::create([
            'document_id'  => $request->document_id,
            'requested_by' => $request->user()->id,
            'status'       => 'pending',
            'reason'       => $request->reason,
        ]);

        // Notifier tous les admins
        $admins = \App\Models\User::role('admin')->get();
        foreach ($admins as $admin) {
            $admin->notify(new \App\Notifications\DeletionRequestNotification(
                'pending',
                "📋 Demande de suppression : \"{$document->name}\"",
                $deletionRequest
            ));
        }

        activity('document')
            ->causedBy($request->user())
            ->performedOn($document)
            ->log('Demande de suppression soumise');

        return response()->json([
            'message' => 'Demande de suppression envoyée à l\'administrateur.',
            'request' => $deletionRequest->load(['document', 'requester']),
        ], 201);
    }

    // ── Approuver (admin) ──────────────────────────────────────────────────
    public function approve(Request $request, DeletionRequest $deletionRequest): JsonResponse
    {
        $request->validate([
            'admin_comment' => 'nullable|string|max:500',
        ]);

        $document = $deletionRequest->document;

        $deletionRequest->update([
            'status'        => 'approved',
            'reviewed_by'   => $request->user()->id,
            'admin_comment' => $request->admin_comment,
            'reviewed_at'   => now(),
        ]);

        // Supprimer le fichier physique
        if ($document->file_path && Storage::exists($document->file_path)) {
            Storage::delete($document->file_path);
        }

        // Supprimer le document
        $document->delete();

        // Notifier le demandeur
        $deletionRequest->requester->notify(new \App\Notifications\DeletionRequestNotification(
            'approved',
            "✅ Suppression approuvée : \"{$document->name}\"",
            $deletionRequest,
            $request->admin_comment
        ));

        activity('document')
            ->causedBy($request->user())
            ->log("Suppression approuvée : {$document->name}");

        return response()->json(['message' => 'Document supprimé avec succès.']);
    }

    // ── Rejeter (admin) ────────────────────────────────────────────────────
    public function reject(Request $request, DeletionRequest $deletionRequest): JsonResponse
    {
        $request->validate([
            'admin_comment' => 'required|string|min:5|max:500',
        ]);

        $document = $deletionRequest->document;

        $deletionRequest->update([
            'status'        => 'rejected',
            'reviewed_by'   => $request->user()->id,
            'admin_comment' => $request->admin_comment,
            'reviewed_at'   => now(),
        ]);

        // Notifier le demandeur avec le motif
        $deletionRequest->requester->notify(new \App\Notifications\DeletionRequestNotification(
            'rejected',
            "❌ Suppression refusée : \"{$document->name}\"",
            $deletionRequest,
            $request->admin_comment
        ));

        return response()->json(['message' => 'Demande de suppression refusée.']);
    }

    // ── Mes demandes (lecteur) ─────────────────────────────────────────────
    public function myRequests(Request $request): JsonResponse
    {
        $requests = DeletionRequest::with(['document', 'reviewer'])
            ->where('requested_by', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($requests);
    }
}