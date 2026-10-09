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
    protected $attributes = [
        'visibility_classification' => \App\Services\VisibilityService::CLASSIFICATION_CUSTOMER_VISIBLE,
        'is_customer_visible' => true,
    ];

    protected $fillable = [
        'client_id',
        'responsible_user_id',
        'project_id',
        'finding_type',
        'source_type',
        'source_id',
        'fingerprint',
        'finding_category',
        'title',
        'description',
        'severity',
        'escalation_level',
        'confidence_score',
        'estimated_revenue_impact',
        'status',
        'visibility_classification',
        'is_customer_visible',
        'metadata_json',
        'evidence_json',
        'snoozed_until',
        'resolved_at',
        'dismissed_at',
        'dismissed_reason',
        'detected_at',
    ];

    protected $casts = [
        'finding_category'         => FindingCategory::class,
        'severity'                 => FindingSeverity::class,
        'status'                   => FindingStatus::class,
        'confidence_score'         => 'decimal:2',
        'estimated_revenue_impact' => 'decimal:2',
        'metadata_json'            => 'array',
        'evidence_json'            => 'array',
        'snoozed_until'            => 'datetime',
        'resolved_at'              => 'datetime',
        'dismissed_at'             => 'datetime',
        'detected_at'              => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    public function investigationNotes(): HasMany
    {
        return $this->hasMany(InvestigationNote::class);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            FindingStatus::New->value,
            FindingStatus::Acknowledged->value,
            FindingStatus::Investigating->value,
            FindingStatus::Accepted->value,
        ])->where(function ($q) {
            $q->whereNull('snoozed_until')
              ->orWhere('snoozed_until', '<=', now());
        });
    }

    public function isOpen(): bool
    {
        if ($this->status === FindingStatus::Snoozed && $this->snoozed_until && $this->snoozed_until->isFuture()) {
            return false;
        }

        return in_array($this->status, [
            FindingStatus::New,
            FindingStatus::Acknowledged,
            FindingStatus::Investigating,
            FindingStatus::Accepted,
        ], true);
    }

    public function acknowledge(): bool
    {
        return $this->update([
            'status' => FindingStatus::Acknowledged,
        ]);
    }

    public function snooze(\Carbon\CarbonInterface $until): bool
    {
        return $this->update([
            'status'        => FindingStatus::Snoozed,
            'snoozed_until' => $until,
        ]);
    }

    public function resolve(): bool
    {
        return $this->update([
            'status'      => FindingStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    public function dismiss(?string $reason = null): bool
    {
        return $this->update([
            'status'           => FindingStatus::Dismissed,
            'dismissed_at'     => now(),
            'dismissed_reason' => $reason,
        ]);
    }

    public function getJiraUrlAttribute(): ?string
    {
        $key = $this->evidence_json['item_key'] ?? $this->evidence_json['external_item_key'] ?? null;
        if (! $key && is_array($this->metadata_json)) {
            $key = $this->metadata_json['item_key'] ?? $this->metadata_json['key'] ?? null;
        }

        if ($key) {
            $baseUrl = config('meeting_agent.jira.base_url') ?: 'https://technopath.atlassian.net';
            return rtrim($baseUrl, '/') . '/browse/' . $key;
        }

        if (in_array($this->source_type, ['pm_work_item', PmWorkItem::class], true) && $this->source_id) {
            $item = PmWorkItem::find($this->source_id);
            return $item?->jira_url;
        }

        return null;
    }
}

