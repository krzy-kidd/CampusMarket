<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Admin extends Model
{
    protected $fillable = [
        'user_id',
        'role',
        'account_status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function updatedSettings(): HasMany
    {
        return $this->hasMany(PlatformSetting::class, 'updated_by');
    }

    public function isActiveVerificationAdmin(): bool
    {
        return $this->account_status === 'active'
            && $this->user?->account_status === 'active'
            && in_array($this->role, ['verification_admin', 'super_admin'], true);
    }

    public function isActiveSuperAdmin(): bool
    {
        return $this->account_status === 'active'
            && $this->user?->account_status === 'active'
            && $this->role === 'super_admin';
    }
}
