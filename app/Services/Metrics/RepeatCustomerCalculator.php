<?php

namespace App\Services\Metrics;

use App\Models\Client;
use App\Models\CommerceOrder;
use App\Models\CommerceCustomerPurchaseFact;
use Carbon\Carbon;

/**
 * RepeatCustomerCalculator
 *
 * Evaluates historical cohort facts in commerce_customer_purchase_facts
 * to determine the Repeat Customer Rate for a selected period.
 */
class RepeatCustomerCalculator
{
    /**
     * Calculate repeat customer rate for a client over a date range.
     *
     * @return array{
     *   total_purchasing_customers: int,
     *   repeat_purchasing_customers: int,
     *   new_purchasing_customers: int,
     *   repeat_customer_rate: float
     * }
     */
    public function calculate(Client $client, Carbon $from, Carbon $to): array
    {
        // 1. Retrieve all valid orders in the period
        $orders = CommerceOrder::where('client_id', $client->id)
            ->where('is_valid', true)
            ->whereBetween('order_date', [$from, $to])
            ->get();

        if ($orders->isEmpty()) {
            return [
                'total_purchasing_customers'  => 0,
                'repeat_purchasing_customers' => 0,
                'new_purchasing_customers'    => 0,
                'repeat_customer_rate'        => 0.0,
            ];
        }

        // 2. Group orders by customer identity:
        // Use registered_customer_id if available, otherwise normalized customer_identity_hash
        $customerOrderCounts = [];

        foreach ($orders as $order) {
            $key = $order->registered_customer_id
                ? 'reg_' . $order->registered_customer_id
                : ($order->customer_identity_hash ? 'hash_' . $order->customer_identity_hash : null);

            if ($key) {
                $customerOrderCounts[$key] = ($customerOrderCounts[$key] ?? 0) + 1;
            }
        }

        $totalPurchasingCustomers = count($customerOrderCounts);

        if ($totalPurchasingCustomers === 0) {
            return [
                'total_purchasing_customers'  => 0,
                'repeat_purchasing_customers' => 0,
                'new_purchasing_customers'    => 0,
                'repeat_customer_rate'        => 0.0,
            ];
        }

        // 3. Repeat customers = unique customers placing 2 or more valid orders within period
        $repeatCustomers = 0;
        foreach ($customerOrderCounts as $key => $count) {
            if ($count >= 2) {
                $repeatCustomers++;
            }
        }

        $newCustomers = max(0, $totalPurchasingCustomers - $repeatCustomers);
        $repeatRate   = round(($repeatCustomers / $totalPurchasingCustomers) * 100, 2);

        return [
            'total_purchasing_customers'  => $totalPurchasingCustomers,
            'repeat_purchasing_customers' => $repeatCustomers,
            'new_purchasing_customers'    => $newCustomers,
            'repeat_customer_rate'        => $repeatRate,
        ];
    }
}
