<?php

namespace App\Services\Intelligence\Synthesis;

use Carbon\Carbon;

class IncidentFingerprintGenerator
{
    /**
     * Generate a stable, lifecycle-aware incident fingerprint.
     *
     * Format: {client_id}:{issue_family}:{scope_type}:{scope_target}:{episode_id}
     *
     * @param int $clientId
     * @param string $issueFamily e.g., 'technical.script_error', 'traffic.volume_surge', 'commercial.revenue_decline'
     * @param string $scopeType e.g., 'page', 'channel', 'integration', 'global'
     * @param string $scopeTarget e.g., 'tapes_packaging_tapes', 'direct', 'ga4_vs_commerce'
     * @param Carbon $detectedDate
     * @param array $existingEpisodes Array of known active episodes for this scope
     * @return array Contains fingerprint, episode_id, lifecycle_state
     */
    public function generate(
        int $clientId,
        string $issueFamily,
        string $scopeType,
        string $scopeTarget,
        Carbon $detectedDate,
        array $existingEpisodes = []
    ): array {
        // Clean scope target (safe for keys)
        $cleanTarget = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', trim($scopeTarget, '/')));
        $baseScopeKey = sprintf('%d:%s:%s:%s', $clientId, $issueFamily, $scopeType, $cleanTarget);

        // Check if an active episode exists within dormancy threshold (7 days)
        $dormancyDays = 7;
        $activeEpisode = null;
        $lifecycleState = 'new_episode';

        foreach ($existingEpisodes as $ep) {
            $epScope = $ep['scope_key'] ?? '';
            if ($epScope === $baseScopeKey) {
                $lastSeen = isset($ep['last_seen']) ? Carbon::parse($ep['last_seen']) : null;
                $daysSinceLastSeen = $lastSeen ? abs($detectedDate->diffInDays($lastSeen)) : 999;
                if ($daysSinceLastSeen <= $dormancyDays) {
                    $activeEpisode = $ep['episode_id'];
                    $lifecycleState = 'active_episode';
                    break;
                } elseif ($lastSeen && $detectedDate->isAfter($lastSeen)) {
                    $activeEpisode = $ep['episode_id'];
                    $lifecycleState = 'reopened_episode';
                    break;
                }
            }
        }

        if (!$activeEpisode) {
            // Generate episode ID anchored by year-month-day of start
            $episodeId = 'ep_' . $detectedDate->format('Ymd');
        } else {
            $episodeId = $activeEpisode;
        }

        $fingerprint = sprintf('%s:%s', $baseScopeKey, $episodeId);

        return [
            'fingerprint' => $fingerprint,
            'base_scope_key' => $baseScopeKey,
            'episode_id' => $episodeId,
            'lifecycle_state' => $lifecycleState,
            'scope' => [
                'client_id' => $clientId,
                'issue_family' => $issueFamily,
                'scope_type' => $scopeType,
                'scope_target' => $cleanTarget,
            ],
        ];
    }
}
