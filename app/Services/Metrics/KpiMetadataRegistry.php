<?php

namespace App\Services\Metrics;

use App\Models\Client;

/**
 * KpiMetadataRegistry
 *
 * Stores and exposes standardized calculation metadata for all Business Dashboard KPIs.
 */
class KpiMetadataRegistry
{
    public const CALCULATION_VERSION = 'v1.0.0';

    /**
     * Retrieve metadata array for a given KPI key and client.
     */
    public function getMetadata(string $kpiKey, ?Client $client = null, array $overrides = []): array
    {
        $timezone = $client?->timezone ?? 'America/New_York';
        $currency = $client?->currency ?? 'USD';

        $definitions = [
            'commerce_conversion_rate' => [
                'metric_key'               => 'commerce_conversion_rate',
                'display_name'             => 'Commerce Conversion Rate',
                'business_definition'      => 'The percentage of GA4 active users who became unique purchasing customers during the selected reporting period.',
                'formula'                  => 'Unique Purchasing Customers ÷ GA4 Unique Active Users × 100',
                'numerator'                => 'Unique purchasing customer identities with ≥ 1 valid Adobe Commerce order in period',
                'denominator'              => 'GA4 unique active users in period',
                'data_source'              => 'Adobe Commerce + GA4',
                'data_source_fields'       => 'commerce_orders (customer_identity_hash) + GA4 activeUsers',
                'included_order_statuses'  => ValidOrderFilter::DEFAULT_ELIGIBLE_STATUSES,
                'excluded_order_statuses'  => ValidOrderFilter::DEFAULT_EXCLUDED_STATUSES,
                'refund_handling'          => 'Fully refunded orders excluded; partial refunds remain valid',
                'tax_handling'             => 'N/A (user-based conversion rate)',
                'shipping_handling'        => 'N/A',
                'discount_handling'        => 'N/A',
                'reporting_timezone'       => $timezone,
                'currency'                 => $currency,
                'validation_status'        => 'Validated',
                'calculation_version'     => self::CALCULATION_VERSION,
                'known_limitations'        => 'Guest buyers matched via hashed email; anonymous non-buyers counted from GA4.',
            ],

            'adobe_valid_order_revenue' => [
                'metric_key'               => 'adobe_valid_order_revenue',
                'display_name'             => 'Adobe Valid Order Revenue',
                'business_definition'      => 'Net recognized revenue from valid Adobe Commerce orders in the selected period.',
                'formula'                  => 'Gross Valid Order Revenue − Refunded Revenue − Canceled Amount',
                'numerator'                => 'Net Revenue sum of valid orders attributed to original order date',
                'denominator'              => 'N/A',
                'data_source'              => 'Adobe Commerce',
                'data_source_fields'       => 'commerce_orders (net_revenue, gross_revenue, refunded_revenue)',
                'included_order_statuses'  => ValidOrderFilter::DEFAULT_ELIGIBLE_STATUSES,
                'excluded_order_statuses'  => ValidOrderFilter::DEFAULT_EXCLUDED_STATUSES,
                'refund_handling'          => 'Refunds/credit memos reduce net revenue of original order date retroactively',
                'tax_handling'             => 'Excludes tax by default unless configured in client settings',
                'shipping_handling'        => 'Excludes shipping by default unless configured',
                'discount_handling'        => 'Reflects post-discount net order value',
                'reporting_timezone'       => $timezone,
                'currency'                 => $currency,
                'validation_status'        => 'Validated',
                'calculation_version'     => self::CALCULATION_VERSION,
                'known_limitations'        => 'Late refunds alter historical period totals when back-synced.',
            ],

            'ga4_tracked_purchase_revenue' => [
                'metric_key'               => 'ga4_tracked_purchase_revenue',
                'display_name'             => 'GA4 Tracked Purchase Revenue',
                'business_definition'      => 'Gross ecommerce purchase revenue recorded by Google Analytics 4 purchase events.',
                'formula'                  => 'SUM(GA4 purchase event revenue)',
                'numerator'                => 'GA4 purchase event purchaseRevenue sum',
                'denominator'              => 'N/A',
                'data_source'              => 'Google Analytics 4',
                'data_source_fields'       => 'analytics_purchase_events (tracked_revenue)',
                'included_order_statuses'  => 'Client-side tracked purchase events',
                'excluded_order_statuses'  => 'N/A (tracked on browser client)',
                'refund_handling'          => 'GA4 client-side events do not automatically capture post-purchase backend refunds',
                'tax_handling'             => 'Subject to client-side GA4 dataLayer event configuration',
                'shipping_handling'        => 'Subject to client-side GA4 dataLayer event configuration',
                'discount_handling'        => 'Reflects cart purchase value passed in purchase event',
                'reporting_timezone'       => $timezone,
                'currency'                 => $currency,
                'validation_status'        => 'Validated',
                'calculation_version'     => self::CALCULATION_VERSION,
                'known_limitations'        => 'Subject to browser ad-blockers, consent banners, and duplicate client events.',
            ],

            'adobe_aov' => [
                'metric_key'               => 'adobe_aov',
                'display_name'             => 'Adobe Average Order Value',
                'business_definition'      => 'Average net recognized revenue per valid Adobe Commerce order.',
                'formula'                  => 'Net Valid Order Revenue ÷ Valid Orders',
                'numerator'                => 'Net Valid Order Revenue',
                'denominator'              => 'Count of valid Adobe Commerce orders',
                'data_source'              => 'Adobe Commerce',
                'data_source_fields'       => 'commerce_orders (net_revenue, is_valid)',
                'included_order_statuses'  => ValidOrderFilter::DEFAULT_ELIGIBLE_STATUSES,
                'excluded_order_statuses'  => ValidOrderFilter::DEFAULT_EXCLUDED_STATUSES,
                'refund_handling'          => 'Net revenue numerator is reduced by partial/full refunds',
                'tax_handling'             => 'Configurable per client revenue settings',
                'shipping_handling'        => 'Configurable per client revenue settings',
                'discount_handling'        => 'Net of applied discounts',
                'reporting_timezone'       => $timezone,
                'currency'                 => $currency,
                'validation_status'        => 'Validated',
                'calculation_version'     => self::CALCULATION_VERSION,
                'known_limitations'        => 'Uses matching valid orders population for both numerator and denominator.',
            ],

            'revenue_per_visitor' => [
                'metric_key'               => 'revenue_per_visitor',
                'display_name'             => 'Revenue per Visitor',
                'business_definition'      => 'Average net revenue generated per unique site visitor.',
                'formula'                  => 'Adobe Net Valid Order Revenue ÷ GA4 Unique Active Users',
                'numerator'                => 'Adobe Net Valid Order Revenue',
                'denominator'              => 'GA4 Unique Active Users',
                'data_source'              => 'Adobe Commerce + GA4',
                'data_source_fields'       => 'commerce_orders (net_revenue) + GA4 activeUsers',
                'included_order_statuses'  => ValidOrderFilter::DEFAULT_ELIGIBLE_STATUSES,
                'excluded_order_statuses'  => ValidOrderFilter::DEFAULT_EXCLUDED_STATUSES,
                'refund_handling'          => 'Net revenue numerator accounts for refunds',
                'tax_handling'             => 'Excludes tax by default',
                'shipping_handling'        => 'Excludes shipping by default',
                'discount_handling'        => 'Net of applied discounts',
                'reporting_timezone'       => $timezone,
                'currency'                 => $currency,
                'validation_status'        => 'Validated',
                'calculation_version'     => self::CALCULATION_VERSION,
                'known_limitations'        => 'Combines backend accounting revenue with analytics traffic counts.',
            ],

            'repeat_customer_rate' => [
                'metric_key'               => 'repeat_customer_rate',
                'display_name'             => 'Repeat Customer Rate',
                'business_definition'      => 'Percentage of purchasing customers in period who placed at least one valid order prior to the period start date.',
                'formula'                  => 'Purchasing Customers with Prior Order ÷ All Unique Purchasing Customers in Period × 100',
                'numerator'                => 'Unique purchasing customer identities whose first valid order was before period start',
                'denominator'              => 'Total unique purchasing customer identities in period',
                'data_source'              => 'Adobe Commerce',
                'data_source_fields'       => 'commerce_customer_purchase_facts (first_valid_order_at)',
                'included_order_statuses'  => ValidOrderFilter::DEFAULT_ELIGIBLE_STATUSES,
                'excluded_order_statuses'  => ValidOrderFilter::DEFAULT_EXCLUDED_STATUSES,
                'refund_handling'          => 'Requires at least 1 valid non-canceled/non-fully refunded historical order',
                'tax_handling'             => 'N/A',
                'shipping_handling'        => 'N/A',
                'discount_handling'        => 'N/A',
                'reporting_timezone'       => $timezone,
                'currency'                 => $currency,
                'validation_status'        => 'Validated',
                'calculation_version'     => self::CALCULATION_VERSION,
                'known_limitations'        => 'Evaluates historical cohort purchase facts; guest identity matching via conservative email HMAC.',
            ],

            'user_participation_funnel' => [
                'metric_key'               => 'user_participation_funnel',
                'display_name'             => 'User Participation Funnel',
                'business_definition'      => 'Unique active users performing each ecommerce funnel event independently during the period.',
                'formula'                  => 'Independent unique activeUsers per funnel event (view_item, add_to_cart, begin_checkout, purchase)',
                'numerator'                => 'Unique users at given event stage',
                'denominator'              => 'Unique users at previous event stage (participation pass-through)',
                'data_source'              => 'Google Analytics 4',
                'data_source_fields'       => 'GA4 activeUsers per eventName',
                'included_order_statuses'  => 'N/A (analytics event user counts)',
                'excluded_order_statuses'  => 'N/A',
                'refund_handling'          => 'N/A',
                'tax_handling'             => 'N/A',
                'shipping_handling'        => 'N/A',
                'discount_handling'        => 'N/A',
                'reporting_timezone'       => $timezone,
                'currency'                 => $currency,
                'validation_status'        => 'Validated',
                'calculation_version'     => self::CALCULATION_VERSION,
                'known_limitations'        => 'Displays independent stage participation user counts; does not claim strict sequential user journey.',
            ],
        ];

        $base = $definitions[$kpiKey] ?? [
            'metric_key'               => $kpiKey,
            'display_name'             => ucwords(str_replace('_', ' ', $kpiKey)),
            'business_definition'      => 'Standardized dashboard metric.',
            'formula'                  => 'Standard formula',
            'numerator'                => 'N/A',
            'denominator'              => 'N/A',
            'data_source'              => 'Commerce Intelligence Hub',
            'data_source_fields'       => 'N/A',
            'included_order_statuses'  => ValidOrderFilter::DEFAULT_ELIGIBLE_STATUSES,
            'excluded_order_statuses'  => ValidOrderFilter::DEFAULT_EXCLUDED_STATUSES,
            'refund_handling'          => 'Standard refund handling',
            'tax_handling'             => 'Standard tax handling',
            'shipping_handling'        => 'Standard shipping handling',
            'discount_handling'        => 'Standard discount handling',
            'reporting_timezone'       => $timezone,
            'currency'                 => $currency,
            'validation_status'        => 'Validated',
            'calculation_version'     => self::CALCULATION_VERSION,
            'known_limitations'        => 'Standard metric calculation.',
        ];

        return array_merge($base, $overrides, ['refresh_timestamp' => now()->toIso8601String()]);
    }
}
