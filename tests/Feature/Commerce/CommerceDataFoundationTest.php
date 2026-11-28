<?php

namespace Tests\Feature\Commerce;

use App\Enums\FindingExecutionStatus;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Enums\SyncStatus;
use App\Models\Client;
use App\Models\ClientConfigurationAudit;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\CommerceMetric;
use App\Models\CommerceOrder;
use App\Models\Finding;
use App\Models\Integration;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\Configuration\ClientConfigurationManager;
use App\Services\Connectors\AdobeCommerceConnector;
use App\Services\Intelligence\CustomerPurchaseFactsReconciler;
use App\Services\Intelligence\OutcomeMeasurementService;
use App\Services\Metrics\OrderQualificationPolicy;
use App\Services\Metrics\OrderQualificationResult;
use App\Services\Security\CustomerIdentityHasher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class CommerceDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function makeClientAndIntegration(array $clientConfig = [], array $integrationConfig = []): array
    {
        $client = Client::create([
            'name'              => 'Test B2B Store',
            'status'            => 'active',
            'currency'          => 'USD',
            'timezone'          => 'UTC',
            'jira_project_key'  => 'TB2B',
            'monitoring_config' => array_merge([
                'identity_hmac_key' => encrypt(str_repeat('a', 32)),
            ], $clientConfig),
        ]);

        $integration = Integration::create([
            'client_id'         => $client->id,
            'integration_type'  => IntegrationType::AdobeCommerce,
            'status'            => IntegrationStatus::Active,
            'credentials_json'  => [
                'base_url'     => 'https://magento.example.test',
                'access_token' => 'test-bearer-token',
            ],
            'monitoring_config' => $integrationConfig,
        ]);

        return [$client, $integration];
    }

    public function test_order_qualification_policy_handles_b2b_paid_invoiced_partial_and_exclusions(): void
    {
        [$client, $integration] = $this->makeClientAndIntegration([
            'order_settings' => [
                'eligible_statuses'              => ['complete', 'processing', 'confirmed'],
                'excluded_statuses'              => OrderQualificationPolicy::DEFAULT_EXCLUDED_STATUSES,
                'b2b_credit_term_methods'        => OrderQualificationPolicy::DEFAULT_B2B_CREDIT_TERM_METHODS,
                'b2b_authorized_statuses'        => ['confirmed'],
                'allow_authorized_b2b_confirmed' => false,
                'allow_invoiced_pending_payment' => true,
            ],
        ]);

        $policy = app(OrderQualificationPolicy::class);

        // 1. Fully paid processing/confirmed with partial refund (modeled on Goldkamp EID 16256)
        $resPaid = $policy->evaluate([
            'state'               => 'processing',
            'status'              => 'confirmed',
            'base_grand_total'    => 998.77,
            'base_total_invoiced' => 998.77,
            'base_total_paid'     => 998.77,
            'base_total_refunded' => 137.77,
            'payment'             => ['method' => 'stripe_payments', 'base_amount_authorized' => 998.77],
            'ga4_transaction_id'  => 'GA4-1',
        ], $integration);

        $this->assertTrue($resPaid->isValid);
        $this->assertSame(OrderQualificationResult::CLASS_QUALIFIED_PAID, $resPaid->classification);
        $this->assertEquals(861.00, $resPaid->qualifyingNetRevenue);
        $this->assertEquals(861.00, $resPaid->paidRevenue);
        $this->assertEquals(0.0, $resPaid->outstandingReceivable);

        // 2. B2B invoiced net terms in pending_payment (modeled on Stripe Invoice)
        $resInvoiced = $policy->evaluate([
            'state'               => 'pending_payment',
            'status'              => 'pending_payment',
            'base_grand_total'    => 720.00,
            'base_total_invoiced' => 720.00,
            'base_total_paid'     => 0.00,
            'base_total_refunded' => 0.00,
            'payment'             => ['method' => 'stripe_payments_invoice'],
        ], $integration);

        $this->assertTrue($resInvoiced->isValid);
        $this->assertSame(OrderQualificationResult::CLASS_QUALIFIED_B2B_INVOICED, $resInvoiced->classification);
        $this->assertEquals(720.00, $resInvoiced->invoicedRevenue);
        $this->assertEquals(0.00, $resInvoiced->paidRevenue);
        $this->assertEquals(720.00, $resInvoiced->outstandingReceivable);

        // 3. B2B Purchase Order credit terms without immediate invoice/payment
        $resPo = $policy->evaluate([
            'state'               => 'processing',
            'status'              => 'confirmed',
            'base_grand_total'    => 1500.00,
            'base_total_invoiced' => 0.00,
            'base_total_paid'     => 0.00,
            'payment'             => ['method' => 'purchaseorder'],
        ], $integration);

        $this->assertTrue($resPo->isValid);
        $this->assertSame(OrderQualificationResult::CLASS_QUALIFIED_B2B_CREDIT_TERM, $resPo->classification);
        $this->assertEquals(1500.00, $resPo->qualifyingNetRevenue);
        $this->assertEquals(1500.00, $resPo->outstandingReceivable);

        // 4. Partial settlement/invoice strictly capped to settled portion (NEVER full gross!)
        $resPartial = $policy->evaluate([
            'state'               => 'processing',
            'status'              => 'processing',
            'base_grand_total'    => 1000.00,
            'base_total_invoiced' => 250.00,
            'base_total_paid'     => 250.00,
            'payment'             => ['method' => 'stripe_payments', 'base_amount_authorized' => 1000.00],
        ], $integration);

        $this->assertTrue($resPartial->isValid);
        $this->assertSame(OrderQualificationResult::CLASS_QUALIFIED_PARTIAL, $resPartial->classification);
        $this->assertEquals(250.00, $resPartial->qualifyingGrossRevenue);
        $this->assertEquals(250.00, $resPartial->qualifyingNetRevenue);
        $this->assertEquals(750.00, $resPartial->unsettledBalance);

        // 5. Under-authorized + unpaid/uninvoiced order (even with GA4 event!) MUST NOT qualify
        $resUnderAuth = $policy->evaluate([
            'state'               => 'processing',
            'status'              => 'confirmed',
            'base_grand_total'    => 964.07,
            'base_total_invoiced' => 0.00,
            'base_total_paid'     => 0.00,
            'payment'             => ['method' => 'md_authorizecim', 'base_amount_authorized' => 899.78],
            'ga4_purchase_event'  => true,
        ], $integration);

        $this->assertFalse($resUnderAuth->isValid);
        $this->assertSame('unsettled_under_authorized', $resUnderAuth->exclusionReason);

        // Full-authorized unpaid PayPal Express order with allow_authorized_b2b_confirmed = false -> unsettled_authorized_only
        $resFullAuthUnpaid = $policy->evaluate([
            'state'               => 'processing',
            'status'              => 'confirmed',
            'base_grand_total'    => 324.11,
            'base_total_invoiced' => 0.00,
            'base_total_paid'     => 0.00,
            'payment'             => ['method' => 'paypal_express', 'base_amount_authorized' => 324.11],
        ], $integration);

        $this->assertFalse($resFullAuthUnpaid->isValid);
        $this->assertSame('unsettled_authorized_only', $resFullAuthUnpaid->exclusionReason);

        // Status-only legacy order without settlement telemetry qualifies under legacy status fallback
        $resLegacy = $policy->evaluate([
            'status'         => 'complete',
            'grand_total'    => 250.00,
            'total_refunded' => 0.00,
        ], $integration);

        $this->assertTrue($resLegacy->isValid);
        $this->assertSame(OrderQualificationResult::CLASS_QUALIFIED_LEGACY_STATUS, $resLegacy->classification);
        $this->assertEquals(250.00, $resLegacy->qualifyingNetRevenue);

        // 6. payment_review, holded (even if paid), and canceled MUST be excluded
        $resPayReview = $policy->evaluate([
            'state'               => 'payment_review',
            'status'              => 'confirmed',
            'base_grand_total'    => 547.88,
            'base_total_paid'     => 547.88,
            'payment'             => ['method' => 'stripe_payments'],
        ], $integration);
        $this->assertFalse($resPayReview->isValid);
        $this->assertSame('state_excluded:payment_review', $resPayReview->exclusionReason);

        $resHolded = $policy->evaluate([
            'state'               => 'holded',
            'status'              => 'holded',
            'base_grand_total'    => 560.20,
            'base_total_paid'     => 560.20,
        ], $integration);
        $this->assertFalse($resHolded->isValid);
        $this->assertSame('status_excluded:holded', $resHolded->exclusionReason);
    }

    public function test_client_configuration_manager_and_model_hooks_preserve_protected_keys_and_audit(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        [$client, $integration] = $this->makeClientAndIntegration([
            'order_settings'            => ['eligible_statuses' => ['complete', 'processing', 'confirmed']],
            'disable_builtin_detectors' => true,
            'thresholds'                => [['metric' => 'revenue_decrease', 'severity' => 'critical', 'value' => 0.2]],
            'custom_rules'              => ['vip_rule' => ['enabled' => true]],
        ], [
            'enabled_metrics'        => ['commerce' => ['revenue', 'orders']],
            'comparison_period_days' => '365',
            'order_settings'         => ['allow_invoiced_pending_payment' => true],
            'sync_state'             => ['last_successful_window_end' => '2026-11-26T00:00:00+00:00'],
        ]);

        // Simulate a partial form save that only supplies thresholds (without order_settings or identity_hmac_key)
        $client->monitoring_config = [
            'thresholds' => [['metric' => 'revenue_decrease', 'severity' => 'high', 'value' => 0.15]],
        ];
        $client->save();
        $client->refresh();

        $this->assertArrayHasKey('identity_hmac_key', $client->monitoring_config);
        $this->assertSame(['complete', 'processing', 'confirmed'], $client->monitoring_config['order_settings']['eligible_statuses']);
        $this->assertTrue($client->monitoring_config['disable_builtin_detectors']);
        $this->assertSame(['vip_rule' => ['enabled' => true]], $client->monitoring_config['custom_rules']);

        // Partial Integration save preserves sync_state and order_settings
        $integration->monitoring_config = ['comparison_period_days' => '90'];
        $integration->save();
        $integration->refresh();

        $this->assertSame('90', $integration->monitoring_config['comparison_period_days']);
        $this->assertSame('2026-11-26T00:00:00+00:00', $integration->monitoring_config['sync_state']['last_successful_window_end']);
        $this->assertTrue($integration->monitoring_config['order_settings']['allow_invoiced_pending_payment']);

        // Explicit versioned audit update with HMAC redaction
        $manager = app(ClientConfigurationManager::class);
        $manager->updateClientMonitoringConfig($client, [
            'order_settings' => [
                'eligible_statuses'              => ['complete', 'processing', 'confirmed'],
                'allow_invoiced_pending_payment' => true,
            ],
        ], source: 'filament_ui', actorUserId: $user->id);

        $audit = ClientConfigurationAudit::where('client_id', $client->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(1, $audit->version);
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame('[REDACTED]', $audit->after_json['identity_hmac_key'] ?? null);

        // Rejects forbidden status
        $this->expectException(InvalidArgumentException::class);
        $manager->updateClientMonitoringConfig($client, [
            'order_settings' => ['eligible_statuses' => ['complete', 'canceled']],
        ]);
    }

    public function test_incremental_sync_preserves_lifetime_facts_handles_updates_refunds_guest_merges_and_503_retries(): void
    {
        Carbon::setTestNow('2026-11-27 12:00:00');
        config(['intelligence.commerce_sync.retry_base_delay_ms' => 1]);
        config(['intelligence.commerce_sync.retry_max_attempts' => 3]);

        [$client, $integration] = $this->makeClientAndIntegration([
            'order_settings' => [
                'eligible_statuses'              => ['complete', 'processing', 'confirmed'],
                'allow_invoiced_pending_payment' => true,
            ],
        ]);

        $hasher = new CustomerIdentityHasher();
        $custId = '42';
        $email = 'repeat.buyer@b2b.test';
        $regHash = $hasher->hashCustomerIdentity($client, $custId, $email);
        $guestHash = $hasher->hashCustomerIdentity($client, null, $email);
        $singleHash = $hasher->hashCustomerIdentity($client, '99', 'refunded.one@b2b.test');

        // Seed 3 historical orders for repeat buyer ($3,000 total) + 1 valid order for single-order buyer on Nov 10 ($600)
        // PLUS 1 historical pending order on Nov 15 ($400) that will later transition via updated_at
        foreach ([
            ['eid' => '1001', 'date' => '2025-01-10 10:00:00', 'net' => 1000.00],
            ['eid' => '1002', 'date' => '2025-06-15 10:00:00', 'net' => 1000.00],
            ['eid' => '1003', 'date' => '2026-09-01 10:00:00', 'net' => 1000.00],
        ] as $row) {
            CommerceOrder::create([
                'client_id'              => $client->id,
                'integration_id'         => $integration->id,
                'source'                 => 'adobe_commerce',
                'source_order_id'        => $row['eid'],
                'source_increment_id'    => '13000' . $row['eid'],
                'order_status'           => 'complete',
                'customer_identity_hash' => $regHash,
                'canonical_account_id'   => "client_{$client->id}:cid_{$custId}",
                'registered_customer_id' => $custId,
                'customer_email'         => $email,
                'customer_name'          => 'Repeat Buyer',
                'order_date'             => $row['date'],
                'gross_revenue'          => $row['net'],
                'net_revenue'            => $row['net'],
                'is_valid'               => true,
            ]);
        }

        // Order placed on Nov 10 by single-order customer ($600), and sibling order on Nov 10 by another buyer ($200)
        CommerceOrder::create([
            'client_id'              => $client->id,
            'integration_id'         => $integration->id,
            'source'                 => 'adobe_commerce',
            'source_order_id'        => '2001',
            'source_increment_id'    => '130002001',
            'order_status'           => 'complete',
            'customer_identity_hash' => $singleHash,
            'canonical_account_id'   => "client_{$client->id}:cid_99",
            'registered_customer_id' => '99',
            'customer_email'         => 'refunded.one@b2b.test',
            'order_date'             => '2026-11-10 09:00:00',
            'gross_revenue'          => 600.00,
            'net_revenue'            => 600.00,
            'is_valid'               => true,
        ]);

        // Historical pending order on Nov 15 ($400, currently invalid) + another valid Nov 15 order ($250)
        CommerceOrder::create([
            'client_id'              => $client->id,
            'integration_id'         => $integration->id,
            'source'                 => 'adobe_commerce',
            'source_order_id'        => '1004',
            'source_increment_id'    => '130001004',
            'order_status'           => 'pending',
            'customer_identity_hash' => $regHash,
            'canonical_account_id'   => "client_{$client->id}:cid_{$custId}",
            'registered_customer_id' => $custId,
            'customer_email'         => $email,
            'order_date'             => '2026-11-15 14:00:00',
            'gross_revenue'          => 400.00,
            'net_revenue'            => 0.00,
            'is_valid'               => false,
        ]);
        $otherHash = $hasher->hashCustomerIdentity($client, '77', 'other@b2b.test');
        CommerceOrder::create([
            'client_id'              => $client->id,
            'integration_id'         => $integration->id,
            'source'                 => 'adobe_commerce',
            'source_order_id'        => '3001',
            'source_increment_id'    => '130003001',
            'order_status'           => 'complete',
            'customer_identity_hash' => $otherHash,
            'canonical_account_id'   => "client_{$client->id}:cid_77",
            'registered_customer_id' => '77',
            'customer_email'         => 'other@b2b.test',
            'order_date'             => '2026-11-15 11:00:00',
            'gross_revenue'          => 250.00,
            'net_revenue'            => 250.00,
            'is_valid'               => true,
        ]);

        app(CustomerPurchaseFactsReconciler::class)->reconcileClient($client, false);

        // 1. Sync #1: transient HTTP 503 then HTTP 200 returns:
        //    - New registered order 1005 ($500) today
        //    - Older Nov 15 order 1004 transitioned via updated_at from pending -> processing/confirmed paid ($400)
        //    - Guest order 1006 using the exact same verified email ($300)
        $calls = 0;
        $stage = 'initial';
        $capturedQuery = null;
        Http::fake(function (Request $request) use (&$calls, &$stage, &$capturedQuery, $custId, $email) {
            if ($stage === 'refund') {
                return Http::response([
                    'total_count' => 1,
                    'items'       => [
                        [
                            'entity_id'           => 2001,
                            'increment_id'        => '130002001',
                            'state'               => 'closed',
                            'status'              => 'closed',
                            'base_grand_total'    => 600.00,
                            'base_total_invoiced' => 600.00,
                            'base_total_paid'     => 600.00,
                            'base_total_refunded' => 600.00,
                            'created_at'          => '2026-11-10 09:00:00',
                            'updated_at'          => '2026-11-27 13:30:00',
                            'customer_id'         => 99,
                            'customer_email'      => 'refunded.one@b2b.test',
                            'customer_is_guest'   => 0,
                            'total_qty_ordered'   => 2,
                            'payment'             => ['method' => 'stripe_payments'],
                        ],
                    ],
                ], 200);
            }

            if ($stage === 'persistent_503') {
                return Http::response('Downstream maintenance', 503);
            }

            $calls++;
            if ($calls === 1) {
                return Http::response('Service Unavailable', 503);
            }
            $capturedQuery = urldecode($request->url());
            return Http::response([
                'total_count' => 3,
                'items'       => [
                    [
                        'entity_id'           => 1005,
                        'increment_id'        => '130001005',
                        'state'               => 'processing',
                        'status'              => 'confirmed',
                        'base_grand_total'    => 500.00,
                        'base_total_invoiced' => 500.00,
                        'base_total_paid'     => 500.00,
                        'base_total_refunded' => 0.00,
                        'created_at'          => '2026-11-27 11:00:00',
                        'updated_at'          => '2026-11-27 11:05:00',
                        'customer_id'         => (int) $custId,
                        'customer_email'      => $email,
                        'customer_firstname'  => 'Repeat',
                        'customer_lastname'   => 'Buyer',
                        'customer_is_guest'   => 0,
                        'total_qty_ordered'   => 5,
                        'payment'             => ['method' => 'stripe_payments', 'base_amount_authorized' => 500.00],
                    ],
                    [
                        'entity_id'           => 1004,
                        'increment_id'        => '130001004',
                        'state'               => 'complete',
                        'status'              => 'complete',
                        'base_grand_total'    => 400.00,
                        'base_total_invoiced' => 400.00,
                        'base_total_paid'     => 400.00,
                        'base_total_refunded' => 0.00,
                        'created_at'          => '2026-11-15 14:00:00',
                        'updated_at'          => '2026-11-27 11:10:00',
                        'customer_id'         => (int) $custId,
                        'customer_email'      => $email,
                        'customer_firstname'  => 'Repeat',
                        'customer_lastname'   => 'Buyer',
                        'customer_is_guest'   => 0,
                        'total_qty_ordered'   => 4,
                        'payment'             => ['method' => 'stripe_payments', 'base_amount_authorized' => 400.00],
                    ],
                    [
                        'entity_id'           => 1006,
                        'increment_id'        => '130001006',
                        'state'               => 'complete',
                        'status'              => 'complete',
                        'base_grand_total'    => 300.00,
                        'base_total_invoiced' => 300.00,
                        'base_total_paid'     => 300.00,
                        'base_total_refunded' => 0.00,
                        'created_at'          => '2026-11-27 11:20:00',
                        'updated_at'          => '2026-11-27 11:20:00',
                        'customer_id'         => null,
                        'customer_email'      => $email,
                        'customer_firstname'  => 'Repeat',
                        'customer_lastname'   => 'Buyer',
                        'customer_is_guest'   => 1,
                        'total_qty_ordered'   => 3,
                        'payment'             => ['method' => 'stripe_payments', 'base_amount_authorized' => 300.00],
                    ],
                ],
            ], 200);
        });

        $connector = new AdobeCommerceConnector($integration->fresh());
        $log1 = SyncLog::create(['integration_id' => $integration->id, 'status' => SyncStatus::Running, 'started_at' => now()]);
        $connector->sync($log1, 1);

        $this->assertSame(SyncStatus::Success, $log1->fresh()->status);
        $this->assertStringContainsString('[filter_groups][0][filters][0][field]=created_at', (string) $capturedQuery);
        $this->assertStringContainsString('[filter_groups][0][filters][1][field]=updated_at', (string) $capturedQuery);

        // Fact for repeat buyer consolidated across all 6 orders (3 historical + 1004 + 1005 + guest 1006) without clobbering
        $repeatFacts = CommerceCustomerPurchaseFact::where('client_id', $client->id)
            ->where('canonical_account_id', "client_{$client->id}:cid_{$custId}")
            ->get();
        $this->assertCount(1, $repeatFacts);
        $fact = $repeatFacts->first();
        $this->assertSame(6, (int) $fact->lifetime_valid_order_count);
        $this->assertEquals(4200.00, (float) $fact->lifetime_net_revenue);
        $this->assertSame('2025-01-10 10:00:00', $fact->first_valid_order_at->toDateTimeString());
        $this->assertSame('2026-11-27 11:20:00', $fact->latest_valid_order_at->toDateTimeString());

        // No orphaned split guest fact row created for $guestHash
        $this->assertSame(0, CommerceCustomerPurchaseFact::where('client_id', $client->id)->where('customer_identity_hash', $guestHash)->count());

        // Historical Nov 15 CommerceMetric recalculated to include BOTH 3001 ($250) + newly confirmed 1004 ($400) = $650
        $nov15Metric = CommerceMetric::where('client_id', $client->id)->whereDate('date', '2026-11-15')->where('source', 'adobe_commerce')->first();
        $this->assertNotNull($nov15Metric);
        $this->assertEquals(650.00, (float) $nov15Metric->revenue);
        $this->assertSame(2, (int) $nov15Metric->orders);

        // Checkpoint advanced
        $checkpoint1 = $integration->fresh()->monitoring_config['sync_state']['last_successful_window_end'] ?? null;
        $this->assertSame('2026-11-27T12:00:00+00:00', $checkpoint1);

        // 2. Sync idempotency: run identical payload 3x more -> totals stay 6 orders / $4,200.00
        for ($i = 0; $i < 3; $i++) {
            $logRep = SyncLog::create(['integration_id' => $integration->id, 'status' => SyncStatus::Running, 'started_at' => now()]);
            (new AdobeCommerceConnector($integration->fresh()))->sync($logRep, 1);
        }
        $fact->refresh();
        $this->assertSame(6, (int) $fact->lifetime_valid_order_count);
        $this->assertEquals(4200.00, (float) $fact->lifetime_net_revenue);

        // 3. Full refund transition on Nov 10 single-order customer (2001 -> closed) drops Nov 10 metric to $0 & archives fact!
        Carbon::setTestNow('2026-11-27 14:00:00');
        $stage = 'refund';

        $logRefund = SyncLog::create(['integration_id' => $integration->id, 'status' => SyncStatus::Running, 'started_at' => now()]);
        (new AdobeCommerceConnector($integration->fresh()))->sync($logRefund, 1);

        $nov10Metric = CommerceMetric::where('client_id', $client->id)->whereDate('date', '2026-11-10')->where('source', 'adobe_commerce')->first();
        $this->assertNotNull($nov10Metric);
        $this->assertEquals(0.00, (float) $nov10Metric->revenue);
        $this->assertSame(0, (int) $nov10Metric->orders);
        $this->assertSame(0, CommerceCustomerPurchaseFact::where('client_id', $client->id)->where('customer_identity_hash', $singleHash)->count());
        $this->assertSame(1, DB::table('commerce_customer_purchase_facts_archive')->where('client_id', $client->id)->where('customer_identity_hash', $singleHash)->where('archive_reason', 'reconciliation_orphaned_hash')->count());

        // 4. Persistent HTTP 503 failure marks SyncLog Failed and NEVER advances checkpoint!
        Carbon::setTestNow('2026-11-27 18:00:00');
        $stage = 'persistent_503';

        $checkpointBeforeFail = $integration->fresh()->monitoring_config['sync_state']['last_successful_window_end'];
        $logFail = SyncLog::create(['integration_id' => $integration->id, 'status' => SyncStatus::Running, 'started_at' => now()]);
        (new AdobeCommerceConnector($integration->fresh()))->sync($logFail, 1);

        $this->assertSame(SyncStatus::Failed, $logFail->fresh()->status);
        $this->assertSame($checkpointBeforeFail, $integration->fresh()->monitoring_config['sync_state']['last_successful_window_end']);

        Carbon::setTestNow();
    }

    public function test_outcome_measurement_uses_canonical_identity_and_never_matches_by_company_domain_or_unidentified_findings(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        [$clientA, $intA] = $this->makeClientAndIntegration();
        [$clientB, $intB] = $this->makeClientAndIntegration();

        $hasher = new CustomerIdentityHasher();
        $cid = '305';
        $verifiedEmail = 'alice@acmesupply.com';
        $canonicalA = "client_{$clientA->id}:cid_{$cid}";

        // Prior historical registered order establishes verified email for cid=305
        CommerceOrder::create([
            'client_id'              => $clientA->id,
            'integration_id'         => $intA->id,
            'source'                 => 'adobe_commerce',
            'source_order_id'        => 'p_1',
            'order_status'           => 'complete',
            'customer_identity_hash' => $hasher->hashCustomerIdentity($clientA, $cid, $verifiedEmail),
            'canonical_account_id'   => $canonicalA,
            'registered_customer_id' => $cid,
            'customer_email'         => $verifiedEmail,
            'company_name'           => 'Acme Supply Co',
            'order_date'             => now()->subDays(90),
            'gross_revenue'          => 1000.00,
            'net_revenue'            => 1000.00,
            'is_valid'               => true,
        ]);

        // Post-action GUEST order by same verified buyer email -> MUST MATCH ($1,500)
        CommerceOrder::create([
            'client_id'              => $clientA->id,
            'integration_id'         => $intA->id,
            'source'                 => 'adobe_commerce',
            'source_order_id'        => 'post_guest_same_buyer',
            'order_status'           => 'confirmed',
            'customer_identity_hash' => $hasher->hashCustomerIdentity($clientA, null, $verifiedEmail),
            'canonical_account_id'   => "client_{$clientA->id}:guest_" . hash('sha256', $verifiedEmail),
            'registered_customer_id' => null,
            'customer_email'         => $verifiedEmail,
            'company_name'           => 'Acme Supply Co',
            'order_date'             => now()->subDays(2),
            'gross_revenue'          => 1500.00,
            'net_revenue'            => 1500.00,
            'is_valid'               => true,
        ]);

        // Post-action order by DIFFERENT buyer at SAME company & domain -> MUST NOT MATCH!
        CommerceOrder::create([
            'client_id'              => $clientA->id,
            'integration_id'         => $intA->id,
            'source'                 => 'adobe_commerce',
            'source_order_id'        => 'post_diff_buyer_same_company',
            'order_status'           => 'complete',
            'customer_identity_hash' => $hasher->hashCustomerIdentity($clientA, '999', 'bob@acmesupply.com'),
            'canonical_account_id'   => "client_{$clientA->id}:cid_999",
            'registered_customer_id' => '999',
            'customer_email'         => 'bob@acmesupply.com',
            'company_name'           => 'Acme Supply Co',
            'order_date'             => now()->subDays(2),
            'gross_revenue'          => 9000.00,
            'net_revenue'            => 9000.00,
            'is_valid'               => true,
        ]);

        // Cross-tenant order in Client B with SAME cid and SAME email -> MUST NOT MATCH!
        CommerceOrder::create([
            'client_id'              => $clientB->id,
            'integration_id'         => $intB->id,
            'source'                 => 'adobe_commerce',
            'source_order_id'        => 'cross_tenant_ord',
            'order_status'           => 'complete',
            'customer_identity_hash' => $hasher->hashCustomerIdentity($clientB, $cid, $verifiedEmail),
            'canonical_account_id'   => "client_{$clientB->id}:cid_{$cid}",
            'registered_customer_id' => $cid,
            'customer_email'         => $verifiedEmail,
            'company_name'           => 'Acme Supply Co',
            'order_date'             => now()->subDays(2),
            'gross_revenue'          => 7000.00,
            'net_revenue'            => 7000.00,
            'is_valid'               => true,
        ]);

        $finding = Finding::create([
            'client_id'                => $clientA->id,
            'finding_type'             => 'opportunity',
            'fingerprint'              => hash('sha256', 'canon_outcome_1'),
            'source_type'              => 'detector',
            'source_id'                => 'opportunity.long_term_winback',
            'finding_pillar'           => FindingPillar::GrowthOpportunity->value,
            'finding_category'         => 'Revenue',
            'severity'                 => FindingSeverity::High->value,
            'status'                   => FindingStatus::Approved->value,
            'execution_status'         => FindingExecutionStatus::ActionCompleted->value,
            'action_completed_at'      => now()->subDays(10),
            'estimated_revenue_impact' => 1200.00,
            'title'                    => 'Winback Acme Alice',
            'metadata_json'            => [
                'canonical_account_id'   => $canonicalA,
                'registered_customer_id' => $cid,
            ],
        ]);

        $service = app(OutcomeMeasurementService::class);
        $outcome = $service->evaluateFindingOutcome($finding, $user, [
            'attribution_method'        => OutcomeMeasurementService::METHOD_DORMANT_BASELINE,
            'baseline_expected_revenue' => 200.00,
        ]);

        $this->assertSame(1, (int) $outcome->orders_count);
        $this->assertEquals(1500.00, (float) $outcome->observed_revenue);
        $this->assertEquals(1300.00, (float) $outcome->actual_incremental_impact);

        // Fail-closed test: finding with NO verified identity fields returns 0 orders even when client has recent valid orders
        $unidentifiedFinding = Finding::create([
            'client_id'           => $clientA->id,
            'finding_type'        => 'opportunity',
            'fingerprint'         => hash('sha256', 'canon_outcome_failclosed'),
            'source_type'         => 'detector',
            'source_id'           => 'opportunity.wholesale_retention',
            'finding_pillar'      => FindingPillar::GrowthOpportunity->value,
            'finding_category'    => 'Revenue',
            'severity'            => FindingSeverity::Medium->value,
            'status'              => FindingStatus::Approved->value,
            'execution_status'    => FindingExecutionStatus::ActionCompleted->value,
            'action_completed_at' => now()->subDays(5),
            'title'               => 'Unidentified Account',
            'metadata_json'       => ['company_name' => 'Acme Supply Co'],
        ]);

        $failClosedOutcome = $service->evaluateFindingOutcome($unidentifiedFinding, $user, ['force_finalize' => true]);
        $this->assertSame(0, (int) $failClosedOutcome->orders_count);
        $this->assertEquals(0.00, (float) $failClosedOutcome->observed_revenue);
        $this->assertTrue($failClosedOutcome->is_zero_outcome);
        $this->assertStringContainsString('fail-closed', $failClosedOutcome->methodology_notes);
    }
}
