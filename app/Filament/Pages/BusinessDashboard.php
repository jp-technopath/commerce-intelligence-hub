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
use App\Services\Metrics\CommerceRevenueCalculator;
use App\Services\Metrics\DataQualityEvaluator;
use App\Services\Metrics\KpiMetadataRegistry;
use App\Services\Metrics\RepeatCustomerCalculator;
use App\Services\Metrics\RevenueReconciler;
use App\Services\Metrics\UserParticipationFunnelCalculator;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

class BusinessDashboard extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar-square';
    protected static ?string $navigationLabel = 'Business Dashboard';
    protected static ?string $navigationGroup = 'Dashboard';
    protected static ?string $title           = 'Business Dashboard';
    protected static ?string $slug            = 'dashboard/business';
    protected static ?int    $navigationSort  = 2;

    protected static string $view = 'filament.pages.business-dashboard';

    #[Url(keep: true)]
    public ?int $selectedClientId = null;

    #[Url(keep: true)]
    public string $period = '7'; // days

    private KpiMetadataRegistry $metadataRegistry;

    public function boot(): void
    {
        $this->metadataRegistry = new KpiMetadataRegistry();
    }

    public function mount(): void
    {
        $allowedClients = $this->getClients();

        if (! $this->selectedClientId || ! array_key_exists($this->selectedClientId, $allowedClients)) {
            $this->selectedClientId = array_key_first($allowedClients);
        }

        if ($this->period === '7' && session()->has('dashboard.period')) {
            $this->period = session('dashboard.period');
        }

        if ($this->selectedClientId) {
            session([
                'dashboard.selectedClientId' => $this->selectedClientId,
                'dashboard.period'           => $this->period,
            ]);
        }
    }

    public function updatedSelectedClientId($value): void
    {
        session(['dashboard.selectedClientId' => $value]);
    }

    public function updatedPeriod($value): void
    {
        session(['dashboard.period' => $value]);
    }

    public function getClients(): array
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();
        $query = Client::orderBy('name');

        if ($user && ! $user->isSuperAdmin()) {
            $assignedClientIds = $user->getAssignedClientIds();
            if (! in_array('*', $assignedClientIds)) {
                $query->whereIn('id', $assignedClientIds);
            }
        }

        return $query->pluck('name', 'id')->toArray();
    }

    public function getClientsProperty(): array
    {
        return $this->getClients();
    }

    public function getRevenueChartData(): array
    {
        $client = $this->getSelectedClientProperty();
        if (! $client) return ['labels' => [], 'datasets' => [], 'ga4' => [], 'adobe' => []];

        $days = (int) $this->period;
        $from = now()->subDays($days)->startOfDay();
        $to   = now()->endOfDay();

        $dates = [];
        $ga4Revenue = [];
        $adobeRevenue = [];

        $metrics = CommerceMetric::where('client_id', $client->id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->groupBy(fn ($m) => \Carbon\Carbon::parse($m->date)->format('Y-m-d'));

        foreach ($metrics as $date => $group) {
            $dates[] = \Carbon\Carbon::parse($date)->format('M j');
            $ga4     = $group->where('source', 'ga4')->first();
            $adobe   = $group->where('source', 'adobe_commerce')->first();

            $ga4Revenue[]   = $ga4 ? (float) $ga4->revenue : 0.0;
            $adobeRevenue[] = $adobe ? (float) $adobe->revenue : 0.0;
        }

        return [
            'labels'   => $dates,
            'ga4'      => $ga4Revenue,
            'adobe'    => $adobeRevenue,
            'datasets' => [
                ['label' => 'GA4 Tracked Revenue', 'data' => $ga4Revenue, 'borderColor' => '#6366f1'],
                ['label' => 'Adobe Valid Order Revenue', 'data' => $adobeRevenue, 'borderColor' => '#f97316'],
            ],
        ];
    }

    public function getFindingsSummary(): array
    {
        $client = $this->getSelectedClientProperty();
        if (! $client) return ['total' => 0, 'critical' => 0, 'open' => 0, 'resolved' => 0, 'recent' => collect()];

        $allFindings  = Finding::where('client_id', $client->id)->get();
        $openFindings = $allFindings->reject(fn ($f) => in_array($f->status, [FindingStatus::Resolved, FindingStatus::Ignored]));

        return [
            'total'    => $allFindings->count(),
            'critical' => $openFindings->where('severity', FindingSeverity::Critical)->count(),
            'open'     => $openFindings->count(),
            'resolved' => $allFindings->where('status', FindingStatus::Resolved)->count(),
            'recent'   => Finding::where('client_id', $client->id)->latest()->take(5)->get(),
        ];
    }

    public function getSelectedClientProperty(): ?Client
    {
        if (! $this->selectedClientId) return null;
        return Client::find($this->selectedClientId);
    }

    public function setPeriod(string $days): void
    {
        $this->period = $days;
        session(['dashboard.period' => $days]);
    }

    /**
     * Conversion: Commerce Conversion Rate (Unique Buyers ÷ GA4 Active Users),
     *             Repeat Customer Rate, Orders, Items Sold
     */
    public function getConversionKpis(): array
    {
        $client = $this->getSelectedClientProperty();
        if (! $client) return [];

        $active = $this->getActiveIntegrations();
        $days   = (int) $this->period;
        $fromCur  = now()->subDays($days)->startOfDay();
        $toCur    = now()->endOfDay();
        $fromPrev = now()->subDays($days * 2)->startOfDay();
        $toPrev   = now()->subDays($days + 1)->endOfDay();

        $kpis   = [];
        $hasGA4   = in_array('ga4', $active);
        $hasAdobe = in_array('adobe_commerce', $active);

        $revCalc = new CommerceRevenueCalculator();
        $curRev  = $revCalc->calculate($client, $fromCur, $toCur);
        $prevRev = $revCalc->calculate($client, $fromPrev, $toPrev);

        if ($hasGA4) {
            $ga4Current  = $this->getCommerceAggregates('ga4', $days);
            $ga4Previous = $this->getCommerceAggregates('ga4', $days, offset: $days);
        }

        if ($hasGA4 && $hasAdobe) {
            $curUsers  = $ga4Current['active_users'];
            $prevUsers = $ga4Previous['active_users'];

            $curRate  = $curUsers > 0 ? ($curRev['unique_purchasing_customers'] / $curUsers) * 100 : 0.0;
            $prevRate = $prevUsers > 0 ? ($prevRev['unique_purchasing_customers'] / $prevUsers) * 100 : 0.0;

            $kpis[] = [
                'label'    => 'Commerce Conversion Rate',
                'value'    => number_format($curRate, 2) . '%',
                'previous' => number_format($prevRate, 2) . '%',
                'change'   => $this->pctChange($prevRate, $curRate),
                'icon'     => 'heroicon-o-arrow-trending-up',
                'color'    => 'violet',
                'source'   => 'GA4 + Adobe',
                'metadata' => $this->metadataRegistry->getMetadata('commerce_conversion_rate', $client),
            ];
        } elseif ($hasGA4) {
            // Fallback: GA4-only session conversion rate
            $kpis[] = [
                'label'    => 'GA4 Purchase Session Conversion Rate',
                'value'    => number_format($ga4Current['conversion_rate'], 2) . '%',
                'previous' => number_format($ga4Previous['conversion_rate'], 2) . '%',
                'change'   => $this->pctChange($ga4Previous['conversion_rate'], $ga4Current['conversion_rate']),
                'icon'     => 'heroicon-o-arrow-trending-up',
                'color'    => 'violet',
                'source'   => 'GA4 (Fallback)',
                'metadata' => $this->metadataRegistry->getMetadata('commerce_conversion_rate', $client, ['known_limitations' => 'Session-level fallback metric.']),
            ];
        }

        if ($hasAdobe) {
            $repCalc = new RepeatCustomerCalculator();
            $curRep  = $repCalc->calculate($client, $fromCur, $toCur);
            $prevRep = $repCalc->calculate($client, $fromPrev, $toPrev);

            $kpis[] = [
                'label'    => 'Repeat Customer Rate',
                'value'    => number_format($curRep['repeat_customer_rate'], 1) . '%',
                'previous' => number_format($prevRep['repeat_customer_rate'], 1) . '%',
                'change'   => $this->pctChange($prevRep['repeat_customer_rate'], $curRep['repeat_customer_rate']),
                'icon'     => 'heroicon-o-user-group',
                'color'    => 'teal',
                'source'   => 'Adobe',
                'metadata' => $this->metadataRegistry->getMetadata('repeat_customer_rate', $client),
            ];

            $kpis[] = [
                'label'    => 'Orders',
                'value'    => number_format($curRev['valid_orders']),
                'previous' => number_format($prevRev['valid_orders']),
                'change'   => $this->pctChange($prevRev['valid_orders'], $curRev['valid_orders']),
                'icon'     => 'heroicon-o-shopping-cart',
                'color'    => 'orange',
                'source'   => 'Adobe',
            ];

            $kpis[] = [
                'label'    => 'Items Sold',
                'value'    => number_format($this->getCommerceAggregates('adobe_commerce', $days)['items_sold']),
                'previous' => number_format($this->getCommerceAggregates('adobe_commerce', $days, offset: $days)['items_sold']),
                'change'   => $this->pctChange(
                    $this->getCommerceAggregates('adobe_commerce', $days, offset: $days)['items_sold'],
                    $this->getCommerceAggregates('adobe_commerce', $days)['items_sold']
                ),
                'icon'     => 'heroicon-o-cube',
                'color'    => 'rose',
                'source'   => 'Adobe',
            ];
        }

        return $kpis;
    }

    /**
     * User Participation Funnel
     */
    public function getPurchaseFunnelData(): array
    {
        $client = $this->getSelectedClientProperty();
        if (! $client) return [];

        $active = $this->getActiveIntegrations();
        if (! in_array('ga4', $active)) return [];

        $days = (int) $this->period;
        $from = now()->subDays($days)->startOfDay();
        $to   = now()->endOfDay();

        $calc = new UserParticipationFunnelCalculator();
        return $calc->calculate($client, $from, $to);
    }

    /**
     * Revenue: GA4 Tracked Purchase Revenue, Adobe Valid Order Revenue, Adobe AOV, Revenue per Visitor
     */
    public function getRevenueKpis(): array
    {
        $client = $this->getSelectedClientProperty();
        if (! $client) return [];

        $active = $this->getActiveIntegrations();
        $days   = (int) $this->period;
        $fromCur  = now()->subDays($days)->startOfDay();
        $toCur    = now()->endOfDay();
        $fromPrev = now()->subDays($days * 2)->startOfDay();
        $toPrev   = now()->subDays($days + 1)->endOfDay();

        $kpis   = [];
        $revCalc = new CommerceRevenueCalculator();
        $curRev  = $revCalc->calculate($client, $fromCur, $toCur);
        $prevRev = $revCalc->calculate($client, $fromPrev, $toPrev);

        if (in_array('ga4', $active)) {
            $ga4Current  = $this->getCommerceAggregates('ga4', $days);
            $ga4Previous = $this->getCommerceAggregates('ga4', $days, offset: $days);

            $kpis[] = [
                'label'    => 'GA4 Tracked Purchase Revenue',
                'value'    => '$' . number_format($ga4Current['revenue'], 2),
                'previous' => '$' . number_format($ga4Previous['revenue'], 2),
                'change'   => $this->pctChange($ga4Previous['revenue'], $ga4Current['revenue']),
                'icon'     => 'heroicon-o-banknotes',
                'color'    => 'emerald',
                'source'   => 'GA4',
                'metadata' => $this->metadataRegistry->getMetadata('ga4_tracked_purchase_revenue', $client),
            ];
        }

        if (in_array('adobe_commerce', $active)) {
            $kpis[] = [
                'label'    => 'Adobe Valid Order Revenue',
                'value'    => '$' . number_format($curRev['net_revenue'], 2),
                'previous' => '$' . number_format($prevRev['net_revenue'], 2),
                'change'   => $this->pctChange($prevRev['net_revenue'], $curRev['net_revenue']),
                'icon'     => 'heroicon-o-banknotes',
                'color'    => 'emerald',
                'source'   => 'Adobe',
                'metadata' => $this->metadataRegistry->getMetadata('adobe_valid_order_revenue', $client),
            ];

            $kpis[] = [
                'label'    => 'Adobe AOV',
                'value'    => '$' . number_format($curRev['aov'], 2),
                'previous' => '$' . number_format($prevRev['aov'], 2),
                'change'   => $this->pctChange($prevRev['aov'], $curRev['aov']),
                'icon'     => 'heroicon-o-receipt-percent',
                'color'    => 'amber',
                'source'   => 'Adobe',
                'metadata' => $this->metadataRegistry->getMetadata('adobe_aov', $client),
            ];
        }

        if (in_array('ga4', $active) && in_array('adobe_commerce', $active)) {
            $curUsers  = $ga4Current['active_users'] ?? 0;
            $prevUsers = $ga4Previous['active_users'] ?? 0;

            $curRpv  = $curUsers > 0 ? $curRev['net_revenue'] / $curUsers : 0.0;
            $prevRpv = $prevUsers > 0 ? $prevRev['net_revenue'] / $prevUsers : 0.0;

            $kpis[] = [
                'label'    => 'Revenue per Visitor',
                'value'    => '$' . number_format($curRpv, 2),
                'previous' => '$' . number_format($prevRpv, 2),
                'change'   => $this->pctChange($prevRpv, $curRpv),
                'icon'     => 'heroicon-o-currency-dollar',
                'color'    => 'blue',
                'source'   => 'GA4 + Adobe',
                'metadata' => $this->metadataRegistry->getMetadata('revenue_per_visitor', $client),
            ];
        }

        return $kpis;
    }

    /**
     * Acquisition: Sessions, New Users, Returning Visitor Rate, Traffic
     */
    public function getAcquisitionKpis(): array
    {
        $client = $this->getSelectedClientProperty();
        if (! $client) return [];

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
                'icon'     => 'heroicon-o-globe-alt',
                'color'    => 'indigo',
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

            $kpis[] = [
                'label'    => 'Returning Visitor Rate',
                'value'    => number_format($current['return_rate'], 1) . '%',
                'previous' => number_format($previous['return_rate'], 1) . '%',
                'change'   => $this->pctChange($previous['return_rate'], $current['return_rate']),
                'icon'     => 'heroicon-o-arrow-path',
                'color'    => 'teal',
                'source'   => 'GA4',
            ];
        }

        if (in_array('clarity', $active)) {
            $current  = $this->getBehavioralAggregates($days);
            $previous = $this->getBehavioralAggregates($days, offset: $days);

            $kpis[] = [
                'label'    => 'Clarity Sessions',
                'value'    => number_format($current['total_sessions']),
                'previous' => number_format($previous['total_sessions']),
                'change'   => $this->pctChange($previous['total_sessions'], $current['total_sessions']),
                'icon'     => 'heroicon-o-cursor-arrow-rays',
                'color'    => 'purple',
                'source'   => 'Clarity',
            ];
        }

        return $kpis;
    }

    /**
     * UX & Friction: Friction Score, Rage Clicks, Script Errors
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
     * Performance: Core Web Vitals (LCP p75, INP p75, CLS p75)
     */
    public function getPerformanceKpis(): array
    {
        if (! $this->selectedClientId) return [];

        $active  = $this->getActiveIntegrations();
        $days    = (int) $this->period;
        $current = $this->getPerformanceAggregates($days);
        $previous= $this->getPerformanceAggregates($days, offset: $days);

        $kpis = [];

        if ($current['lcp'] > 0 || $previous['lcp'] > 0) {
            $kpis[] = [
                'label'    => 'LCP p75',
                'value'    => number_format($current['lcp'], 2) . 's',
                'previous' => number_format($previous['lcp'], 2) . 's',
                'change'   => $this->pctChange($previous['lcp'], $current['lcp']),
                'icon'     => 'heroicon-o-clock',
                'color'    => 'violet',
                'invert'   => true,
                'source'   => 'CrUX',
            ];
        }

        if ($current['inp'] > 0 || $previous['inp'] > 0) {
            $kpis[] = [
                'label'    => 'INP p75',
                'value'    => number_format($current['inp'], 0) . 'ms',
                'previous' => number_format($previous['inp'], 0) . 'ms',
                'change'   => $this->pctChange($previous['inp'], $current['inp']),
                'icon'     => 'heroicon-o-cursor-arrow-ripple',
                'color'    => 'blue',
                'invert'   => true,
                'source'   => 'CrUX',
            ];
        }

        if ($current['cls'] > 0 || $previous['cls'] > 0) {
            $kpis[] = [
                'label'    => 'CLS p75',
                'value'    => number_format($current['cls'], 3),
                'previous' => number_format($previous['cls'], 3),
                'change'   => $this->pctChange($previous['cls'], $current['cls']),
                'icon'     => 'heroicon-o-arrows-up-down',
                'color'    => 'amber',
                'invert'   => true,
                'source'   => 'CrUX',
            ];
        }

        if ($current['throughput'] > 0 || $previous['throughput'] > 0) {
            $kpis[] = [
                'label'    => 'Average Throughput',
                'value'    => number_format($current['throughput']) . ' req/m',
                'previous' => number_format($previous['throughput']) . ' req/m',
                'change'   => $this->pctChange($previous['throughput'], $current['throughput']),
                'icon'     => 'heroicon-o-arrow-trending-up',
                'color'    => 'blue',
                'source'   => 'New Relic',
            ];
        }

        return $kpis;
    }

    /**
     * Inventory: Out of Stock, Low Stock, OOS Rate, Turnover
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

        return $kpis;
    }

    /**
     * Email Marketing: Klaviyo Attributed Revenue vs GA4 Tracked Email Revenue
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
            'label'    => 'Klaviyo Attributed Revenue',
            'value'    => '$' . number_format($current['revenue'], 2),
            'previous' => '$' . number_format($previous['revenue'], 2),
            'change'   => $this->pctChange($previous['revenue'], $current['revenue']),
            'icon'     => 'heroicon-o-banknotes',
            'color'    => 'emerald',
            'source'   => 'Klaviyo',
        ];

        if (in_array('ga4', $active)) {
            $ga4Current  = $this->getGA4EmailChannelRevenue($days);
            $ga4Previous = $this->getGA4EmailChannelRevenue($days, offset: $days);

            $kpis[] = [
                'label'    => 'GA4 Tracked Email Revenue',
                'value'    => '$' . number_format($ga4Current['revenue'], 2),
                'previous' => '$' . number_format($ga4Previous['revenue'], 2),
                'change'   => $this->pctChange($ga4Previous['revenue'], $ga4Current['revenue']),
                'icon'     => 'heroicon-o-chart-bar',
                'color'    => 'teal',
                'source'   => 'GA4',
            ];
        }

        if ($current['delivered'] > 0) {
            $openRate = round(($current['opens'] / $current['delivered']) * 100, 1);
            $prevDel  = max(1, $previous['delivered']);
            $prevOpen = round(($previous['opens'] / $prevDel) * 100, 1);

            $kpis[] = [
                'label'    => 'Open Rate',
                'value'    => number_format($openRate, 1) . '%',
                'previous' => number_format($prevOpen, 1) . '%',
                'change'   => $this->pctChange($prevOpen, $openRate),
                'icon'     => 'heroicon-o-chart-bar',
                'color'    => 'emerald',
                'source'   => 'Klaviyo',
            ];
        }

        return $kpis;
    }

    /**
     * Get Revenue Reconciliation Result
     */
    public function getRevenueReconciliationProperty()
    {
        $client = $this->getSelectedClientProperty();
        if (! $client) return null;

        $days = (int) $this->period;
        $from = now()->subDays($days)->startOfDay();
        $to   = now()->endOfDay();

        return (new RevenueReconciler())->reconcile($client, $from, $to);
    }

    /**
     * Get Data Quality Findings
     */
    public function getDataQualityFindingsProperty()
    {
        $client = $this->getSelectedClientProperty();
        if (! $client) return [];

        $days = (int) $this->period;
        $from = now()->subDays($days)->startOfDay();
        $to   = now()->endOfDay();

        return (new DataQualityEvaluator())->evaluate($client, $from, $to);
    }

    private function getActiveIntegrations(): array
    {
        $client = $this->getSelectedClientProperty();
        if (! $client) return [];
        return $client->getActiveIntegrationTypes();
    }

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
            'sessions'            => (int) ($row->sessions ?? 0),
            'active_users'        => (int) ($row->active_users ?? 0),
            'revenue'             => (float) ($row->revenue ?? 0),
            'orders'              => (int) ($row->orders ?? 0),
            'new_customers'       => (int) ($row->new_customers ?? 0),
            'returning_customers' => (int) ($row->returning_customers ?? 0),
            'items_sold'          => (int) ($row->items_sold ?? 0),
            'conversion_rate'     => (float) ($row->conversion_rate ?? 0),
            'aov'                 => (float) ($row->aov ?? 0),
            'return_rate'         => (float) ($row->return_rate ?? 0),
        ];
    }

    private function getBehavioralAggregates(int $days, int $offset = 0): array
    {
        $from = now()->subDays($days + $offset)->startOfDay();
        $to   = now()->subDays($offset)->endOfDay();

        $row = BehavioralMetric::where('client_id', $this->selectedClientId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(AVG(friction_score), 0) as friction_score,
                COALESCE(SUM(rage_clicks), 0) as rage_clicks,
                COALESCE(SUM(script_errors), 0) as script_errors,
                COALESCE(SUM(dead_clicks), 0) as dead_clicks,
                COALESCE(SUM(traffic), 0) as total_sessions
            ')
            ->first();

        return [
            'friction_score' => (float) ($row->friction_score ?? 0),
            'rage_clicks'    => (int) ($row->rage_clicks ?? 0),
            'script_errors'  => (int) ($row->script_errors ?? 0),
            'dead_clicks'    => (int) ($row->dead_clicks ?? 0),
            'total_sessions' => (int) ($row->total_sessions ?? 0),
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
                COALESCE(AVG(fid), 0) as fid,
                COALESCE(AVG(inp), 0) as inp,
                COALESCE(AVG(cls), 0) as cls,
                COALESCE(AVG(page_load_time), 0) as page_load_time,
                COALESCE(AVG(server_response_time), 0) as server_response_time,
                COALESCE(AVG(ttfb), 0) as ttfb,
                COALESCE(AVG(bounce_rate), 0) as bounce_rate
            ')
            ->first();

        $nrRow = PerformanceMetric::where('client_id', $this->selectedClientId)
            ->where('source', 'new_relic')
            ->whereBetween('date', [$from, $to])
            ->get();

        $throughput = 0;
        $apdexSum   = 0.0;
        $errorRate  = 0.0;
        $nrCount    = $nrRow->count();

        foreach ($nrRow as $r) {
            $meta = $r->metadata_json ?? [];
            $throughput += (int) ($meta['throughput'] ?? 0);
            $apdexSum   += (float) ($meta['apdex'] ?? 0);
            $errorRate  += (float) ($meta['error_rate'] ?? 0);
        }

        return [
            'lcp'                  => (float) ($row->lcp ?? 0),
            'fid'                  => (float) ($row->fid ?? 0),
            'inp'                  => (float) ($row->inp ?? 0),
            'cls'                  => (float) ($row->cls ?? 0),
            'page_load_time'       => (float) ($row->page_load_time ?? 0),
            'server_response_time' => (float) ($row->server_response_time ?? 0),
            'ttfb'                 => (float) ($row->ttfb ?? 0),
            'bounce_rate'          => (float) ($row->bounce_rate ?? 0),
            'throughput'           => $nrCount > 0 ? (int) round($throughput / $nrCount) : 0,
            'apdex'                => $nrCount > 0 ? round($apdexSum / $nrCount, 2) : 0.0,
            'error_rate'           => $nrCount > 0 ? round($errorRate / $nrCount, 4) : 0.0,
            'has_new_relic'        => $nrCount > 0,
        ];
    }

    private function getInventoryAggregates(int $days, string $source, int $offset = 0): array
    {
        $from = now()->subDays($days + $offset)->startOfDay();
        $to   = now()->subDays($offset)->endOfDay();

        $row = InventoryMetric::where('client_id', $this->selectedClientId)
            ->where('source', $source)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(AVG(out_of_stock_count), 0) as out_of_stock_count,
                COALESCE(AVG(low_stock_count), 0) as low_stock_count,
                COALESCE(AVG(out_of_stock_rate), 0) as out_of_stock_rate,
                COALESCE(AVG(low_stock_rate), 0) as low_stock_rate,
                COALESCE(AVG(inventory_turnover), 0) as inventory_turnover,
                COALESCE(AVG(backorder_count), 0) as backorder_count
            ')
            ->first();

        return [
            'out_of_stock_count' => (int) round($row->out_of_stock_count ?? 0),
            'low_stock_count'    => (int) round($row->low_stock_count ?? 0),
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
                COALESCE(SUM(recipients), 0) as recipients,
                COALESCE(SUM(opens), 0) as opens,
                COALESCE(SUM(clicks), 0) as clicks,
                COALESCE(SUM(conversions), 0) as conversions,
                COALESCE(SUM(revenue), 0) as revenue,
                COALESCE(SUM(unsubscribes), 0) as unsubscribes,
                COALESCE(SUM(bounces), 0) as bounces,
                COUNT(DISTINCT campaign_name) as active_flows
            ')
            ->first();

        $recipients = (int) ($row->recipients ?? 0);
        $bounces    = (int) ($row->bounces ?? 0);
        $delivered  = max(0, $recipients - $bounces);

        return [
            'recipients'   => $recipients,
            'bounces'      => $bounces,
            'delivered'    => $delivered,
            'opens'        => (int) ($row->opens ?? 0),
            'clicks'       => (int) ($row->clicks ?? 0),
            'conversions'  => (int) ($row->conversions ?? 0),
            'revenue'      => (float) ($row->revenue ?? 0),
            'unsubscribes' => (int) ($row->unsubscribes ?? 0),
            'active_flows' => (int) ($row->active_flows ?? 0),
        ];
    }

    public function getDeveloperDiagnosticsProperty(): array
    {
        $client = $this->getSelectedClientProperty();
        if (! $client) return [];

        return (new \App\Services\Metrics\MetricsDiagnosticService())->generateDiagnostics($client, (int) $this->period);
    }

    private function pctChange(float $previous, float $current): ?float
    {
        if ($previous == 0.0) {
            if ($current > 0.0) return null; // Displayed as "New"
            return 0.0;
        }
        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
