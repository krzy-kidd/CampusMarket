<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationAttempt extends Model
{
    protected $fillable = [
        'verification_id',
        'user_id',
        'academic_year_id',
        'attempt_number',
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
            'attempt_number' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
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
}
