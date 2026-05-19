<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class OcrController extends Controller
{
    public function process(Document $document): JsonResponse
    {
        if (!$document->file_path || !Storage::exists($document->file_path)) {
            return response()->json(['message' => 'Fichier introuvable.'], 404);
        }

        $supportedTypes = [
            'image/jpeg', 'image/jpg', 'image/png',
            'image/bmp', 'image/tiff', 'image/webp',
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint',
        ];

        $supportedExts = [
            'jpg', 'jpeg', 'png', 'bmp', 'tiff', 'tif',
            'webp', 'pdf', 'docx', 'doc', 'pptx', 'ppt'
        ];

        $ext = strtolower($document->extension ?? '');

        if (!in_array($document->mime_type, $supportedTypes)
            && !in_array($ext, $supportedExts)) {
            $document->update(['ocr_status' => 'not_supported']);
            return response()->json([
                'message'         => 'Type de fichier non supporte pour l\'OCR.',
                'supported_types' => ['JPG', 'PNG', 'PDF', 'DOCX', 'PPTX'],
            ], 422);
        }

        $document->update(['ocr_status' => 'processing']);

        try {
            $filePath = Storage::path($document->file_path);

            $response = Http::timeout(120)
                ->attach(
                    'file',
                    file_get_contents($filePath),
                    $document->file_name ?? basename($document->file_path)
                )
                ->post(config('services.ocr.url') . '/ocr');

            if ($response->failed()) {
                $document->update(['ocr_status' => 'failed']);
                return response()->json([
                    'message' => 'Erreur du service OCR.',
                    'error'   => $response->json(),
                ], 500);
            }

            $data = $response->json();

            $document->update([
                'ocr_text'         => $data['text'] ?? '',
                'ocr_confidence'   => $data['confidence'] ?? 0,
                'ocr_processed_at' => now(),
                'ocr_status'       => 'done',
            ]);

            activity('document')
                ->causedBy(auth()->user())
                ->performedOn($document)
                ->log('OCR effectue sur le document');

            return response()->json([
                'message'        => 'OCR effectue avec succes.',
                'text'           => $data['text'],
                'confidence'     => $data['confidence'],
                'word_count'     => $data['word_count'],
                'char_count'     => $data['char_count'],
                'ocr_status'     => 'done',
            ]);

        } catch (\Exception $e) {
            $document->update(['ocr_status' => 'failed']);
            return response()->json([
                'message' => 'Erreur lors du traitement OCR.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function getText(Document $document): JsonResponse
    {
        return response()->json([
            'id'               => $document->id,
            'name'             => $document->name,
            'ocr_text'         => $document->ocr_text,
            'ocr_confidence'   => $document->ocr_confidence,
            'ocr_status'       => $document->ocr_status,
            'ocr_processed_at' => $document->ocr_processed_at,
        ]);
    }

    public function status(): JsonResponse
    {
        try {
            $response = Http::timeout(5)
                ->get(config('services.ocr.url') . '/health');
            $isOnline = $response->successful();
        } catch (\Exception $e) {
            $isOnline = false;
        }

        $stats = [
            'total'         => Document::whereNotNull('ocr_status')->count(),
            'done'          => Document::where('ocr_status', 'done')->count(),
            'pending'       => Document::where('ocr_status', 'pending')->count(),
            'failed'        => Document::where('ocr_status', 'failed')->count(),
            'not_supported' => Document::where('ocr_status', 'not_supported')->count(),
        ];

        return response()->json([
            'service_online' => $isOnline,
            'service_url'    => config('services.ocr.url'),
            'stats'          => $stats,
        ]);
    }
}