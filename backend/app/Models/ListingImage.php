<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ListingImage extends Model
{
    public $timestamps = false;

    protected $hidden = ['image_url'];

    protected $appends = ['public_url'];

    protected $fillable = [
        'listing_id',
        'image_url',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function getPublicUrlAttribute(): string
    {
        return Storage::disk((string) config('campus.listing_image_disk', 'public'))
            ->url($this->image_url);
    }
}
