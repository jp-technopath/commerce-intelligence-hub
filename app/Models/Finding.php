<?php

namespace App\Models;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Finding extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'finding_type',
        'finding_category',
        'title',
        'description',
        'severity',
        'confidence_score',
        'estimated_revenue_impact',
        'status',
        'metadata_json',
        'detected_at',
    ];

    protected $casts = [
        'finding_category'        => FindingCategory::class,
        'severity'                => FindingSeverity::class,
        'status'                  => FindingStatus::class,
        'confidence_score'        => 'decimal:2',
        'estimated_revenue_impact' => 'decimal:2',
        'metadata_json'           => 'array',
        'detected_at'             => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    public function investigationNotes(): HasMany
    {
        return $this->hasMany(InvestigationNote::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            FindingStatus::New,
            FindingStatus::Investigating,
            FindingStatus::Accepted,
        ]);
    }
}
