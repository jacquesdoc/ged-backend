<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Workflow;
use App\Models\WorkflowApproval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    // ── Liste ──────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $workflows = Workflow::with(['document', 'requester', 'approvals.approver'])
            ->when(!$request->user()->hasRole('admin'), function ($q) use ($request) {
                $q->where('requested_by', $request->user()->id);
            })
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($workflows);
    }

    // ── Créer ──────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'document_id'    => 'required|exists:documents,id',
            'type'           => 'required|in:validation,approval,review,publication',
            'approver_ids'   => 'required|array|min:1',
            'approver_ids.*' => 'exists:users,id',
            'notes'          => 'nullable|string|max:1000',
            'due_date'       => 'nullable|date|after:now',
        ]);

        $steps = collect($request->approver_ids)
            ->values()
            ->map(fn($id, $i) => [
                'step'        => $i + 1,
                'approver_id' => $id,
                'status'      => $i === 0 ? 'pending' : 'waiting',
            ])->toArray();

        $workflow = Workflow::create([
            'document_id'  => $request->document_id,
            'requested_by' => $request->user()->id,
            'type'         => $request->type,
            'status'       => 'in_review',
            'current_step' => 1,
            'steps'        => $steps,
            'notes'        => $request->notes,
            'due_date'     => $request->due_date,
        ]);

        // Créer la première approbation
        WorkflowApproval::create([
            'workflow_id' => $workflow->id,
            'approver_id' => $request->approver_ids[0],
            'step'        => 1,
            'status'      => 'pending',
        ]);

        // Mettre le document en révision
        Document::find($request->document_id)
            ->update(['status' => 'review']);

        activity('workflow')
            ->causedBy($request->user())
            ->performedOn($workflow)
            ->log('Workflow créé');

        return response()->json([
            'message'  => 'Workflow créé.',
            'workflow' => $workflow->load(['document', 'approvals.approver']),
        ], 201);
    }

    // ── Détail ─────────────────────────────────────────────────────────────
    public function show(Workflow $workflow): JsonResponse
    {
        return response()->json(
            $workflow->load(['document', 'requester', 'approvals.approver'])
        );
    }

    // ── Approuver ──────────────────────────────────────────────────────────
    public function approve(Request $request, Workflow $workflow): JsonResponse
    {
        $request->validate(['comment' => 'nullable|string|max:1000']);

        $approval = WorkflowApproval::where('workflow_id', $workflow->id)
            ->where('approver_id', $request->user()->id)
            ->where('status', 'pending')
            ->first();

        if (!$approval) {
            return response()->json([
                'message' => 'Vous n\'êtes pas le validateur de cette étape.'
            ], 403);
        }

        $approval->update([
            'status'   => 'approved',
            'comment'  => $request->comment,
            'acted_at' => now(),
        ]);

        $steps    = $workflow->steps;
        $nextStep = $workflow->current_step + 1;

        if ($nextStep > count($steps)) {
            // Toutes les étapes approuvées
            $workflow->update([
                'status'       => 'approved',
                'completed_at' => now(),
            ]);
            $workflow->document->update(['status' => 'approved']);
        } else {
            // Passer à l'étape suivante
            $workflow->update(['current_step' => $nextStep]);
            WorkflowApproval::create([
                'workflow_id' => $workflow->id,
                'approver_id' => $steps[$nextStep - 1]['approver_id'],
                'step'        => $nextStep,
                'status'      => 'pending',
            ]);
        }

        return response()->json([
            'message'  => 'Approuvé avec succès.',
            'workflow' => $workflow->fresh()->load('approvals.approver'),
        ]);
    }

    // ── Rejeter ────────────────────────────────────────────────────────────
    public function reject(Request $request, Workflow $workflow): JsonResponse
    {
        $request->validate(['comment' => 'required|string|max:1000']);

        $approval = WorkflowApproval::where('workflow_id', $workflow->id)
            ->where('approver_id', $request->user()->id)
            ->where('status', 'pending')
            ->first();

        if (!$approval) {
            return response()->json([
                'message' => 'Vous n\'êtes pas le validateur de cette étape.'
            ], 403);
        }

        $approval->update([
            'status'   => 'rejected',
            'comment'  => $request->comment,
            'acted_at' => now(),
        ]);

        $workflow->update([
            'status'       => 'rejected',
            'completed_at' => now(),
        ]);

        $workflow->document->update(['status' => 'rejected']);

        return response()->json([
            'message'  => 'Rejeté.',
            'workflow' => $workflow->fresh()->load('approvals.approver'),
        ]);
    }

    // ── Annuler ────────────────────────────────────────────────────────────
    public function cancel(Request $request, Workflow $workflow): JsonResponse
    {
        if ($workflow->requested_by !== $request->user()->id
            && !$request->user()->hasRole('admin')) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $workflow->update([
            'status'       => 'cancelled',
            'completed_at' => now(),
        ]);

        $workflow->document->update(['status' => 'draft']);

        return response()->json(['message' => 'Workflow annulé.']);
    }

    // ── Approbations en attente ────────────────────────────────────────────
    public function pendingApprovals(Request $request): JsonResponse
    {
        $approvals = WorkflowApproval::where('approver_id', $request->user()->id)
            ->where('status', 'pending')
            ->with(['workflow.document', 'workflow.requester'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($approvals);
    }

    public function update(Request $request, Workflow $workflow): JsonResponse
    {
        $request->validate(['notes' => 'nullable|string', 'due_date' => 'nullable|date']);
        $workflow->update($request->only(['notes', 'due_date']));
        return response()->json($workflow);
    }

    public function destroy(Workflow $workflow): JsonResponse
    {
        $workflow->delete();
        return response()->json(['message' => 'Workflow supprimé.']);
    }
}