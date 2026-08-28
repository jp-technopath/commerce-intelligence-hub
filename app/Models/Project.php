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
        'owner_id',
    ];

    protected $casts = [
        'status' => ProjectStatus::class,
        'budget_amount' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function environmentMappings(): HasMany
    {
        return $this->hasMany(ProjectEnvironmentMapping::class);
    }
}
