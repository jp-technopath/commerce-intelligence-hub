<?php

namespace App\Models;

use App\Enums\ProjectStatus;
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
            if (($project->wasChanged('project_lead_id') || $project->wasChanged('owner_id')) && ($project->project_lead_id || $project->owner_id)) {
                $project->reattributeExternalMeetingsToLead();
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
     * Re-attribute any meetings for this project organized by external clients to the project lead.
     */
    public function reattributeExternalMeetingsToLead(): int
    {
        $leadId = $this->project_lead_id ?? $this->owner_id;
        if (! $leadId) {
            return 0;
        }

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
            if ($meeting->isOrganizedByExternalParty()) {
                $meeting->update(['internal_owner_id' => $leadId]);
                $count++;
            }
        }

        return $count;
    }
}
