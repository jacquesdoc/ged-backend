<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LlmService
{
    private string $apiKey;
    private string $model;
    private string $url;

    public function __construct()
    {
        $this->apiKey = config('services.groq.key');
        $this->model  = config('services.groq.model');
        $this->url    = config('services.groq.url');
    }

    private function call(string $prompt, int $maxTokens = 500): ?string
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post($this->url, [
                    'model'       => $this->model,
                    'messages'    => [['role' => 'user', 'content' => $prompt]],
                    'max_tokens'  => $maxTokens,
                    'temperature' => 0.3,
                ]);

            if ($response->successful()) {
                return $response->json()['choices'][0]['message']['content'] ?? null;
            }

            \Log::error('Groq API error: ' . json_encode($response->json()));
            return null;

        } catch (\Exception $e) {
            \Log::error('Groq API exception: ' . $e->getMessage());
            return null;
        }
    }

    public function summarize(string $text, string $documentName): ?string
    {
        if (empty(trim($text))) return null;

        $prompt = "Tu es un assistant pour une application GED d'une entreprise ivoirienne.

Resume ce document en 3-4 phrases courtes et claires en français.
Sois precis et professionnel.

Nom du document : {$documentName}
Contenu : " . substr($text, 0, 2000) . "

Resume :";

        return $this->call($prompt, 300);
    }

    public function classify(string $text, string $documentName): ?array
    {
        if (empty(trim($text))) return null;

        $prompt = "Tu es un assistant pour une application GED.
Analyse ce document et reponds UNIQUEMENT en JSON valide sans markdown.

Format requis :
{
  \"type\": \"type du document parmi: Facture, Contrat, Rapport, Courrier, Bon de commande, Devis, Proces-verbal, Note de service, Autre\",
  \"tags\": [\"tag1\", \"tag2\", \"tag3\"],
  \"priority\": \"haute|moyenne|basse\",
  \"language\": \"français|anglais|autre\"
}

Nom du document : {$documentName}
Contenu : " . substr($text, 0, 1000);

        $result = $this->call($prompt, 200);
        if (!$result) return null;

        try {
            $clean = preg_replace('/```json|```/i', '', $result);
            return json_decode(trim($clean), true);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function extractMetadata(string $text, string $documentName): ?array
    {
        if (empty(trim($text))) return null;

        $prompt = "Tu es un assistant pour une application GED.
Extrais les informations cles de ce document et reponds UNIQUEMENT en JSON valide.

Format requis :
{
  \"date\": \"date trouvee ou null\",
  \"montant\": \"montant trouve ou null\",
  \"reference\": \"numero de reference ou null\",
  \"expediteur\": \"expediteur ou null\",
  \"destinataire\": \"destinataire ou null\",
  \"objet\": \"objet ou sujet principal ou null\"
}

Nom du document : {$documentName}
Contenu : " . substr($text, 0, 1500);

        $result = $this->call($prompt, 300);
        if (!$result) return null;

        try {
            $clean = preg_replace('/```json|```/i', '', $result);
            return json_decode(trim($clean), true);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function chatWithDocument(string $text, string $question, string $documentName): ?string
    {
        if (empty(trim($text))) return null;

        $prompt = "Tu es un assistant IA pour une GED d'entreprise ivoirienne.
Reponds aux questions sur le document suivant en français.
Sois precis, concis et professionnel.

Document : {$documentName}
Contenu : " . substr($text, 0, 2000) . "

Question : {$question}

Reponse :";

        return $this->call($prompt, 400);
    }

    public function analyzeDocument(string $text, string $documentName): array
    {
        return [
            'summary'  => $this->summarize($text, $documentName),
            'classify' => $this->classify($text, $documentName),
            'metadata' => $this->extractMetadata($text, $documentName),
        ];
    }
}