<?php

namespace App\Services\Metrics;

use App\Models\Client;
use App\Models\CommerceOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CommerceRevenueCalculator
 *
 * Calculates Gross, Refunded, and Net Valid Order Revenue and AOV
 * from normalized commerce_orders table.
 */
class CommerceRevenueCalculator
{
    /**
     * Calculate revenue metrics for a client over a given date range.
     *
     * @return array{
     *   valid_orders: int,
     *   gross_revenue: float,
     *   refunded_revenue: float,
     *   net_revenue: float,
     *   aov: float,
     *   unique_purchasing_customers: int
     * }
     */
    public function calculate(Client $client, Carbon $from, Carbon $to): array
    {
        $row = CommerceOrder::where('client_id', $client->id)
            ->where('is_valid', true)
            ->whereBetween('order_date', [$from, $to])
            ->selectRaw('
                COUNT(id) as valid_orders,
                COALESCE(SUM(gross_revenue), 0) as gross_revenue,
                COALESCE(SUM(refunded_revenue), 0) as refunded_revenue,
                COALESCE(SUM(net_revenue), 0) as net_revenue,
                COUNT(DISTINCT customer_identity_hash) as unique_purchasing_customers
            ')
            ->first();

        $validOrders               = (int) ($row->valid_orders ?? 0);
        $grossRevenue              = (float) ($row->gross_revenue ?? 0);
        $refundedRevenue           = (float) ($row->refunded_revenue ?? 0);
        $netRevenue                = (float) ($row->net_revenue ?? 0);
        $uniquePurchasingCustomers = (int) ($row->unique_purchasing_customers ?? 0);

        $aov = $validOrders > 0 ? round($netRevenue / $validOrders, 2) : 0.0;

        return [
            'valid_orders'                => $validOrders,
            'gross_revenue'               => $grossRevenue,
            'refunded_revenue'            => $refundedRevenue,
            'net_revenue'                 => $netRevenue,
            'aov'                         => $aov,
            'unique_purchasing_customers' => $uniquePurchasingCustomers,
        ];
    }
}
