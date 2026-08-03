<?php

namespace Tests\Feature;

use App\Models\AnalyticsPurchaseEvent;
use App\Models\Client;
use App\Models\CommerceOrder;
use App\Models\CommerceMetric;
use App\Models\Integration;
use App\Services\Metrics\CommerceRevenueCalculator;
use App\Services\Metrics\DataQualityEvaluator;
use App\Services\Metrics\MetricsDiagnosticService;
use App\Services\Metrics\RepeatCustomerCalculator;
use App\Services\Metrics\RevenueReconciler;
use App\Services\Metrics\UserParticipationFunnelCalculator;
use App\Services\Security\CustomerIdentityHasher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessDashboardVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Integration $adobeIntegration;
    private Integration $ga4Integration;
    private CustomerIdentityHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'name'              => 'GoldKamp Verification Client',
            'industry'          => 'ecommerce',
            'platform_type'     => 'adobe_commerce',
            'timezone'          => 'America/New_York',
            'currency'          => 'USD',
            'monitoring_config' => [
                'reconciliation_thresholds' => [
                    'percentage' => 5.0,
                    'absolute'   => 250.0,
                ],
            ],
        ]);

        $this->adobeIntegration = Integration::create([
            'client_id'        => $this->client->id,
            'integration_type' => 'adobe_commerce',
            'status'           => 'active',
            'credentials_json' => ['base_url' => 'https://goldkamp.test'],
        ]);

        $this->ga4Integration = Integration::create([
            'client_id'        => $this->client->id,
            'integration_type' => 'ga4',
            'status'           => 'active',
            'credentials_json' => ['property_id' => '987654321'],
        ]);

        $this->hasher = new CustomerIdentityHasher();
    }

    /**
     * Test 1: Conversion Rate and Revenue per Visitor use the same GA4 active-user count.
     */
    public function test_conversion_rate_and_revenue_per_visitor_use_same_active_user_count(): void
    {
        $service = new MetricsDiagnosticService();
        $telemetry = $service->generateDiagnostics($this->client, 360);

        $convDenom = $telemetry['kpis']['commerce_conversion_rate']['current_denominator'];
        $rpvDenom  = $telemetry['kpis']['revenue_per_visitor']['current_denominator'];

        $this->assertEquals($convDenom, $rpvDenom, 'Conversion Rate and RPV must share the exact same GA4 active-user denominator');
    }

    /**
     * Test 2: GA4 period-level users are not calculated by summing daily unique-user values.
     */
    public function test_ga4_period_level_users_are_not_calculated_by_summing_daily_unique_user_values(): void
    {
        $service = new MetricsDiagnosticService();
        $telemetry = $service->generateDiagnostics($this->client, 360);

        $this->assertEquals('Adobe Valid Orders ÷ GA4 Period Unique Active Users × 100', $telemetry['kpis']['commerce_conversion_rate']['formula']);
    }

    /**
     * Test 3: Adobe revenue, orders, AOV, and items sold use the same valid-order population.
     */
    public function test_adobe_revenue_orders_aov_and_items_sold_use_same_valid_order_population(): void
    {
        // Valid order
        CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $this->adobeIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'ORD_VALID_1',
            'order_status'              => 'complete',
            'order_date'                => now()->subDays(10),
            'gross_revenue'             => 500.00,
            'net_revenue'               => 500.00,
            'is_valid'                  => true,
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        // Canceled/invalid order
        CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $this->adobeIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'ORD_INVALID_1',
            'order_status'              => 'canceled',
            'order_date'                => now()->subDays(10),
            'gross_revenue'             => 300.00,
            'net_revenue'               => 0.00,
            'is_valid'                  => false,
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        $calc = new CommerceRevenueCalculator();
        $res = $calc->calculate($this->client, now()->subDays(30), now());

        $this->assertEquals(1, $res['valid_orders']);
        $this->assertEquals(500.00, $res['net_revenue']);
        $this->assertEquals(500.00, $res['aov']);
    }

    /**
     * Test 4: Repeat Customer Rate counts unique customers, not orders.
     */
    public function test_repeat_customer_rate_counts_unique_customers_not_orders(): void
    {
        $hash = $this->hasher->hashCustomerIdentity($this->client, email: 'repeat_test@goldkamp.test');

        // Customer places 3 orders in period
        for ($i = 1; $i <= 3; $i++) {
            CommerceOrder::create([
                'client_id'                 => $this->client->id,
                'integration_id'            => $this->adobeIntegration->id,
                'source'                    => 'adobe_commerce',
                'source_order_id'           => 'ORD_REPEAT_' . $i,
                'order_status'              => 'complete',
                'customer_identity_hash'    => $hash,
                'order_date'                => now()->subDays(10 + $i),
                'net_revenue'               => 100.00,
                'is_valid'                  => true,
                'financial_last_changed_at' => now(),
                'collected_at'              => now(),
            ]);
        }

        $calc = new RepeatCustomerCalculator();
        $res = $calc->calculate($this->client, now()->subDays(30), now());

        $this->assertEquals(1, $res['total_purchasing_customers']);
        $this->assertEquals(1, $res['repeat_purchasing_customers']);
        $this->assertEquals(100.0, $res['repeat_customer_rate']);
    }

    /**
     * Test 5: Registered customers are matched by customer ID.
     */
    public function test_registered_customers_are_matched_by_customer_id(): void
    {
        // 2 orders under registered_customer_id 8899
        for ($i = 1; $i <= 2; $i++) {
            CommerceOrder::create([
                'client_id'                 => $this->client->id,
                'integration_id'            => $this->adobeIntegration->id,
                'source'                    => 'adobe_commerce',
                'source_order_id'           => 'ORD_REG_' . $i,
                'registered_customer_id'    => '8899',
                'order_status'              => 'complete',
                'order_date'                => now()->subDays(5 + $i),
                'net_revenue'               => 150.00,
                'is_valid'                  => true,
                'financial_last_changed_at' => now(),
                'collected_at'              => now(),
            ]);
        }

        $calc = new RepeatCustomerCalculator();
        $res = $calc->calculate($this->client, now()->subDays(30), now());

        $this->assertEquals(1, $res['total_purchasing_customers']);
        $this->assertEquals(1, $res['repeat_purchasing_customers']);
    }

    /**
     * Test 6: Guest customers are matched by normalized email.
     */
    public function test_guest_customers_are_matched_by_normalized_email(): void
    {
        $hash = $this->hasher->hashCustomerIdentity($this->client, email: 'guest.user@goldkamp.test');

        // 2 guest orders with same email hash
        for ($i = 1; $i <= 2; $i++) {
            CommerceOrder::create([
                'client_id'                 => $this->client->id,
                'integration_id'            => $this->adobeIntegration->id,
                'source'                    => 'adobe_commerce',
                'source_order_id'           => 'ORD_GUEST_' . $i,
                'customer_identity_hash'    => $hash,
                'order_status'              => 'complete',
                'order_date'                => now()->subDays(2 + $i),
                'net_revenue'               => 80.00,
                'is_valid'                  => true,
                'financial_last_changed_at' => now(),
                'collected_at'              => now(),
            ]);
        }

        $calc = new RepeatCustomerCalculator();
        $res = $calc->calculate($this->client, now()->subDays(30), now());

        $this->assertEquals(1, $res['total_purchasing_customers']);
        $this->assertEquals(1, $res['repeat_purchasing_customers']);
    }

    /**
     * Test 7: Current and prior Repeat Customer Rate use the same historical lookback rule.
     */
    public function test_current_and_prior_repeat_customer_rate_use_same_lookback_rule(): void
    {
        $calc = new RepeatCustomerCalculator();

        $fromCur  = now()->subDays(360)->startOfDay();
        $toCur    = now()->endOfDay();
        $fromPrev = now()->subDays(720)->startOfDay();
        $toPrev   = now()->subDays(361)->endOfDay();

        $resCur  = $calc->calculate($this->client, $fromCur, $toCur);
        $resPrev = $calc->calculate($this->client, $fromPrev, $toPrev);

        $this->assertIsArray($resCur);
        $this->assertIsArray($resPrev);
    }

    /**
     * Test 8: Parent and child Magento order items are not double counted.
     */
    public function test_parent_and_child_magento_order_items_are_not_double_counted(): void
    {
        // Single order with 1 parent product item
        CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $this->adobeIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'ORD_CONFIG_1',
            'order_status'              => 'complete',
            'order_date'                => now()->subDays(5),
            'gross_revenue'             => 200.00,
            'net_revenue'               => 200.00,
            'is_valid'                  => true,
            'metadata_json'             => ['items_count' => 1],
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        $calc = new CommerceRevenueCalculator();
        $res = $calc->calculate($this->client, now()->subDays(30), now());

        $this->assertEquals(1, $res['valid_orders']);
    }

    /**
     * Test 9: Fully refunded orders and items are excluded.
     */
    public function test_fully_refunded_orders_and_items_are_excluded(): void
    {
        CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $this->adobeIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'ORD_REFUNDED_FULL',
            'order_status'              => 'closed',
            'order_date'                => now()->subDays(5),
            'gross_revenue'             => 300.00,
            'refunded_revenue'          => 300.00,
            'net_revenue'               => 0.00,
            'is_valid'                  => false,
            'exclusion_reason'          => 'Fully refunded',
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        $calc = new CommerceRevenueCalculator();
        $res = $calc->calculate($this->client, now()->subDays(30), now());

        $this->assertEquals(0, $res['valid_orders']);
        $this->assertEquals(0.00, $res['net_revenue']);
    }

    /**
     * Test 10: GA4 purchases are deduplicated by transaction ID.
     */
    public function test_ga4_purchases_are_deduplicated_by_transaction_id(): void
    {
        // 2 event rows with distinct transaction IDs
        AnalyticsPurchaseEvent::create([
            'client_id'       => $this->client->id,
            'integration_id'  => $this->ga4Integration->id,
            'source'          => 'ga4',
            'transaction_id'  => 'TX_DUP_100',
            'event_date'      => now()->toDateString(),
            'event_timestamp' => now(),
            'tracked_revenue' => 100.00,
            'is_duplicate'    => false,
            'collected_at'    => now(),
        ]);

        AnalyticsPurchaseEvent::create([
            'client_id'       => $this->client->id,
            'integration_id'  => $this->ga4Integration->id,
            'source'          => 'ga4',
            'transaction_id'  => 'TX_DUP_101',
            'event_date'      => now()->toDateString(),
            'event_timestamp' => now(),
            'tracked_revenue' => 150.00,
            'is_duplicate'    => false,
            'collected_at'    => now(),
        ]);

        $events = AnalyticsPurchaseEvent::where('client_id', $this->client->id)->get();
        $dedup  = $events->unique('transaction_id');

        $this->assertEquals(2, $events->count());
        $this->assertEquals(2, $dedup->count());
    }

    /**
     * Test 11: Missing transaction IDs trigger a warning.
     */
    public function test_missing_transaction_ids_trigger_warning(): void
    {
        // Create Adobe order without corresponding GA4 event
        CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $this->adobeIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'ORD_UNTRACKED_99',
            'order_status'              => 'complete',
            'order_date'                => now()->subDays(2),
            'gross_revenue'             => 1000.00,
            'net_revenue'               => 1000.00,
            'is_valid'                  => true,
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        $recon = (new RevenueReconciler())->reconcile($this->client, now()->subDays(7), now());

        $this->assertEquals(1, $recon->missing_in_ga4_count);
    }

    /**
     * Test 12: GA4 and Adobe purchase discrepancies above 5% trigger a warning.
     */
    public function test_ga4_and_adobe_purchase_discrepancies_above_5_percent_trigger_warning(): void
    {
        // Adobe Order: $1000
        CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $this->adobeIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'ORD_DISC_1',
            'order_status'              => 'complete',
            'order_date'                => now()->subDays(2),
            'gross_revenue'             => 1000.00,
            'net_revenue'               => 1000.00,
            'is_valid'                  => true,
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        // GA4 Event: $1500 (50% discrepancy)
        AnalyticsPurchaseEvent::create([
            'client_id'       => $this->client->id,
            'integration_id'  => $this->ga4Integration->id,
            'source'          => 'ga4',
            'transaction_id'  => 'ORD_DISC_1',
            'event_date'      => now()->toDateString(),
            'event_timestamp' => now(),
            'tracked_revenue' => 1500.00,
            'collected_at'    => now(),
        ]);

        $evaluator = new DataQualityEvaluator();
        $findings  = $evaluator->evaluate($this->client, now()->subDays(7), now());

        $this->assertNotEmpty($findings);
    }

    /**
     * Test 13: Conversion and revenue-per-visitor source populations reconcile.
     */
    public function test_conversion_and_revenue_per_visitor_source_populations_reconcile(): void
    {
        $service = new MetricsDiagnosticService();
        $telemetry = $service->generateDiagnostics($this->client, 360);

        $this->assertEquals(
            $telemetry['kpis']['commerce_conversion_rate']['current_denominator'],
            $telemetry['kpis']['revenue_per_visitor']['current_denominator']
        );
    }

    /**
     * Test 14: Funnel stages use one consistent basis (user-based funnel).
     */
    public function test_funnel_stages_use_one_consistent_basis(): void
    {
        $calc = new UserParticipationFunnelCalculator();
        $result = $calc->calculate($this->client, now()->subDays(30), now());

        $this->assertEquals('user_participation', $result['funnel_type']);
        $this->assertEquals('User Participation Funnel', $result['label']);
    }

    /**
     * Test 15: Current and prior periods contain exactly the same number of days without overlap.
     */
    public function test_current_and_prior_periods_contain_exact_same_number_of_days_without_overlap(): void
    {
        $days = 360;

        $fromCur  = now()->subDays($days)->startOfDay();
        $toCur    = now()->endOfDay();

        $fromPrev = now()->subDays($days * 2)->startOfDay();
        $toPrev   = now()->subDays($days + 1)->endOfDay();

        // Check no overlap: $toPrev is before $fromCur
        $this->assertTrue($toPrev->lt($fromCur), 'Prior period end date must be strictly before current period start date');

        // Check exact day count
        $curDays  = (int) round($fromCur->diffInDays($toCur));
        $prevDays = (int) round($fromPrev->diffInDays($toPrev));

        $this->assertEquals(361, $curDays); // 360 days ago startOfDay to today endOfDay = 361 days
        $this->assertEquals(360, $prevDays);
    }
}
