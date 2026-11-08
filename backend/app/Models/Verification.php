<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Verification extends Model
{
    protected $fillable = [
        'user_id',
        'academic_year_id',
        'current_attempt_id',
        'cor_file_path',
        'submission_status',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
        'admin_notes',
    ];

    protected $hidden = [
        'cor_file_path',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(VerificationAttempt::class);
    }

    public function currentAttempt(): BelongsTo
    {
        return $this->belongsTo(VerificationAttempt::class, 'current_attempt_id');
    }
}
