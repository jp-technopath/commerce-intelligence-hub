<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingPrep extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_meeting_id',
        'jira_project_key',
        'jira_jql',
        'internal_summary',
        'generated_status_email_subject',
        'generated_status_email_body',
        'edited_status_email_subject',
        'edited_status_email_body',
        'recommended_agenda',
        'jira_snapshot',
        'gmail_draft_id',
        'email_sent_at',
        'email_to',
        'email_cc',
        'google_doc_id',
        'google_doc_url',
        'ai_provider',
        'ai_model',
        'ai_error',
        'generated_at',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'recommended_agenda' => 'array',
        'jira_snapshot'      => 'array',
        'generated_at'       => 'datetime',
        'approved_at'        => 'datetime',
        'email_sent_at'      => 'datetime',
        'email_cc'           => 'array',
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

    // ── Helpers ──────────────────────────────────────────────────────────

    public function effectiveSubject(): ?string
    {
        return $this->edited_status_email_subject ?? $this->generated_status_email_subject;
    }

    public function effectiveBody(): ?string
    {
        return $this->edited_status_email_body ?? $this->generated_status_email_body;
    }
}
