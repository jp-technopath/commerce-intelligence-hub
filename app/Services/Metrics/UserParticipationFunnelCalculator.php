<?php

namespace App\Services\Metrics;

use App\Models\Client;
use App\Models\CommerceMetric;
use Carbon\Carbon;

/**
 * UserParticipationFunnelCalculator
 *
 * Computes independent user participation counts and stage pass-through rates
 * across ecommerce funnel stages (view_item -> add_to_cart -> begin_checkout -> purchase).
 */
class UserParticipationFunnelCalculator
{
    /**
     * Calculate user participation funnel metrics over a date range.
     */
    public function calculate(Client $client, Carbon $from, Carbon $to): array
    {
        $metrics = CommerceMetric::where('client_id', $client->id)
            ->where('source', 'ga4')
            ->whereBetween('date', [$from, $to])
            ->get();

        $viewItem      = 0;
        $addToCart     = 0;
        $beginCheckout = 0;
        $purchase      = 0;

        foreach ($metrics as $m) {
            $funnel = $m->metadata_json['funnel'] ?? [];
            $viewItem      += (int) ($funnel['view_item'] ?? 0);
            $addToCart     += (int) ($funnel['add_to_cart'] ?? 0);
            $beginCheckout += (int) ($funnel['begin_checkout'] ?? 0);
            $purchase      += (int) ($funnel['purchase'] ?? 0);
        }

        $stages = [
            [
                'stage'             => 'View Product',
                'label'             => 'View Product',
                'event_name'        => 'view_item',
                'count'             => $viewItem,
                'users'             => $viewItem,
                'color'             => '#6366f1',
                'pass_through'      => 100.0,
                'pass_through_rate' => 100.0,
                'drop_off_rate'     => 0.0,
                'drop_off'          => 0.0,
            ],
            [
                'stage'             => 'Add to Cart',
                'label'             => 'Add to Cart',
                'event_name'        => 'add_to_cart',
                'count'             => $addToCart,
                'users'             => $addToCart,
                'color'             => '#8b5cf6',
                'pass_through'      => $viewItem > 0 ? round(($addToCart / $viewItem) * 100, 1) : 0.0,
                'pass_through_rate' => $viewItem > 0 ? round(($addToCart / $viewItem) * 100, 1) : 0.0,
                'drop_off_rate'     => $viewItem > 0 ? round((1 - ($addToCart / $viewItem)) * 100, 1) : 0.0,
                'drop_off'          => $viewItem > 0 ? round((1 - ($addToCart / $viewItem)) * 100, 1) : 0.0,
            ],
            [
                'stage'             => 'Begin Checkout',
                'label'             => 'Begin Checkout',
                'event_name'        => 'begin_checkout',
                'count'             => $beginCheckout,
                'users'             => $beginCheckout,
                'color'             => '#a855f7',
                'pass_through'      => $addToCart > 0 ? round(($beginCheckout / $addToCart) * 100, 1) : 0.0,
                'pass_through_rate' => $addToCart > 0 ? round(($beginCheckout / $addToCart) * 100, 1) : 0.0,
                'drop_off_rate'     => $addToCart > 0 ? round((1 - ($beginCheckout / $addToCart)) * 100, 1) : 0.0,
                'drop_off'          => $addToCart > 0 ? round((1 - ($beginCheckout / $addToCart)) * 100, 1) : 0.0,
            ],
            [
                'stage'             => 'Purchase',
                'label'             => 'Purchase',
                'event_name'        => 'purchase',
                'count'             => $purchase,
                'users'             => $purchase,
                'color'             => '#10b981',
                'pass_through'      => $beginCheckout > 0 ? round(($purchase / $beginCheckout) * 100, 1) : 0.0,
                'pass_through_rate' => $beginCheckout > 0 ? round(($purchase / $beginCheckout) * 100, 1) : 0.0,
                'drop_off_rate'     => $beginCheckout > 0 ? round((1 - ($purchase / $beginCheckout)) * 100, 1) : 0.0,
                'drop_off'          => $beginCheckout > 0 ? round((1 - ($purchase / $beginCheckout)) * 100, 1) : 0.0,
            ],
        ];

        $overallConversion = $viewItem > 0 ? round(($purchase / $viewItem) * 100, 2) : 0.0;

        return [
            'funnel_type'        => 'user_participation',
            'label'              => 'User Participation Funnel',
            'stages'             => $stages,
            'overall_conversion' => $overallConversion,
            'overall_rate'       => $overallConversion,
        ];
    }
}
