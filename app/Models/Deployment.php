<?php

namespace App\Models;

use App\Enums\DeploymentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'title',
        'deployment_type',
        'description',
        'deployed_by',
        'deployed_at',
        'metadata_json',
    ];

    protected $casts = [
        'deployment_type' => DeploymentType::class,
        'deployed_at'     => 'datetime',
        'metadata_json'   => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
