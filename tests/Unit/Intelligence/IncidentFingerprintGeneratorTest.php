<?php

namespace Tests\Unit\Intelligence;

use App\Services\Intelligence\Synthesis\IncidentFingerprintGenerator;
use Carbon\Carbon;
use Tests\TestCase;

class IncidentFingerprintGeneratorTest extends TestCase
{
    private IncidentFingerprintGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new IncidentFingerprintGenerator();
    }

    public function test_generates_stable_fingerprint_structure(): void
    {
        $clientId = 5;
        $family = 'technical.script_error';
        $scopeType = 'page';
        $scopeTarget = '/tapes/packaging-tapes.html';
        $detectedDate = Carbon::parse('2026-11-02 10:00:00');

        $result = $this->generator->generate($clientId, $family, $scopeType, $scopeTarget, $detectedDate);

        $this->assertEquals('5:technical.script_error:page:tapes_packaging_tapes_html:ep_20261102', $result['fingerprint']);
        $this->assertEquals('ep_20261102', $result['episode_id']);
        $this->assertEquals('new_episode', $result['lifecycle_state']);
    }

    public function test_matches_active_episode_within_dormancy_threshold(): void
    {
        $clientId = 5;
        $family = 'technical.script_error';
        $scopeType = 'page';
        $scopeTarget = '/tapes/packaging-tapes.html';

        $existingEpisodes = [
            [
                'scope_key' => '5:technical.script_error:page:tapes_packaging_tapes_html',
                'episode_id' => 'ep_20261102',
                'last_seen' => '2026-11-03 12:00:00',
            ],
        ];

        // 2 days later (within 7-day dormancy threshold)
        $detectedDate = Carbon::parse('2026-11-05 14:00:00');

        $result = $this->generator->generate($clientId, $family, $scopeType, $scopeTarget, $detectedDate, $existingEpisodes);

        $this->assertEquals('ep_20261102', $result['episode_id']);
        $this->assertEquals('active_episode', $result['lifecycle_state']);
        $this->assertEquals('5:technical.script_error:page:tapes_packaging_tapes_html:ep_20261102', $result['fingerprint']);
    }

    public function test_reopens_episode_when_dormancy_threshold_exceeded(): void
    {
        $clientId = 5;
        $family = 'technical.script_error';
        $scopeType = 'page';
        $scopeTarget = '/tapes/packaging-tapes.html';

        $existingEpisodes = [
            [
                'scope_key' => '5:technical.script_error:page:tapes_packaging_tapes_html',
                'episode_id' => 'ep_20261001',
                'last_seen' => '2026-10-05 12:00:00',
            ],
        ];

        // 28 days later (exceeds 7-day dormancy threshold)
        $detectedDate = Carbon::parse('2026-11-02 10:00:00');

        $result = $this->generator->generate($clientId, $family, $scopeType, $scopeTarget, $detectedDate, $existingEpisodes);

        $this->assertEquals('ep_20261001', $result['episode_id']);
        $this->assertEquals('reopened_episode', $result['lifecycle_state']);
    }
}
