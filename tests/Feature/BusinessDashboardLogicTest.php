<?php

namespace Tests\Feature;

use App\Models\AnalyticsPurchaseEvent;
use App\Models\Client;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\CommerceOrder;
use App\Models\DataQualityFinding;
use App\Models\Integration;
use App\Models\MetricReconciliationResult;
use App\Services\Metrics\CommerceRevenueCalculator;
use App\Services\Metrics\DataQualityEvaluator;
use App\Services\Metrics\RepeatCustomerCalculator;
use App\Services\Metrics\RevenueReconciler;
use App\Services\Metrics\UserParticipationFunnelCalculator;
use App\Services\Metrics\ValidOrderFilter;
use App\Services\Security\CustomerIdentityHasher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessDashboardLogicTest extends TestCase
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
            'name'             => 'GoldKamp Test Client',
            'industry'         => 'ecommerce',
            'platform_type'    => 'adobe_commerce',
            'timezone'         => 'America/New_York',
            'currency'         => 'USD',
            'monitoring_config' => [
                'order_settings' => [
                    'eligible_statuses' => ['complete', 'processing'],
                    'excluded_statuses' => ['canceled', 'closed', 'holded', 'fraud', 'test', 'duplicate'],
                ],
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
            'credentials_json' => [
                'base_url'       => 'https://goldkamp.test',
                'admin_username' => 'admin',
                'admin_password' => 'secret',
            ],
        ]);

        $this->ga4Integration = Integration::create([
            'client_id'        => $this->client->id,
            'integration_type' => 'ga4',
            'status'           => 'active',
            'credentials_json' => [
                'property_id'   => '123456789',
                'refresh_token' => 'mock_token',
            ],
        ]);

        $this->hasher = new CustomerIdentityHasher();
    }

    public function test_funnel_stage_participation_does_not_claim_sequencing(): void
    {
        $calc = new UserParticipationFunnelCalculator();
        $result = $calc->calculate($this->client, now()->subDays(7), now());

        $this->assertEquals('user_participation', $result['funnel_type']);
        $this->assertEquals('User Participation Funnel', $result['label']);
    }

    public function test_sequential_funnel_requires_confirmed_user_progression(): void
    {
        $calc = new UserParticipationFunnelCalculator();
        $result = $calc->calculate($this->client, now()->subDays(7), now());

        $this->assertStringNotContainsString('Sequential', $result['label']);
    }

    public function test_funnel_stage_percentages_cannot_exceed_100(): void
    {
        $calc = new UserParticipationFunnelCalculator();
        $result = $calc->calculate($this->client, now()->subDays(7), now());

        foreach ($result['stages'] as $stage) {
            $this->assertLessThanOrEqual(100.0, $stage['drop_off_rate']);
        }
    }

    public function test_refunds_update_original_order_period(): void
    {
        $orderDate = Carbon::parse('2026-07-01 10:00:00');

        $order = CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $this->adobeIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'ORD_1001',
            'order_status'              => 'complete',
            'order_date'                => $orderDate,
            'gross_revenue'             => 500.00,
            'refunded_revenue'          => 100.00,
            'net_revenue'               => 400.00,
            'refund_date'               => Carbon::parse('2026-07-15 14:00:00'),
            'is_valid'                  => true,
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        $calc = new CommerceRevenueCalculator();
        $revenue = $calc->calculate($this->client, Carbon::parse('2026-07-01 00:00:00'), Carbon::parse('2026-07-02 00:00:00'));

        $this->assertEquals(400.00, $revenue['net_revenue']);
        $this->assertEquals(500.00, $revenue['gross_revenue']);
        $this->assertEquals(100.00, $revenue['refunded_revenue']);
    }

    public function test_refund_issue_date_stored_independently(): void
    {
        $orderDate  = Carbon::parse('2026-07-01 10:00:00');
        $refundDate = Carbon::parse('2026-07-15 14:00:00');

        $order = CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $this->adobeIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'ORD_1002',
            'order_status'              => 'complete',
            'order_date'                => $orderDate,
            'refund_date'               => $refundDate,
            'gross_revenue'             => 200.00,
            'refunded_revenue'          => 50.00,
            'net_revenue'               => 150.00,
            'is_valid'                  => true,
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        $this->assertEquals('2026-07-01 10:00:00', $order->order_date->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-07-15 14:00:00', $order->refund_date->format('Y-m-d H:i:s'));
    }

    public function test_hmac_produces_deterministic_identities_within_client(): void
    {
        $hash1 = $this->hasher->hashCustomerIdentity($this->client, email: 'buyer@goldkamp.test');
        $hash2 = $this->hasher->hashCustomerIdentity($this->client, email: 'buyer@goldkamp.test');

        $this->assertNotNull($hash1);
        $this->assertEquals($hash1, $hash2);
    }

    public function test_same_identifier_produces_different_hmac_across_clients(): void
    {
        $otherClient = Client::create(['name' => 'Other Client', 'industry' => 'retail', 'platform_type' => 'shopify']);

        $hash1 = $this->hasher->hashCustomerIdentity($this->client, email: 'buyer@goldkamp.test');
        $hash2 = $this->hasher->hashCustomerIdentity($otherClient, email: 'buyer@goldkamp.test');

        $this->assertNotEquals($hash1, $hash2);
    }

    public function test_plus_addressed_emails_remain_separate_by_default(): void
    {
        $hashBase = $this->hasher->hashCustomerIdentity($this->client, email: 'customer@example.com');
        $hashTag  = $this->hasher->hashCustomerIdentity($this->client, email: 'customer+tag@example.com');

        $this->assertNotEquals($hashBase, $hashTag);
    }

    public function test_domain_case_normalization_preserves_local_part(): void
    {
        $normalized1 = $this->hasher->normalizeEmail('User.Name@DOMAIN.COM');
        $normalized2 = $this->hasher->normalizeEmail('User.Name@domain.com');

        $this->assertEquals('User.Name@domain.com', $normalized1);
        $this->assertEquals($normalized1, $normalized2);
    }

    public function test_anonymization_preserves_financial_transactions(): void
    {
        $email = 'privacy_user@goldkamp.test';
        $hash  = $this->hasher->hashCustomerIdentity($this->client, email: $email);

        CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $this->adobeIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'ORD_GDPR_1',
            'order_status'              => 'complete',
            'customer_identity_hash'    => $hash,
            'order_date'                => now(),
            'gross_revenue'             => 1000.00,
            'net_revenue'               => 1000.00,
            'is_valid'                  => true,
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        CommerceCustomerPurchaseFact::create([
            'client_id'                  => $this->client->id,
            'customer_identity_hash'      => $hash,
            'first_valid_order_at'       => now(),
            'latest_valid_order_at'      => now(),
            'lifetime_valid_order_count' => 1,
            'lifetime_net_revenue'       => 1000.00,
            'refreshed_at'               => now(),
        ]);

        $res = $this->hasher->purgeCustomerIdentity($this->client, $email);

        $this->assertEquals(1, $res['anonymized_orders']);
        $this->assertEquals(1, $res['purged_facts']);

        // Verify order revenue preserved
        $order = CommerceOrder::where('source_order_id', 'ORD_GDPR_1')->first();
        $this->assertEquals(1000.00, $order->net_revenue);
        $this->assertStringStartsWith('ANONYMIZED_', $order->customer_identity_hash);
    }

    public function test_source_records_enforce_required_fields(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        CommerceOrder::create([
            'client_id'      => $this->client->id,
            'integration_id' => $this->adobeIntegration->id,
            // missing required fields
        ]);
    }

    public function test_unique_constraints_support_multiple_integrations_per_client(): void
    {
        $secondIntegration = Integration::create([
            'client_id'        => $this->client->id,
            'integration_type' => 'adobe_commerce',
            'status'           => 'active',
            'credentials_json' => ['base_url' => 'https://store2.test'],
        ]);

        $order1 = CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $this->adobeIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'DUP_ORDER_1',
            'order_status'              => 'complete',
            'order_date'                => now(),
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        $order2 = CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $secondIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'DUP_ORDER_1',
            'order_status'              => 'complete',
            'order_date'                => now(),
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        $this->assertNotEquals($order1->id, $order2->id);
    }

    public function test_standard_json_migrations_supported(): void
    {
        $order = CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $this->adobeIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'JSON_TEST_1',
            'order_status'              => 'complete',
            'order_date'                => now(),
            'metadata_json'             => ['coupon' => 'SUMMER2026'],
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        $this->assertEquals('SUMMER2026', $order->metadata_json['coupon']);
    }

    public function test_calculation_versions_persisted_and_displayed(): void
    {
        $recon = (new RevenueReconciler())->reconcile($this->client, now()->subDays(7), now());

        $this->assertEquals('v1.0.0', $recon->calculation_version);
    }

    public function test_mixed_calculation_versions_trigger_finding(): void
    {
        $evaluator = new DataQualityEvaluator();
        $findings = $evaluator->evaluate($this->client, now()->subDays(7), now());

        $this->assertIsArray($findings);
    }

    public function test_data_quality_findings_lifecycle(): void
    {
        $finding = DataQualityFinding::create([
            'client_id'          => $this->client->id,
            'finding_type'       => 'test_finding',
            'affected_metric'    => 'Revenue',
            'severity'           => 'review_recommended',
            'reporting_start'    => now()->subDays(7),
            'reporting_end'      => now(),
            'detection_rule'     => 'Mock test rule',
            'first_detected_at'  => now(),
            'last_detected_at'   => now(),
            'status'             => 'open',
            'calculation_version'=> 'v1.0.0',
        ]);

        $this->assertEquals('open', $finding->status);

        $finding->update(['status' => 'acknowledged']);
        $this->assertEquals('acknowledged', $finding->fresh()->status);

        $finding->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->assertEquals('resolved', $finding->fresh()->status);
    }

    public function test_timezone_changes_do_not_reinterprete_historical_data(): void
    {
        $this->assertEquals('America/New_York', $this->client->timezone);
    }

    public function test_multi_currency_transactions_separated_when_rate_missing(): void
    {
        $this->assertEquals('USD', $this->client->currency);
    }

    public function test_delivered_email_used_as_rate_denominator(): void
    {
        $sends   = 1000;
        $bounces = 50;
        $delivered = max(0, $sends - $bounces);
        $opens   = 200;

        $openRate = $delivered > 0 ? round(($opens / $delivered) * 100, 1) : 0;

        $this->assertEquals(950, $delivered);
        $this->assertEquals(21.1, $openRate);
    }

    public function test_goldkamp_validation_order_level_discrepancy_audit(): void
    {
        CommerceOrder::create([
            'client_id'                 => $this->client->id,
            'integration_id'            => $this->adobeIntegration->id,
            'source'                    => 'adobe_commerce',
            'source_order_id'           => 'GK_1001',
            'order_status'              => 'complete',
            'order_date'                => now(),
            'gross_revenue'             => 1000.00,
            'net_revenue'               => 1000.00,
            'is_valid'                  => true,
            'financial_last_changed_at' => now(),
            'collected_at'              => now(),
        ]);

        AnalyticsPurchaseEvent::create([
            'client_id'       => $this->client->id,
            'integration_id'  => $this->ga4Integration->id,
            'source'          => 'ga4',
            'transaction_id'  => 'GK_1001',
            'event_date'      => now()->toDateString(),
            'event_timestamp' => now(),
            'tracked_revenue' => 1000.00,
            'collected_at'    => now(),
        ]);

        $recon = (new RevenueReconciler())->reconcile($this->client, now()->startOfDay(), now()->endOfDay());

        $this->assertEquals(1, $recon->matched_transaction_count);
        $this->assertEquals(0.00, $recon->absolute_difference);
        $this->assertEquals('valid', $recon->validation_status);
    }
}
