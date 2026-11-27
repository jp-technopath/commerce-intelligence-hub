<?php

namespace Tests\Unit\Intelligence;

use App\Enums\ClientStatus;
use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingStatus;
use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Models\Client;
use App\Models\CommerceOrder;
use App\Models\Finding;
use App\Models\Integration;
use App\Services\Intelligence\CustomerPurchaseAnalysisService;
use App\Services\Intelligence\OpportunityExplanationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WinbackAndContactAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private OpportunityExplanationService $explainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->explainer = app(OpportunityExplanationService::class);
    }

    public function test_best_sanitizers_winback_displays_effective_181d_threshold_and_interval_ratio(): void
    {
        $finding = new Finding();
        $finding->forceFill([
            'id'             => 500,
            'finding_type'   => 'opportunity',
            'source_type'    => 'detector',
            'source_id'      => 'opportunity.long_term_winback',
            'title'          => 'Long-Term Account Inactivity: Best Sanitizers',
            'status'         => FindingStatus::New,
            'outcome_status' => FindingOutcomeStatus::PendingExecution,
            'customer_name'  => 'Best Sanitizers',
            'company_name'   => 'Best Sanitizers',
            'customer_email' => 'orders@bestsanitizers.com',
            'evidence_json'  => [
                'detector_version'              => '2.1.0-method-d',
                'evaluated_at'                  => '2026-11-24T12:00:00+00:00',
                'days_since_last_purchase'      => 322,
                'effective_min_dormancy'        => 181,
                'cadence_threshold_days'        => 118,
                'median_interval_days'          => 54,
                'days_overdue'                  => 141,
                'episode_count'                 => 2,
                'is_provisional_cadence'        => true,
                'lifetime_net_purchasing_value' => 3420.50,
                'historical_episode_benchmark'  => 1710.25,
                'winback_order_summary'         => [
                    'last_order_at'          => '2026-01-06T10:15:00+00:00',
                    'last_order_net_revenue' => 1850.00,
                    'pre_dormancy_12m_spend' => 3420.50,
                    'pre_dormancy_12m_orders'=> 2,
                    'peak_ordering_months'   => ['Jan', 'Jul'],
                    'has_line_item_sku_data' => false,
                ],
            ],
        ]);

        $explained = $this->explainer->explainFinding($finding);
        $text = $explained['concise_explanation'];

        // Must prioritize effective 181-day Winback threshold, NOT 118d raw cadence as eligibility line
        $this->assertStringContainsString('effective 181-day Winback threshold by 141 days', $text);
        $this->assertStringContainsString('6x its typical 54-day purchasing interval', str_replace('6.0x', '6x', $text));
        $this->assertStringContainsString('Jan 6, 2026', $text);
        $this->assertStringContainsString('$1,850.00', $text);
        $this->assertStringContainsString('$3,420.50', $text);
        $this->assertStringNotContainsString('${', $text);
        $this->assertStringNotContainsString('Best Sanitizers (Best Sanitizers)', $text);

        $this->assertSame('322d (Effective: 181d)', $explained['supporting_metrics'][0]['value']);
        $this->assertStringContainsString('Raw cadence: 118d', $explained['supporting_metrics'][1]['subtext']);
    }

    public function test_customer_display_suppresses_repeated_identical_company_and_person_names(): void
    {
        $display = CustomerPurchaseAnalysisService::formatCustomerDisplay(
            customerIdentityHash: hash('sha256', 'acme@supply.com'),
            customerId: 'CUST-10',
            customerEmail: 'acme@supply.com',
            customerName: 'Acme Supply',
            companyName: 'Acme Supply'
        );

        $this->assertSame('Acme Supply', $display['primary_name']);
        $this->assertSame('Acme Supply (acme@supply.com)', $display['display_label']);
        $this->assertStringNotContainsString('Acme Supply (Acme Supply)', $display['display_label']);
    }

    public function test_all_four_contact_verification_tiers_handle_edge_cases_accurately(): void
    {
        // Tier 1: Independently Verified with explicit provenance
        $tier1 = OpportunityExplanationService::assessContactConsistency(
            'Wendy Taylor',
            'wendy.taylor@sonoco.com',
            'Sonoco',
            [
                'verification_source'        => 'HubSpot CRM Verified Buyer',
                'verification_date'          => '2026-11-20',
                'verified_by'                => 'Donia',
                'procurement_role_confirmed' => true,
            ]
        );
        $this->assertSame('independently_verified', $tier1['status_key']);
        $this->assertTrue($tier1['is_verified_buyer']);
        $this->assertTrue($tier1['is_independently_verified']);

        // Tier 2: Consistent with Customer Records — ALL-CAPS PROLIMPA company bug regression guard
        $tier2Prolimpa = OpportunityExplanationService::assessContactConsistency(
            'Paulo Silva',
            'paulo@prolimpa.com',
            'PROLIMPA'
        );
        $this->assertSame('records_consistent', $tier2Prolimpa['status_key']);
        $this->assertTrue($tier2Prolimpa['name_email_concordant']);
        $this->assertTrue($tier2Prolimpa['email_domain_concordant']);
        $this->assertTrue($tier2Prolimpa['is_records_consistent']);
        $this->assertFalse($tier2Prolimpa['is_verified_buyer']);

        // Tier 2 initial + surname on abbreviated corporate domain: shorton@acdri.com & stacy.horton@acdri.com for Aircraft Ducting Repair (#209)
        $tier2Initial = OpportunityExplanationService::assessContactConsistency(
            'Stacy Horton',
            'shorton@acdri.com',
            'ACDRI'
        );
        $this->assertSame('records_consistent', $tier2Initial['status_key']);
        $this->assertTrue($tier2Initial['name_email_concordant']);
        $this->assertTrue($tier2Initial['email_domain_concordant']);
        $this->assertFalse($tier2Initial['is_verified_buyer']);

        $tier2Acdri = OpportunityExplanationService::assessContactConsistency(
            'Stacy Horton',
            'stacy.horton@acdri.com',
            'Aircraft Ducting Repair'
        );
        $this->assertSame('records_consistent', $tier2Acdri['status_key']);
        $this->assertTrue($tier2Acdri['name_email_concordant']);
        $this->assertTrue($tier2Acdri['email_domain_concordant']);
        $this->assertFalse($tier2Acdri['is_verified_buyer']);

        // Tier 2 corporate alias acronyms & consonant abbreviations (live Goldkamp & Cambro patterns)
        $mmpmg = OpportunityExplanationService::assessContactConsistency('JASON HINKLE', 'JASON@MMPMG.COM', 'Molded Magnesium Products Llc');
        $this->assertSame('records_consistent', $mmpmg['status_key']);
        $this->assertTrue($mmpmg['email_domain_concordant']);
        $this->assertFalse($mmpmg['is_verified_buyer']);

        $mtlcraft = OpportunityExplanationService::assessContactConsistency('Beth Grantman', 'bgrantman@Mtlcraft.com', 'Metalcraft of Mayville');
        $this->assertSame('records_consistent', $mtlcraft['status_key']);
        $this->assertTrue($mtlcraft['email_domain_concordant']);
        $this->assertFalse($mtlcraft['is_verified_buyer']);

        // Tier 3: Actual Goldkamp same-domain person mismatch (#208 Pace: Brent Afseth vs corey@paceintl.com)
        $tier3PersonMismatch = OpportunityExplanationService::assessContactConsistency(
            'Brent Afseth',
            'corey@paceintl.com',
            'Pace'
        );
        $this->assertSame('requires_review', $tier3PersonMismatch['status_key']);
        $this->assertTrue($tier3PersonMismatch['email_domain_concordant']);
        $this->assertFalse($tier3PersonMismatch['name_email_concordant']);
        $this->assertFalse($tier3PersonMismatch['is_verified_buyer']);

        // Tier 3: Shared role inbox on corporate domain (including European accounting/supply inboxes)
        $tier3Role = OpportunityExplanationService::assessContactConsistency(
            'Goldkamp Buyer',
            'purchasing@goldkamp.com',
            'Goldkamp'
        );
        $this->assertSame('requires_review', $tier3Role['status_key']);
        $this->assertTrue($tier3Role['is_shared_role_inbox']);
        $this->assertFalse($tier3Role['is_verified_buyer']);

        $euroRole = OpportunityExplanationService::assessContactConsistency(
            'Alpenhotel Oberjoch Mayer GmbH',
            'rechnung@oberjochresort.de',
            null
        );
        $this->assertSame('requires_review', $euroRole['status_key']);
        $this->assertTrue($euroRole['is_shared_role_inbox']);
        $this->assertFalse($euroRole['is_verified_buyer']);

        // Tier 3/4: Personal webmail domains (including libero.it, hotmail.de, gmail.com, yahoo.com)
        $pace = OpportunityExplanationService::assessContactConsistency('Chris Pace', 'cpace@gmail.com', 'Pace');
        $alliance = OpportunityExplanationService::assessContactConsistency('Dave Miller', 'allianceb@yahoo.com', 'Alliance Builders');
        $libero = OpportunityExplanationService::assessContactConsistency('gaetano spagolla', 'g.spagolla@libero.it', null);
        $hotmailDe = OpportunityExplanationService::assessContactConsistency('Martina Özmen', 'umoezmen@hotmail.de', null);
        $this->assertSame('requires_review', $pace['status_key']);
        $this->assertTrue($pace['is_free_email_provider']);
        $this->assertFalse($pace['is_verified_buyer']);
        $this->assertSame('requires_review', $alliance['status_key']);
        $this->assertTrue($alliance['is_free_email_provider']);
        $this->assertFalse($alliance['is_verified_buyer']);
        $this->assertSame('requires_review', $libero['status_key']);
        $this->assertTrue($libero['is_free_email_provider']);
        $this->assertFalse($libero['is_verified_buyer']);
        $this->assertSame('requires_review', $hotmailDe['status_key']);
        $this->assertTrue($hotmailDe['is_free_email_provider']);
        $this->assertFalse($hotmailDe['is_verified_buyer']);

        // Tier 4: Missing contact
        $missing = OpportunityExplanationService::assessContactConsistency(null, null, 'Sonoco');
        $this->assertSame('not_recorded', $missing['status_key']);
        $this->assertFalse($missing['is_verified_buyer']);
    }

    public function test_winback_order_summary_batches_pre_dormancy_order_headers(): void
    {
        $client = Client::create([
            'name'          => 'Order Header Client',
            'industry'      => 'Packaging',
            'platform_type' => 'Magento',
            'status'        => ClientStatus::Active,
        ]);

        $integration = Integration::create([
            'client_id'        => $client->id,
            'integration_type' => IntegrationType::AdobeCommerce,
            'status'           => IntegrationStatus::Active,
        ]);

        $hash = hash('sha256', 'buyer@acdri.com');

        CommerceOrder::create([
            'client_id'              => $client->id,
            'integration_id'         => $integration->id,
            'source'                 => 'adobe_commerce',
            'order_status'           => 'complete',
            'customer_identity_hash' => $hash,
            'source_order_id'        => '1001',
            'source_increment_id'    => '000001001',
            'order_date'             => '2025-11-15 10:00:00',
            'gross_revenue'          => 1200.00,
            'net_revenue'            => 1200.00,
            'is_valid'               => true,
        ]);
        CommerceOrder::create([
            'client_id'              => $client->id,
            'integration_id'         => $integration->id,
            'source'                 => 'adobe_commerce',
            'order_status'           => 'complete',
            'customer_identity_hash' => $hash,
            'source_order_id'        => '1002',
            'source_increment_id'    => '000001002',
            'order_date'             => '2026-02-10 14:30:00',
            'gross_revenue'          => 2400.00,
            'net_revenue'            => 2400.00,
            'is_valid'               => true,
        ]);

        $summaries = app(CustomerPurchaseAnalysisService::class)->getWinbackOrderSummariesForHashes($client->id, [$hash]);

        $this->assertArrayHasKey($hash, $summaries);
        $this->assertSame(2400.00, $summaries[$hash]['last_order_net_revenue']);
        $this->assertSame('000001002', $summaries[$hash]['last_order_increment_id']);
        $this->assertSame(3600.00, $summaries[$hash]['pre_dormancy_12m_spend']);
        $this->assertSame(2, $summaries[$hash]['pre_dormancy_12m_orders']);
        $this->assertFalse($summaries[$hash]['has_line_item_sku_data']);
    }
}
