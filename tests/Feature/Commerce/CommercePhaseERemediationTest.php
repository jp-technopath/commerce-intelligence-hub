<?php

namespace Tests\Feature\Commerce;

use App\Models\Client;
use App\Models\CommerceCustomerIdentityMapping;
use App\Models\CommerceCustomerPurchaseFact;
use App\Models\CommerceOrder;
use App\Models\Integration;
use App\Services\Configuration\ClientConfigurationManager;
use App\Services\Metrics\OrderQualificationPolicy;
use App\Services\Metrics\OrderQualificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommercePhaseERemediationTest extends TestCase
{
    use RefreshDatabase;

    private function makeClientWithKey(int $id, string $name, array $extraConfig = []): Client
    {
        $client = new Client([
            'name' => $name,
            'platform_type' => 'adobe_commerce',
            'status' => 'active',
            'monitoring_config' => array_merge([
                'identity_hmac_key' => Crypt::encryptString(str_repeat('k', 32)),
            ], $extraConfig),
        ]);
        $client->id = $id;
        $client->save();

        return $client->fresh();
    }

    public function test_goldkamp_modified_option_1b_and_ambiguous_zero_order_guard(): void
    {
        $client = $this->makeClientWithKey(5, 'Goldkamp Test', [
            'order_settings' => [
                'eligible_statuses'              => ['complete', 'processing', 'confirmed'],
                'allow_authorized_b2b_confirmed' => false,
                'allow_invoiced_pending_payment' => true,
                'legacy_completed_workflows'     => [
                    'enabled'                 => true,
                    'cutoff_before'           => '2023-01-01',
                    'required_status'         => 'complete',
                    'allowed_states'          => ['processing', 'complete'],
                    'allowed_payment_methods' => ['authorizenet', 'authorizenet_directpost', 'md_authorizecim'],
                ],
            ],
        ]);

        $policy = app(OrderQualificationPolicy::class);

        // 1. Pre-2023 Authorize.Net complete order with authorization evidence qualifies as legacy_completed (unverified payment)
        $legacyRes = $policy->evaluate([
            'created_at'          => '2021-10-19 17:19:24',
            'state'               => 'complete',
            'status'              => 'complete',
            'base_grand_total'    => 2294.31,
            'base_total_invoiced' => 0.0,
            'base_total_paid'     => 0.0,
            'payment'             => [
                'method'                 => 'md_authorizecim',
                'base_amount_authorized' => 2309.31,
                'last_trans_id'          => '43004740173',
            ],
        ], $client);

        $this->assertTrue($legacyRes->isValid);
        $this->assertSame(OrderQualificationResult::CLASS_QUALIFIED_LEGACY_COMPLETED, $legacyRes->classification);
        $this->assertSame('legacy_pre2023_authorizenet_completed', $legacyRes->qualificationReason);
        $this->assertSame(2294.31, $legacyRes->qualifyingOrderValue);
        $this->assertSame(2294.31, $legacyRes->qualifyingNetRevenue);
        $this->assertSame(2294.31, $legacyRes->settlementUnverifiedRevenue);
        $this->assertSame(0.0, $legacyRes->paidRevenue);
        $this->assertSame(0.0, $legacyRes->invoicedRevenue);

        // 2. Post-cutoff 2024+ Authorize.Net complete order with 0 invoiced/paid is rejected (strict cutoff enforcement)
        $modernUnpaid = $policy->evaluate([
            'created_at'          => '2024-06-01 12:00:00',
            'state'               => 'complete',
            'status'              => 'complete',
            'base_grand_total'    => 500.00,
            'base_total_invoiced' => 0.0,
            'base_total_paid'     => 0.0,
            'payment'             => [
                'method'                 => 'md_authorizecim',
                'base_amount_authorized' => 500.00,
            ],
        ], $client);

        $this->assertFalse($modernUnpaid->isValid);
        $this->assertSame('unsettled_authorized_only', $modernUnpaid->exclusionReason);

        // 3. Ambiguous empty $0 order with large authorization (Order #8993 pattern) is separately excluded
        $ambiguousZero = $policy->evaluate([
            'created_at'          => '2020-01-27 15:35:12',
            'state'               => 'complete',
            'status'              => 'complete',
            'base_grand_total'    => 0.0,
            'base_total_invoiced' => 0.0,
            'base_total_paid'     => 0.0,
            'payment'             => [
                'method'                 => 'md_authorizecim',
                'base_amount_authorized' => 23435.60,
            ],
        ], $client);

        $this->assertFalse($ambiguousZero->isValid);
        $this->assertSame(OrderQualificationResult::CLASS_EXCLUDED_AMBIGUOUS_LEGACY, $ambiguousZero->classification);
        $this->assertSame('ambiguous_zero_total_with_authorization', $ambiguousZero->exclusionReason);

        // 4. Unrelated client without legacy_completed_workflows configured rejects the pre-2023 unpaid order
        $otherClient = $this->makeClientWithKey(9, 'Other Client', [
            'order_settings' => [
                'eligible_statuses' => ['complete', 'processing'],
            ],
        ]);
        $otherRes = $policy->evaluate([
            'created_at'          => '2021-10-19 17:19:24',
            'state'               => 'complete',
            'status'              => 'complete',
            'base_grand_total'    => 2294.31,
            'base_total_invoiced' => 0.0,
            'base_total_paid'     => 0.0,
            'payment'             => [
                'method'                 => 'md_authorizecim',
                'base_amount_authorized' => 2309.31,
            ],
        ], $otherClient);

        $this->assertFalse($otherRes->isValid);
    }

    public function test_goldkamp_paypal_settlement_unverified_workflow_preserves_shipped_and_pending_orders(): void
    {
        $client = $this->makeClientWithKey(5, 'Goldkamp PayPal', [
            'order_settings' => [
                'eligible_statuses'               => ['complete', 'processing', 'confirmed'],
                'settlement_unverified_workflows' => [
                    'enabled'          => true,
                    'payment_methods'  => ['paypal_express'],
                    'allowed_statuses' => ['complete', 'processing', 'confirmed', 'paid_in_full'],
                    'allowed_states'   => ['processing', 'complete'],
                ],
            ],
        ]);

        $policy = app(OrderQualificationPolicy::class);

        // 1. Shipped paid_in_full PayPal order with expired IPN void qualifies as settlement_unverified
        $ppShipped = $policy->evaluate([
            'created_at'          => '2026-02-26 22:59:03',
            'state'               => 'processing',
            'status'              => 'paid_in_full',
            'base_grand_total'    => 108.92,
            'base_total_invoiced' => 0.0,
            'base_total_paid'     => 0.0,
            'payment'             => [
                'method'                 => 'paypal_express',
                'base_amount_authorized' => 108.92,
            ],
            'items'               => [
                ['sku' => 'SKU-1', 'qty_ordered' => 2, 'qty_shipped' => 2],
            ],
            'status_histories'    => [
                ['comment' => 'IPN "Expired" Registered a Void notification.'],
            ],
        ], $client);

        $this->assertTrue($ppShipped->isValid);
        $this->assertSame(OrderQualificationResult::CLASS_QUALIFIED_SETTLEMENT_UNVERIFIED, $ppShipped->classification);
        $this->assertSame('paypal_settlement_unverified_shipped', $ppShipped->qualificationReason);
        $this->assertSame(108.92, $ppShipped->qualifyingNetRevenue);
        $this->assertSame(108.92, $ppShipped->settlementUnverifiedRevenue);
        $this->assertSame(0.0, $ppShipped->paidRevenue);
        $this->assertSame(0.0, $ppShipped->invoicedRevenue);
        $this->assertSame(0.0, $ppShipped->outstandingReceivable);
        $this->assertTrue($ppShipped->provenance['ipn_expired_void'] ?? false);

        // 2. Unshipped active confirmed PayPal order pending fulfillment also qualifies as settlement_unverified
        $ppPending = $policy->evaluate([
            'created_at'          => '2026-11-21 21:05:13',
            'state'               => 'processing',
            'status'              => 'confirmed',
            'base_grand_total'    => 197.82,
            'base_total_invoiced' => 0.0,
            'base_total_paid'     => 0.0,
            'payment'             => [
                'method'                 => 'paypal_express',
                'base_amount_authorized' => 197.82,
            ],
            'items' => [
                ['sku' => 'SKU-2', 'qty_ordered' => 1, 'qty_shipped' => 0],
            ],
        ], $client);

        $this->assertTrue($ppPending->isValid);
        $this->assertSame(OrderQualificationResult::CLASS_QUALIFIED_SETTLEMENT_UNVERIFIED, $ppPending->classification);
        $this->assertSame('paypal_settlement_unverified_pending_fulfillment', $ppPending->qualificationReason);
        $this->assertSame(197.82, $ppPending->settlementUnverifiedRevenue);

        // 3. Canceled or holded PayPal orders remain strictly excluded
        $ppHolded = $policy->evaluate([
            'created_at'          => '2026-11-20 12:00:00',
            'state'               => 'holded',
            'status'              => 'holded',
            'base_grand_total'    => 500.00,
            'base_total_invoiced' => 0.0,
            'base_total_paid'     => 0.0,
            'payment'             => [
                'method'                 => 'paypal_express',
                'base_amount_authorized' => 500.00,
            ],
        ], $client);

        $this->assertFalse($ppHolded->isValid);
    }

    public function test_transpart_core_deposit_accounting_prevents_double_deduction_and_isolates_merchandise_refunds(): void
    {
        $client = $this->makeClientWithKey(2, 'Transpart Core', [
            'order_settings' => [
                'eligible_statuses' => ['complete', 'processing', 'processing_core_charged', 'awaiting_core_return'],
                'core_deposit_settings' => [
                    'enabled'                                    => true,
                    'deduct_outstanding_from_merchandise_revenue' => true,
                    'surcharge_codes'                            => ['fooman_surcharge'],
                    'surcharge_labels'                           => ['refundable deposit'],
                ],
            ],
        ]);

        $policy = app(OrderQualificationPolicy::class);

        // Case A: #51189 ($831.47 paid, $90 deposit, $11 pure item refund -> $0 deposit refunded, $90 outstanding deposit)
        $res51189 = $policy->evaluate([
            'state'               => 'processing',
            'status'              => 'processing_core_charged',
            'base_grand_total'    => 831.47,
            'base_total_invoiced' => 831.47,
            'base_total_paid'     => 831.47,
            'base_total_refunded' => 11.00,
            'payment'             => ['method' => 'braintree_applepay'],
            'items'               => [
                ['sku' => 'PART-A', 'base_amount_refunded' => 11.00, 'base_tax_refunded' => 0, 'base_discount_refunded' => 0],
            ],
            'extension_attributes' => [
                'fooman_total_group' => [
                    'items' => [['code' => 'fooman_surcharge', 'label' => 'Refundable Deposit', 'base_amount' => 90.00]],
                ],
            ],
        ], $client);

        $this->assertTrue($res51189->isValid);
        $this->assertSame(820.47, $res51189->qualifyingOrderValue); // 831.47 - 11.00
        $this->assertSame(90.00, $res51189->refundableCoreDeposit);
        $this->assertSame(0.00, $res51189->coreDepositRefunded);
        $this->assertSame(90.00, $res51189->outstandingCoreDeposit);
        $this->assertSame(730.47, $res51189->qualifyingNetRevenue); // 820.47 - 90.00 (no double deduction of $11 item refund)

        // Case B: #51913 ($659.03 paid, $150 deposit, $364.43 refunded [$230.59 items+$tax=$230.59 capped + $133.84 deposit + $16.16 adj_neg fee = $150 settled])
        $res51913 = $policy->evaluate([
            'state'                   => 'processing',
            'status'                  => 'processing_core_charged',
            'base_grand_total'        => 659.03,
            'base_total_invoiced'     => 659.03,
            'base_total_paid'         => 659.03,
            'base_total_refunded'     => 364.43,
            'base_adjustment_negative'=> 16.16,
            'payment'                 => ['method' => 'braintree_applepay'],
            'items'                   => [
                ['sku' => 'PART-B', 'base_amount_refunded' => 230.59, 'base_tax_refunded' => 15.59, 'base_discount_refunded' => 0],
            ],
            'extension_attributes' => [
                'fooman_total_group' => [
                    'items' => [['code' => 'fooman_surcharge', 'label' => 'Refundable Deposit', 'base_amount' => 150.00]],
                ],
            ],
        ], $client);

        $this->assertTrue($res51913->isValid);
        $this->assertSame(294.60, $res51913->qualifyingOrderValue); // 659.03 - 364.43
        $this->assertSame(150.00, $res51913->refundableCoreDeposit);
        $this->assertSame(118.25, $res51913->coreDepositRefunded);  // 364.43 - (230.59+15.59) = 118.25
        $this->assertSame(15.59, $res51913->outstandingCoreDeposit); // 150 - (118.25 + 16.16) = 15.59
        $this->assertSame(279.01, $res51913->qualifyingNetRevenue);

        // Case C: Unpaid klarna_kp core order remains excluded
        $resKlarna = $policy->evaluate([
            'state'               => 'processing',
            'status'              => 'processing_core_charged',
            'base_grand_total'    => 321.64,
            'base_total_invoiced' => 0.0,
            'base_total_paid'     => 0.0,
            'payment'             => ['method' => 'klarna_kp', 'base_amount_authorized' => 321.64],
            'extension_attributes' => [
                'fooman_total_group' => [
                    'items' => [['code' => 'fooman_surcharge', 'label' => 'Refundable Deposit', 'base_amount' => 110.00]],
                ],
            ],
        ], $client);

        $this->assertFalse($resKlarna->isValid);
        $this->assertSame('unsettled_authorized_only', $resKlarna->exclusionReason);
    }

    public function test_cross_tenant_entity_id_collision_quarantine_and_fail_closed_commands(): void
    {
        Http::fake();

        $transpart = $this->makeClientWithKey(2, 'Transpart');
        $createPharmacy = $this->makeClientWithKey(4, 'Create Pharmacy');

        Integration::create([
            'client_id'        => 2,
            'integration_type' => 'adobe_commerce',
            'status'           => 'active',
            'credentials_json' => ['base_url' => 'https://transpart.example.test', 'access_token' => 'tok'],
        ]);

        // Fail-closed guard: legacy commands refuse cross-tenant / unconfigured runs without confirmation
        $this->artisan('transpart:sync-data', ['--days' => 1])->assertExitCode(1);
        $this->artisan('commerce:backfill-customer-display-attributes', ['--client' => 2])->assertExitCode(1);
        $this->artisan('commerce:backfill-goldkamp-history')->assertExitCode(1);

        // Reproduce historical cross-tenant entity_id=50612 collision contamination on Client #2
        $pharmacyHash = hash('sha256', 'pharmacy_patient@createpharmacy.example');
        CommerceOrder::create([
            'client_id'              => 2,
            'integration_id'         => Integration::where('client_id', 2)->value('id'),
            'source'                 => 'adobe_commerce',
            'source_order_id'        => '50612',
            'order_status'           => 'processing_core_charged',
            'customer_identity_hash' => $pharmacyHash,
            'canonical_account_id'   => 'client_2:cid_50612',
            'registered_customer_id' => '50612',
            'customer_email'         => 'pharmacy_patient@createpharmacy.example',
            'customer_name'          => 'Pharmacy Patient',
            'order_date'             => '2026-08-19 04:08:32',
            'gross_revenue'          => 591.32,
            'refunded_revenue'       => 0,
            'net_revenue'            => 0,
            'is_valid'               => false,
        ]);

        CommerceCustomerPurchaseFact::create([
            'client_id'                  => 2,
            'customer_identity_hash'     => $pharmacyHash,
            'canonical_account_id'       => 'client_2:cid_50612',
            'customer_email'             => 'pharmacy_patient@createpharmacy.example',
            'customer_name'              => 'Pharmacy Patient',
            'first_valid_order_at'       => '2026-08-19 04:08:32',
            'latest_valid_order_at'      => '2026-08-19 04:08:32',
            'lifetime_valid_order_count' => 1,
            'lifetime_net_revenue'       => 100.00,
        ]);

        CommerceCustomerIdentityMapping::create([
            'client_id'            => 2,
            'canonical_account_id' => 'client_2:cid_50612',
            'identity_type'        => 'normalized_email',
            'identity_value'       => 'pharmacy_patient@createpharmacy.example',
            'source_system'        => 'adobe_commerce',
            'is_active'            => true,
        ]);

        // Also seed a real Client #4 integration and order sharing entity_id 50612 to prove isolation
        $integ4 = Integration::create([
            'client_id'        => 4,
            'integration_type' => 'adobe_commerce',
            'status'           => 'active',
            'credentials_json' => ['base_url' => 'https://pharmacy.example.test', 'access_token' => 'tok4'],
        ]);
        CommerceOrder::create([
            'client_id'              => 4,
            'integration_id'         => $integ4->id,
            'source'                 => 'adobe_commerce',
            'source_order_id'        => '50612',
            'order_status'           => 'complete',
            'customer_identity_hash' => $pharmacyHash,
            'canonical_account_id'   => 'client_4:cid_50612',
            'registered_customer_id' => '50612',
            'customer_email'         => 'pharmacy_patient@createpharmacy.example',
            'order_date'             => '2026-08-19 04:08:32',
            'gross_revenue'          => 99.00,
            'refunded_revenue'       => 0,
            'net_revenue'            => 99.00,
            'is_valid'               => true,
        ]);

        $tmpDir = sys_get_temp_dir() . '/phase_e_isolation_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        file_put_contents($tmpDir . '/transpart_real_live_orders.json', json_encode([
            '50612' => [
                'entity_id'           => 50612,
                'increment_id'        => '1000141579',
                'created_at'          => '2026-08-19 04:08:32',
                'updated_at'          => '2026-08-19 04:08:32',
                'state'               => 'processing',
                'status'              => 'processing_core_charged',
                'customer_id'         => 99001,
                'customer_email'      => 'shop_owner@transpart-customer.example',
                'customer_firstname'  => 'Shop',
                'customer_lastname'   => 'Owner',
                'base_grand_total'    => 591.32,
                'base_total_invoiced' => 591.32,
                'base_total_paid'     => 591.32,
                'base_total_refunded' => 0.0,
                'payment'             => ['method' => 'braintree_googlepay'],
                'extension_attributes'=> [
                    'fooman_total_group' => [
                        'items' => [['code' => 'fooman_surcharge', 'label' => 'Refundable Deposit', 'base_amount' => 100.00]],
                    ],
                ],
            ],
        ]));

        try {
            $this->artisan('forge:remediate-commerce-foundation', [
                '--client'   => [2],
                '--live-dir' => $tmpDir,
            ])->assertExitCode(0);
        } finally {
            @unlink($tmpDir . '/transpart_real_live_orders.json');
            @rmdir($tmpDir);
        }

        // Assert Create Pharmacy PII was completely purged from Client #2 orders, facts, archive, and mappings
        $this->assertSame(0, CommerceOrder::where('client_id', 2)->where('customer_email', 'pharmacy_patient@createpharmacy.example')->count());
        $this->assertSame(0, CommerceCustomerPurchaseFact::where('client_id', 2)->where('customer_email', 'pharmacy_patient@createpharmacy.example')->count());
        $this->assertSame(0, DB::table('commerce_customer_purchase_facts_archive')->where('client_id', 2)->count());
        $this->assertSame(0, CommerceCustomerIdentityMapping::where('client_id', 2)->where('identity_value', 'pharmacy_patient@createpharmacy.example')->count());

        // Assert authentic Transpart customer & core deposit metrics were persisted
        $repaired = CommerceOrder::where('client_id', 2)->where('source_order_id', '50612')->firstOrFail();
        $this->assertSame('shop_owner@transpart-customer.example', $repaired->customer_email);
        $this->assertTrue((bool) $repaired->is_valid);
        $this->assertSame('591.32', (string) $repaired->qualifying_order_value);
        $this->assertSame('491.32', (string) $repaired->net_revenue);
        $this->assertSame('100.00', (string) $repaired->refundable_core_deposit);
        $this->assertSame('100.00', (string) $repaired->outstanding_core_deposit);

        // Assert Client #4's own order was untouched
        $this->assertSame(1, CommerceOrder::where('client_id', 4)->where('customer_email', 'pharmacy_patient@createpharmacy.example')->count());
    }

    public function test_configuration_manager_preserves_custom_rules_and_validates_nested_phase_e_settings(): void
    {
        $client = $this->makeClientWithKey(5, 'Goldkamp Config', [
            'custom_rules'           => [['key' => 'vip_alert', 'enabled' => true]],
            'performance_objectives' => ['target_aov' => 650],
            'detector_configs'       => ['long_term_winback' => ['min_orders' => 2]],
            'thresholds'             => [['metric' => 'conversion_rate', 'value' => 1.5]],
        ]);

        $manager = app(ClientConfigurationManager::class);
        $updated = $manager->updateClientMonitoringConfig($client, [
            'order_settings' => [
                'eligible_statuses' => ['complete', 'processing', 'confirmed'],
                'legacy_completed_workflows' => [
                    'enabled'         => true,
                    'cutoff_before'   => '2023-01-01',
                    'required_status' => 'complete',
                ],
            ],
        ], 'test');

        $cfg = $updated->monitoring_config;
        $this->assertSame([['key' => 'vip_alert', 'enabled' => true]], $cfg['custom_rules']);
        $this->assertSame(['target_aov' => 650], $cfg['performance_objectives']);
        $this->assertSame(['long_term_winback' => ['min_orders' => 2]], $cfg['detector_configs']);
        $this->assertSame([['metric' => 'conversion_rate', 'value' => 1.5]], $cfg['thresholds']);
        $this->assertTrue((bool) ($cfg['order_settings']['legacy_completed_workflows']['enabled'] ?? false));
    }
}
