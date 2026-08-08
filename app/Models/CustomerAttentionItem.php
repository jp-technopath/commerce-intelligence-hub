<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAttentionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'category',
        'title',
        'description',
        'action_url',
        'severity',
        'source_type',
        'source_id',
        'is_resolved',
        'resolved_at',
    ];

    protected $casts = [
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }
}
