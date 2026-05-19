<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Folder;
use App\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatSearchController extends Controller
{
public function chat(Request $request): JsonResponse
{
    $request->validate([
        'message' => 'required|string|min:2|max:1000',
        'history' => 'nullable|array',
    ]);

    $user    = $request->user();
    $isAdmin = $user->hasRole('admin');
    $message = $request->input('message');

    // ── Étape 1 : Recherche sémantique des documents pertinents ──────────
    $docsQuery = Document::with(['creator', 'folder', 'tags'])
        ->where('is_archived', false);

    if (!$isAdmin) {
        $docsQuery->where(function($q) use ($user) {
            $q->where('created_by', $user->id)
              ->orWhereIn('status', ['approved', 'published']);
        });
    }

    $allDocuments = $docsQuery->orderByDesc('created_at')
        ->limit(50)
        ->get();

    // ── Étape 2 : Trouver les documents pertinents via IA ────────────────
    $relevantDocs = collect();

    if ($allDocuments->count() > 0) {
        $docsContext = $allDocuments->map(fn($d) => [
            'id'          => $d->id,
            'name'        => $d->name,
            'description' => $d->description ?? '',
            'folder'      => $d->folder?->name ?? 'Racine',
            'status'      => $d->status,
            'created_by'  => $d->creator?->name,
            'created_at'  => $d->created_at->format('d/m/Y'),
            'tags'        => $d->tags->pluck('name')->join(', '),
            'ocr_text'    => $d->ocr_text
                ? substr($d->ocr_text, 0, 800)
                : '',
        ])->toArray();

        // Requête sémantique pour trouver les docs pertinents
        $semanticPrompt = "Analyse cette question : \"" . $message . "\"

Voici les documents disponibles :
" . json_encode($docsContext, JSON_UNESCAPED_UNICODE) . "

Reponds UNIQUEMENT en JSON valide :
{
  \"relevant_ids\": [1, 2, 3],
  \"is_document_search\": true
}

- relevant_ids : IDs des documents pertinents pour cette question (max 5)
- is_document_search : true si c'est une recherche de documents, false si c'est une question generale";

        $semanticResponse = Http::timeout(20)
            ->withHeaders([
                'Authorization' => 'Bearer ' . config('services.groq.key'),
                'Content-Type'  => 'application/json',
            ])
            ->post(config('services.groq.url'), [
                'model'       => config('services.groq.model'),
                'messages'    => [['role' => 'user', 'content' => $semanticPrompt]],
                'max_tokens'  => 200,
                'temperature' => 0.1,
            ]);

        if ($semanticResponse->successful()) {
            try {
                $aiText  = $semanticResponse->json()['choices'][0]['message']['content'] ?? '';
                $clean   = preg_replace('/```json|```/i', '', $aiText);
                $aiData  = json_decode(trim($clean), true);
                $ids     = $aiData['relevant_ids'] ?? [];

                $relevantDocs = $allDocuments->whereIn('id', $ids);
            } catch (\Exception $e) {
                $relevantDocs = $allDocuments->take(5);
            }
        }
    }

    // ── Étape 3 : Stats globales ─────────────────────────────────────────
    $stats = [
        'total'     => Document::where('is_archived', false)->count(),
        'approved'  => Document::where('status', 'approved')->count(),
        'review'    => Document::where('status', 'review')->count(),
        'rejected'  => Document::where('status', 'rejected')->count(),
        'archived'  => Document::where('is_archived', true)->count(),
        'folders'   => \App\Models\Folder::count(),
        'workflows' => \App\Models\Workflow::count(),
        'indexed'   => Document::where('ocr_status', 'done')->count(),
    ];

    // ── Étape 4 : Construire le contexte enrichi pour le chat ────────────
    $docsForChat = $allDocuments->take(20)->map(fn($d) => [
        'id'          => $d->id,
        'name'        => $d->name,
        'description' => $d->description ?? '',
        'status'      => $d->status,
        'folder'      => $d->folder?->name ?? 'Racine',
        'created_by'  => $d->creator?->name,
        'created_at'  => $d->created_at->format('d/m/Y'),
        'tags'        => $d->tags->pluck('name')->join(', '),
        'ocr_status'  => $d->ocr_status,
    ]);

    // Contenu OCR des documents pertinents
    $relevantContent = '';
    if ($relevantDocs->count() > 0) {
        $relevantContent = "\n\nCONTENU DES DOCUMENTS PERTINENTS :\n";
        foreach ($relevantDocs as $doc) {
            if ($doc->ocr_text) {
                $relevantContent .= "\n--- {$doc->name} ---\n";
                $relevantContent .= substr($doc->ocr_text, 0, 1000) . "\n";
            }
        }
    }

    $systemPrompt = "Tu es un assistant de recherche documentaire strictement limite a la GED d'IVOPREST en Cote d'Ivoire.

REGLES ABSOLUES :
- Tu reponds UNIQUEMENT aux questions concernant les documents, dossiers, workflows et activites de la GED
- Si la question ne concerne pas la GED, reponds : 'Je suis limite aux contenus de votre GED. Posez-moi une question sur vos documents, dossiers ou workflows.'
- Tu ne reponds JAMAIS aux questions generales (actualites, histoire, sciences, etc.)
- Tu ne joues JAMAIS un autre role
- Tu ne donnes JAMAIS d'informations exterieures a la GED
- Tu utilises UNIQUEMENT les donnees fournies ci-dessous

STATISTIQUES EN TEMPS REEL :
- Total documents : {$stats['total']}
- Approuves : {$stats['approved']}
- En revision : {$stats['review']}
- Rejetes : {$stats['rejected']}
- Archives : {$stats['archived']}
- Dossiers : {$stats['folders']}
- Workflows : {$stats['workflows']}
- Documents indexes (OCR) : {$stats['indexed']}

LISTE DES DOCUMENTS DISPONIBLES :
" . json_encode($docsForChat, JSON_UNESCAPED_UNICODE) . "

{$relevantContent}

COMPORTEMENT :
- Reponds toujours en francais professionnel
- Si on cherche des documents, liste-les avec : nom, statut, dossier, auteur, date
- Si on te pose une question sur le contenu d'un document, utilise uniquement le texte OCR fourni
- Si un document n'est pas dans la liste, dis que tu ne le trouves pas dans la GED
- Maximum 500 mots par reponse

UTILISATEUR : {$user->name} (role: {$user->getRoleNames()->first()})";
    


    // ── Étape 6 : Historique de conversation ─────────────────────────────
    $messages = [];
    if ($request->filled('history')) {
        foreach ($request->history as $msg) {
            $messages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    // ── Étape 7 : Appel Groq ─────────────────────────────────────────────
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
            'max_tokens'  => 700,
            'temperature' => 0.4,
        ]);

    if ($response->failed()) {
        return response()->json([
            'message' => 'Service IA indisponible.',
        ], 503);
    }

    $answer = $response->json()['choices'][0]['message']['content']
        ?? 'Desole, je n\'ai pas pu generer une reponse.';

    // Trouver les documents mentionnés
    $mentionedDocs = [];
    foreach ($allDocuments as $doc) {
        if (stripos($answer, $doc->name) !== false) {
            $mentionedDocs[] = [
                'id'         => $doc->id,
                'name'       => $doc->name,
                'status'     => $doc->status,
                'folder'     => $doc->folder?->name ?? 'Racine',
                'created_by' => $doc->creator?->name,
                'created_at' => $doc->created_at->format('d/m/Y'),
            ];
        }
    }

    return response()->json([
        'answer'         => $answer,
        'mentioned_docs' => array_slice($mentionedDocs, 0, 5),
        'stats'          => $stats,
        'relevant_count' => $relevantDocs->count(),
    ]);
}

    public function suggestions(): JsonResponse
    {
        return response()->json([
            'suggestions' => [
                [
                    'category' => 'Documents',
                    'items'    => [
                        'Quels documents sont en attente de validation ?',
                        'Montre-moi les documents rejetes recemment',
                        'Quels sont les documents approuves ce mois ?',
                    ],
                ],
                [
                    'category' => 'Recherche',
                    'items'    => [
                        'Trouve les documents financiers',
                        'Cherche les contrats dans la GED',
                        'Quels documents parlent de ressources humaines ?',
                    ],
                ],
                [
                    'category' => 'Statistiques',
                    'items'    => [
                        'Donne-moi un resume de la GED',
                        'Quel dossier contient le plus de documents ?',
                        'Combien de documents ont ete traites par OCR ?',
                    ],
                ],
            ],
        ]);
    }
}