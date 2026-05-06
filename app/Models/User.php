<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'department',
        'position',
        'last_login_at',
        'storage_used',
        'storage_quota',
        'email_notifications',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'    => 'datetime',
        'last_login_at'        => 'datetime',
        'password'             => 'hashed',
        'email_notifications'  => 'boolean',
        'storage_used'         => 'integer',
        'storage_quota'        => 'integer',
    ];

    // ── Accesseurs ─────────────────────────────────────────────────────────

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return asset('storage/' . $this->avatar);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=2E7D32&color=fff&size=128';
    }

    public function getStorageUsedPercentAttribute(): float
    {
        if (!$this->storage_quota) return 0;
        return round(($this->storage_used / $this->storage_quota) * 100, 1);
    }

    public function getFormattedStorageUsedAttribute(): string
    {
        return $this->formatBytes($this->storage_used ?? 0);
    }

    public function getFormattedStorageQuotaAttribute(): string
    {
        return $this->formatBytes($this->storage_quota ?? 1073741824);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return number_format($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    // ── Relations ──────────────────────────────────────────────────────────

    public function documents()
    {
        return $this->hasMany(Document::class, 'created_by');
    }

    public function workflows()
    {
        return $this->hasMany(Workflow::class, 'requested_by');
    }
}