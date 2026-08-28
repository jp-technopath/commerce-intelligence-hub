<?php

namespace App\Models;

use App\Enums\AgentCapabilityTier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectEnvironmentMapping extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id', 'pm_project_id', 'repository_id', 'environment_key',
        'gcp_project_id', 'gcp_zone', 'vm_name', 'workspace_path', 'default_branch',
        'allowed_agent_roles', 'allowed_capability_tiers', 'default_capability_tier',
        'tier_recommendation_policy', 'model_group_aliases', 'version', 'is_active',
        'activated_at', 'activated_by', 'deactivated_at', 'deactivated_by',
    ];

    protected $casts = [
        'allowed_agent_roles' => 'array',
        'allowed_capability_tiers' => 'array',
        'default_capability_tier' => AgentCapabilityTier::class,
        'tier_recommendation_policy' => 'array',
        'model_group_aliases' => 'array',
        'is_active' => 'boolean',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function pmProject(): BelongsTo
    {
        return $this->belongsTo(PmProject::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function snapshot(): array
    {
        return [
            'mapping_id' => $this->id,
            'mapping_version' => $this->version,
            'project_id' => $this->project_id,
            'pm_project_id' => $this->pm_project_id,
            'repository_id' => $this->repository_id,
            'environment_key' => $this->environment_key,
            'gcp' => ['project_id' => $this->gcp_project_id, 'zone' => $this->gcp_zone, 'vm_name' => $this->vm_name],
            'workspace_path' => $this->workspace_path,
            'default_branch' => $this->default_branch,
            'allowed_agent_roles' => $this->allowed_agent_roles,
            'allowed_capability_tiers' => $this->allowed_capability_tiers,
            'default_capability_tier' => $this->default_capability_tier->value,
            'tier_recommendation_policy' => $this->tier_recommendation_policy,
            'model_group_aliases' => $this->model_group_aliases,
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
