<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id', 'version_number', 'file_path',
        'file_name', 'file_size', 'mime_type',
        'checksum', 'created_by', 'change_summary',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'file_size'      => 'integer',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}