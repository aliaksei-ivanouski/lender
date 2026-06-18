<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Event extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
