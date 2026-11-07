<?php

namespace Tests\Unit\Intelligence;

use App\Models\Client;
use App\Services\Metrics\ValidOrderFilter;
use App\Enums\ClientStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidOrderFilterCustomerConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_client_uses_default_statuses_and_rejects_confirmed(): void
    {
        $filter = new ValidOrderFilter();

        $defaultClient = Client::create([
            'name'          => 'Default Store',
            'platform_type' => 'magento',
            'status'        => ClientStatus::Active,
        ]);

        $statuses = $filter->getEligibleStatuses($defaultClient);
        $this->assertEquals(['complete', 'processing'], $statuses);

        $orderConfirmed = [
            'status'            => 'confirmed',
            'is_fully_refunded' => false,
            'is_fraud'          => false,
            'is_test'           => false,
            'is_duplicate'      => false,
        ];

        $this->assertFalse($filter->isValidOrder($orderConfirmed, $defaultClient));
        $this->assertStringContainsString('status_not_eligible', $filter->getExclusionReason($orderConfirmed, $defaultClient));

        $orderProcessing = [
            'status'            => 'processing',
            'is_fully_refunded' => false,
            'is_fraud'          => false,
            'is_test'           => false,
            'is_duplicate'      => false,
        ];

        $this->assertTrue($filter->isValidOrder($orderProcessing, $defaultClient));
    }

    public function test_custom_client_config_includes_confirmed_and_paid_in_full(): void
    {
        $filter = new ValidOrderFilter();

        $b2bClient = Client::create([
            'name'              => 'Goldkamp B2B',
            'platform_type'     => 'magento',
            'status'            => ClientStatus::Active,
            'monitoring_config' => [
                'order_settings' => [
                    'eligible_statuses' => ['complete', 'processing', 'confirmed', 'paid_in_full'],
                    'excluded_statuses' => ['canceled', 'closed', 'holded', 'fraud', 'test'],
                ],
            ],
        ]);

        $statuses = $filter->getEligibleStatuses($b2bClient);
        $this->assertContains('confirmed', $statuses);
        $this->assertContains('paid_in_full', $statuses);

        $orderConfirmed = [
            'status'            => 'confirmed',
            'is_fully_refunded' => false,
            'is_fraud'          => false,
            'is_test'           => false,
            'is_duplicate'      => false,
        ];

        $this->assertTrue($filter->isValidOrder($orderConfirmed, $b2bClient));
        $this->assertNull($filter->getExclusionReason($orderConfirmed, $b2bClient));

        // Excluded statuses remain invalid even with custom config
        $orderCanceled = [
            'status'            => 'canceled',
            'is_fully_refunded' => false,
            'is_fraud'          => false,
            'is_test'           => false,
            'is_duplicate'      => false,
        ];

        $this->assertFalse($filter->isValidOrder($orderCanceled, $b2bClient));
    }

    public function test_refund_handling_in_net_revenue(): void
    {
        $filter = new ValidOrderFilter();

        $client = Client::create([
            'name'          => 'Store',
            'platform_type' => 'magento',
            'status'        => ClientStatus::Active,
        ]);

        $orderFullRefund = [
            'status'            => 'complete',
            'is_fully_refunded' => true,
        ];

        $this->assertFalse($filter->isValidOrder($orderFullRefund, $client));
        $this->assertEquals('fully_refunded', $filter->getExclusionReason($orderFullRefund, $client));

        $net = $filter->calculateNetRevenue(100.00, 20.00);
        $this->assertEquals(80.00, $net);

        $netOverRefund = $filter->calculateNetRevenue(100.00, 150.00);
        $this->assertEquals(0.00, $netOverRefund);
    }
}
