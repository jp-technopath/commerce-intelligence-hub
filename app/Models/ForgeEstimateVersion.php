<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ForgeEstimateVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'pm_work_item_id',
        'version',
        'estimated_seconds',
        'external_estimate_at_submission',
        'submitted_by_user_id',
        'submitted_at',
        'po_notes',
        'cost_impact_amount',
    ];

    protected $casts = [
        'submitted_at'       => 'datetime',
        'cost_impact_amount' => 'decimal:2',
    ];

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(PmWorkItem::class, 'pm_work_item_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function approvalEvents(): HasMany
    {
        return $this->hasMany(ForgeApprovalEvent::class, 'estimate_version_id')->orderBy('created_at', 'asc');
    }

    public function latestEvent(): HasOne
    {
        return $this->hasOne(ForgeApprovalEvent::class, 'estimate_version_id')->latestOfMany();
    }

    public function getEstimatedHoursAttribute(): float
    {
        return round($this->estimated_seconds / 3600, 1);
    }
}
