<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'description', 'file_path', 'file_name',
        'file_size', 'mime_type', 'extension', 'folder_id',
        'created_by', 'status', 'is_locked', 'locked_by',
        'locked_at', 'version', 'metadata', 'checksum',
        'is_archived', 'archived_at', 'expires_at',
        'ocr_text',
        'ocr_confidence',
        'ocr_processed_at',
        'ocr_status',
        'metadata',
    ];

    protected $casts = [
        'file_size'   => 'integer',
        'is_locked'   => 'boolean',
        'is_archived' => 'boolean',
        'locked_at'   => 'datetime',
        'archived_at' => 'datetime',
        'expires_at'  => 'datetime',
        'metadata'    => 'array',
        'ocr_processed_at' => 'datetime',
        'ocr_confidence'   => 'float',
        'metadata'         => 'array',

    ];

    // ── Relations ──────────────────────────────────────────────────────────

    public function folder()
    {
        return $this->belongsTo(Folder::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function locker()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'document_tags')->withTimestamps();
    }

    public function comments()
    {
        return $this->hasMany(Comment::class)->orderByDesc('created_at');
    }

    public function sharedWith()
    {
        return $this->belongsToMany(User::class, 'document_shares')
            ->withPivot('permission', 'expires_at')
            ->withTimestamps();
    }

    public function workflows()
    {
        return $this->hasMany(Workflow::class);
    }

    // ── Accesseurs ─────────────────────────────────────────────────────────

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = $this->file_size ?? 0;
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return number_format($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    public function getTypeIconAttribute(): string
    {
        return match (true) {
            str_starts_with($this->mime_type ?? '', 'image/')       => 'image',
            ($this->mime_type ?? '') === 'application/pdf'          => 'pdf',
            str_contains($this->mime_type ?? '', 'word')            => 'word',
            str_contains($this->mime_type ?? '', 'excel')           => 'excel',
            str_contains($this->mime_type ?? '', 'spreadsheet')     => 'excel',
            str_contains($this->mime_type ?? '', 'presentation')    => 'powerpoint',
            default                                                  => 'file',
        };
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_archived', false);
    }

    public function scopeArchived($query)
    {
        return $query->where('is_archived', true);
    }
}