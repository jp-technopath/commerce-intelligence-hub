<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceCustomerIdentityMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'canonical_account_id',
        'identity_type',
        'identity_value',
        'source_system',
        'confidence_score',
        'provenance_metadata',
        'is_active',
    ];

    protected $casts = [
        'confidence_score'    => 'decimal:2',
        'provenance_metadata' => 'array',
        'is_active'           => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
