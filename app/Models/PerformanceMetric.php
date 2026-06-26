<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'date',
        'source',
        'lcp',
        'fid',
        'inp',
        'cls',
        'ttfb',
        'page_load_time',
        'server_response_time',
        'bounce_rate',
        'slow_pages_count',
        'metadata_json',
    ];

    protected $casts = [
        'date'          => 'date',
        'metadata_json' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
