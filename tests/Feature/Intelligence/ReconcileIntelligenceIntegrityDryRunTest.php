<?php

namespace Tests\Feature\Intelligence;

use App\Enums\ClientStatus;
use App\Enums\FindingCategory;
use App\Enums\FindingPillar;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Client;
use App\Models\Finding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileIntelligenceIntegrityDryRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_makes_zero_mutations_while_apply_is_idempotent_and_preserves_dismissals(): void
    {
        $client = Client::create([
            'name'          => 'Reconciliation Test Client',
            'industry'      => 'Packaging',
            'platform_type' => 'Magento',
            'status'        => ClientStatus::Active,
        ]);

        // 1. Null-pillar operational finding needing backfill
        $nullPillar = Finding::create([
            'client_id'           => $client->id,
            'finding_type'        => 'attribution.platform_variance',
            'fingerprint'         => hash('sha256', 'rec_null'),
            'source_type'         => 'detector',
            'source_id'           => 'ga4_vs_commerce',
            'title'               => 'Platform variance',
            'finding_category'    => FindingCategory::Technical,
            'severity'            => FindingSeverity::Medium,
            'status'              => FindingStatus::New,
            'finding_pillar'      => null,
            'is_customer_visible' => true,
        ]);

        // 2. Active-lifecycle Clarity script_error duplicate pair (UnderReview survivor with legacy stub strings + New duplicate)
        $survivor = Finding::create([
            'client_id'           => $client->id,
            'finding_type'        => 'technical.script_error',
            'fingerprint'         => hash('sha256', 'rec_dup_1'),
            'source_type'         => 'page',
            'source_id'           => 'tapes_packaging_tapes',
            'title'               => 'JavaScript error rate increased on packaging tapes (+216.5% per session)',
            'description'         => 'Legacy cart_item_options claim on https://www.tgoldkamp.com/tapes/packaging-tapes.html',
            'finding_category'    => FindingCategory::Technical,
            'severity'            => FindingSeverity::High,
            'status'              => FindingStatus::UnderReview,
            'finding_pillar'      => null,
            'is_customer_visible' => true,
            'evidence_json'       => [
                'error_count'      => 17221,
                'clarity_sessions' => 8010,
                'affected_url'     => 'https://www.tgoldkamp.com/tapes/packaging-tapes.html',
                'exception_type'   => 'cart_item_options',
            ],
            'metadata_json'       => [
                'episode_id'     => 'ep_20261107',
                'primary_signal' => [
                    'family'       => 'technical.script_error',
                    'scope_target' => 'tapes_packaging_tapes',
                ],
            ],
        ]);
        $duplicate = Finding::create([
            'client_id'           => $client->id,
            'finding_type'        => 'technical.script_error',
            'fingerprint'         => hash('sha256', 'rec_dup_2'),
            'source_type'         => 'page',
            'source_id'           => 'tapes_packaging_tapes',
            'title'               => 'JavaScript error rate increased on packaging tapes (+216.5% per session)',
            'finding_category'    => FindingCategory::Technical,
            'severity'            => FindingSeverity::High,
            'status'              => FindingStatus::New,
            'finding_pillar'      => null,
            'is_customer_visible' => true,
            'evidence_json'       => [
                'error_count'      => 116,
                'clarity_sessions' => 1397,
            ],
        ]);

        // 3. Fabricated Signal #3 stub (attribution.campaign_tagging with static $4,658.47) alongside an actual rule-evaluated Klaviyo variance sibling
        $klaviyoSibling = Finding::create([
            'client_id'           => $client->id,
            'finding_type'        => 'ga4_klaviyo_attribution_variance',
            'fingerprint'         => hash('sha256', 'rec_klaviyo_real'),
            'source_type'         => 'rule',
            'title'               => 'GA4 vs Klaviyo Attribution Variance (100% Discrepancy)',
            'finding_category'    => FindingCategory::Technical,
            'severity'            => FindingSeverity::Medium,
            'status'              => FindingStatus::New,
            'finding_pillar'      => null,
            'is_customer_visible' => true,
            'metadata_json'       => [
                'rule_key'          => 'ga4_klaviyo_attribution_variance',
                'difference_amount' => 4846.63,
            ],
        ]);
        $fabricated = Finding::create([
            'client_id'           => $client->id,
            'finding_type'        => 'attribution.campaign_tagging',
            'fingerprint'         => hash('sha256', 'rec_fab'),
            'source_type'         => 'detector',
            'source_id'           => 'email_klaviyo',
            'title'               => 'Email campaign revenue unassigned in GA4 (fabricated)',
            'finding_category'    => FindingCategory::Technical,
            'severity'            => FindingSeverity::Medium,
            'status'              => FindingStatus::New,
            'finding_pillar'      => null,
            'is_customer_visible' => true,
            'evidence_json'       => [
                'klaviyo_revenue'   => 4658.47,
                'ga4_email_revenue' => 0,
            ],
        ]);

        // 4. User-dismissed finding that must never be reactivated or altered
        $dismissed = Finding::create([
            'client_id'           => $client->id,
            'finding_type'        => 'technical.script_error',
            'fingerprint'         => hash('sha256', 'rec_dismissed'),
            'source_type'         => 'detector',
            'title'               => 'Previously dismissed finding',
            'finding_category'    => FindingCategory::Technical,
            'severity'            => FindingSeverity::Medium,
            'status'              => FindingStatus::Dismissed,
            'finding_pillar'      => null,
            'is_customer_visible' => true,
        ]);

        // Dry run must mutate zero rows
        $this->artisan('forge:reconcile-intelligence-integrity', [
            '--dry-run' => true,
            '--client'  => $client->id,
        ])->assertExitCode(0);

        $this->assertNull($nullPillar->fresh()->finding_pillar);
        $this->assertSame(FindingStatus::New, $duplicate->fresh()->status);
        $this->assertSame(FindingStatus::New, $fabricated->fresh()->status);
        $this->assertSame(FindingStatus::Dismissed, $dismissed->fresh()->status);

        // Apply in isolated SQLite :memory:
        $this->artisan('forge:reconcile-intelligence-integrity', [
            '--apply'  => true,
            '--client' => $client->id,
        ])->assertExitCode(0);

        $this->assertSame(FindingPillar::TechnicalHealth, $nullPillar->fresh()->finding_pillar);

        $survivorFresh = $survivor->fresh();
        $this->assertSame(FindingStatus::UnderReview, $survivorFresh->status);
        $this->assertSame(FindingPillar::TechnicalHealth, $survivorFresh->finding_pillar);
        $this->assertSame('sitewide', $survivorFresh->source_type);
        $this->assertSame('clarity_script_errors', $survivorFresh->source_id);
        $this->assertSame(hash('sha256', "{$client->id}:technical.script_error:sitewide:clarity_script_errors:ep_20261107"), $survivorFresh->fingerprint);
        $this->assertLessThanOrEqual(64, strlen((string) $survivorFresh->fingerprint));
        $this->assertStringContainsString('116 errors', (string) $survivorFresh->title);
        $this->assertStringNotContainsString('17,221', (string) $survivorFresh->title);
        $this->assertStringNotContainsString('packaging-tapes.html', (string) $survivorFresh->description);
        $this->assertStringNotContainsString('cart_item_options', json_encode($survivorFresh->evidence_json));
        $this->assertStringNotContainsString('216.5', (string) $survivorFresh->title);
        $auditTrail = $survivorFresh->metadata_json['integrity_reconciliation_audit'] ?? [];
        $this->assertNotEmpty($auditTrail);
        $this->assertStringContainsString('packaging-tapes.html', (string) ($auditTrail[0]['prior_description'] ?? ''));

        $dupFresh = $duplicate->fresh();
        $this->assertSame(FindingStatus::Dismissed, $dupFresh->status);
        $this->assertSame('consolidated_duplicate', $dupFresh->dismissed_reason);
        $this->assertSame($survivor->id, (int) ($dupFresh->metadata_json['superseded_by_finding_id'] ?? 0));
        $this->assertNotEmpty($dupFresh->metadata_json['integrity_reconciliation_audit'] ?? []);

        $fabFresh = $fabricated->fresh();
        $this->assertSame(FindingStatus::Dismissed, $fabFresh->status);
        $this->assertSame('invalidated_fabricated_signal', $fabFresh->dismissed_reason);
        $this->assertSame('invalidated_unsupported_intelligence', $fabFresh->metadata_json['disposition_type'] ?? null);
        $this->assertArrayNotHasKey('superseded_by_finding_id', $fabFresh->metadata_json ?? []);
        $this->assertSame($klaviyoSibling->id, (int) ($fabFresh->metadata_json['related_finding_id'] ?? 0));
        $this->assertNotEmpty($fabFresh->metadata_json['integrity_reconciliation_audit'] ?? []);

        // Genuine Klaviyo variance sibling must NOT absorb fabricated static $4,658.47 evidence from $fabricated
        $klavFresh = $klaviyoSibling->fresh();
        $this->assertSame(FindingStatus::New, $klavFresh->status);
        $this->assertSame(FindingPillar::TechnicalHealth, $klavFresh->finding_pillar);
        $this->assertEmpty($klavFresh->metadata_json['observation_history'] ?? []);
        $this->assertStringNotContainsString('4658.47', json_encode($klavFresh->metadata_json) . json_encode($klavFresh->evidence_json));

        $disFresh = $dismissed->fresh();
        $this->assertSame(FindingStatus::Dismissed, $disFresh->status);
        $this->assertEmpty($disFresh->metadata_json['integrity_reconciliation_audit'] ?? []);

        // Second --apply run must be completely idempotent
        $exit = \Illuminate\Support\Facades\Artisan::call('forge:reconcile-intelligence-integrity', [
            '--apply'  => true,
            '--client' => $client->id,
            '--format' => 'json',
        ]);
        $this->assertSame(0, $exit);
        $secondPayload = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
        $this->assertIsArray($secondPayload);
        $this->assertSame(0, $secondPayload['summary']['pillar_updates'] ?? -1);
        $this->assertSame(0, $secondPayload['summary']['consolidated_duplicates'] ?? -1);
        $this->assertSame(0, $secondPayload['summary']['invalidated_fabricated'] ?? -1);
        $this->assertSame(0, $secondPayload['summary']['evidence_freshness_updates'] ?? -1);

        // Post-apply --dry-run must also report zero outstanding actions
        $dryExit = \Illuminate\Support\Facades\Artisan::call('forge:reconcile-intelligence-integrity', [
            '--dry-run' => true,
            '--client'  => $client->id,
            '--format'  => 'json',
        ]);
        $this->assertSame(0, $dryExit);
        $dryPayload = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
        $this->assertSame(6, $dryPayload['summary']['unchanged'] ?? -1);
        $this->assertSame(0, $dryPayload['summary']['reconciled_active_findings'] ?? -1);
        $this->assertSame(0, $dryPayload['summary']['evidence_freshness_updates'] ?? -1);
        $this->assertSame([], $dryPayload['actions'] ?? null);
    }
}
