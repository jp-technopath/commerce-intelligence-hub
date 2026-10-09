<?php

namespace App\Services\Intelligence;

use App\Models\Client;
use App\Models\PmWorklog;
use Carbon\Carbon;

class ProjectAllocationHealthService
{
    /**
     * Evaluate allocation health for a client.
     */
    public function evaluateClientAllocation(Client $client, ?Carbon $date = null): array
    {
        $date = $date ? $date->copy() : now();
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        $totalSeconds = PmWorklog::where('client_id', $client->id)
            ->whereBetween('worklog_started_at', [$startOfMonth, $endOfMonth])
            ->sum('time_spent_seconds');

        $actualHours = round($totalSeconds / 3600, 1);
        $allocatedHours = $client->monthly_allocated_hours;

        if ($allocatedHours === null || $allocatedHours <= 0) {
            return [
                'status'          => 'unbudgeted',
                'badge_color'     => 'gray',
                'label'           => 'Unbudgeted',
                'actual_hours'    => $actualHours,
                'allocated_hours' => null,
                'consumption_pct' => null,
                'summary'         => "{$actualHours}h logged (no monthly allocation configured)",
                'reasons'         => ['No contract allocation threshold configured for this client.'],
            ];
        }

        $consumptionPct = round(($actualHours / $allocatedHours) * 100, 1);
        $status = 'on_budget';
        $badgeColor = 'success';
        $label = 'On Budget';
        $reasons = [];

        if ($actualHours >= $allocatedHours) {
            $status = 'over_allocation';
            $badgeColor = 'danger';
            $label = 'Over Allocation';
            $overage = round($actualHours - $allocatedHours, 1);
            $reasons[] = "Client has exceeded allocation by {$overage}h ({$consumptionPct}% consumed).";
        } elseif ($consumptionPct >= 85) {
            $status = 'approaching';
            $badgeColor = 'warning';
            $label = 'Approaching Allocation';
            $remaining = round($allocatedHours - $actualHours, 1);
            $reasons[] = "Client at {$consumptionPct}% of monthly budget ({$remaining}h remaining).";
        } else {
            $reasons[] = "Healthy budget pacing: {$actualHours}h of {$allocatedHours}h consumed ({$consumptionPct}%).";
        }

        return [
            'status'          => $status,
            'badge_color'     => $badgeColor,
            'label'           => $label,
            'actual_hours'    => $actualHours,
            'logged_hours'    => $actualHours,
            'allocated_hours' => $allocatedHours,
            'consumption_pct' => $consumptionPct,
            'utilization_pct' => $consumptionPct,
            'summary'         => "{$actualHours}h / {$allocatedHours}h ({$consumptionPct}%)",
            'reasons'         => $reasons,
        ];
    }
}
