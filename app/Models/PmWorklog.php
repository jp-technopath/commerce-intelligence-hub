<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PmWorklog extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'user_id',
        'pm_connection_id',
        'pm_work_item_id',
        'canonical_worklog_key',
        'external_worklog_id',
        'external_author_id',
        'author_name',
        'attribution_source',
        'attribution_metadata_json',
        'time_spent_seconds',
        'worklog_started_at',
        'external_created_at',
        'external_updated_at',
        'last_synced_at',
    ];

    protected $casts = [
        'worklog_started_at'        => 'datetime',
        'external_created_at'       => 'datetime',
        'external_updated_at'       => 'datetime',
        'last_synced_at'            => 'datetime',
        'attribution_metadata_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (PmWorklog $wl) {
            if (empty($wl->canonical_worklog_key) && ! empty($wl->external_worklog_id)) {
                $conn = $wl->pmConnection;
                $site = ($conn instanceof PmConnection) ? $conn->getJiraSiteIdentity() : null;
                $site = $site
                    ?: parse_url(config('meeting_agent.jira.base_url', 'https://technopath.atlassian.net'), PHP_URL_HOST)
                    ?: 'technopath.atlassian.net';
                $wl->canonical_worklog_key = self::generateCanonicalKey($site, (string) $wl->external_worklog_id);
            }
        });

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

    /**
     * Generate a stable canonical worklog key incorporating site identity and worklog ID.
     */
    public static function generateCanonicalKey(PmConnection|string|null $connectionOrSite, string $externalWorklogId): string
    {
        if ($connectionOrSite instanceof PmConnection) {
            $site = $connectionOrSite->getJiraSiteIdentity();
        } elseif (is_string($connectionOrSite) && ! empty($connectionOrSite)) {
            $parsed = parse_url($connectionOrSite, PHP_URL_HOST);
            $site = $parsed ?: $connectionOrSite;
        } else {
            $site = parse_url(config('meeting_agent.jira.base_url', 'https://technopath.atlassian.net'), PHP_URL_HOST) ?: 'technopath.atlassian.net';
        }

        return $site . ':' . $externalWorklogId;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pmConnection(): BelongsTo
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

    public function isVerified(): bool
    {
        return in_array($this->attribution_source, ['jira_verified', 'tempo_verified', 'authoritative_correction'], true);
    }

    public function isLegacy(): bool
    {
        return $this->attribution_source === 'legacy_attributed';
    }

    public function isUnattributed(): bool
    {
        return $this->attribution_source === 'unattributed' || $this->user_id === null;
    }

    /**
     * Scope query to ensure canonical deduplication of worklogs across multiple connections.
     * Guarantees each upstream canonical worklog contributes only once.
     */
    public function scopeCanonicalUnique(Builder $query): Builder
    {
        return $query->whereRaw('pm_worklogs.id = (SELECT MIN(w2.id) FROM pm_worklogs w2 WHERE w2.canonical_worklog_key = pm_worklogs.canonical_worklog_key AND w2.deleted_at IS NULL)');
    }

    /**
     * Scope query to only worklogs belonging to customer spaces with Jira project code
     * (including direct project tasks and customer service desk tickets).
     */
    public function scopeForCustomerSpacesWithJiraCode(Builder $query, ?int $clientId = null): Builder
    {
        if ($clientId !== null) {
            $client = Client::find($clientId);
            $keys = $client?->normalized_jira_project_keys ?? [];
            if (! $client || empty($keys)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('pm_worklogs.client_id', $client->id)
                ->whereHas('workItem', function ($wq) use ($client, $keys) {
                    $wq->where('pm_work_items.client_id', $client->id)
                       ->where(function ($inner) use ($client, $keys) {
                           foreach ($keys as $k) {
                               $inner->orWhere('pm_work_items.external_item_key', 'LIKE', $k . '-%')
                                     ->orWhereHas('project', fn ($pq) => $pq->where('external_project_key', $k));
                           }
                           $inner->orWhereHas('project', fn ($pq) => $pq->where('client_id', $client->id));
                           // Shared support tickets (e.g. SUP) are included ONLY when the work item has explicit verified customer attribution
                           $inner->orWhere(function ($supClause) use ($client) {
                               $supClause->where(function ($isSup) {
                                   $isSup->where('pm_work_items.external_item_key', 'LIKE', 'SUP-%')
                                         ->orWhereHas('project', fn ($pq) => $pq->where('external_project_key', 'SUP'));
                               })->where('pm_work_items.client_id', $client->id);
                           });
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
                $keys = $client->normalized_jira_project_keys;
                if (empty($keys)) {
                    continue;
                }

                $sub->orWhere(function ($clause) use ($client, $keys) {
                    $clause->where('pm_worklogs.client_id', $client->id)
                           ->whereHas('workItem', function ($wq) use ($client, $keys) {
                               $wq->where('pm_work_items.client_id', $client->id)
                                  ->where(function ($inner) use ($client, $keys) {
                                      foreach ($keys as $k) {
                                          $inner->orWhere('pm_work_items.external_item_key', 'LIKE', $k . '-%')
                                                ->orWhereHas('project', fn ($pq) => $pq->where('external_project_key', $k));
                                      }
                                      $inner->orWhereHas('project', fn ($pq) => $pq->where('client_id', $client->id));
                                      // Shared support tickets (e.g. SUP) are included ONLY when the work item has explicit verified customer attribution
                                      $inner->orWhere(function ($supClause) use ($client) {
                                          $supClause->where(function ($isSup) {
                                              $isSup->where('pm_work_items.external_item_key', 'LIKE', 'SUP-%')
                                                    ->orWhereHas('project', fn ($pq) => $pq->where('external_project_key', 'SUP'));
                                          })->where('pm_work_items.client_id', $client->id);
                                      });
                                  });
                           });
                });
            }
        });
    }
}
