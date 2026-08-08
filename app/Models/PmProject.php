<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PmProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'pm_connection_id',
        'name',
        'external_project_id',
        'external_project_key',
        'custom_filter_jql',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PmConnection::class, 'pm_connection_id');
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(PmWorkItem::class);
    }
}
