<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserGroup extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'color', 'created_by'];

    public function members()
    {
        return $this->belongsToMany(User::class, 'user_group_members')
            ->withTimestamps();
    }

    public function folders()
    {
        return $this->belongsToMany(Folder::class, 'folder_group_access')
            ->withPivot('permission', 'status', 'approved_by', 'approved_at')
            ->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}