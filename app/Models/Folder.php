<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Folder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'description', 'parent_id',
        'created_by', 'color', 'icon',
        'is_shared', 'path',
    ];

    protected $casts = [
        'is_shared' => 'boolean',
    ];

    // ── Relations ──────────────────────────────────────────────────────────

    public function parent()
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Folder::class, 'parent_id')->with('children');
    }

    public function documents()
    {
        return $this->hasMany(Document::class)->active();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sharedWith()
    {
        return $this->belongsToMany(User::class, 'folder_shares')
            ->withPivot('permission')
            ->withTimestamps();
    }

    // ── Méthodes ───────────────────────────────────────────────────────────

    public function getBreadcrumb(): array
    {
        $breadcrumb = [];
        $folder = $this;

        while ($folder) {
            array_unshift($breadcrumb, [
                'id'   => $folder->id,
                'name' => $folder->name,
            ]);
            $folder = $folder->parent;
        }

        return $breadcrumb;
    }

    public function updatePath(): void
    {
        $path = $this->parent
            ? $this->parent->path . '/' . $this->name
            : $this->name;

        $this->update(['path' => $path]);

        $this->children->each->updatePath();
    }
}