<?php

namespace Database\Seeders;

use App\Enums\ClientStatus;
use App\Enums\DeploymentType;
use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Models\BehavioralMetric;
use App\Models\Client;
use App\Models\CommerceMetric;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Integration;
use Illuminate\Database\Seeder;

class FoodserviceDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Client 1: B2B foodservice distributor ────────────────────────────
        $client1 = Client::create([
            'name'          => 'BrightServe Foodservice Group',
            'industry'      => 'Commercial Foodservice & Hospitality',
            'platform_type' => 'Shopify',
            'status'        => ClientStatus::Active,
            'notes'         => 'B2B wholesale ordering platform for restaurants, hotels and catering companies. Primary revenue via bulk orders. Key conversion risk: checkout abandonment on large quantity orders.',
        ]);

        Integration::create(['client_id' => $client1->id, 'integration_type' => IntegrationType::Shopify, 'status' => IntegrationStatus::Active, 'last_sync_at' => now()->subHours(10)]);
        Integration::create(['client_id' => $client1->id, 'integration_type' => IntegrationType::GA4,     'status' => IntegrationStatus::Active, 'last_sync_at' => now()->subHours(10)]);
        Integration::create(['client_id' => $client1->id, 'integration_type' => IntegrationType::Clarity, 'status' => IntegrationStatus::Active, 'last_sync_at' => now()->subHours(10)]);

        Deployment::create([
            'client_id'       => $client1->id,
            'title'           => 'Shopify Checkout v2 Upgrade',
            'deployment_type' => DeploymentType::Checkout,
            'description'     => 'Migrated from legacy checkout to Shopify Checkout v2. Introduced new quantity selector and updated minimum order logic.',
            'deployed_by'     => 'James P.',
            'deployed_at'     => now()->subDays(12),
            'metadata_json'   => ['ticket' => 'TCI-441', 'theme_version' => '4.2.1'],
        ]);
        Deployment::create([
            'client_id'       => $client1->id,
            'title'           => 'Winter Hospitality Promo Launch',
            'deployment_type' => DeploymentType::Promotion,
            'description'     => 'Activated 15% bulk discount for hospitality sector. Applied at cart for orders over $2,500.',
            'deployed_by'     => 'Sarah M.',
            'deployed_at'     => now()->subDays(5),
            'metadata_json'   => ['promo_code' => 'HOSP-WINTER25', 'min_order' => 2500],
        ]);

        // Commerce metrics: 7 days, post-checkout dip
        $baseRevenue = 48200;
        for ($i = 6; $i >= 0; $i--) {
            $dip = ($i >= 3) ? 0.84 : 1.0;
            CommerceMetric::create([
                'client_id'             => $client1->id,
                'date'                  => now()->subDays($i)->toDateString(),
                'revenue'               => round($baseRevenue * $dip * (0.92 + (rand(0, 16) / 100)), 2),
                'orders'                => rand(38, 52),
                'conversion_rate'       => round((1.8 + (rand(-5, 5) / 100)) * $dip, 4),
                'average_order_value'   => round(920 * $dip, 2),
                'sessions'              => rand(2100, 2800),
                'new_customers'         => rand(12, 22),
                'returning_customers'   => rand(28, 42),
                'source_breakdown_json' => ['direct' => 42, 'organic' => 28, 'email' => 21, 'paid' => 9],
                'device_breakdown_json' => ['desktop' => 68, 'mobile' => 24, 'tablet' => 8],
            ]);
        }

        // Behavioral metrics: elevated friction post-checkout deployment
        for ($i = 6; $i >= 0; $i--) {
            $fm = ($i >= 3) ? 1.38 : 1.0;
            BehavioralMetric::create([
                'client_id'           => $client1->id,
                'date'                => now()->subDays($i)->toDateString(),
                'rage_clicks'         => (int) round(148 * $fm),
                'dead_clicks'         => (int) round(312 * $fm),
                'quick_backs'         => (int) round(89  * $fm),
                'excessive_scrolling' => rand(200, 350),
                'script_errors'       => (int) round(23  * $fm),
                'error_clicks'        => (int) round(67  * $fm),
                'scroll_depth'        => round(42.5 + rand(-5, 5), 2),
                'engagement_time'     => round(187.3 + rand(-20, 20), 2),
                'traffic'             => rand(2100, 2800),
                'friction_score'      => round(38.4 * $fm, 2),
                'metadata_json'       => ['clarity_request_group' => 'device_browser'],
            ]);
        }

        // Findings: 4 realistic findings across categories
        Finding::create([
            'client_id'                => $client1->id,
            'finding_type'             => 'checkout_friction_increase',
            'finding_category'         => FindingCategory::Checkout,
            'title'                    => 'Checkout Rage Clicks Up 38% Following v2 Upgrade',
            'description'              => 'Since the Shopify Checkout v2 migration on 18 May, Clarity behavioral signals indicate a 38% increase in rage clicks concentrated on the quantity selector field and the "Confirm Order" button. This correlates with a 14.2% decline in checkout completion rate over the same period.',
            'severity'                 => FindingSeverity::High,
            'confidence_score'         => 87.5,
            'estimated_revenue_impact' => -6840.00,
            'status'                   => FindingStatus::Investigating,
            'metadata_json'            => ['trigger' => 'rage_clicks_increase', 'comparison_period' => '7d', 'change_pct' => 38.0, 'deployment_correlation' => 'Checkout v2 Upgrade (TCI-441)'],
            'detected_at'              => now()->subDays(3),
        ]);
        Finding::create([
            'client_id'                => $client1->id,
            'finding_type'             => 'mobile_conversion_decline',
            'finding_category'         => FindingCategory::Conversion,
            'title'                    => 'Mobile Conversion Rate Down 22% — Quantity Selector Issue Suspected',
            'description'              => 'Mobile conversion rate has declined from 1.4% to 1.1% over the last 7 days. Clarity behavioral signals indicate high dead-click activity on the quantity input field on mobile, suggesting the updated checkout quantity selector is not rendering correctly on smaller screens.',
            'severity'                 => FindingSeverity::High,
            'confidence_score'         => 79.0,
            'estimated_revenue_impact' => -4200.00,
            'status'                   => FindingStatus::New,
            'metadata_json'            => ['trigger' => 'mobile_conversion_decrease', 'comparison_period' => '7d', 'change_pct' => -22.0],
            'detected_at'              => now()->subDays(1),
        ]);
        Finding::create([
            'client_id'                => $client1->id,
            'finding_type'             => 'returning_customer_drop',
            'finding_category'         => FindingCategory::Customer,
            'title'                    => 'Returning Customer Rate Down 18% vs Prior 30 Days',
            'description'              => 'The returning customer share has dropped from 71% to 58% over the last 30 days. This may reflect friction in the account-based reorder flow or dissatisfaction with the recent checkout experience change.',
            'severity'                 => FindingSeverity::Medium,
            'confidence_score'         => 65.0,
            'estimated_revenue_impact' => -9100.00,
            'status'                   => FindingStatus::New,
            'metadata_json'            => ['trigger' => 'returning_customer_decrease', 'comparison_period' => '30d', 'change_pct' => -18.0],
            'detected_at'              => now()->subDays(2),
        ]);
        Finding::create([
            'client_id'                => $client1->id,
            'finding_type'             => 'revenue_positive',
            'finding_category'         => FindingCategory::Revenue,
            'title'                    => 'Revenue Up 18% Since Winter Promo Launch',
            'description'              => 'Average order value has increased from $780 to $920 since the HOSP-WINTER25 promotion launched on 25 May. The promotion is successfully increasing basket size among hospitality buyers.',
            'severity'                 => FindingSeverity::Low,
            'confidence_score'         => 91.0,
            'estimated_revenue_impact' => 8400.00,
            'status'                   => FindingStatus::Accepted,
            'metadata_json'            => ['trigger' => 'revenue_increase', 'comparison_period' => '7d', 'change_pct' => 18.0],
            'detected_at'              => now()->subDays(2),
        ]);

        $this->command->info("Seeded: {$client1->name}");

        // ─── Client 2: Commercial kitchen equipment (Adobe Commerce) ──────────
        $client2 = Client::create([
            'name'          => 'ProKitchen Direct',
            'industry'      => 'Commercial Foodservice & Hospitality',
            'platform_type' => 'Adobe Commerce',
            'status'        => ClientStatus::Active,
            'notes'         => 'B2C and B2B commercial kitchen equipment retailer. High-consideration purchases, average order $4,200. Key segment: restaurants and hotel procurement teams.',
        ]);

        Integration::create(['client_id' => $client2->id, 'integration_type' => IntegrationType::AdobeCommerce, 'status' => IntegrationStatus::Pending]);
        Integration::create(['client_id' => $client2->id, 'integration_type' => IntegrationType::GA4,           'status' => IntegrationStatus::Pending]);
        Integration::create(['client_id' => $client2->id, 'integration_type' => IntegrationType::Clarity,       'status' => IntegrationStatus::Pending]);

        Finding::create([
            'client_id'        => $client2->id,
            'finding_type'     => 'system_notice',
            'finding_category' => FindingCategory::Technical,
            'title'            => 'Connectors Pending — Intelligence Monitoring Not Yet Active',
            'description'      => 'Adobe Commerce and GA4 connectors are configured but awaiting activation. No performance baseline established. Activate integrations to begin intelligence monitoring.',
            'severity'         => FindingSeverity::Low,
            'confidence_score' => 100.0,
            'status'           => FindingStatus::Accepted,
            'metadata_json'    => ['system_generated' => true],
            'detected_at'      => now()->subDays(1),
        ]);

        $this->command->info("Seeded: {$client2->name}");

        // ─── Client 3: Regional catering supplies (onboarding) ────────────────
        $client3 = Client::create([
            'name'          => 'Coastal Catering Supplies',
            'industry'      => 'Commercial Foodservice & Hospitality',
            'platform_type' => 'Shopify',
            'status'        => ClientStatus::Onboarding,
            'notes'         => 'Regional catering supplies business. Transitioning from phone/email ordering to ecommerce. Onboarding phase — collecting baseline data.',
        ]);

        Integration::create(['client_id' => $client3->id, 'integration_type' => IntegrationType::Shopify, 'status' => IntegrationStatus::Pending]);

        $this->command->info("Seeded: {$client3->name}");
        $this->command->info('Foodservice demo data seeded successfully.');
    }
}
