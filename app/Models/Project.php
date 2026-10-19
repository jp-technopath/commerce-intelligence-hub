<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Services\PM\IdentityResolverService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
        'code',
        'description',
        'status',
        'platform',
        'jira_project_key',
        'repository_url',
        'budget_amount',
        'monthly_allocated_hours',
        'owner_id',
        'project_lead_id',
    ];

    protected $casts = [
        'status' => ProjectStatus::class,
        'budget_amount' => 'decimal:2',
        'monthly_allocated_hours' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Project $project) {
            if ($project->isDirty('project_lead_id')) {
                $project->owner_id = $project->project_lead_id;
            } elseif ($project->isDirty('owner_id')) {
                $project->project_lead_id = $project->owner_id;
            }
        });

        static::saved(function (Project $project) {
            if ($project->project_lead_id || $project->owner_id) {
                $project->reattributeMeetingsToLead();
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function projectLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_lead_id');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function environmentMappings(): HasMany
    {
        return $this->hasMany(ProjectEnvironmentMapping::class);
    }

    /**
     * Re-attribute any meetings for this project that belong to the project lead.
     */
    public function reattributeMeetingsToLead(): int
    {
        $leadId = $this->project_lead_id ?? $this->owner_id;
        if (! $leadId) {
            return 0;
        }

        $lead = User::find($leadId);
        if (! $lead) {
            return 0;
        }

        $leadEmails = IdentityResolverService::getUserEmails($lead);

        $query = ClientMeeting::query();
        if ($this->client_id) {
            $query->where('client_id', $this->client_id);
        }

        $keys = array_filter([$this->jira_project_key, $this->code]);
        if (! empty($keys)) {
            $query->whereIn('project_key', $keys);
        }

        $count = 0;
        $meetings = $query->get();
        foreach ($meetings as $meeting) {
            if ($this->shouldAttributeMeetingToLead($meeting, $lead, $leadEmails)) {
                if ($meeting->internal_owner_id !== $leadId) {
                    $meeting->update(['internal_owner_id' => $leadId]);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Determine if a meeting for this project should be attributed to the project lead.
     */
    public function shouldAttributeMeetingToLead(ClientMeeting $meeting, User $lead, array $leadEmails = []): bool
    {
        // 1. External client organized meeting -> always attribute to Project Lead
        if ($meeting->isOrganizedByExternalParty()) {
            return true;
        }

        $meta = $meeting->metadata ?? [];
        $organizerEmail = strtolower(trim($meta['organizer_email'] ?? ''));
        $creatorEmail = strtolower(trim($meta['creator_email'] ?? ''));

        // 2. Group/resource calendar meeting -> always attribute to Project Lead
        if (str_ends_with($organizerEmail, '@group.calendar.google.com') || str_ends_with($organizerEmail, '@resource.calendar.google.com')) {
            return true;
        }

        $leadEmails = ! empty($leadEmails) ? $leadEmails : IdentityResolverService::getUserEmails($lead);

        // 3. Meeting created or organized by the Project Lead
        if ((! empty($creatorEmail) && in_array($creatorEmail, $leadEmails, true)) ||
            (! empty($organizerEmail) && in_array($organizerEmail, $leadEmails, true))) {
            return true;
        }

        // 4. Meeting with external client attendees -> always attribute to Project Lead
        if (! empty($meeting->external_attendees)) {
            return true;
        }

        // 5. Meeting without metadata or creator/organizer
        if (empty($organizerEmail) && empty($creatorEmail)) {
            return true;
        }

        // 6. Current owner is NOT the genuine creator or organizer of the meeting
        $currentOwner = $meeting->internalOwner;
        if ($currentOwner) {
            $ownerEmails = IdentityResolverService::getUserEmails($currentOwner);

            $isOwnerGenuine = (! empty($creatorEmail) && in_array($creatorEmail, $ownerEmails, true))
                || (! empty($organizerEmail) && in_array($organizerEmail, $ownerEmails, true));

            if (! $isOwnerGenuine) {
                return true;
            }
        }

        return false;
    }

    /**
     * Backward-compatible alias for reattributeMeetingsToLead.
     */
    public function reattributeExternalMeetingsToLead(): int
    {
        return $this->reattributeMeetingsToLead();
    }
}
