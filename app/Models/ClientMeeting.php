<?php

namespace App\Models;

use App\Enums\MeetingSource;
use App\Enums\MeetingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClientMeeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'project_key',
        'google_calendar_id',
        'google_event_id',
        'google_ical_uid',
        'scanned_by_user_id',
        'title',
        'meeting_start_at',
        'meeting_end_at',
        'timezone',
        'internal_owner_id',
        'external_attendees',
        'internal_attendees',
        'status',
        'source',
        'metadata',
    ];

    protected $casts = [
        'status'             => MeetingStatus::class,
        'source'             => MeetingSource::class,
        'external_attendees' => 'array',
        'internal_attendees' => 'array',
        'metadata'           => 'array',
        'meeting_start_at'   => 'datetime',
        'meeting_end_at'     => 'datetime',
    ];

    // ── Auto-update status when client is mapped ────────────────────────

    protected static function booted(): void
    {
        static::saving(function (ClientMeeting $meeting) {
            if ($meeting->isDirty('client_id')
                && $meeting->client_id !== null
                && $meeting->status === MeetingStatus::NeedsMapping
            ) {
                $meeting->status = MeetingStatus::Detected;
            }
        });
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'internal_owner_id');
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by_user_id');
    }

    public function prep(): HasOne
    {
        return $this->hasOne(MeetingPrep::class);
    }

    public function followUp(): HasOne
    {
        return $this->hasOne(MeetingFollowUp::class);
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(MeetingActionItem::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('meeting_start_at', '>', now());
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('internal_owner_id', $user->id);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }
}
