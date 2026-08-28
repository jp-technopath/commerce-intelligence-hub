<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Repository extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'client_id',
        'name',
        'url',
        'provider',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    public function environmentMappings(): HasMany
    {
        return $this->hasMany(ProjectEnvironmentMapping::class);
    }
}
