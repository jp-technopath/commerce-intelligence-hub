<?php

namespace Tests\Unit\Intelligence;

use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingStatus;
use App\Models\Finding;
use App\Services\Intelligence\OpportunityExplanationService;
use Tests\TestCase;

class OpportunityExplanationServiceTest extends TestCase
{
    private OpportunityExplanationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OpportunityExplanationService::class);
    }

    public function test_calculate_method_d_threshold_for_sonoco_calibration(): void
    {
        // Sonoco: median interval = 66, cv = 1.872
        // M = min(3.2, 2.0 + 1.2 * (1.872 - 0.35) / 0.45) = 3.2
        // Threshold = round(66 * 3.2) = 211
        $threshold = $this->service->calculateMethodDThresholdDays(66, 1.872);
        $this->assertSame(211, $threshold);
    }

    public function test_calculate_method_d_threshold_enforces_180_day_floor_for_low_variance(): void
    {
        // Low variance customer: median = 50, cv = 0.20 -> M = 2.0 -> 50 * 2.0 = 100
        // Floor of 180 days should apply
        $threshold = $this->service->calculateMethodDThresholdDays(50, 0.20);
        $this->assertSame(180, $threshold);
    }

    public function test_calculate_method_d_threshold_for_mid_variance(): void
    {
        // Mid variance customer: median = 80, cv = 0.50 -> M = 2.0 + 1.2 * (0.15 / 0.45) = 2.4
        // Threshold = round(80 * 2.4) = 192
        $threshold = $this->service->calculateMethodDThresholdDays(80, 0.50);
        $this->assertSame(192, $threshold);
    }

    public function test_assess_contact_consistency_sonoco_concordant(): void
    {
        $result = $this->service->assessContactConsistency('Wendy Taylor', 'Wendy.Taylor@Sonoco.com', 'Sonoco');

        $this->assertTrue($result['is_verified_buyer']);
        $this->assertTrue($result['name_email_concordant']);
        $this->assertTrue($result['email_domain_concordant']);
        $this->assertSame('Wendy Taylor', $result['display_contact_name']);
        $this->assertStringContainsString('Verified concordant procurement contact', $result['notes']);
    }

    public function test_assess_contact_consistency_qtran_discordant(): void
    {
        $result = $this->service->assessContactConsistency('jerry carter', 'amber@excelsiorlighting.com', 'QTran');

        $this->assertFalse($result['is_verified_buyer']);
        $this->assertFalse($result['name_email_concordant']);
        $this->assertFalse($result['email_domain_concordant']);
        $this->assertNull($result['display_contact_name']);
        $this->assertStringContainsString('Discordant contact records', $result['notes']);
    }

    public function test_assess_contact_consistency_handles_missing_contact(): void
    {
        $result = $this->service->assessContactConsistency(null, null, 'Acme Corp');

        $this->assertFalse($result['is_verified_buyer']);
        $this->assertNull($result['display_contact_name']);
        $this->assertStringContainsString('No contact records available', $result['notes']);
    }

    public function test_build_outcome_presentation_for_unworked_opportunity(): void
    {
        $finding = new Finding([
            'status' => FindingStatus::New,
            'outcome_status' => FindingOutcomeStatus::PendingExecution,
            'observed_post_action_revenue' => 0.0,
            'modeled_lift_revenue' => 0.0,
        ]);

        $presentation = $this->service->buildOutcomePresentation($finding);

        $this->assertSame('not_yet_measured', $presentation['state']);
        $this->assertSame('Not yet measured', $presentation['label']);
        $this->assertSame('gray', $presentation['badge_color']);
        $this->assertNull($presentation['observed_revenue']);
        $this->assertStringContainsString('Measurement begins after outreach', $presentation['description']);
    }

    public function test_build_outcome_presentation_for_in_observation_window(): void
    {
        $finding = new Finding([
            'status' => FindingStatus::Approved,
            'execution_status' => 'action_completed',
            'outcome_status' => FindingOutcomeStatus::AwaitingResults,
            'action_completed_at' => now()->subDays(10),
            'observed_post_action_revenue' => 0.0,
        ]);

        $presentation = $this->service->buildOutcomePresentation($finding);

        $this->assertSame('in_observation', $presentation['state']);
        $this->assertSame('In Observation', $presentation['label']);
        $this->assertSame('amber', $presentation['badge_color']);
        $this->assertNull($presentation['observed_revenue']);
        $this->assertStringContainsString('Action completed', $presentation['description']);
    }

    public function test_build_outcome_presentation_for_measured_zero(): void
    {
        $finding = new Finding([
            'status' => FindingStatus::Resolved,
            'outcome_status' => FindingOutcomeStatus::OutcomeMeasured,
            'observed_post_action_revenue' => 0.0,
            'modeled_lift_revenue' => 0.0,
        ]);

        $presentation = $this->service->buildOutcomePresentation($finding);

        $this->assertSame('measured_zero', $presentation['state']);
        $this->assertSame('$0.00 observed (0 orders)', $presentation['label']);
        $this->assertSame('gray', $presentation['badge_color']);
        $this->assertSame(0.0, $presentation['observed_revenue']);
        $this->assertStringContainsString('0 post-action reorders observed', $presentation['description']);
    }

    public function test_build_outcome_presentation_for_measured_positive_recovery(): void
    {
        $finding = new Finding();
        $finding->forceFill([
            'status' => FindingStatus::Resolved,
            'outcome_status' => FindingOutcomeStatus::OutcomeMeasured,
            'observed_post_action_revenue' => 4500.50,
            'modeled_lift_revenue' => 2100.00,
        ]);

        $presentation = $this->service->buildOutcomePresentation($finding);

        $this->assertSame('measured_positive', $presentation['state']);
        $this->assertSame('$4,500.50 observed reorders', $presentation['label']);
        $this->assertSame('emerald', $presentation['badge_color']);
        $this->assertSame(4500.50, $presentation['observed_revenue']);
        $this->assertSame(2100.00, $presentation['modeled_lift']);
    }

    public function test_explain_finding_long_term_winback_provides_3_metrics_and_grounded_action(): void
    {
        $finding = new Finding();
        $finding->forceFill([
            'id' => 101,
            'source_id' => 'opportunity.long_term_winback',
            'title' => 'Long-Term Account Inactivity: Sonoco',
            'status' => FindingStatus::New,
            'outcome_status' => FindingOutcomeStatus::PendingExecution,
            'customer_name' => 'Wendy Taylor',
            'customer_email' => 'Wendy.Taylor@Sonoco.com',
            'company_name' => 'Sonoco',
            'evidence_json' => [
                'days_inactive' => 301,
                'cadence_days' => 66,
                'cadence_variability_cv' => 1.872,
                'lifetime_spend' => 46112.87,
                'purchasing_episodes_count' => 32,
            ],
        ]);

        $explanation = $this->service->explainFinding($finding);

        $this->assertStringContainsString('Winback', $explanation['opportunity_type']);
        $this->assertNotEmpty($explanation['concise_explanation']);
        $this->assertCount(3, $explanation['supporting_metrics']);

        $metricLabels = array_column($explanation['supporting_metrics'], 'label');
        $this->assertSame(['Inactivity', 'Cadence Deviation', 'Historical Scale'], $metricLabels);

        // Verify calibrated threshold in expandable details is 211d, not stale 264d
        $this->assertSame(211, $explanation['expandable_details']['calibrated_threshold_days']);
        $this->assertSame(66, $explanation['expandable_details']['median_cadence_days']);
        $this->assertSame(1.872, $explanation['expandable_details']['cadence_variability_cv']);

        // Verify recommended action mentions Wendy Taylor
        $this->assertStringContainsString('Wendy Taylor', $explanation['recommended_action']);
    }

    public function test_explain_finding_discordant_contact_recommends_account_review(): void
    {
        $finding = new Finding();
        $finding->forceFill([
            'id' => 102,
            'source_id' => 'opportunity.long_term_winback',
            'title' => 'Long-Term Account Inactivity: QTran',
            'status' => FindingStatus::New,
            'outcome_status' => FindingOutcomeStatus::PendingExecution,
            'customer_name' => 'jerry carter',
            'customer_email' => 'amber@excelsiorlighting.com',
            'company_name' => 'QTran',
            'evidence_json' => [
                'days_inactive' => 437,
                'cadence_days' => 116,
                'cadence_variability_cv' => 0.552,
                'lifetime_spend' => 21597.71,
                'purchasing_episodes_count' => 14,
            ],
        ]);

        $explanation = $this->service->explainFinding($finding);

        // Since contact is discordant, action should recommend account review, not Jerry Carter
        $this->assertStringNotContainsString('Jerry Carter', $explanation['recommended_action']);
        $this->assertStringContainsString('identify the active procurement contact', $explanation['recommended_action']);
        $this->assertStringContainsString('discordant email domain', $explanation['recommended_action']);
    }

    public function test_explain_finding_replenishment_cadence(): void
    {
        $finding = new Finding();
        $finding->forceFill([
            'id' => 103,
            'source_id' => 'opportunity.replenishment_cadence',
            'title' => 'Replenishment Due: Acme Industrial',
            'status' => FindingStatus::New,
            'outcome_status' => FindingOutcomeStatus::PendingExecution,
            'customer_name' => 'Alice Cooper',
            'customer_email' => 'alice@acme.com',
            'company_name' => 'Acme Industrial',
            'evidence_json' => [
                'days_since_last_order' => 45,
                'cadence_days' => 28,
                'historical_aov' => 1250.00,
                'lifetime_orders' => 18,
                'variance_cv' => 0.22,
            ],
        ]);

        $explanation = $this->service->explainFinding($finding);

        $this->assertSame('Consumable Replenishment', $explanation['opportunity_type']);
        $this->assertCount(3, $explanation['supporting_metrics']);
        $metricLabels = array_column($explanation['supporting_metrics'], 'label');
        $this->assertSame(['Replenishment Cycle', 'Cycle Timing', 'Expected Reorder'], $metricLabels);
        $this->assertStringContainsString('Alice Cooper', $explanation['recommended_action']);
    }

    public function test_explain_finding_account_contraction(): void
    {
        $finding = new Finding();
        $finding->forceFill([
            'id' => 104,
            'source_id' => 'opportunity.account_contraction',
            'title' => 'Spending Contraction: Beta Corp',
            'status' => FindingStatus::New,
            'outcome_status' => FindingOutcomeStatus::PendingExecution,
            'customer_name' => 'Bob Builder',
            'customer_email' => 'bob@betacorp.com',
            'company_name' => 'Beta Corp',
            'evidence_json' => [
                'decline_percentage' => 42.5,
                'baseline_quarterly_spend' => 30000.00,
                'current_quarter_spend' => 17250.00,
                'baseline_quarter_label' => 'Q1',
                'current_quarter_label' => 'Q2',
            ],
        ]);

        $explanation = $this->service->explainFinding($finding);

        $this->assertSame('Account Contraction Risk', $explanation['opportunity_type']);
        $this->assertCount(3, $explanation['supporting_metrics']);
        $metricLabels = array_column($explanation['supporting_metrics'], 'label');
        $this->assertSame(['Spending Decline', 'Quarterly Baseline', 'Recent Spend'], $metricLabels);
        $this->assertStringContainsString('Bob Builder', $explanation['recommended_action']);
    }

    public function test_explain_finding_b2b_reactivation(): void
    {
        $finding = new Finding();
        $finding->forceFill([
            'id' => 105,
            'source_id' => 'opportunity.b2b_account_reactivation',
            'title' => 'Dormant Account: Gamma Logistics',
            'status' => FindingStatus::New,
            'outcome_status' => FindingOutcomeStatus::PendingExecution,
            'customer_name' => 'Carol Danvers',
            'customer_email' => 'carol@gammalogistics.com',
            'company_name' => 'Gamma Logistics',
            'evidence_json' => [
                'days_since_last_order' => 85,
                'dormancy_threshold_days' => 60,
                'median_interval_days' => 30,
                'order_count' => 8,
                'historical_aov' => 3200.00,
                'lifetime_net_purchasing_value' => 25600.00,
            ],
        ]);

        $explanation = $this->service->explainFinding($finding);

        $this->assertSame('B2B Account Reactivation', $explanation['opportunity_type']);
        $this->assertCount(3, $explanation['supporting_metrics']);
        $metricLabels = array_column($explanation['supporting_metrics'], 'label');
        $this->assertSame(['Inactivity Window', 'Cadence Interval', 'Order Benchmark'], $metricLabels);
        $this->assertStringContainsString('Carol Danvers', $explanation['recommended_action']);
    }

    public function test_assess_contact_consistency_independently_verified_with_provenance(): void
    {
        $result = $this->service->assessContactConsistency(
            customerName: 'Sarah Jenkins',
            customerEmail: 'sjenkins@apexsupply.com',
            companyName: 'Apex Supply',
            contactMetadata: [
                'verification_source' => 'Direct Procurement Confirmation',
                'verification_date'   => '2026-11-20',
                'verified_by'         => 'Account Exec Jane Doe',
                'role_confirmation'   => 'VP of Global Sourcing',
            ]
        );

        $this->assertSame('independently_verified', $result['status_key']);
        $this->assertSame('Independently Verified', $result['badge_text']);
        $this->assertSame('emerald', $result['badge_color']);
        $this->assertTrue($result['is_independently_verified']);
        $this->assertNotNull($result['provenance']);
        $this->assertSame('Direct Procurement Confirmation', $result['provenance']['verification_source']);
        $this->assertStringContainsString('verified purchasing contact Sarah Jenkins', $result['recommended_action']);
    }

    public function test_validate_finding_evidence_detects_outdated_or_missing_version(): void
    {
        $finding = new Finding();
        $finding->forceFill([
            'source_id' => 'opportunity.long_term_winback',
            'evidence_json' => [
                'detector_version' => '1.0.0', // outdated
                'cadence_threshold_days' => 211,
                'days_since_last_purchase' => 301,
                'days_overdue' => 90,
                'evaluated_at' => now()->toIso8601String(),
            ],
        ]);

        $validation = $this->service->validateFindingEvidence($finding, $finding->evidence_json);

        $this->assertTrue($validation['requires_revalidation']);
        $this->assertStringContainsString('missing or outdated', $validation['revalidation_reason']);
    }

    public function test_validate_finding_evidence_detects_missing_required_evidence_fields(): void
    {
        $finding = new Finding();
        $finding->forceFill([
            'source_id' => 'opportunity.long_term_winback',
            'evidence_json' => [
                'detector_version' => '2.1.0-method-d',
                // missing cadence_threshold_days, days_overdue, etc.
                'days_since_last_purchase' => 301,
            ],
        ]);

        $validation = $this->service->validateFindingEvidence($finding, $finding->evidence_json);

        $this->assertTrue($validation['requires_revalidation']);
        $this->assertStringContainsString("Required authoritative evidence key 'cadence_threshold_days' is missing", $validation['revalidation_reason']);
    }

    public function test_validate_finding_evidence_detects_conflicting_adaptive_multiplier(): void
    {
        $finding = new Finding();
        $finding->forceFill([
            'source_id' => 'opportunity.long_term_winback',
            'evidence_json' => [
                'detector_version' => '2.1.0-method-d',
                'cadence_threshold_days' => 264,
                'days_since_last_purchase' => 301,
                'days_overdue' => 37,
                'adaptive_multiplier' => 4.0, // exceeds Method D cap of 3.2
                'median_interval_days' => 66,
                'evaluated_at' => now()->toIso8601String(),
            ],
        ]);

        $validation = $this->service->validateFindingEvidence($finding, $finding->evidence_json);

        $this->assertTrue($validation['requires_revalidation']);
        $this->assertStringContainsString('conflicts with Method D cap (3.2)', $validation['revalidation_reason']);
    }

    public function test_validate_finding_evidence_passes_for_authoritative_method_d_evidence(): void
    {
        $finding = new Finding();
        $finding->forceFill([
            'source_id' => 'opportunity.long_term_winback',
            'evidence_json' => [
                'detector_version' => '2.1.0-method-d',
                'cadence_threshold_days' => 211,
                'days_since_last_purchase' => 301,
                'days_overdue' => 90,
                'adaptive_multiplier' => 3.2,
                'median_interval_days' => 66,
                'variance_cv' => 1.872,
                'evaluated_at' => now()->toIso8601String(),
            ],
        ]);

        $validation = $this->service->validateFindingEvidence($finding, $finding->evidence_json);

        $this->assertFalse($validation['requires_revalidation']);
        $this->assertNull($validation['revalidation_reason']);
        $this->assertSame('2.1.0-method-d', $validation['detector_version']);
    }

    public function test_explain_finding_strictly_reads_authoritative_detector_evidence(): void
    {
        $evaluatedAt = '2026-11-21T12:00:00+00:00';
        $finding = new Finding();
        $finding->forceFill([
            'id' => 214,
            'source_id' => 'opportunity.long_term_winback',
            'title' => 'Long-Term Account Inactivity: Sonoco',
            'status' => FindingStatus::New,
            'outcome_status' => FindingOutcomeStatus::PendingExecution,
            'customer_name' => 'Wendy Taylor',
            'customer_email' => 'Wendy.Taylor@Sonoco.com',
            'company_name' => 'Sonoco',
            'evidence_json' => [
                'detector_version' => '2.1.0-method-d',
                'evaluated_at' => $evaluatedAt,
                'cadence_threshold_days' => 211,
                'effective_min_dormancy' => 211,
                'days_since_last_purchase' => 301,
                'days_overdue' => 90,
                'median_interval_days' => 66,
                'variance_cv' => 1.872,
                'adaptive_multiplier' => 3.2,
                'episode_count' => 32,
                'lifetime_net_purchasing_value' => 46112.87,
                'historical_episode_benchmark' => 1441.03,
            ],
        ]);

        $explanation = $this->service->explainFinding($finding);

        // Verification of authoritative values in concise explanation
        $this->assertStringContainsString('301 days', $explanation['concise_explanation']);
        $this->assertStringContainsString('211-day dormancy threshold', $explanation['concise_explanation']);
        $this->assertStringContainsString('90 days', $explanation['concise_explanation']);

        // Supporting metrics verify exact authoritative values
        $this->assertSame('301d (Threshold: 211d)', $explanation['supporting_metrics'][0]['value']);
        $this->assertSame('90d past dormancy line', $explanation['supporting_metrics'][0]['subtext']);
        $this->assertSame('+90d (1.43x)', $explanation['supporting_metrics'][1]['value']);
        $this->assertSame('Based on 66d median interval', $explanation['supporting_metrics'][1]['subtext']);

        // Expandable details preserve version and authoritative evaluation timestamp
        $this->assertFalse($explanation['requires_revalidation']);
        $this->assertSame('2.1.0-method-d', $explanation['detector_version']);
        $this->assertSame($evaluatedAt, $explanation['evaluated_at']);
        $this->assertSame(211, $explanation['expandable_details']['calibrated_threshold_days']);
        $this->assertSame(3.2, $explanation['expandable_details']['calibrated_multiplier']);
    }

    public function test_validate_finding_evidence_flags_revalidation_when_newer_purchasing_order_detected(): void
    {
        $evaluatedAt = '2026-11-20T12:00:00+00:00';
        $finding = new Finding();
        $finding->forceFill([
            'source_id' => 'opportunity.long_term_winback',
            'evidence_json' => [
                'detector_version' => '2.1.0-method-d',
                'cadence_threshold_days' => 211,
                'days_since_last_purchase' => 301,
                'days_overdue' => 90,
                'median_interval_days' => 66,
                'evaluated_at' => $evaluatedAt,
                'episode_count' => 32,
            ],
        ]);

        // Customer placed a new order on 2026-11-22, AFTER evaluation on 2026-11-20
        $fact = new \App\Models\CommerceCustomerPurchaseFact();
        $fact->forceFill([
            'latest_valid_order_at' => \Illuminate\Support\Carbon::parse('2026-11-22T09:30:00+00:00'),
            'lifetime_valid_order_count' => 33,
        ]);

        $validation = $this->service->validateFindingEvidence($finding, $finding->evidence_json, $fact);

        $this->assertTrue($validation['requires_revalidation']);
        $this->assertStringContainsString('Newer purchasing data detected', $validation['revalidation_reason']);
    }

    public function test_validate_finding_evidence_detector_specific_schemas_without_cross_flagging(): void
    {
        // 1. Long Term Winback
        $winbackFinding = new Finding();
        $winbackFinding->forceFill(['source_id' => 'opportunity.long_term_winback']);
        $winbackEvidence = [
            'detector_version' => '2.1.0-method-d',
            'cadence_threshold_days' => 211,
            'days_since_last_purchase' => 301,
            'days_overdue' => 90,
            'median_interval_days' => 66,
            'evaluated_at' => now()->toIso8601String(),
        ];
        $valWinback = $this->service->validateFindingEvidence($winbackFinding, $winbackEvidence);
        $this->assertFalse($valWinback['requires_revalidation']);

        // 2. B2B Reactivation
        $reactivationFinding = new Finding();
        $reactivationFinding->forceFill(['source_id' => 'opportunity.b2b_account_reactivation']);
        $reactivationEvidence = [
            'detector_version' => '2.0.0',
            'cadence_threshold_days' => 85,
            'days_since_last_purchase' => 110,
            'days_overdue' => 25,
            'evaluated_at' => now()->toIso8601String(),
        ];
        $valReactivation = $this->service->validateFindingEvidence($reactivationFinding, $reactivationEvidence);
        $this->assertFalse($valReactivation['requires_revalidation']);

        // 3. Replenishment Cadence
        $replenishmentFinding = new Finding();
        $replenishmentFinding->forceFill(['source_id' => 'opportunity.replenishment_cadence']);
        $replenishmentEvidence = [
            'detector_version' => '2.0.0',
            'cadence_threshold_days' => 30,
            'days_since_last_purchase' => 45,
            'median_interval_days' => 30,
            'evaluated_at' => now()->toIso8601String(),
        ];
        $valReplenishment = $this->service->validateFindingEvidence($replenishmentFinding, $replenishmentEvidence);
        $this->assertFalse($valReplenishment['requires_revalidation']);

        // 4. Account Contraction (Risk detector: verifies baseline and drop, does NOT require dormancy fields)
        $contractionFinding = new Finding();
        $contractionFinding->forceFill(['source_id' => 'risk.account_contraction']);
        $contractionEvidence = [
            'detector_version' => '2.0.0',
            'baseline_spend' => 25000.00,
            'eval_spend' => 12500.00,
            'evaluated_at' => now()->toIso8601String(),
        ];
        $valContraction = $this->service->validateFindingEvidence($contractionFinding, $contractionEvidence);
        $this->assertFalse($valContraction['requires_revalidation'], $valContraction['revalidation_reason'] ?? '');
    }
}


