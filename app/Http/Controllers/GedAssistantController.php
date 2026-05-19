<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Folder;
use App\Models\Workflow;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

class GedAssistantController extends Controller
{
    private function buildContext(Request $request): array
    {
        $user    = $request->user();
        $isAdmin = $user->hasRole('admin');

        $docsQuery = $isAdmin
            ? Document::query()
            : Document::where('created_by', $user->id);

        $stats = [
            'total'    => (clone $docsQuery)->count(),
            'draft'    => (clone $docsQuery)->where('status', 'draft')->count(),
            'review'   => (clone $docsQuery)->where('status', 'review')->count(),
            'approved' => (clone $docsQuery)->where('status', 'approved')->count(),
            'rejected' => (clone $docsQuery)->where('status', 'rejected')->count(),
            'archived' => (clone $docsQuery)->where('is_archived', true)->count(),
        ];

        $recentDocs = (clone $docsQuery)
            ->with(['creator', 'folder'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($d) => [
                'id'         => $d->id,
                'name'       => $d->name,
                'status'     => $d->status,
                'created_by' => $d->creator?->name,
                'folder'     => $d->folder?->name ?? 'Racine',
                'created_at' => $d->created_at->format('d/m/Y'),
                'ocr_status' => $d->ocr_status,
            ]);

        $wfQuery = $isAdmin
            ? Workflow::with(['document', 'requester'])
            : Workflow::where('requested_by', $user->id)->with(['document', 'requester']);

        $workflows = (clone $wfQuery)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($w) => [
                'document'   => $w->document?->name,
                'status'     => $w->status,
                'type'       => $w->type,
                'requester'  => $w->requester?->name,
                'created_at' => $w->created_at->format('d/m/Y'),
            ]);

        $folders = Folder::withCount('documents')
            ->limit(10)
            ->get()
            ->map(fn($f) => [
                'name'      => $f->name,
                'documents' => $f->documents_count,
            ]);

        $users = [];
        if ($isAdmin) {
            $users = User::with('roles')->get()->map(fn($u) => [
                'name'  => $u->name,
                'email' => $u->email,
                'role'  => $u->getRoleNames()->first(),
            ]);
        }

        $activity = Activity::with('causer')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn($a) => [
                'action'     => $a->description,
                'user'       => $a->causer?->name,
                'created_at' => $a->created_at->format('d/m/Y H:i'),
            ]);

        return [
            'user'      => ['name' => $user->name, 'role' => $user->getRoleNames()->first()],
            'stats'     => $stats,
            'documents' => $recentDocs,
            'workflows' => $workflows,
            'folders'   => $folders,
            'users'     => $users,
            'activity'  => $activity,
        ];
    }

    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|min:2|max:1000',
            'history' => 'nullable|array',
        ]);

        $user    = $request->user();
        $context = $this->buildContext($request);

        $systemPrompt = "Tu es un assistant IA integre dans une GED pour IVOPREST en Cote d'Ivoire.

UTILISATEUR : {$context['user']['name']} (role: {$context['user']['role']})

STATS DOCUMENTS :
- Total : {$context['stats']['total']}
- Brouillons : {$context['stats']['draft']}
- En revision : {$context['stats']['review']}
- Approuves : {$context['stats']['approved']}
- Rejetes : {$context['stats']['rejected']}
- Archives : {$context['stats']['archived']}

DOCUMENTS RECENTS :
" . json_encode($context['documents'], JSON_UNESCAPED_UNICODE) . "

WORKFLOWS :
" . json_encode($context['workflows'], JSON_UNESCAPED_UNICODE) . "

DOSSIERS :
" . json_encode($context['folders'], JSON_UNESCAPED_UNICODE) . "

ACTIVITE :
" . json_encode($context['activity'], JSON_UNESCAPED_UNICODE) . "

INSTRUCTIONS :
- Reponds toujours en français
- Sois precis, concis et professionnel
- Utilise les donnees reelles ci-dessus
- Maximum 300 mots";

        $messages = [];
        if ($request->filled('history')) {
            foreach ($request->history as $msg) {
                $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $request->message];

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer ' . config('services.groq.key'),
                'Content-Type'  => 'application/json',
            ])
            ->post(config('services.groq.url'), [
                'model'       => config('services.groq.model'),
                'messages'    => array_merge(
                    [['role' => 'system', 'content' => $systemPrompt]],
                    $messages
                ),
                'max_tokens'  => 500,
                'temperature' => 0.5,
            ]);

        if ($response->failed()) {
            return response()->json(['message' => 'Service IA indisponible.'], 503);
        }

        $answer = $response->json()['choices'][0]['message']['content']
            ?? 'Desole, je n\'ai pas pu generer de reponse.';

        return response()->json([
            'answer'  => $answer,
            'context' => ['stats' => $context['stats']],
        ]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $user    = $request->user();
        $isAdmin = $user->hasRole('admin');

        $suggestions = [
            "Combien de documents sont en attente d'approbation ?",
            "Quels sont les documents crees cette semaine ?",
            "Montre-moi un resume de l'activite recente",
            "Quels workflows sont en cours ?",
            "Quel est le dossier avec le plus de documents ?",
        ];

        if ($isAdmin) {
            $suggestions[] = "Combien d'utilisateurs sont actifs ?";
            $suggestions[] = "Quels documents ont ete rejetes recemment ?";
            $suggestions[] = "Donne-moi les statistiques globales de la GED";
        }

        return response()->json(['suggestions' => $suggestions]);
    }
}