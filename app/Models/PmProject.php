<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Scope query to only PM projects/spaces belonging to customers in the customer list with an assigned Jira project code.
     */
    public function scopeForCustomerSpacesWithJiraCode(Builder $query): Builder
    {
        $validClients = Client::query()
            ->whereNotNull('jira_project_key')
            ->where('jira_project_key', '!=', '')
            ->get(['id', 'jira_project_key']);

        if ($validClients->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($sub) use ($validClients) {
            foreach ($validClients as $client) {
                $sub->orWhere(function ($clause) use ($client) {
                    $clause->where('pm_projects.client_id', $client->id)
                           ->where('pm_projects.external_project_key', $client->jira_project_key);
                });
            }
        });
    }
}
