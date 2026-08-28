<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectEnvironmentMappingAudit extends Model
{
    protected $fillable = ['project_environment_mapping_id', 'actor_id', 'action', 'snapshot'];

    protected $casts = ['snapshot' => 'array'];

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironmentMapping::class, 'project_environment_mapping_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
