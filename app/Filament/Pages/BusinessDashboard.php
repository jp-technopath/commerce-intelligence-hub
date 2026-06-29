<?php

namespace App\Filament\Pages;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\BehavioralMetric;
use App\Models\PerformanceMetric;
use App\Models\InventoryMetric;
use App\Models\EmailMarketingMetric;
use App\Models\Finding;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

class BusinessDashboard extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar-square';
    protected static ?string $navigationLabel = 'Business Dashboard';
    protected static ?string $navigationGroup = 'Dashboard';
    protected static ?string $title           = 'Business Dashboard';
    protected static ?string $slug            = 'dashboard/business';
    protected static ?int    $navigationSort  = -1;

    protected static string $view = 'filament.pages.business-dashboard';

    #[Url(keep: true)]
    public ?int $selectedClientId = null;

    #[Url(keep: true)]
    public string $period = '7'; // days

    public function mount(): void
    {
        // URL params (from #[Url]) take priority; fall back to session, then default
        if (! $this->selectedClientId) {
            $this->selectedClientId = session('dashboard.selectedClientId', Client::first()?->id);
        }
        if ($this->period === '7' && session()->has('dashboard.period')) {
            $this->period = session('dashboard.period');
        }

        // Persist current values to session
        session(['dashboard.selectedClientId' => $this->selectedClientId]);
        session(['dashboard.period' => $this->period]);
    }

    public function getClients(): \Illuminate\Support\Collection
    {
        return Client::orderBy('name')->pluck('name', 'id');
    }

    public function updatedSelectedClientId(): void
    {
        session(['dashboard.selectedClientId' => $this->selectedClientId]);
    }

    public function updatedPeriod(): void
    {
        session(['dashboard.period' => $this->period]);
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
     * Conversion: Conversion Rate (Adobe Orders ÷ GA4 Unique Users),
     *             Orders (Adobe), Items Sold (Adobe)
     */
    public function getConversionKpis(): array
    {
        if (! $this->selectedClientId) return [];

        $active = $this->getActiveIntegrations();
        $days   = (int) $this->period;
        $kpis   = [];

        // Conversion Rate = Adobe Orders ÷ GA4 Unique Users
        $hasGA4   = in_array('ga4', $active);
        $hasAdobe = in_array('adobe_commerce', $active);

        if ($hasGA4) {
            $ga4Current  = $this->getCommerceAggregates('ga4', $days);
            $ga4Previous = $this->getCommerceAggregates('ga4', $days, offset: $days);
        }

        if ($hasAdobe) {
            $adobeCurrent  = $this->getCommerceAggregates('adobe_commerce', $days);
            $adobePrevious = $this->getCommerceAggregates('adobe_commerce', $days, offset: $days);
        }

        if ($hasGA4 && $hasAdobe) {
            $currentUsers  = $ga4Current['active_users'];
            $previousUsers = $ga4Previous['active_users'];

            $currentRate  = $currentUsers > 0
                ? ($adobeCurrent['orders'] / $currentUsers) * 100
                : 0;
            $previousRate = $previousUsers > 0
                ? ($adobePrevious['orders'] / $previousUsers) * 100
                : 0;

            $kpis[] = [
                'label'    => 'Conversion Rate',
                'value'    => number_format($currentRate, 2) . '%',
                'previous' => number_format($previousRate, 2) . '%',
                'change'   => $this->pctChange($previousRate, $currentRate),
                'icon'     => 'heroicon-o-arrow-trending-up',
                'color'    => 'violet',
                'source'   => 'GA4 + Adobe',
            ];
        } elseif ($hasGA4) {
            // Fallback: GA4-only session conversion rate
            $kpis[] = [
                'label'    => 'Conversion Rate',
                'value'    => number_format($ga4Current['conversion_rate'], 2) . '%',
                'previous' => number_format($ga4Previous['conversion_rate'], 2) . '%',
                'change'   => $this->pctChange($ga4Previous['conversion_rate'], $ga4Current['conversion_rate']),
                'icon'     => 'heroicon-o-arrow-trending-up',
                'color'    => 'violet',
                'source'   => 'GA4',
            ];
        }

        if ($hasAdobe) {
            $kpis[] = [
                'label'    => 'Orders',
                'value'    => number_format($adobeCurrent['orders']),
                'previous' => number_format($adobePrevious['orders']),
                'change'   => $this->pctChange($adobePrevious['orders'], $adobeCurrent['orders']),
                'icon'     => 'heroicon-o-shopping-cart',
                'color'    => 'orange',
                'source'   => 'Adobe',
            ];
            $kpis[] = [
                'label'    => 'Items Sold',
                'value'    => number_format($adobeCurrent['items_sold']),
                'previous' => number_format($adobePrevious['items_sold']),
                'change'   => $this->pctChange($adobePrevious['items_sold'], $adobeCurrent['items_sold']),
                'icon'     => 'heroicon-o-cube',
                'color'    => 'rose',
                'source'   => 'Adobe',
            ];
        }

        return $kpis;
    }

    /**
     * Purchase Journey Funnel: View Product → Add to Cart → Begin Checkout → Purchase
     */
    public function getPurchaseFunnelData(): array
    {
        if (! $this->selectedClientId) return [];

        $active = $this->getActiveIntegrations();
        if (! in_array('ga4', $active)) return [];

        $days = (int) $this->period;
        $from = now()->subDays($days)->startOfDay();
        $to   = now()->endOfDay();

        $metrics = CommerceMetric::where('client_id', $this->selectedClientId)
            ->where('source', 'ga4')
            ->whereBetween('date', [$from, $to])
            ->whereNotNull('metadata_json')
            ->get();

        $totals = [
            'view_item'      => 0,
            'add_to_cart'    => 0,
            'begin_checkout' => 0,
            'purchase'       => 0,
        ];

        foreach ($metrics as $m) {
            $funnel = ($m->metadata_json ?? [])['funnel'] ?? null;
            if (! $funnel) continue;

            foreach ($totals as $key => &$val) {
                $val += (int) ($funnel[$key] ?? 0);
            }
            unset($val);
        }

        // If no funnel data yet, return empty
        if ($totals['view_item'] === 0) return [];

        $stageConfig = [
            ['key' => 'view_item',      'label' => 'View Product',   'color' => '#6366f1'],
            ['key' => 'add_to_cart',    'label' => 'Add to Cart',    'color' => '#8b5cf6'],
            ['key' => 'begin_checkout', 'label' => 'Begin Checkout', 'color' => '#a855f7'],
            ['key' => 'purchase',       'label' => 'Purchase',       'color' => '#10b981'],
        ];

        $stages = [];
        $prevCount = null;

        foreach ($stageConfig as $cfg) {
            $count = $totals[$cfg['key']];
            $dropOff     = $prevCount !== null && $prevCount > 0
                ? round((1 - $count / $prevCount) * 100, 1)
                : 0;
            $passThrough = $prevCount !== null && $prevCount > 0
                ? round(($count / $prevCount) * 100, 1)
                : 100;

            $stages[] = [
                'label'        => $cfg['label'],
                'count'        => $count,
                'color'        => $cfg['color'],
                'drop_off'     => $dropOff,
                'pass_through' => $passThrough,
            ];

            $prevCount = $count;
        }

        $overallRate = $totals['view_item'] > 0
            ? round(($totals['purchase'] / $totals['view_item']) * 100, 2)
            : 0;

        return [
            'stages'       => $stages,
            'overall_rate' => $overallRate,
        ];
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
        $hasPerf = in_array('ga4', $active) || in_array('clarity', $active) || in_array('new_relic', $active);
        if (! $hasPerf) return [];

        $days     = (int) $this->period;
        $current  = $this->getPerformanceAggregates($days);
        $previous = $this->getPerformanceAggregates($days, offset: $days);

        $kpis = [];

        // Core Web Vitals (GA4)
        if ($current['lcp'] > 0 || $previous['lcp'] > 0) {
            $kpis[] = [
                'label'    => 'LCP',
                'value'    => number_format($current['lcp'], 2) . 's',
                'previous' => number_format($previous['lcp'], 2) . 's',
                'change'   => $this->pctChange($previous['lcp'], $current['lcp']),
                'icon'     => 'heroicon-o-clock',
                'color'    => 'violet',
                'invert'   => true,
                'source'   => 'GA4',
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

        // Page Load Time (New Relic → seconds)
        if ($current['page_load_time'] > 0 || $previous['page_load_time'] > 0) {
            $kpis[] = [
                'label'    => 'Page Load',
                'value'    => number_format($current['page_load_time'], 2) . 's',
                'previous' => number_format($previous['page_load_time'], 2) . 's',
                'change'   => $this->pctChange($previous['page_load_time'], $current['page_load_time']),
                'icon'     => 'heroicon-o-bolt',
                'color'    => 'orange',
                'invert'   => true,
                'source'   => $current['has_new_relic'] ? 'New Relic' : 'GA4',
            ];
        }

        // Server Response Time (New Relic → seconds)
        if ($current['server_response_time'] > 0 || $previous['server_response_time'] > 0) {
            $kpis[] = [
                'label'    => 'Response Time',
                'value'    => number_format($current['server_response_time'], 2) . 's',
                'previous' => number_format($previous['server_response_time'], 2) . 's',
                'change'   => $this->pctChange($previous['server_response_time'], $current['server_response_time']),
                'icon'     => 'heroicon-o-server',
                'color'    => 'sky',
                'invert'   => true,
                'source'   => 'New Relic',
            ];
        }

        // TTFB (New Relic → seconds)
        if ($current['ttfb'] > 0 || $previous['ttfb'] > 0) {
            $kpis[] = [
                'label'    => 'TTFB',
                'value'    => number_format($current['ttfb'], 2) . 's',
                'previous' => number_format($previous['ttfb'], 2) . 's',
                'change'   => $this->pctChange($previous['ttfb'], $current['ttfb']),
                'icon'     => 'heroicon-o-signal',
                'color'    => 'cyan',
                'invert'   => true,
                'source'   => 'New Relic',
            ];
        }

        // Apdex Score (New Relic — higher is better)
        if ($current['apdex'] > 0 || $previous['apdex'] > 0) {
            $kpis[] = [
                'label'    => 'Apdex',
                'value'    => number_format($current['apdex'], 2),
                'previous' => number_format($previous['apdex'], 2),
                'change'   => $this->pctChange($previous['apdex'], $current['apdex']),
                'icon'     => 'heroicon-o-shield-check',
                'color'    => 'emerald',
                'source'   => 'New Relic',
            ];
        }

        // Throughput (New Relic)
        if ($current['throughput'] > 0 || $previous['throughput'] > 0) {
            $kpis[] = [
                'label'    => 'Throughput',
                'value'    => number_format($current['throughput']),
                'previous' => number_format($previous['throughput']),
                'change'   => $this->pctChange($previous['throughput'], $current['throughput']),
                'icon'     => 'heroicon-o-arrow-trending-up',
                'color'    => 'blue',
                'source'   => 'New Relic',
            ];
        }

        // Error Rate (New Relic — lower is better)
        if ($current['error_rate'] > 0 || $previous['error_rate'] > 0) {
            $kpis[] = [
                'label'    => 'Error Rate',
                'value'    => number_format($current['error_rate'] * 100, 2) . '%',
                'previous' => number_format($previous['error_rate'] * 100, 2) . '%',
                'change'   => $this->pctChange($previous['error_rate'], $current['error_rate']),
                'icon'     => 'heroicon-o-bug-ant',
                'color'    => 'red',
                'invert'   => true,
                'source'   => 'New Relic',
            ];
        }

        // Bounce Rate (GA4/Clarity)
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

    /**
     * Email Marketing: Flow summary, rates, revenue, engagement (Klaviyo)
     */
    public function getEmailMarketingKpis(): array
    {
        if (! $this->selectedClientId) return [];

        $active = $this->getActiveIntegrations();
        if (! in_array('klaviyo', $active)) return [];

        $days     = (int) $this->period;
        $current  = $this->getEmailMarketingAggregates($days);
        $previous = $this->getEmailMarketingAggregates($days, offset: $days);

        $kpis = [];

        // ── High-level flow summary ──────────────────────────────────────

        $kpis[] = [
            'label'    => 'Active Flows',
            'value'    => number_format($current['active_flows']),
            'previous' => number_format($previous['active_flows']),
            'change'   => $this->pctChange($previous['active_flows'], $current['active_flows']),
            'icon'     => 'heroicon-o-arrows-right-left',
            'color'    => 'violet',
            'source'   => 'Klaviyo',
        ];

        $kpis[] = [
            'label'    => 'Emails Sent',
            'value'    => number_format($current['recipients']),
            'previous' => number_format($previous['recipients']),
            'change'   => $this->pctChange($previous['recipients'], $current['recipients']),
            'icon'     => 'heroicon-o-paper-airplane',
            'color'    => 'blue',
            'source'   => 'Klaviyo',
        ];

        $kpis[] = [
            'label'    => 'Klaviyo Conversion Value',
            'value'    => '$' . number_format($current['revenue'], 2),
            'previous' => '$' . number_format($previous['revenue'], 2),
            'change'   => $this->pctChange($previous['revenue'], $current['revenue']),
            'icon'     => 'heroicon-o-banknotes',
            'color'    => 'emerald',
            'source'   => 'Klaviyo',
        ];

        // ── GA4 Email Revenue (last-click attribution) ───────────────────
        if (in_array('ga4', $active)) {
            $ga4Current  = $this->getGA4EmailChannelRevenue($days);
            $ga4Previous = $this->getGA4EmailChannelRevenue($days, offset: $days);

            $kpis[] = [
                'label'    => 'GA4 Email Revenue',
                'value'    => '$' . number_format($ga4Current['revenue'], 2),
                'previous' => '$' . number_format($ga4Previous['revenue'], 2),
                'change'   => $this->pctChange($ga4Previous['revenue'], $ga4Current['revenue']),
                'icon'     => 'heroicon-o-chart-bar',
                'color'    => 'teal',
                'source'   => 'GA4',
            ];
        }

        // ── Engagement metrics ───────────────────────────────────────────

        if ($current['opens'] > 0 || $previous['opens'] > 0) {
            $kpis[] = [
                'label'    => 'Total Opens',
                'value'    => number_format($current['opens']),
                'previous' => number_format($previous['opens']),
                'change'   => $this->pctChange($previous['opens'], $current['opens']),
                'icon'     => 'heroicon-o-envelope-open',
                'color'    => 'sky',
                'source'   => 'Klaviyo',
            ];
        }

        if ($current['clicks'] > 0 || $previous['clicks'] > 0) {
            $kpis[] = [
                'label'    => 'Total Clicks',
                'value'    => number_format($current['clicks']),
                'previous' => number_format($previous['clicks']),
                'change'   => $this->pctChange($previous['clicks'], $current['clicks']),
                'icon'     => 'heroicon-o-cursor-arrow-rays',
                'color'    => 'blue',
                'source'   => 'Klaviyo',
            ];
        }

        // ── Rate cards ───────────────────────────────────────────────────

        if ($current['open_rate'] > 0 || $previous['open_rate'] > 0) {
            $kpis[] = [
                'label'    => 'Open Rate',
                'value'    => number_format($current['open_rate'], 1) . '%',
                'previous' => number_format($previous['open_rate'], 1) . '%',
                'change'   => $this->pctChange($previous['open_rate'], $current['open_rate']),
                'icon'     => 'heroicon-o-chart-bar',
                'color'    => 'emerald',
                'source'   => 'Klaviyo',
            ];
        }

        if ($current['click_rate'] > 0 || $previous['click_rate'] > 0) {
            $kpis[] = [
                'label'    => 'Click Rate',
                'value'    => number_format($current['click_rate'], 1) . '%',
                'previous' => number_format($previous['click_rate'], 1) . '%',
                'change'   => $this->pctChange($previous['click_rate'], $current['click_rate']),
                'icon'     => 'heroicon-o-arrow-top-right-on-square',
                'color'    => 'blue',
                'source'   => 'Klaviyo',
            ];
        }

        // ── Health signals (inverted — increase is bad) ──────────────────

        if ($current['unsubscribes'] > 0 || $previous['unsubscribes'] > 0) {
            $kpis[] = [
                'label'    => 'Unsubscribes',
                'value'    => number_format($current['unsubscribes']),
                'previous' => number_format($previous['unsubscribes']),
                'change'   => $this->pctChange($previous['unsubscribes'], $current['unsubscribes']),
                'icon'     => 'heroicon-o-user-minus',
                'color'    => 'rose',
                'invert'   => true,
                'source'   => 'Klaviyo',
            ];
        }

        if ($current['bounces'] > 0 || $previous['bounces'] > 0) {
            $kpis[] = [
                'label'    => 'Bounces',
                'value'    => number_format($current['bounces']),
                'previous' => number_format($previous['bounces']),
                'change'   => $this->pctChange($previous['bounces'], $current['bounces']),
                'icon'     => 'heroicon-o-arrow-uturn-left',
                'color'    => 'amber',
                'invert'   => true,
                'source'   => 'Klaviyo',
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

    /**
     * Extract email channel revenue from GA4 source_breakdown_json.
     * Uses GA4's last-click, session-based attribution.
     */
    private function getGA4EmailChannelRevenue(int $days, int $offset = 0): array
    {
        $from = now()->subDays($days + $offset)->startOfDay();
        $to   = now()->subDays($offset)->endOfDay();

        $metrics = CommerceMetric::where('client_id', $this->selectedClientId)
            ->where('source', 'ga4')
            ->whereBetween('date', [$from, $to])
            ->whereNotNull('source_breakdown_json')
            ->get();

        $revenue      = 0.0;
        $transactions = 0;
        $sessions     = 0;

        foreach ($metrics as $m) {
            $breakdown = $m->source_breakdown_json ?? [];
            $email     = $breakdown['email'] ?? $breakdown['Email'] ?? null;
            if ($email) {
                $revenue      += (float) ($email['revenue'] ?? 0);
                $transactions += (int)   ($email['transactions'] ?? 0);
                $sessions     += (int)   ($email['sessions'] ?? 0);
            }
        }

        return [
            'revenue'      => $revenue,
            'transactions' => $transactions,
            'sessions'     => $sessions,
        ];
    }

    private function getCommerceAggregates(string $source, int $days, int $offset = 0): array
    {
        $from = now()->subDays($days + $offset)->startOfDay();
        $to   = now()->subDays($offset)->endOfDay();

        $row = CommerceMetric::where('client_id', $this->selectedClientId)
            ->where('source', $source)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(sessions), 0) as sessions,
                COALESCE(SUM(active_users), 0) as active_users,
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
            'active_users'         => (int) ($row->active_users ?? 0),
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
                COALESCE(AVG(NULLIF(ttfb, 0)), 0) as ttfb,
                COALESCE(AVG(NULLIF(page_load_time, 0)), 0) as page_load_time,
                COALESCE(AVG(NULLIF(server_response_time, 0)), 0) as server_response_time,
                COALESCE(AVG(bounce_rate), 0) as bounce_rate,
                COALESCE(SUM(slow_pages_count), 0) as slow_pages_count
            ')
            ->first();

        // NR connector stored seconds×1000² (µs) — divide by 1,000,000 to get seconds
        $pageLoadUs       = (float) ($row->page_load_time ?? 0);
        $serverResponseUs = (float) ($row->server_response_time ?? 0);
        $ttfbUs           = (float) ($row->ttfb ?? 0);

        // Also pull New Relic metadata averages (apdex, throughput, error_rate)
        $nrMeta = PerformanceMetric::where('client_id', $this->selectedClientId)
            ->where('source', 'new_relic')
            ->whereBetween('date', [$from, $to])
            ->get();

        $apdex      = $nrMeta->avg(fn ($r) => $r->metadata_json['apdex'] ?? 0) ?: 0;
        $throughput  = $nrMeta->sum(fn ($r) => $r->metadata_json['throughput'] ?? 0) ?: 0;
        $errorRate   = $nrMeta->avg(fn ($r) => $r->metadata_json['error_rate'] ?? 0) ?: 0;
        $errorCount  = $nrMeta->sum(fn ($r) => $r->metadata_json['error_count'] ?? 0) ?: 0;

        return [
            'lcp'                  => (float) ($row->lcp ?? 0),
            'inp'                  => (float) ($row->inp ?? 0),
            'cls'                  => (float) ($row->cls ?? 0),
            'ttfb'                 => $ttfbUs / 1000000,           // µs → seconds
            'page_load_time'       => $pageLoadUs / 1000000,       // µs → seconds
            'server_response_time' => $serverResponseUs / 1000000, // µs → seconds
            'bounce_rate'          => (float) ($row->bounce_rate ?? 0),
            'slow_pages_count'     => (int) ($row->slow_pages_count ?? 0),
            'apdex'                => (float) $apdex,
            'throughput'           => (int) $throughput,
            'error_rate'           => (float) $errorRate,
            'error_count'          => (int) $errorCount,
            'has_new_relic'        => $nrMeta->count() > 0,
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

    private function getEmailMarketingAggregates(int $days, int $offset = 0): array
    {
        $from = now()->subDays($days + $offset)->startOfDay();
        $to   = now()->subDays($offset)->endOfDay();

        $row = EmailMarketingMetric::where('client_id', $this->selectedClientId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(AVG(open_rate), 0) as open_rate,
                COALESCE(AVG(click_rate), 0) as click_rate,
                COALESCE(SUM(conversions), 0) as conversions,
                COALESCE(SUM(revenue), 0) as revenue,
                COALESCE(SUM(unsubscribes), 0) as unsubscribes,
                COALESCE(SUM(bounces), 0) as bounces,
                COALESCE(SUM(recipients), 0) as recipients,
                COALESCE(SUM(opens), 0) as opens,
                COALESCE(SUM(clicks), 0) as clicks,
                COUNT(DISTINCT campaign_name) as active_flows
            ')
            ->first();

        return [
            'open_rate'    => (float) ($row->open_rate ?? 0),
            'click_rate'   => (float) ($row->click_rate ?? 0),
            'conversions'  => (int) ($row->conversions ?? 0),
            'revenue'      => (float) ($row->revenue ?? 0),
            'unsubscribes' => (int) ($row->unsubscribes ?? 0),
            'bounces'      => (int) ($row->bounces ?? 0),
            'recipients'   => (int) ($row->recipients ?? 0),
            'opens'        => (int) ($row->opens ?? 0),
            'clicks'       => (int) ($row->clicks ?? 0),
            'active_flows' => (int) ($row->active_flows ?? 0),
        ];
    }

    private function pctChange(float $previous, float $current): ?float
    {
        if ($previous == 0) return $current > 0 ? 100.0 : null;
        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
