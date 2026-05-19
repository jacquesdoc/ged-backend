<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SemanticSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $request->validate(['query' => 'required|string|min:3|max:500']);

        $user    = $request->user();
        $isAdmin = $user->hasRole('admin');
        $query   = $request->input('query');

        $docsQuery = Document::with(['creator', 'folder', 'tags'])
            ->where('is_archived', false)
            ->where(function($q) {
                $q->whereNotNull('ocr_text')
                  ->orWhereNotNull('description');
            });

        if (!$isAdmin) {
            $docsQuery->where(function($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhereIn('status', ['approved', 'published']);
            });
        }

        $documents = $docsQuery->limit(50)->get();

        if ($documents->isEmpty()) {
            return response()->json([
                'query'   => $query,
                'results' => [],
                'message' => 'Aucun document indexe trouve.',
            ]);
        }

        $docsContext = $documents->map(fn($d) => [
            'id'          => $d->id,
            'name'        => $d->name,
            'description' => $d->description ?? '',
            'folder'      => $d->folder?->name ?? 'Racine',
            'status'      => $d->status,
            'created_by'  => $d->creator?->name,
            'created_at'  => $d->created_at->format('d/m/Y'),
            'tags'        => $d->tags->pluck('name')->join(', '),
            'text_sample' => $d->ocr_text ? substr($d->ocr_text, 0, 300) : '',
        ])->toArray();

        $prompt = "Tu es un moteur de recherche semantique pour une GED.

Voici les documents disponibles :
" . json_encode($docsContext, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "

Recherche : \"" . $query . "\"

Reponds UNIQUEMENT avec un JSON valide sans markdown :
{
  \"results\": [
    {
      \"id\": 1,
      \"relevance_score\": 95,
      \"relevance_reason\": \"Ce document correspond car...\"
    }
  ],
  \"interpretation\": \"Explication de la recherche\"
}

Regles :
- Score > 40 uniquement
- Score de 0 a 100
- Trier par score decroissant
- Maximum 10 resultats";

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer ' . config('services.groq.key'),
                'Content-Type'  => 'application/json',
            ])
            ->post(config('services.groq.url'), [
                'model'       => config('services.groq.model'),
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'max_tokens'  => 1000,
                'temperature' => 0.1,
            ]);

        if ($response->failed()) {
            return response()->json(['message' => 'Service IA indisponible.'], 503);
        }

        $aiText = $response->json()['choices'][0]['message']['content'] ?? '';

        try {
            $clean  = preg_replace('/```json|```/i', '', $aiText);
            $aiData = json_decode(trim($clean), true);
            if (!$aiData || !isset($aiData['results'])) {
                throw new \Exception('Invalid JSON');
            }
        } catch (\Exception $e) {
            return response()->json([
                'query'          => $query,
                'results'        => [],
                'interpretation' => 'Erreur lors de l\'analyse.',
            ]);
        }

        $enrichedResults = [];
        foreach ($aiData['results'] as $result) {
            $doc = $documents->firstWhere('id', $result['id']);
            if ($doc) {
                $enrichedResults[] = [
                    'id'               => $doc->id,
                    'name'             => $doc->name,
                    'description'      => $doc->description,
                    'status'           => $doc->status,
                    'mime_type'        => $doc->mime_type,
                    'file_size'        => $doc->file_size,
                    'created_at'       => $doc->created_at->toISOString(),
                    'creator'          => $doc->creator?->name,
                    'folder'           => $doc->folder?->name ?? 'Racine',
                    'tags'             => $doc->tags,
                    'ocr_status'       => $doc->ocr_status,
                    'relevance_score'  => $result['relevance_score'],
                    'relevance_reason' => $result['relevance_reason'],
                ];
            }
        }

        usort($enrichedResults, fn($a, $b) =>
            $b['relevance_score'] <=> $a['relevance_score']
        );

        return response()->json([
            'query'          => $query,
            'total'          => count($enrichedResults),
            'results'        => $enrichedResults,
            'interpretation' => $aiData['interpretation'] ?? '',
        ]);
    }

    public function indexStatus(): JsonResponse
    {
        $total   = Document::where('is_archived', false)->count();
        $indexed = Document::where('is_archived', false)
            ->where('ocr_status', 'done')->count();

        return response()->json([
            'total'          => $total,
            'indexed'        => $indexed,
            'not_indexed'    => $total - $indexed,
            'index_coverage' => $total > 0 ? round(($indexed / $total) * 100) : 0,
        ]);
    }
}