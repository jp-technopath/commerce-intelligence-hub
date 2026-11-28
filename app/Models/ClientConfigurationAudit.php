<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientConfigurationAudit extends Model
{
    protected $fillable = [
        'client_id',
        'integration_id',
        'actor_user_id',
        'config_scope',
        'version',
        'changed_keys',
        'before_json',
        'after_json',
        'source',
        'ip_address',
    ];

    protected $casts = [
        'changed_keys' => 'array',
        'before_json'  => 'array',
        'after_json'   => 'array',
        'version'      => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
