<?php

namespace App\Filament\Pages;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\BehavioralMetric;
use App\Models\PerformanceMetric;
use App\Models\InventoryMetric;
use App\Models\Finding;
use Filament\Pages\Page;

class BusinessDashboard extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar-square';
    protected static ?string $navigationLabel = 'Business Dashboard';
    protected static ?string $navigationGroup = 'Dashboard';
    protected static ?string $title           = 'Business Dashboard';
    protected static ?string $slug            = 'dashboard/business';
    protected static ?int    $navigationSort  = -1;

    protected static string $view = 'filament.pages.business-dashboard';

    public ?int $selectedClientId = null;
    public string $period = '7'; // days

    public function mount(): void
    {
        $this->selectedClientId = Client::first()?->id;
    }

    public function getClients(): \Illuminate\Support\Collection
    {
        return Client::orderBy('name')->pluck('name', 'id');
    }

    public function updatedSelectedClientId(): void
    {
        // Livewire reactivity — re-renders automatically
    }

    public function updatedPeriod(): void
    {
        // Livewire reactivity — re-renders automatically
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Active integrations for this client
    // ─────────────────────────────────────────────────────────────────────────

    public function getActiveIntegrations(): array
    {
        if (! $this->selectedClientId) return [];

        $client = Client::find($this->selectedClientId);
        return $client ? $client->getActiveIntegrationTypes() : [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Funnel-Stage KPI Methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Acquisition: Sessions (GA4), New Users (GA4), Traffic (Clarity)
     */
    public function getAcquisitionKpis(): array
    {
        if (! $this->selectedClientId) return [];

        $active = $this->getActiveIntegrations();
        $days   = (int) $this->period;
        $kpis   = [];

        if (in_array('ga4', $active)) {
            $current  = $this->getCommerceAggregates('ga4', $days);
            $previous = $this->getCommerceAggregates('ga4', $days, offset: $days);

            $kpis[] = [
                'label'    => 'Sessions',
                'value'    => number_format($current['sessions']),
                'previous' => number_format($previous['sessions']),
                'change'   => $this->pctChange($previous['sessions'], $current['sessions']),
                'icon'     => 'heroicon-o-cursor-arrow-rays',
                'color'    => 'blue',
                'source'   => 'GA4',
            ];
            $kpis[] = [
                'label'    => 'New Users',
                'value'    => number_format($current['new_customers']),
                'previous' => number_format($previous['new_customers']),
                'change'   => $this->pctChange($previous['new_customers'], $current['new_customers']),
                'icon'     => 'heroicon-o-user-plus',
                'color'    => 'sky',
                'source'   => 'GA4',
            ];

            if ($current['return_rate'] > 0 || $previous['return_rate'] > 0) {
                $kpis[] = [
                    'label'    => 'Return Rate',
                    'value'    => number_format($current['return_rate'], 1) . '%',
                    'previous' => number_format($previous['return_rate'], 1) . '%',
                    'change'   => $this->pctChange($previous['return_rate'], $current['return_rate']),
                    'icon'     => 'heroicon-o-arrow-path',
                    'color'    => 'indigo',
                    'source'   => 'GA4',
                ];
            }
        }

        if (in_array('clarity', $active)) {
            $current  = $this->getBehavioralAggregates($days);
            $previous = $this->getBehavioralAggregates($days, offset: $days);

            $kpis[] = [
                'label'    => 'Traffic',
                'value'    => number_format($current['traffic']),
                'previous' => number_format($previous['traffic']),
                'change'   => $this->pctChange($previous['traffic'], $current['traffic']),
                'icon'     => 'heroicon-o-globe-alt',
                'color'    => 'cyan',
                'source'   => 'Clarity',
            ];
        }

        return $kpis;
    }

    /**
     * Conversion: Conversion Rate (GA4), Orders (Adobe), Items Sold (Adobe)
     */
    public function getConversionKpis(): array
    {
        if (! $this->selectedClientId) return [];

        $active = $this->getActiveIntegrations();
        $days   = (int) $this->period;
        $kpis   = [];

        if (in_array('ga4', $active)) {
            $current  = $this->getCommerceAggregates('ga4', $days);
            $previous = $this->getCommerceAggregates('ga4', $days, offset: $days);

            $kpis[] = [
                'label'    => 'Conversion Rate',
                'value'    => number_format($current['conversion_rate'], 2) . '%',
                'previous' => number_format($previous['conversion_rate'], 2) . '%',
                'change'   => $this->pctChange($previous['conversion_rate'], $current['conversion_rate']),
                'icon'     => 'heroicon-o-arrow-trending-up',
                'color'    => 'violet',
                'source'   => 'GA4',
            ];
        }

        if (in_array('adobe_commerce', $active)) {
            $current  = $this->getCommerceAggregates('adobe_commerce', $days);
            $previous = $this->getCommerceAggregates('adobe_commerce', $days, offset: $days);

            $kpis[] = [
                'label'    => 'Orders',
                'value'    => number_format($current['orders']),
                'previous' => number_format($previous['orders']),
                'change'   => $this->pctChange($previous['orders'], $current['orders']),
                'icon'     => 'heroicon-o-shopping-cart',
                'color'    => 'orange',
                'source'   => 'Adobe',
            ];
            $kpis[] = [
                'label'    => 'Items Sold',
                'value'    => number_format($current['items_sold']),
                'previous' => number_format($previous['items_sold']),
                'change'   => $this->pctChange($previous['items_sold'], $current['items_sold']),
                'icon'     => 'heroicon-o-cube',
                'color'    => 'rose',
                'source'   => 'Adobe',
            ];
        }

        return $kpis;
    }

    /**
     * Revenue: GA4 Revenue, Adobe Revenue, AOV (Adobe)
     */
    public function getRevenueKpis(): array
    {
        if (! $this->selectedClientId) return [];

        $active = $this->getActiveIntegrations();
        $days   = (int) $this->period;
        $kpis   = [];

        if (in_array('ga4', $active)) {
            $current  = $this->getCommerceAggregates('ga4', $days);
            $previous = $this->getCommerceAggregates('ga4', $days, offset: $days);

            $kpis[] = [
                'label'    => 'GA4 Revenue',
                'value'    => '$' . number_format($current['revenue'], 2),
                'previous' => '$' . number_format($previous['revenue'], 2),
                'change'   => $this->pctChange($previous['revenue'], $current['revenue']),
                'icon'     => 'heroicon-o-banknotes',
                'color'    => 'emerald',
                'source'   => 'GA4',
            ];
        }

        if (in_array('adobe_commerce', $active)) {
            $current  = $this->getCommerceAggregates('adobe_commerce', $days);
            $previous = $this->getCommerceAggregates('adobe_commerce', $days, offset: $days);

            $kpis[] = [
                'label'    => 'Adobe Revenue',
                'value'    => '$' . number_format($current['revenue'], 2),
                'previous' => '$' . number_format($previous['revenue'], 2),
                'change'   => $this->pctChange($previous['revenue'], $current['revenue']),
                'icon'     => 'heroicon-o-banknotes',
                'color'    => 'emerald',
                'source'   => 'Adobe',
            ];
            $kpis[] = [
                'label'    => 'AOV',
                'value'    => '$' . number_format($current['aov'], 2),
                'previous' => '$' . number_format($previous['aov'], 2),
                'change'   => $this->pctChange($previous['aov'], $current['aov']),
                'icon'     => 'heroicon-o-receipt-percent',
                'color'    => 'amber',
                'source'   => 'Adobe',
            ];
        }

        return $kpis;
    }

    /**
     * UX & Friction: Friction Score, Rage Clicks, Script Errors, Dead Clicks (all Clarity)
     */
    public function getFrictionKpis(): array
    {
        if (! $this->selectedClientId) return [];

        $active = $this->getActiveIntegrations();
        if (! in_array('clarity', $active)) return [];

        $days     = (int) $this->period;
        $current  = $this->getBehavioralAggregates($days);
        $previous = $this->getBehavioralAggregates($days, offset: $days);

        return [
            [
                'label'    => 'Friction Score',
                'value'    => number_format($current['friction_score'], 1),
                'previous' => number_format($previous['friction_score'], 1),
                'change'   => $this->pctChange($previous['friction_score'], $current['friction_score']),
                'icon'     => 'heroicon-o-exclamation-triangle',
                'color'    => 'red',
                'invert'   => true,
                'source'   => 'Clarity',
            ],
            [
                'label'    => 'Rage Clicks',
                'value'    => number_format($current['rage_clicks']),
                'previous' => number_format($previous['rage_clicks']),
                'change'   => $this->pctChange($previous['rage_clicks'], $current['rage_clicks']),
                'icon'     => 'heroicon-o-hand-raised',
                'color'    => 'red',
                'invert'   => true,
                'source'   => 'Clarity',
            ],
            [
                'label'    => 'Script Errors',
                'value'    => number_format($current['script_errors']),
                'previous' => number_format($previous['script_errors']),
                'change'   => $this->pctChange($previous['script_errors'], $current['script_errors']),
                'icon'     => 'heroicon-o-bug-ant',
                'color'    => 'red',
                'invert'   => true,
                'source'   => 'Clarity',
            ],
        ];
    }

    /**
     * Performance: LCP, INP, CLS, TTFB, Page Load Time, Bounce Rate (GA4 + Clarity)
     */
    public function getPerformanceKpis(): array
    {
        if (! $this->selectedClientId) return [];

        $active = $this->getActiveIntegrations();
        $hasPerf = in_array('ga4', $active) || in_array('clarity', $active);
        if (! $hasPerf) return [];

        $days     = (int) $this->period;
        $current  = $this->getPerformanceAggregates($days);
        $previous = $this->getPerformanceAggregates($days, offset: $days);

        $kpis = [];

        if ($current['lcp'] > 0 || $previous['lcp'] > 0) {
            $kpis[] = [
                'label'    => 'LCP',
                'value'    => number_format($current['lcp'], 2) . 's',
                'previous' => number_format($previous['lcp'], 2) . 's',
                'change'   => $this->pctChange($previous['lcp'], $current['lcp']),
                'icon'     => 'heroicon-o-clock',
                'color'    => 'violet',
                'invert'   => true,
                'source'   => $current['lcp_source'] ?? 'GA4',
            ];
        }

        if ($current['inp'] > 0 || $previous['inp'] > 0) {
            $kpis[] = [
                'label'    => 'INP',
                'value'    => number_format($current['inp'], 0) . 'ms',
                'previous' => number_format($previous['inp'], 0) . 'ms',
                'change'   => $this->pctChange($previous['inp'], $current['inp']),
                'icon'     => 'heroicon-o-cursor-arrow-ripple',
                'color'    => 'blue',
                'invert'   => true,
                'source'   => 'GA4',
            ];
        }

        if ($current['cls'] > 0 || $previous['cls'] > 0) {
            $kpis[] = [
                'label'    => 'CLS',
                'value'    => number_format($current['cls'], 3),
                'previous' => number_format($previous['cls'], 3),
                'change'   => $this->pctChange($previous['cls'], $current['cls']),
                'icon'     => 'heroicon-o-arrows-up-down',
                'color'    => 'amber',
                'invert'   => true,
                'source'   => 'GA4',
            ];
        }

        if ($current['page_load_time'] > 0 || $previous['page_load_time'] > 0) {
            $kpis[] = [
                'label'    => 'Page Load',
                'value'    => number_format($current['page_load_time'], 2) . 's',
                'previous' => number_format($previous['page_load_time'], 2) . 's',
                'change'   => $this->pctChange($previous['page_load_time'], $current['page_load_time']),
                'icon'     => 'heroicon-o-bolt',
                'color'    => 'orange',
                'invert'   => true,
                'source'   => 'GA4',
            ];
        }

        if ($current['bounce_rate'] > 0 || $previous['bounce_rate'] > 0) {
            $kpis[] = [
                'label'    => 'Bounce Rate',
                'value'    => number_format($current['bounce_rate'], 1) . '%',
                'previous' => number_format($previous['bounce_rate'], 1) . '%',
                'change'   => $this->pctChange($previous['bounce_rate'], $current['bounce_rate']),
                'icon'     => 'heroicon-o-arrow-uturn-left',
                'color'    => 'rose',
                'invert'   => true,
                'source'   => 'GA4',
            ];
        }

        return $kpis;
    }

    /**
     * Inventory: Out of Stock, Low Stock, OOS Rate, Turnover (Adobe/Shopify)
     */
    public function getInventoryKpis(): array
    {
        if (! $this->selectedClientId) return [];

        $active = $this->getActiveIntegrations();
        $hasInventory = in_array('adobe_commerce', $active) || in_array('shopify', $active);
        if (! $hasInventory) return [];

        $days     = (int) $this->period;
        $source   = in_array('adobe_commerce', $active) ? 'adobe_commerce' : 'shopify';
        $srcLabel = $source === 'adobe_commerce' ? 'Adobe' : 'Shopify';
        $current  = $this->getInventoryAggregates($days, $source);
        $previous = $this->getInventoryAggregates($days, $source, offset: $days);

        $kpis = [];

        if ($current['out_of_stock_count'] > 0 || $previous['out_of_stock_count'] > 0) {
            $kpis[] = [
                'label'    => 'Out of Stock',
                'value'    => number_format($current['out_of_stock_count']),
                'previous' => number_format($previous['out_of_stock_count']),
                'change'   => $this->pctChange($previous['out_of_stock_count'], $current['out_of_stock_count']),
                'icon'     => 'heroicon-o-x-circle',
                'color'    => 'red',
                'invert'   => true,
                'source'   => $srcLabel,
            ];
        }

        if ($current['low_stock_count'] > 0 || $previous['low_stock_count'] > 0) {
            $kpis[] = [
                'label'    => 'Low Stock',
                'value'    => number_format($current['low_stock_count']),
                'previous' => number_format($previous['low_stock_count']),
                'change'   => $this->pctChange($previous['low_stock_count'], $current['low_stock_count']),
                'icon'     => 'heroicon-o-exclamation-circle',
                'color'    => 'amber',
                'invert'   => true,
                'source'   => $srcLabel,
            ];
        }

        if ($current['out_of_stock_rate'] > 0 || $previous['out_of_stock_rate'] > 0) {
            $kpis[] = [
                'label'    => 'OOS Rate',
                'value'    => number_format($current['out_of_stock_rate'], 1) . '%',
                'previous' => number_format($previous['out_of_stock_rate'], 1) . '%',
                'change'   => $this->pctChange($previous['out_of_stock_rate'], $current['out_of_stock_rate']),
                'icon'     => 'heroicon-o-chart-pie',
                'color'    => 'orange',
                'invert'   => true,
                'source'   => $srcLabel,
            ];
        }

        if ($current['inventory_turnover'] > 0 || $previous['inventory_turnover'] > 0) {
            $kpis[] = [
                'label'    => 'Turnover',
                'value'    => number_format($current['inventory_turnover'], 2),
                'previous' => number_format($previous['inventory_turnover'], 2),
                'change'   => $this->pctChange($previous['inventory_turnover'], $current['inventory_turnover']),
                'icon'     => 'heroicon-o-arrow-path',
                'color'    => 'emerald',
                'source'   => $srcLabel,
            ];
        }

        return $kpis;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Findings & Revenue Chart
    // ─────────────────────────────────────────────────────────────────────────

    public function getFindingsSummary(): array
    {
        if (! $this->selectedClientId) return [];

        $base = Finding::where('client_id', $this->selectedClientId);

        $open = (clone $base)->whereIn('status', [
            FindingStatus::New->value,
            FindingStatus::Investigating->value,
            FindingStatus::Accepted->value,
        ])->count();

        $critical = (clone $base)->whereIn('status', [
            FindingStatus::New->value,
            FindingStatus::Investigating->value,
        ])->whereIn('severity', [
            FindingSeverity::Critical->value,
            FindingSeverity::High->value,
        ])->count();

        $resolved = (clone $base)->where('status', FindingStatus::Resolved->value)->count();

        $recentFindings = Finding::where('client_id', $this->selectedClientId)
            ->whereIn('status', [FindingStatus::New->value, FindingStatus::Investigating->value])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->limit(5)
            ->get();

        return [
            'open'     => $open,
            'critical' => $critical,
            'resolved' => $resolved,
            'recent'   => $recentFindings,
        ];
    }

    public function getRevenueChartData(): array
    {
        if (! $this->selectedClientId) return [];

        $days   = (int) $this->period;
        $active = $this->getActiveIntegrations();

        $rows = CommerceMetric::where('client_id', $this->selectedClientId)
            ->where('date', '>=', now()->subDays($days)->startOfDay())
            ->orderBy('date')
            ->get()
            ->groupBy(fn ($r) => $r->date->format('M j'));

        $labels = [];
        $ga4Data = [];
        $adobeData = [];

        foreach ($rows as $date => $records) {
            $labels[] = $date;
            $ga4Revenue = in_array('ga4', $active) ? $records->where('source', 'ga4')->sum('revenue') : 0;
            $adobeRevenue = in_array('adobe_commerce', $active) ? $records->where('source', 'adobe_commerce')->sum('revenue') : 0;
            $ga4Data[] = round($ga4Revenue, 2);
            $adobeData[] = round($adobeRevenue, 2);
        }

        $datasets = [];
        if (in_array('ga4', $active)) $datasets['ga4'] = $ga4Data;
        if (in_array('adobe_commerce', $active)) $datasets['adobe'] = $adobeData;

        return array_merge(['labels' => $labels], $datasets);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function getCommerceAggregates(string $source, int $days, int $offset = 0): array
    {
        $from = now()->subDays($days + $offset)->startOfDay();
        $to   = now()->subDays($offset)->endOfDay();

        $row = CommerceMetric::where('client_id', $this->selectedClientId)
            ->where('source', $source)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(sessions), 0) as sessions,
                COALESCE(SUM(revenue), 0) as revenue,
                COALESCE(SUM(orders), 0) as orders,
                COALESCE(SUM(new_customers), 0) as new_customers,
                COALESCE(SUM(returning_customers), 0) as returning_customers,
                COALESCE(SUM(items_sold), 0) as items_sold,
                CASE WHEN SUM(sessions) > 0
                    THEN (CAST(SUM(orders) AS DECIMAL(20,6)) / CAST(SUM(sessions) AS DECIMAL(20,6))) * 100
                    ELSE 0 END as conversion_rate,
                CASE WHEN SUM(orders) > 0
                    THEN CAST(SUM(revenue) AS DECIMAL(20,6)) / CAST(SUM(orders) AS DECIMAL(20,6))
                    ELSE 0 END as aov,
                CASE WHEN (SUM(new_customers) + SUM(returning_customers)) > 0
                    THEN (CAST(SUM(returning_customers) AS DECIMAL(20,6)) / CAST((SUM(new_customers) + SUM(returning_customers)) AS DECIMAL(20,6))) * 100
                    ELSE 0 END as return_rate
            ')
            ->first();

        return [
            'sessions'             => (int) ($row->sessions ?? 0),
            'revenue'              => (float) ($row->revenue ?? 0),
            'orders'               => (int) ($row->orders ?? 0),
            'new_customers'        => (int) ($row->new_customers ?? 0),
            'returning_customers'  => (int) ($row->returning_customers ?? 0),
            'items_sold'           => (int) ($row->items_sold ?? 0),
            'conversion_rate'      => (float) ($row->conversion_rate ?? 0),
            'aov'                  => (float) ($row->aov ?? 0),
            'return_rate'          => (float) ($row->return_rate ?? 0),
        ];
    }

    private function getBehavioralAggregates(int $days, int $offset = 0): array
    {
        $from = now()->subDays($days + $offset)->startOfDay();
        $to   = now()->subDays($offset)->endOfDay();

        $row = BehavioralMetric::where('client_id', $this->selectedClientId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(traffic), 0) as traffic,
                COALESCE(SUM(rage_clicks), 0) as rage_clicks,
                COALESCE(SUM(dead_clicks), 0) as dead_clicks,
                COALESCE(SUM(quick_backs), 0) as quick_backs,
                COALESCE(SUM(script_errors), 0) as script_errors,
                COALESCE(SUM(error_clicks), 0) as error_clicks,
                COALESCE(AVG(scroll_depth), 0) as scroll_depth,
                COALESCE(AVG(friction_score), 0) as friction_score,
                COALESCE(AVG(engagement_time), 0) as engagement_time
            ')
            ->first();

        return [
            'traffic'         => (int) ($row->traffic ?? 0),
            'rage_clicks'     => (int) ($row->rage_clicks ?? 0),
            'dead_clicks'     => (int) ($row->dead_clicks ?? 0),
            'quick_backs'     => (int) ($row->quick_backs ?? 0),
            'script_errors'   => (int) ($row->script_errors ?? 0),
            'error_clicks'    => (int) ($row->error_clicks ?? 0),
            'scroll_depth'    => (float) ($row->scroll_depth ?? 0),
            'friction_score'  => (float) ($row->friction_score ?? 0),
            'engagement_time' => (float) ($row->engagement_time ?? 0),
        ];
    }

    private function getPerformanceAggregates(int $days, int $offset = 0): array
    {
        $from = now()->subDays($days + $offset)->startOfDay();
        $to   = now()->subDays($offset)->endOfDay();

        $row = PerformanceMetric::where('client_id', $this->selectedClientId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(AVG(lcp), 0) as lcp,
                COALESCE(AVG(inp), 0) as inp,
                COALESCE(AVG(cls), 0) as cls,
                COALESCE(AVG(ttfb), 0) as ttfb,
                COALESCE(AVG(page_load_time), 0) as page_load_time,
                COALESCE(AVG(server_response_time), 0) as server_response_time,
                COALESCE(AVG(bounce_rate), 0) as bounce_rate,
                COALESCE(SUM(slow_pages_count), 0) as slow_pages_count
            ')
            ->first();

        return [
            'lcp'                  => (float) ($row->lcp ?? 0),
            'inp'                  => (float) ($row->inp ?? 0),
            'cls'                  => (float) ($row->cls ?? 0),
            'ttfb'                 => (float) ($row->ttfb ?? 0),
            'page_load_time'       => (float) ($row->page_load_time ?? 0),
            'server_response_time' => (float) ($row->server_response_time ?? 0),
            'bounce_rate'          => (float) ($row->bounce_rate ?? 0),
            'slow_pages_count'     => (int) ($row->slow_pages_count ?? 0),
        ];
    }

    private function getInventoryAggregates(int $days, string $source, int $offset = 0): array
    {
        $from = now()->subDays($days + $offset)->startOfDay();
        $to   = now()->subDays($offset)->endOfDay();

        // For inventory we take the latest snapshot in the period (not sum)
        $row = InventoryMetric::where('client_id', $this->selectedClientId)
            ->where('source', $source)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(AVG(total_products), 0) as total_products,
                COALESCE(AVG(in_stock_count), 0) as in_stock_count,
                COALESCE(AVG(out_of_stock_count), 0) as out_of_stock_count,
                COALESCE(AVG(low_stock_count), 0) as low_stock_count,
                COALESCE(AVG(out_of_stock_rate), 0) as out_of_stock_rate,
                COALESCE(AVG(low_stock_rate), 0) as low_stock_rate,
                COALESCE(AVG(inventory_turnover), 0) as inventory_turnover,
                COALESCE(SUM(backorder_count), 0) as backorder_count
            ')
            ->first();

        return [
            'total_products'     => (int) ($row->total_products ?? 0),
            'in_stock_count'     => (int) ($row->in_stock_count ?? 0),
            'out_of_stock_count' => (int) ($row->out_of_stock_count ?? 0),
            'low_stock_count'    => (int) ($row->low_stock_count ?? 0),
            'out_of_stock_rate'  => (float) ($row->out_of_stock_rate ?? 0),
            'low_stock_rate'     => (float) ($row->low_stock_rate ?? 0),
            'inventory_turnover' => (float) ($row->inventory_turnover ?? 0),
            'backorder_count'    => (int) ($row->backorder_count ?? 0),
        ];
    }

    private function pctChange(float $previous, float $current): ?float
    {
        if ($previous == 0) return $current > 0 ? 100.0 : null;
        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
