<?php

namespace App\Services\Intelligence;

use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Models\Client;
use App\Models\CommerceOrder;
use App\Models\Finding;
use App\Models\RecommendationOutcome;
use App\Models\User;
use App\Services\Security\ClientScopeContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OutcomeMeasurementService
{
    public const METHOD_DORMANT_BASELINE      = 'dormant_historical_baseline';
    public const METHOD_FULL_RECOVERY         = 'full_recovery_justified';
    public const METHOD_HISTORICAL_BASELINE   = 'historical_baseline';
    public const METHOD_OBSERVED_ONLY         = 'observed_only';

    /**
     * Evaluate and record three-tier commercial outcome for an action-completed Finding.
     *
     * Tiers:
     * 1. Action Completed: Verified execution of work with timestamp.
     * 2. Observed Business Outcome: Raw Commerce orders and revenue occurring within the evaluation window.
     * 3. Estimated Incremental Impact: Defensible attribution deducting expected baseline,
     *    accounting for zero-outcomes, and strictly preventing double-counting of Commerce orders.
     */
    public function evaluateFindingOutcome(Finding $finding, ?User $actor = null, array $options = []): RecommendationOutcome
    {
        // 0. Security Context Fail-Closed Safeguard
        ClientScopeContext::assertAuthorizedForClient($finding->client_id, $actor);

        // 1. Safeguard: Verify Action Completed
        if ($finding->execution_status !== FindingExecutionStatus::ActionCompleted && $finding->execution_status !== FindingExecutionStatus::Closed) {
            throw new \InvalidArgumentException(
                "Cannot measure outcome for Finding #{$finding->id}: Action must be completed before outcome measurement. Current execution status: {$finding->execution_status->value}."
            );
        }

        $completedAt = $finding->action_completed_at ?? $finding->updated_at ?? now();
        $windowDays = $options['window_days'] ?? 30;
        $windowEnd = Carbon::parse($completedAt)->copy()->addDays($windowDays);

        $method = $options['attribution_method'] ?? $this->determineDefaultMethod($finding);
        $fullRecoveryJustification = $options['full_recovery_justification'] ?? null;

        // 2. Fetch and Deduplicate Eligible Commerce Orders
        $customerId = $finding->metadata_json['registered_customer_id'] 
            ?? $finding->metadata_json['customer_id'] 
            ?? $finding->metadata_json['account_id'] 
            ?? null;

        $ordersQuery = CommerceOrder::query()
            ->where('client_id', $finding->client_id)
            ->where('is_valid', true)
            ->whereNotIn(DB::raw('LOWER(order_status)'), ['canceled', 'cancelled', 'void', 'failed', 'fraud'])
            ->whereBetween('order_date', [$completedAt, $windowEnd]);

        if ($customerId !== null) {
            $ordersQuery->where(function ($q) use ($customerId) {
                $q->where('registered_customer_id', (string) $customerId)
                  ->orWhere('customer_identity_hash', (string) $customerId);
            });
        }

        $candidateOrders = $ordersQuery->orderBy('order_date', 'asc')->get();

        // Check for already-attributed orders across this client to prevent double counting
        $existingOutcomes = RecommendationOutcome::whereHas('finding', fn ($q) => $q->where('client_id', $finding->client_id))
            ->where('finding_id', '!=', $finding->id)
            ->whereNotNull('matched_orders_json')
            ->get();

        $previouslyAttributedOrderIds = [];
        $orderAttributionMap = []; // order_id => prior finding_id
        foreach ($existingOutcomes as $outcome) {
            $matched = $outcome->matched_orders_json ?? [];
            foreach ($matched as $item) {
                if (isset($item['order_id'])) {
                    $previouslyAttributedOrderIds[] = $item['order_id'];
                    $orderAttributionMap[$item['order_id']] = $outcome->finding_id;
                }
            }
        }

        $matchedOrders = [];
        $doubleCountedOrders = [];
        $observedRevenue = 0.00;

        foreach ($candidateOrders as $order) {
            $orderNetRevenue = (float) ($order->net_revenue ?? $order->gross_revenue ?? 0);

            if (in_array($order->id, $previouslyAttributedOrderIds, true)) {
                $doubleCountedOrders[] = [
                    'order_id'                  => $order->id,
                    'order_date'                => $order->order_date?->toDateTimeString(),
                    'revenue'                   => $orderNetRevenue,
                    'prior_finding_id'          => $orderAttributionMap[$order->id] ?? null,
                    'exclusion_reason'          => 'Order was already attributed to an earlier completed opportunity.',
                ];
                continue;
            }

            $matchedOrders[] = [
                'order_id'       => $order->id,
                'order_date'     => $order->order_date?->toDateTimeString(),
                'net_revenue'    => $orderNetRevenue,
                'gross_revenue'  => (float) $order->gross_revenue,
            ];

            $observedRevenue += $orderNetRevenue;
        }

        $ordersCount = count($matchedOrders);
        $isZeroOutcome = ($ordersCount === 0 || $observedRevenue <= 0.0);

        // 3. Defensible Incremental Impact Calculation
        $baselineExpectedRevenue = 0.00;
        $actualIncrementalImpact = 0.00;
        $methodologyNotes = [];

        if ($isZeroOutcome) {
            $windowElapsed = now()->greaterThanOrEqualTo($windowEnd) || ! empty($options['force_finalize']);

            if (! $windowElapsed) {
                // Observation window is still active: Zero orders observed to date, awaiting completion of window
                $baselineExpectedRevenue = (float) ($options['baseline_expected_revenue'] ?? 0.00);
                $actualIncrementalImpact = 0.00;
                $methodologyNotes[] = "Observation window in progress: Action completed on {$completedAt->format('Y-m-d')}. Evaluation window active through {$windowEnd->format('Y-m-d')}. 0 orders observed to date; zero-outcome will be finalized only after observation window elapses.";

                $finding->transitionOutcomeState(
                    FindingOutcomeStatus::AwaitingResults,
                    'outcome_measurement',
                    $actor,
                    "Observation window active ({$completedAt->format('Y-m-d')} to {$windowEnd->format('Y-m-d')}). 0 orders observed to date; awaiting window completion."
                );
            } else {
                // Defensibly record finalized zero outcome after window has elapsed: Do NOT hide or discard!
                $baselineExpectedRevenue = (float) ($options['baseline_expected_revenue'] ?? 0.00);
                $actualIncrementalImpact = 0.00;
                $methodologyNotes[] = "Finalized zero outcome: Zero orders observed after full {$windowDays}-day post-action evaluation window elapsed ({$completedAt->format('Y-m-d')} to {$windowEnd->format('Y-m-d')}). Recorded as zero-outcome benchmark for attribution calibration.";

                $finding->transitionOutcomeState(
                    FindingOutcomeStatus::NoOrdersObserved,
                    'outcome_measurement',
                    $actor,
                    "Action completed on {$completedAt->format('Y-m-d')}, and 0 orders were observed within the completed {$windowDays}-day attribution window."
                );
            }
        } else {
            // Calculate baseline expected revenue
            if ($method === self::METHOD_FULL_RECOVERY) {
                if (empty($fullRecoveryJustification)) {
                    throw new \InvalidArgumentException("Full recovery attribution requires explicit documented justification.");
                }
                $baselineExpectedRevenue = 0.00;
                $actualIncrementalImpact = $observedRevenue;
                $methodologyNotes[] = "Full recovery justified: {$fullRecoveryJustification}. Observed verified transactions: \${$observedRevenue}.";
            } elseif ($method === self::METHOD_DORMANT_BASELINE) {
                // For dormant customer reactivation, DO NOT assume baseline is zero!
                // Calculate historical pre-dormancy baseline or use supplied benchmark
                $baselineExpectedRevenue = isset($options['baseline_expected_revenue'])
                    ? (float) $options['baseline_expected_revenue']
                    : $this->calculateDormantHistoricalBaseline($finding, $customerId, $completedAt);

                $actualIncrementalImpact = max(0.00, round($observedRevenue - $baselineExpectedRevenue, 2));
                $methodologyNotes[] = "Modeled Incremental Lift (Non-Causal): Observed verified transactions of \${$observedRevenue} across {$ordersCount} valid order(s). Deducted historical pre-dormancy run-rate baseline of \${$baselineExpectedRevenue}. Modeled incremental lift: \${$actualIncrementalImpact}. (Attribution Note: Modeled estimate based on historical purchasing run-rate; external factors including seasonality, general market conditions, and concurrent marketing may influence buyer activity).";
            } elseif ($method === self::METHOD_HISTORICAL_BASELINE) {
                $baselineExpectedRevenue = (float) ($options['baseline_expected_revenue'] ?? 0.00);
                $actualIncrementalImpact = max(0.00, round($observedRevenue - $baselineExpectedRevenue, 2));
                $methodologyNotes[] = "Modeled Incremental Lift (Non-Causal): Observed verified transactions of \${$observedRevenue} across {$ordersCount} valid order(s). Deducted expected baseline of \${$baselineExpectedRevenue}. Modeled incremental lift: \${$actualIncrementalImpact}.";
            } else { // Observed Only
                $baselineExpectedRevenue = 0.00;
                $actualIncrementalImpact = 0.00; // Not claiming incremental lift
                $methodologyNotes[] = "Observed only: Recorded verified transactions of \${$observedRevenue} without claiming incremental causal lift.";
            }

            if (! empty($doubleCountedOrders)) {
                $excludedCount = count($doubleCountedOrders);
                $methodologyNotes[] = "Double-counting protection: {$excludedCount} overlapping order(s) excluded because they were already attributed to prior findings.";
            }

            $finding->transitionOutcomeState(
                FindingOutcomeStatus::OutcomeMeasured,
                'outcome_measurement',
                $actor,
                "Measured outcome: \${$observedRevenue} observed revenue, \${$actualIncrementalImpact} modeled incremental lift across {$ordersCount} order(s)."
            );
        }

        // 4. Persist or Update RecommendationOutcome
        $outcome = RecommendationOutcome::updateOrCreate(
            ['finding_id' => $finding->id],
            [
                'implemented'                 => true,
                'implemented_at'              => $completedAt,
                'observed_revenue'            => round($observedRevenue, 2),
                'baseline_expected_revenue'   => round($baselineExpectedRevenue, 2),
                'actual_incremental_impact'   => round($actualIncrementalImpact, 2),
                'actual_impact'               => round($actualIncrementalImpact, 2),
                'attribution_method'          => $method,
                'attribution_window_days'     => $windowDays,
                'orders_count'                => $ordersCount,
                'is_zero_outcome'             => $isZeroOutcome,
                'matched_orders_json'         => $matchedOrders,
                'methodology_notes'           => implode(' ', $methodologyNotes),
                'confidence_score'            => $isZeroOutcome ? 1.00 : ($options['confidence_score'] ?? 0.85),
                'verified_at'                 => now(),
            ]
        );

        return $outcome;
    }

    /**
     * Compute historical pre-dormancy baseline revenue for a customer.
     * Looks at historical purchases before the dormant period to establish expected baseline.
     */
    protected function calculateDormantHistoricalBaseline(Finding $finding, mixed $customerId, Carbon $completedAt): float
    {
        if ($customerId === null) {
            // Default conservative baseline: 15% of estimated opportunity value
            return round((float) ($finding->estimated_revenue_impact ?? 0) * 0.15, 2);
        }

        // Look at orders in the 90 days before dormancy/action
        $priorOrders = CommerceOrder::where('client_id', $finding->client_id)
            ->where('is_valid', true)
            ->where('order_date', '<', $completedAt)
            ->where(function ($q) use ($customerId) {
                $q->where('registered_customer_id', (string) $customerId)
                  ->orWhere('customer_identity_hash', (string) $customerId);
            })
            ->latest('order_date')
            ->take(5)
            ->get();

        if ($priorOrders->isEmpty()) {
            // No prior orders found; assume baseline of 10% of estimated impact
            return round((float) ($finding->estimated_revenue_impact ?? 0) * 0.10, 2);
        }

        $avgOrderValue = $priorOrders->avg('net_revenue') ?? 0;
        // Conservative expectation: at least 1 typical order would occur naturally over 60-90 days
        return round((float) $avgOrderValue * 0.5, 2);
    }

    /**
     * Determine default attribution method based on finding type/source.
     */
    protected function determineDefaultMethod(Finding $finding): string
    {
        $category = $finding->finding_category?->value ?? (string) $finding->finding_category;
        $source = $finding->source_id ?? '';

        if (str_contains(strtolower($category), 'dormant') || str_contains(strtolower($source), 'dormant') || str_contains(strtolower($finding->title), 'dormant')) {
            return self::METHOD_DORMANT_BASELINE;
        }

        return self::METHOD_HISTORICAL_BASELINE;
    }
}
