<?php

namespace App\Models;

use App\Enums\ActionItemSource;
use App\Enums\ActionItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingActionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_meeting_id',
        'meeting_follow_up_id',
        'title',
        'description',
        'owner_name',
        'owner_user_id',
        'due_date',
        'status',
        'source',
        'jira_issue_key',
        'is_customer_facing',
    ];

    protected $casts = [
        'status'             => ActionItemStatus::class,
        'source'             => ActionItemSource::class,
        'due_date'           => 'date',
        'is_customer_facing' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ClientMeeting::class, 'client_meeting_id');
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(MeetingFollowUp::class, 'meeting_follow_up_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
