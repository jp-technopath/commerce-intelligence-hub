<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingFollowUp extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_meeting_id',
        'raw_notes',
        'transcript_text',
        'summary',
        'generated_followup_email_subject',
        'generated_followup_email_body',
        'edited_followup_email_subject',
        'edited_followup_email_body',
        'suggested_action_items',
        'decisions',
        'open_questions',
        'gmail_draft_id',
        'email_sent_at',
        'email_to',
        'email_cc',
        'ai_provider',
        'ai_model',
        'ai_error',
        'generated_at',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'suggested_action_items' => 'array',
        'decisions'              => 'array',
        'open_questions'         => 'array',
        'generated_at'           => 'datetime',
        'approved_at'            => 'datetime',
        'email_sent_at'          => 'datetime',
        'email_cc'               => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ClientMeeting::class, 'client_meeting_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(MeetingActionItem::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function effectiveSubject(): ?string
    {
        return $this->edited_followup_email_subject ?? $this->generated_followup_email_subject;
    }

    public function effectiveBody(): ?string
    {
        return $this->edited_followup_email_body ?? $this->generated_followup_email_body;
    }
}
