<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    // ── Stocker un fichier ─────────────────────────────────────────────────
    public function storeFile(Document $document, UploadedFile $file): void
    {
        $path = $file->store('documents/' . auth()->id(), 'local');

        $document->update([
            'file_path'  => $path,
            'file_name'  => $file->getClientOriginalName(),
            'file_size'  => $file->getSize(),
            'mime_type'  => $file->getMimeType(),
            'extension'  => $file->getClientOriginalExtension(),
            'checksum'   => hash_file('sha256', $file->getRealPath()),
        ]);
    }

    // ── Créer une nouvelle version ─────────────────────────────────────────
    public function createVersion(Document $document, UploadedFile $file, ?string $summary): void
    {
        // Sauvegarder l'ancienne version
        if ($document->file_path) {
            DocumentVersion::create([
                'document_id'    => $document->id,
                'version_number' => $document->version,
                'file_path'      => $document->file_path,
                'file_name'      => $document->file_name,
                'file_size'      => $document->file_size,
                'mime_type'      => $document->mime_type,
                'checksum'       => $document->checksum,
                'created_by'     => $document->created_by,
                'change_summary' => $summary,
            ]);
        }

        // Stocker le nouveau fichier
        $this->storeFile($document, $file);
        $document->increment('version');
    }

    // ── Supprimer le fichier physique ──────────────────────────────────────
    public function deleteFile(Document $document): void
    {
        if ($document->file_path && Storage::exists($document->file_path)) {
            Storage::delete($document->file_path);
        }
    }

    // ── Formater la taille du fichier ──────────────────────────────────────
    public function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return number_format($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }
}