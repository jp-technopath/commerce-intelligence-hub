<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForgeApprovalEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_version_id',
        'event_type',
        'actor_user_id',
        'notes',
    ];

    public function estimateVersion(): BelongsTo
    {
        return $this->belongsTo(ForgeEstimateVersion::class, 'estimate_version_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
