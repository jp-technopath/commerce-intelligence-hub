<?php

namespace App\Services\Intelligence;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\BehavioralMetric;
use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\Finding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ChangeDetectionEngine
{
    private array $thresholds;

    public function __construct()
    {
        $this->thresholds = config('intelligence.thresholds', []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public entry point
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run change detection for a single client.
     * Compares last 7 days vs previous 7 days for all available metrics.
     * Creates Finding records for any metric that exceeds thresholds.
     *
     * Returns the number of new findings generated.
     */
    public function run(Client $client): int
    {
        $newFindings = 0;

        try {
            $enabledCommerce   = $client->getMonitoredMetrics('commerce');
            $enabledBehavioral = $client->getMonitoredMetrics('behavioral');

            $newFindings += $this->detectCommerceChanges($client, $enabledCommerce);
            $newFindings += $this->detectBehavioralChanges($client, $enabledBehavioral);
            $newFindings += $this->detectCrossDatasetChanges($client);
        } catch (\Exception $e) {
            Log::error('ChangeDetectionEngine: error for client', [
                'client_id' => $client->id,
                'message'   => $e->getMessage(),
            ]);
        }

        return $newFindings;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Commerce metrics (GA4)
    // ─────────────────────────────────────────────────────────────────────────

    private function detectCommerceChanges(Client $client, array $enabledMetrics): int
    {
        $days     = $client->getComparisonPeriod();
        $current  = $this->getCommercePeriod($client, 0, $days);
        $previous = $this->getCommercePeriod($client, $days, $days);

        if ($current->isEmpty() || $previous->isEmpty()) {
            return 0;
        }

        $curr = $this->aggregateCommerce($current);
        $prev = $this->aggregateCommerce($previous);

        $findings = 0;

        // Sessions (traffic)
        if (in_array('sessions', $enabledMetrics)) {
            $findings += $this->checkMetric(
                client:         $client,
                metric:         'sessions',
                current:        $curr['sessions'],
                previous:       $prev['sessions'],
                decreaseKey:    'revenue_decrease',
                increaseKey:    null,
                category:       FindingCategory::Revenue,
                title:          fn($dir, $pct) => "Traffic {$dir} {$pct}% week-over-week",
                description:    fn($dir, $pct, $c, $p) => "Sessions {$dir} from {$p} to {$c} ({$pct}% change) comparing the last {$days} days to the prior {$days}-day period.",
                findingType:    'traffic_change',
            );
        }

        // New customers / users
        if (in_array('new_customers', $enabledMetrics)) {
            $findings += $this->checkMetric(
                client:         $client,
                metric:         'new_customers',
                current:        $curr['new_customers'],
                previous:       $prev['new_customers'],
                decreaseKey:    'returning_customer_decrease',
                increaseKey:    null,
                category:       FindingCategory::Customer,
                title:          fn($dir, $pct) => "New visitor acquisition {$dir} {$pct}%",
                description:    fn($dir, $pct, $c, $p) => "New users {$dir} from {$p} to {$c} ({$pct}% change) over the past {$days} days.",
                findingType:    'new_customer_change',
            );
        }

        // Revenue (when available)
        if (in_array('revenue', $enabledMetrics) && ($curr['revenue'] > 0 || $prev['revenue'] > 0)) {
            $findings += $this->checkMetric(
                client:         $client,
                metric:         'revenue',
                current:        $curr['revenue'],
                previous:       $prev['revenue'],
                decreaseKey:    'revenue_decrease',
                increaseKey:    'revenue_increase',
                category:       FindingCategory::Revenue,
                title:          fn($dir, $pct) => "Revenue {$dir} {$pct}% week-over-week",
                description:    fn($dir, $pct, $c, $p) => "Total revenue {$dir} from \${$p} to \${$c} ({$pct}% change) comparing the last {$days} days to the prior {$days}-day period.",
                findingType:    'revenue_change',
                revenueImpact:  fn($c, $p) => round($c - $p, 2),
            );
        }

        // Conversion rate
        if (in_array('conversion_rate', $enabledMetrics) && ($curr['conversion_rate'] > 0 || $prev['conversion_rate'] > 0)) {
            $findings += $this->checkMetric(
                client:         $client,
                metric:         'conversion_rate',
                current:        $curr['conversion_rate'],
                previous:       $prev['conversion_rate'],
                decreaseKey:    'conversion_decrease',
                increaseKey:    'conversion_increase',
                category:       FindingCategory::Conversion,
                title:          fn($dir, $pct) => "Conversion rate {$dir} {$pct}%",
                description:    fn($dir, $pct, $c, $p) => "Conversion rate {$dir} from {$p}% to {$c}% ({$pct}% change) over the past {$days} days.",
                findingType:    'conversion_change',
            );
        }

        // Average Order Value
        if (in_array('aov', $enabledMetrics) && ($curr['aov'] > 0 || $prev['aov'] > 0)) {
            $findings += $this->checkMetric(
                client:         $client,
                metric:         'aov',
                current:        $curr['aov'],
                previous:       $prev['aov'],
                decreaseKey:    'aov_change',
                increaseKey:    'aov_change',
                category:       FindingCategory::Revenue,
                title:          fn($dir, $pct) => "Average order value {$dir} {$pct}%",
                description:    fn($dir, $pct, $c, $p) => "AOV {$dir} from \${$p} to \${$c} ({$pct}% change) over the past {$days} days.",
                findingType:    'aov_change',
            );
        }

        return $findings;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Behavioral metrics (Clarity)
    // ─────────────────────────────────────────────────────────────────────────

    private function detectBehavioralChanges(Client $client, array $enabledMetrics): int
    {
        $days     = $client->getComparisonPeriod();
        $current  = $this->getBehavioralPeriod($client, 0, $days);
        $previous = $this->getBehavioralPeriod($client, $days, $days);

        if ($current->isEmpty() || $previous->isEmpty()) {
            return 0;
        }

        $curr = $this->aggregateBehavioral($current);
        $prev = $this->aggregateBehavioral($previous);

        $findings = 0;

        $behavioralChecks = [
            ['rage_clicks',   'rage_clicks_increase',   FindingCategory::Behavioral, 'Rage click rate increased %s%%',    'Rage clicks increased from %s to %s (%s%% change) over the past 7 days. This indicates user frustration with unresponsive UI elements.'],
            ['dead_clicks',   'dead_clicks_increase',   FindingCategory::Behavioral, 'Dead click rate increased %s%%',    'Dead clicks increased from %s to %s (%s%% change). Users are clicking on non-interactive elements — potential CTA or layout confusion.'],
            ['quick_backs',   'quickbacks_increase',    FindingCategory::Behavioral, 'Quickback rate increased %s%%',     'Quickbacks increased from %s to %s (%s%% change). Users are navigating away quickly, suggesting content or relevance issues.'],
            ['script_errors', 'script_errors_increase', FindingCategory::Technical,  'JavaScript error rate increased %s%%', 'Script errors increased from %s to %s (%s%% change). This may be causing invisible checkout or page failures.'],
            ['error_clicks',  'error_clicks_increase',  FindingCategory::Technical,  'Error click rate increased %s%%',   'Error clicks increased from %s to %s (%s%% change). Users are clicking on broken elements.'],
            ['friction_score','friction_score_increase',FindingCategory::Behavioral, 'Overall friction score increased %s%%', 'The composite friction score increased from %s to %s (%s%% change), indicating a broad degradation in UX quality.'],
        ];

        foreach ($behavioralChecks as [$metric, $thresholdKey, $category, $titleTemplate, $descTemplate]) {
            // Skip metrics not enabled for this client
            if (! in_array($metric, $enabledMetrics)) continue;

            $currVal = $curr[$metric] ?? 0;
            $prevVal = $prev[$metric] ?? 0;

            if ($prevVal <= 0) continue;

            $change = ($currVal - $prevVal) / $prevVal;
            $threshold = $client->getThreshold($thresholdKey);

            if ($change >= $threshold) {
                $pct  = round($change * 100, 1);
                $findings += $this->createFinding(
                    client:      $client,
                    findingType: $metric . '_increase',
                    category:    $category,
                    title:       sprintf($titleTemplate, $pct),
                    description: 'Clarity behavioral signals indicate: ' . sprintf($descTemplate, round($prevVal, 1), round($currVal, 1), $pct),
                    severity:    $this->behavioralSeverity($metric, $change),
                    confidence:  $this->confidence($change, $threshold),
                    metadata:    [
                        'metric'       => $metric,
                        'current'      => $currVal,
                        'previous'     => $prevVal,
                        'change_pct'   => $pct,
                        'period_days'  => 7,
                        'source'       => 'clarity',
                    ],
                );
            }
        }

        return $findings;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Core metric check helper
    // ─────────────────────────────────────────────────────────────────────────

    private function checkMetric(
        Client          $client,
        string          $metric,
        float           $current,
        float           $previous,
        ?string         $decreaseKey,
        ?string         $increaseKey,
        FindingCategory $category,
        \Closure        $title,
        \Closure        $description,
        string          $findingType,
        ?\Closure       $revenueImpact = null,
    ): int {
        if ($previous <= 0) {
            return 0;
        }

        $change = ($current - $previous) / $previous;
        $absChange = abs($change);
        $dir = $change > 0 ? 'increased' : 'decreased';
        $pct = round($absChange * 100, 1);

        // Check decrease
        if ($change < 0 && $decreaseKey) {
            $threshold = $client->getThreshold($decreaseKey);
            if ($absChange >= $threshold) {
                return $this->createFinding(
                    client:        $client,
                    findingType:   $findingType . '_decrease',
                    category:      $category,
                    title:         $title($dir, $pct),
                    description:   $description($dir, $pct, round($current, 2), round($previous, 2)),
                    severity:      $this->commerceSeverity($absChange, $threshold),
                    confidence:    $this->confidence($absChange, $threshold),
                    revenueImpact: $revenueImpact ? $revenueImpact($current, $previous) : null,
                    metadata:      [
                        'metric'      => $metric,
                        'current'     => $current,
                        'previous'    => $previous,
                        'change_pct'  => -$pct,
                        'period_days' => 7,
                        'sources'     => ['ga4', 'adobe_commerce'],
                    ],
                );
            }
        }

        // Check increase
        if ($change > 0 && $increaseKey) {
            $threshold = $client->getThreshold($increaseKey);
            if ($absChange >= $threshold) {
                return $this->createFinding(
                    client:        $client,
                    findingType:   $findingType . '_increase',
                    category:      $category,
                    title:         $title($dir, $pct),
                    description:   $description($dir, $pct, round($current, 2), round($previous, 2)),
                    severity:      FindingSeverity::Low,
                    confidence:    $this->confidence($absChange, $threshold),
                    revenueImpact: $revenueImpact ? $revenueImpact($current, $previous) : null,
                    metadata:      [
                        'metric'      => $metric,
                        'current'     => $current,
                        'previous'    => $previous,
                        'change_pct'  => $pct,
                        'period_days' => 7,
                        'sources'     => ['ga4', 'adobe_commerce'],
                    ],
                );
            }
        }

        return 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Finding creation (deduplication: skip if same type already open)
    // ─────────────────────────────────────────────────────────────────────────

    private function createFinding(
        Client          $client,
        string          $findingType,
        FindingCategory $category,
        string          $title,
        string          $description,
        FindingSeverity $severity,
        float           $confidence,
        ?float          $revenueImpact = null,
        array           $metadata = [],
    ): int {
        // Skip if an open finding of the same type already exists for this client
        $exists = Finding::where('client_id', $client->id)
            ->where('finding_type', $findingType)
            ->whereIn('status', [
                FindingStatus::New->value,
                FindingStatus::Investigating->value,
                FindingStatus::Accepted->value,
            ])
            ->exists();

        if ($exists) {
            return 0;
        }

        Finding::create([
            'client_id'                => $client->id,
            'finding_type'             => $findingType,
            'finding_category'         => $category->value,
            'title'                    => $title,
            'description'              => $description,
            'severity'                 => $severity->value,
            'confidence_score'         => $confidence,
            'estimated_revenue_impact' => $revenueImpact,
            'status'                   => FindingStatus::New->value,
            'metadata_json'            => $metadata,
            'detected_at'              => now(),
        ]);

        return 1;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data fetching
    // ─────────────────────────────────────────────────────────────────────────

    private function getCommercePeriod(Client $client, int $offsetDays, int $periodDays): Collection
    {
        $end   = Carbon::today()->subDays($offsetDays);
        $start = $end->copy()->subDays($periodDays - 1);

        return CommerceMetric::where('client_id', $client->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();
    }

    private function getBehavioralPeriod(Client $client, int $offsetDays, int $periodDays): Collection
    {
        $end   = Carbon::today()->subDays($offsetDays);
        $start = $end->copy()->subDays($periodDays - 1);

        return BehavioralMetric::where('client_id', $client->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Aggregation
    // ─────────────────────────────────────────────────────────────────────────

    private function aggregateCommerce(Collection $rows): array
    {
        $orders = $rows->sum('orders');
        $revenue = $rows->sum('revenue');

        return [
            'sessions'        => (float) $rows->sum('sessions'),
            'new_customers'   => (float) $rows->sum('new_customers'),
            'revenue'         => (float) $revenue,
            'orders'          => (float) $orders,
            'conversion_rate' => (float) $rows->avg('conversion_rate') ?? 0,
            'aov'             => $orders > 0 ? round($revenue / $orders, 2) : 0,
        ];
    }

    private function aggregateBehavioral(Collection $rows): array
    {
        return [
            'rage_clicks'   => (float) $rows->avg('rage_clicks')   ?? 0,
            'dead_clicks'   => (float) $rows->avg('dead_clicks')   ?? 0,
            'quick_backs'   => (float) $rows->avg('quick_backs')   ?? 0,
            'script_errors' => (float) $rows->avg('script_errors') ?? 0,
            'error_clicks'  => (float) $rows->avg('error_clicks')  ?? 0,
            'friction_score'=> (float) $rows->avg('friction_score')?? 0,
            'traffic'       => (float) $rows->sum('traffic'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Severity + confidence helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function commerceSeverity(float $change, float $threshold): FindingSeverity
    {
        $ratio = $change / $threshold;
        return match (true) {
            $ratio >= 3.0 => FindingSeverity::Critical,
            $ratio >= 2.0 => FindingSeverity::High,
            $ratio >= 1.5 => FindingSeverity::Medium,
            default       => FindingSeverity::Low,
        };
    }

    private function behavioralSeverity(string $metric, float $change): FindingSeverity
    {
        $highSeverity = ['rage_clicks', 'script_errors', 'friction_score'];
        $base = in_array($metric, $highSeverity) ? FindingSeverity::High : FindingSeverity::Medium;

        if ($change >= 0.50) return FindingSeverity::Critical;
        if ($change >= 0.30) return $base;
        return FindingSeverity::Medium;
    }

    private function confidence(float $change, float $threshold): float
    {
        // Higher divergence from threshold = higher confidence this is real
        $ratio = min($change / $threshold, 3.0);
        return round(min(0.95, 0.50 + ($ratio * 0.15)), 2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cross-dataset correlation detection
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Detect correlated anomalies across GA4, Clarity, and Adobe Commerce.
     *
     * Correlations checked:
     *  1. Revenue drop + UX friction spike → checkout/UX issue
     *  2. Revenue drop + script errors up → technical issue killing sales
     *  3. Traffic up + revenue down → conversion/traffic quality problem
     *  4. Traffic down + quickbacks up → content relevance / landing page issue
     *  5. AOV drop + rage clicks up → pricing/product page UX issue
     *  6. Friction score up + conversion rate down → broad UX degradation
     */
    private function detectCrossDatasetChanges(Client $client): int
    {
        // Pull 7-day current and previous for commerce
        $commCurr = $this->getCommercePeriod($client, 0, 7);
        $commPrev = $this->getCommercePeriod($client, 7, 7);

        if ($commCurr->isEmpty() || $commPrev->isEmpty()) {
            return 0;
        }

        $comm = [
            'curr' => $this->aggregateCommerce($commCurr),
            'prev' => $this->aggregateCommerce($commPrev),
        ];

        // Try period-over-period behavioral comparison first
        $behCurr = $this->getBehavioralPeriod($client, 0, 7);
        $behPrev = $this->getBehavioralPeriod($client, 7, 7);

        $hasBehavioralHistory = $behCurr->isNotEmpty() && $behPrev->isNotEmpty()
            && $behPrev->sum('traffic') > 0;

        if ($hasBehavioralHistory) {
            $beh = [
                'curr' => $this->aggregateBehavioral($behCurr),
                'prev' => $this->aggregateBehavioral($behPrev),
            ];
            $changes = $this->calculateAllChanges($comm, $beh);
        } else {
            // Fallback: use latest behavioral snapshot with absolute thresholds
            $latestBeh = BehavioralMetric::where('client_id', $client->id)
                ->where('traffic', '>', 0)
                ->latest('date')
                ->first();

            if (! $latestBeh) {
                return 0;
            }

            $beh = [
                'curr' => [
                    'rage_clicks'    => (float) $latestBeh->rage_clicks,
                    'dead_clicks'    => (float) $latestBeh->dead_clicks,
                    'quick_backs'    => (float) $latestBeh->quick_backs,
                    'script_errors'  => (float) $latestBeh->script_errors,
                    'error_clicks'   => (float) ($latestBeh->error_clicks ?? 0),
                    'friction_score' => (float) $latestBeh->friction_score,
                    'traffic'        => (float) $latestBeh->traffic,
                ],
                'prev' => [
                    'rage_clicks' => 0, 'dead_clicks' => 0, 'quick_backs' => 0,
                    'script_errors' => 0, 'error_clicks' => 0, 'friction_score' => 0, 'traffic' => 0,
                ],
            ];

            // Build changes using commerce period-over-period + behavioral absolute signals
            $pctChange = function (float $c, float $p): float {
                return $p > 0 ? ($c - $p) / $p : 0.0;
            };

            $changes = [
                'sessions'        => $pctChange($comm['curr']['sessions'], $comm['prev']['sessions']),
                'revenue'         => $pctChange($comm['curr']['revenue'], $comm['prev']['revenue']),
                'orders'          => $pctChange($comm['curr']['orders'], $comm['prev']['orders']),
                'aov'             => $pctChange($comm['curr']['aov'], $comm['prev']['aov']),
                'new_customers'   => $pctChange($comm['curr']['new_customers'], $comm['prev']['new_customers']),
                'conversion_rate' => $pctChange($comm['curr']['conversion_rate'], $comm['prev']['conversion_rate']),
                // For behavioral: use absolute thresholds instead of % change
                'rage_clicks'     => $latestBeh->rage_clicks > 5 ? 0.30 : 0,
                'dead_clicks'     => $latestBeh->dead_clicks > 10 ? 0.25 : 0,
                'quick_backs'     => ($latestBeh->traffic > 0 && $latestBeh->quick_backs / $latestBeh->traffic > 0.40) ? 0.30 : 0,
                'script_errors'   => $latestBeh->script_errors > 5 ? 0.25 : 0,
                'friction_score'  => $latestBeh->friction_score > 15 ? 0.20 : 0,
                'error_clicks'    => ($latestBeh->error_clicks ?? 0) > 5 ? 0.25 : 0,
            ];
        }

        $findings = 0;

        // ── Correlation 1: Revenue Drop + UX Friction Spike ──────────────
        if ($changes['revenue'] < -0.10 && $changes['friction_score'] > 0.15) {
            $findings += $this->createCrossDatasetFinding(
                client:      $client,
                findingType: 'revenue_friction_correlation',
                category:    FindingCategory::Checkout,
                severity:    $this->crossSeverity($changes['revenue'], $changes['friction_score']),
                title:       sprintf(
                    'Revenue down %.1f%% while UX friction up %.1f%% — likely checkout/UX issue',
                    abs($changes['revenue'] * 100),
                    $changes['friction_score'] * 100
                ),
                description: sprintf(
                    "Cross-dataset correlation detected: Revenue decreased from \$%s to \$%s (%.1f%%) "
                    . "while Clarity's friction score increased from %.1f to %.1f (%.1f%%). "
                    . "This strong inverse correlation suggests UX degradation is directly impacting sales. "
                    . "Investigate recent site changes, checkout flow modifications, or mobile experience issues.",
                    number_format($comm['prev']['revenue'], 2),
                    number_format($comm['curr']['revenue'], 2),
                    $changes['revenue'] * 100,
                    $beh['prev']['friction_score'],
                    $beh['curr']['friction_score'],
                    $changes['friction_score'] * 100
                ),
                changes:     $changes,
                sources:     ['ga4', 'clarity', 'adobe_commerce'],
            );
        }

        // ── Correlation 2: Revenue Drop + Script Errors Spike ────────────
        if ($changes['revenue'] < -0.10 && $changes['script_errors'] > 0.20) {
            $findings += $this->createCrossDatasetFinding(
                client:      $client,
                findingType: 'revenue_script_error_correlation',
                category:    FindingCategory::Technical,
                severity:    FindingSeverity::Critical,
                title:       sprintf(
                    'Revenue down %.1f%% while JavaScript errors up %.1f%% — technical issue killing sales',
                    abs($changes['revenue'] * 100),
                    $changes['script_errors'] * 100
                ),
                description: sprintf(
                    "Critical cross-dataset alert: Revenue dropped %.1f%% (\$%s → \$%s) "
                    . "while Clarity detected a %.1f%% increase in script errors (%.0f → %.0f). "
                    . "JavaScript failures may be blocking checkout, breaking add-to-cart, or hiding critical UI elements. "
                    . "Immediate investigation of browser console errors and recent deployments is recommended.",
                    abs($changes['revenue'] * 100),
                    number_format($comm['prev']['revenue'], 2),
                    number_format($comm['curr']['revenue'], 2),
                    $changes['script_errors'] * 100,
                    $beh['prev']['script_errors'],
                    $beh['curr']['script_errors']
                ),
                changes:     $changes,
                sources:     ['adobe_commerce', 'clarity'],
                revenueImpact: round($comm['curr']['revenue'] - $comm['prev']['revenue'], 2),
            );
        }

        // ── Correlation 3: Traffic Up + Revenue Down ─────────────────────
        if ($changes['sessions'] > 0.10 && $changes['revenue'] < -0.10) {
            $findings += $this->createCrossDatasetFinding(
                client:      $client,
                findingType: 'traffic_up_revenue_down_correlation',
                category:    FindingCategory::Conversion,
                severity:    FindingSeverity::High,
                title:       sprintf(
                    'Traffic up %.1f%% but revenue down %.1f%% — conversion or traffic quality problem',
                    $changes['sessions'] * 100,
                    abs($changes['revenue'] * 100)
                ),
                description: sprintf(
                    "Divergence detected: GA4 sessions increased %.1f%% (%.0f → %.0f) "
                    . "but Adobe Commerce revenue decreased %.1f%% (\$%s → \$%s). "
                    . "More visitors are arriving but fewer are converting. "
                    . "Possible causes: lower-quality traffic sources, broken conversion funnel, "
                    . "pricing issues, or landing page misalignment with ad campaigns.",
                    $changes['sessions'] * 100,
                    $comm['prev']['sessions'],
                    $comm['curr']['sessions'],
                    abs($changes['revenue'] * 100),
                    number_format($comm['prev']['revenue'], 2),
                    number_format($comm['curr']['revenue'], 2)
                ),
                changes:     $changes,
                sources:     ['ga4', 'adobe_commerce'],
                revenueImpact: round($comm['curr']['revenue'] - $comm['prev']['revenue'], 2),
            );
        }

        // ── Correlation 4: Traffic Down + Quickbacks Up ──────────────────
        if ($changes['sessions'] < -0.10 && $changes['quick_backs'] > 0.15) {
            $findings += $this->createCrossDatasetFinding(
                client:      $client,
                findingType: 'traffic_drop_quickbacks_correlation',
                category:    FindingCategory::Customer,
                severity:    FindingSeverity::High,
                title:       sprintf(
                    'Traffic down %.1f%% with quickbacks up %.1f%% — landing page or content relevance issue',
                    abs($changes['sessions'] * 100),
                    $changes['quick_backs'] * 100
                ),
                description: sprintf(
                    "Cross-dataset pattern: GA4 sessions dropped %.1f%% (%.0f → %.0f) "
                    . "while Clarity quickbacks increased %.1f%% (%.0f → %.0f). "
                    . "Visitors are arriving and immediately bouncing back, suggesting a mismatch "
                    . "between what brought them to the site (ads, search results) and what they find. "
                    . "Review top landing pages, ad copy alignment, and meta descriptions.",
                    abs($changes['sessions'] * 100),
                    $comm['prev']['sessions'],
                    $comm['curr']['sessions'],
                    $changes['quick_backs'] * 100,
                    $beh['prev']['quick_backs'],
                    $beh['curr']['quick_backs']
                ),
                changes:     $changes,
                sources:     ['ga4', 'clarity'],
            );
        }

        // ── Correlation 5: AOV Drop + Rage Clicks Up ────────────────────
        if ($changes['aov'] < -0.15 && $changes['rage_clicks'] > 0.15) {
            $findings += $this->createCrossDatasetFinding(
                client:      $client,
                findingType: 'aov_rage_clicks_correlation',
                category:    FindingCategory::Behavioral,
                severity:    FindingSeverity::High,
                title:       sprintf(
                    'AOV dropped %.1f%% while rage clicks up %.1f%% — product page or pricing UX issue',
                    abs($changes['aov'] * 100),
                    $changes['rage_clicks'] * 100
                ),
                description: sprintf(
                    "Average order value dropped from \$%s to \$%s (%.1f%%) "
                    . "while Clarity rage clicks increased %.1f%%. "
                    . "Customers appear frustrated with product pages — potentially struggling with "
                    . "variant selection, quantity controls, or unresponsive add-to-cart buttons. "
                    . "This is pushing them toward lower-value purchases or abandonment.",
                    number_format($comm['prev']['aov'], 2),
                    number_format($comm['curr']['aov'], 2),
                    $changes['aov'] * 100,
                    $changes['rage_clicks'] * 100
                ),
                changes:     $changes,
                sources:     ['adobe_commerce', 'clarity'],
            );
        }

        // ── Correlation 6: Broad UX Degradation → Conversion Drop ───────
        if ($changes['friction_score'] > 0.15 && $changes['orders'] < -0.10) {
            $findings += $this->createCrossDatasetFinding(
                client:      $client,
                findingType: 'ux_degradation_conversion_drop',
                category:    FindingCategory::Conversion,
                severity:    $this->crossSeverity($changes['orders'], $changes['friction_score']),
                title:       sprintf(
                    'Friction score up %.1f%% while orders down %.1f%% — broad UX degradation impacting conversions',
                    $changes['friction_score'] * 100,
                    abs($changes['orders'] * 100)
                ),
                description: sprintf(
                    "Systemic UX issue detected: Clarity's composite friction score increased %.1f%% "
                    . "(%.1f → %.1f) while Adobe Commerce orders decreased %.1f%% (%.0f → %.0f). "
                    . "Multiple UX signals (dead clicks: %+.1f%%, rage clicks: %+.1f%%, quickbacks: %+.1f%%) "
                    . "are deteriorating simultaneously, pointing to a site-wide experience regression. "
                    . "Check for recent theme updates, plugin changes, or CDN/performance issues.",
                    $changes['friction_score'] * 100,
                    $beh['prev']['friction_score'],
                    $beh['curr']['friction_score'],
                    abs($changes['orders'] * 100),
                    $comm['prev']['orders'],
                    $comm['curr']['orders'],
                    ($changes['dead_clicks'] ?? 0) * 100,
                    ($changes['rage_clicks'] ?? 0) * 100,
                    ($changes['quick_backs'] ?? 0) * 100
                ),
                changes:     $changes,
                sources:     ['ga4', 'clarity', 'adobe_commerce'],
            );
        }

        // ── Correlation 7: Dead Clicks Up + Revenue Down ────────────────
        if ($changes['dead_clicks'] > 0.20 && $changes['revenue'] < -0.05) {
            $findings += $this->createCrossDatasetFinding(
                client:      $client,
                findingType: 'dead_clicks_revenue_correlation',
                category:    FindingCategory::Behavioral,
                severity:    FindingSeverity::Medium,
                title:       sprintf(
                    'Dead clicks up %.1f%% while revenue down %.1f%% — broken interactive elements',
                    $changes['dead_clicks'] * 100,
                    abs($changes['revenue'] * 100)
                ),
                description: sprintf(
                    "Clarity detected a %.1f%% increase in dead clicks (%.0f → %.0f) "
                    . "coinciding with a %.1f%% revenue drop. Users are clicking on elements they "
                    . "expect to be interactive but aren't responding — potentially broken buttons, "
                    . "non-clickable product images, or disabled CTAs that look active.",
                    $changes['dead_clicks'] * 100,
                    $beh['prev']['dead_clicks'],
                    $beh['curr']['dead_clicks'],
                    abs($changes['revenue'] * 100)
                ),
                changes:     $changes,
                sources:     ['clarity', 'adobe_commerce'],
            );
        }

        return $findings;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cross-dataset helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Calculate percentage changes for all metrics across both datasets.
     */
    private function calculateAllChanges(array $comm, array $beh): array
    {
        $pctChange = function (float $current, float $previous): float {
            return $previous > 0 ? ($current - $previous) / $previous : 0.0;
        };

        return [
            // Commerce
            'sessions'        => $pctChange($comm['curr']['sessions'], $comm['prev']['sessions']),
            'revenue'         => $pctChange($comm['curr']['revenue'], $comm['prev']['revenue']),
            'orders'          => $pctChange($comm['curr']['orders'], $comm['prev']['orders']),
            'aov'             => $pctChange($comm['curr']['aov'], $comm['prev']['aov']),
            'new_customers'   => $pctChange($comm['curr']['new_customers'], $comm['prev']['new_customers']),
            'conversion_rate' => $pctChange($comm['curr']['conversion_rate'], $comm['prev']['conversion_rate']),
            // Behavioral
            'rage_clicks'     => $pctChange($beh['curr']['rage_clicks'], $beh['prev']['rage_clicks']),
            'dead_clicks'     => $pctChange($beh['curr']['dead_clicks'], $beh['prev']['dead_clicks']),
            'quick_backs'     => $pctChange($beh['curr']['quick_backs'], $beh['prev']['quick_backs']),
            'script_errors'   => $pctChange($beh['curr']['script_errors'], $beh['prev']['script_errors']),
            'friction_score'  => $pctChange($beh['curr']['friction_score'], $beh['prev']['friction_score']),
            'error_clicks'    => $pctChange($beh['curr']['error_clicks'] ?? 0, $beh['prev']['error_clicks'] ?? 0),
        ];
    }

    /**
     * Create a cross-dataset correlated finding.
     */
    private function createCrossDatasetFinding(
        Client          $client,
        string          $findingType,
        FindingCategory $category,
        FindingSeverity $severity,
        string          $title,
        string          $description,
        array           $changes,
        array           $sources,
        ?float          $revenueImpact = null,
    ): int {
        return $this->createFinding(
            client:        $client,
            findingType:   $findingType,
            category:      $category,
            title:         $title,
            description:   $description,
            severity:      $severity,
            confidence:    $this->crossConfidence($changes, $sources),
            revenueImpact: $revenueImpact,
            metadata:      [
                'correlation_type' => 'cross_dataset',
                'sources'          => $sources,
                'changes'          => array_map(fn ($v) => round($v * 100, 1), $changes),
                'period_days'      => 7,
            ],
        );
    }

    /**
     * Severity for cross-dataset findings — higher when both signals are strong.
     */
    private function crossSeverity(float $commerceChange, float $behavioralChange): FindingSeverity
    {
        $combined = abs($commerceChange) + abs($behavioralChange);
        return match (true) {
            $combined >= 0.80 => FindingSeverity::Critical,
            $combined >= 0.50 => FindingSeverity::High,
            $combined >= 0.30 => FindingSeverity::Medium,
            default           => FindingSeverity::Low,
        };
    }

    /**
     * Confidence is higher when multiple data sources agree.
     */
    private function crossConfidence(array $changes, array $sources): float
    {
        $sourceCount = count($sources);
        // Count how many metrics show meaningful change
        $significantChanges = collect($changes)
            ->filter(fn ($v) => abs($v) >= 0.05)
            ->count();

        $base = 0.60;
        $sourceBonus = min(0.15, ($sourceCount - 1) * 0.075);   // +0.075 per extra source
        $signalBonus = min(0.20, $significantChanges * 0.025);   // +0.025 per agreeing metric

        return round(min(0.98, $base + $sourceBonus + $signalBonus), 2);
    }
}
