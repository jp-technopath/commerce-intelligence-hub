<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceMemory extends Model
{
    use HasFactory;

    protected $table = 'intelligence_memory';

    protected $fillable = [
        'client_id',
        'finding_type',
        'finding_category',
        'pattern_description',
        'root_cause',
        'resolution',
        'outcome',
        'metadata_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
