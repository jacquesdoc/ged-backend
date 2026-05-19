<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\LlmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LlmController extends Controller
{
    public function __construct(private LlmService $llm) {}

    public function analyze(Document $document): JsonResponse
    {
        if (empty($document->ocr_text)) {
            return response()->json([
                'message' => 'Ce document n\'a pas de texte OCR. Lancez d\'abord l\'OCR.',
            ], 422);
        }

        $result   = $this->llm->analyzeDocument($document->ocr_text, $document->name);
        $metadata = $document->metadata ?? [];
        $metadata['ai_summary']     = $result['summary'];
        $metadata['ai_classify']    = $result['classify'];
        $metadata['ai_metadata']    = $result['metadata'];
        $metadata['ai_analyzed_at'] = now()->toISOString();
        $document->update(['metadata' => $metadata]);

        activity('document')
            ->causedBy(auth()->user())
            ->performedOn($document)
            ->log('Analyse IA effectuee');

        return response()->json([
            'message'  => 'Analyse IA effectuee.',
            'summary'  => $result['summary'],
            'classify' => $result['classify'],
            'metadata' => $result['metadata'],
        ]);
    }

    public function summarize(Document $document): JsonResponse
    {
        if (empty($document->ocr_text)) {
            return response()->json(['message' => 'Aucun texte OCR disponible.'], 422);
        }

        $summary = $this->llm->summarize($document->ocr_text, $document->name);

        if (!$summary) {
            return response()->json(['message' => 'Erreur lors du resume.'], 500);
        }

        $metadata             = $document->metadata ?? [];
        $metadata['ai_summary'] = $summary;
        $document->update(['metadata' => $metadata]);

        return response()->json(['summary' => $summary]);
    }

    public function chat(Request $request, Document $document): JsonResponse
    {
        $request->validate(['question' => 'required|string|min:3|max:500']);

        if (empty($document->ocr_text)) {
            return response()->json([
                'message' => 'Aucun texte OCR disponible pour ce document.',
            ], 422);
        }

        $answer = $this->llm->chatWithDocument(
            $document->ocr_text,
            $request->question,
            $document->name
        );

        if (!$answer) {
            return response()->json(['message' => 'Erreur IA.'], 500);
        }

        return response()->json([
            'question' => $request->question,
            'answer'   => $answer,
        ]);
    }

    public function status(): JsonResponse
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . config('services.groq.key'),
                    'Content-Type'  => 'application/json',
                ])
                ->post(config('services.groq.url'), [
                    'model'      => config('services.groq.model'),
                    'messages'   => [['role' => 'user', 'content' => 'ok']],
                    'max_tokens' => 5,
                ]);

            return response()->json([
                'online' => $response->successful(),
                'model'  => config('services.groq.model'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['online' => false]);
        }
    }
}