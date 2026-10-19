<?php

namespace App\Models;

use App\Enums\MeetingSource;
use App\Enums\MeetingStatus;
use App\Services\PM\IdentityResolverService;
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
        'status' => MeetingStatus::class,
        'source' => MeetingSource::class,
        'external_attendees' => 'array',
        'internal_attendees' => 'array',
        'metadata' => 'array',
        'meeting_start_at' => 'datetime',
        'meeting_end_at' => 'datetime',
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

    public function internalOwner(): BelongsTo
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

    /**
     * Check if this meeting was organized by an external client or third party.
     */
    public function isOrganizedByExternalParty(): bool
    {
        $organizerEmail = strtolower(trim($this->metadata['organizer_email'] ?? ''));
        if (empty($organizerEmail)) {
            return false;
        }

        // If organizer matches any internal user
        $isInternalUser = User::whereRaw('LOWER(email) = ?', [$organizerEmail])->exists();
        if ($isInternalUser) {
            return false;
        }

        // If organizer has company domain
        $companyDomains = config('meeting_agent.calendar.company_domains', ['technopath.co', 'technopath.com.au']);
        $companyDomains[] = 'technopath.ai';
        $domain = substr(strrchr($organizerEmail, '@'), 1);
        if ($domain && in_array(strtolower($domain), array_map('strtolower', $companyDomains), true)) {
            return false;
        }

        // Google calendar group resources are internal room/calendar artifacts
        if (str_ends_with($organizerEmail, '@group.calendar.google.com')) {
            return false;
        }

        return true;
    }

    /**
     * Scope query to meetings where the user is either the owner or the creator/organizer.
     * Strictly excludes meetings where the user is merely an invited attendee.
     * For meetings organized by external clients, ownership is attributed strictly to the internal_owner_id (Project Lead).
     */
    public function scopeOwnedOrCreatedBy(Builder $query, User $user): Builder
    {
        $effectiveUser = IdentityResolverService::resolveEffectiveUser($user);

        $userIds = array_values(array_unique(array_filter([$user->id, $effectiveUser->id])));
        if (in_array($effectiveUser->id, [2, 6], true)) {
            $userIds = array_values(array_unique(array_merge($userIds, [2, 6])));
        } elseif (in_array($effectiveUser->id, [4, 7], true)) {
            $userIds = array_values(array_unique(array_merge($userIds, [4, 7])));
        } elseif ($effectiveUser->id === 3) {
            $userIds = array_values(array_unique(array_merge($userIds, [3])));
        }

        $userEmails = array_values(array_unique(array_filter([
            strtolower(trim($user->email ?? '')),
            strtolower(trim($effectiveUser->email ?? '')),
        ])));

        if (in_array($effectiveUser->id, [2, 6], true)) {
            $userEmails = array_values(array_unique(array_merge($userEmails, ['jp@technopath.co', 'jeanpaulhanna@gmail.com'])));
        } elseif ($effectiveUser->id === 3) {
            $userEmails = array_values(array_unique(array_merge($userEmails, ['donia@technopath.co', 'doniaakhaled@gmail.com'])));
        } elseif (in_array($effectiveUser->id, [4, 7], true)) {
            $userEmails = array_values(array_unique(array_merge($userEmails, ['nour@technopath.co', 'nour@technopath.ai', 'nour.ayman91@gmail.com'])));
        }

        return $query->where(function (Builder $q) use ($userIds, $userEmails) {
            // 1. User is the assigned internal owner (e.g. Project Lead)
            $q->whereIn('internal_owner_id', $userIds);

            // 2. User is the organizer of the meeting
            foreach ($userEmails as $email) {
                if (! empty($email)) {
                    $emailLower = strtolower(trim($email));
                    $q->orWhere('metadata->organizer_email', $emailLower)
                        ->orWhere('metadata->organizer_email', $email);
                }
            }

            // 3. User is creator, PROVIDED the meeting was NOT organized by an external client
            foreach ($userEmails as $email) {
                if (! empty($email)) {
                    $emailLower = strtolower(trim($email));
                    $q->orWhere(function (Builder $sub) use ($emailLower, $email) {
                        $sub->where(function (Builder $creatorMatch) use ($emailLower, $email) {
                            $creatorMatch->where('metadata->creator_email', $emailLower)
                                ->orWhere('metadata->creator_email', $email);
                        })->where(function (Builder $notExternal) {
                            $notExternal->whereNull('metadata->organizer_email')
                                ->orWhere('metadata->organizer_email', '')
                                ->orWhere('metadata->is_external_organizer', false)
                                ->orWhere('metadata->organizer_email', 'like', '%@technopath.co')
                                ->orWhere('metadata->organizer_email', 'like', '%@technopath.ai')
                                ->orWhere('metadata->organizer_email', 'like', '%@technopath.com.au')
                                ->orWhere('metadata->organizer_email', 'like', '%@group.calendar.google.com');
                        });
                    });
                }
            }
        });
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }
}
