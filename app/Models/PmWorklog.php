<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmWorklog extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'user_id',
        'pm_connection_id',
        'pm_work_item_id',
        'external_worklog_id',
        'external_author_id',
        'author_name',
        'time_spent_seconds',
        'worklog_started_at',
        'external_created_at',
        'external_updated_at',
        'last_synced_at',
    ];

    protected $casts = [
        'worklog_started_at'  => 'datetime',
        'external_created_at' => 'datetime',
        'external_updated_at' => 'datetime',
        'last_synced_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (PmWorklog $wl) {
            if ($wl->user_id) {
                app(\App\Services\Intelligence\WorkPrioritizationEngine::class)->invalidateUserPlan($wl->user_id);
            }
        });

        static::deleted(function (PmWorklog $wl) {
            if ($wl->user_id) {
                app(\App\Services\Intelligence\WorkPrioritizationEngine::class)->invalidateUserPlan($wl->user_id);
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PmConnection::class, 'pm_connection_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(PmWorkItem::class, 'pm_work_item_id');
    }

    public function getTimeSpentHoursAttribute(): float
    {
        return round($this->time_spent_seconds / 3600, 1);
    }

    /**
     * Scope query to only worklogs belonging to customer spaces with Jira project code
     * (including direct project tasks and customer service desk tickets).
     */
    public function scopeForCustomerSpacesWithJiraCode(Builder $query, ?int $clientId = null): Builder
    {
        if ($clientId !== null) {
            $client = Client::find($clientId);
            if (! $client || empty($client->jira_project_key)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('pm_worklogs.client_id', $client->id)
                ->where(function ($keyClause) use ($client) {
                    $keyClause->whereHas('workItem', function ($wq) use ($client) {
                        $wq->where('external_item_key', 'LIKE', $client->jira_project_key . '-%')
                           ->orWhereHas('project', fn ($pq) => $pq->where('external_project_key', $client->jira_project_key))
                           ->orWhere('external_item_key', 'LIKE', 'SUP-%')
                           ->orWhereHas('project', fn ($pq) => $pq->where('external_project_key', 'SUP'));
                    });
                });
        }

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
                    $clause->where('pm_worklogs.client_id', $client->id)
                           ->where(function ($keyClause) use ($client) {
                               $keyClause->whereHas('workItem', function ($wq) use ($client) {
                                   $wq->where('external_item_key', 'LIKE', $client->jira_project_key . '-%')
                                      ->orWhereHas('project', fn ($pq) => $pq->where('external_project_key', $client->jira_project_key))
                                      ->orWhere('external_item_key', 'LIKE', 'SUP-%')
                                      ->orWhereHas('project', fn ($pq) => $pq->where('external_project_key', 'SUP'));
                               });
                           });
                });
            }
        });
    }
}
