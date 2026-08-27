<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DevelopmentRequestStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'development_request_status_histories';

    public $timestamps = false;

    protected $fillable = [
        'development_request_id',
        'old_state',
        'new_state',
        'actor_user_id',
        'actor_type',
        'actor_label',
        'reason',
        'idempotency_key',
        'correlation_identifier',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'json',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Development request status history is append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Development request status history is append-only.');
        });
    }

    /**
     * The development request this history entry belongs to.
     */
    public function developmentRequest(): BelongsTo
    {
        return $this->belongsTo(DevelopmentRequest::class);
    }

    /**
     * The user who triggered this state transition (if available).
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
