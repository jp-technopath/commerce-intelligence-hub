<?php

namespace Tests\Feature\Intelligence;

use App\Enums\ClientStatus;
use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Filament\Resources\FindingResource\Pages\ViewFinding;
use App\Jobs\Intelligence\GenerateAIAnalysis;
use App\Models\Client;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Intelligence\AIAnalyst;
use App\Services\Intelligence\DataContextGatherer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class GenerateAIAnalysisJobTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private User $user;
    private Finding $finding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'name'          => 'Test Retailer',
            'industry'      => 'apparel',
            'platform_type' => 'Shopify',
            'status'        => ClientStatus::Active,
        ]);

        $this->user = User::factory()->create([
            'email' => 'admin@technopath.test',
        ]);

        $this->finding = Finding::create([
            'client_id'        => $this->client->id,
            'title'            => 'Conversion Rate Drop',
            'description'      => 'Significant drop in checkout conversion rate.',
            'finding_type'     => 'anomaly',
            'finding_category' => FindingCategory::Conversion,
            'severity'         => FindingSeverity::High,
            'status'           => FindingStatus::New,
            'detected_at'      => now(),
        ]);
    }

    public function test_view_finding_action_dispatches_job_to_queue_and_marks_pending(): void
    {
        Queue::fake();

        $this->actingAs($this->user);

        Livewire::test(ViewFinding::class, ['record' => $this->finding->id])
            ->callAction('generate_ai', [
                'model' => 'openai/gpt-4o',
            ])
            ->assertHasNoActionErrors();

        Queue::assertPushed(GenerateAIAnalysis::class, function ($job) {
            return $job->finding->id === $this->finding->id;
        });

        $fresh = $this->finding->fresh();
        $this->assertSame('pending', $fresh->metadata_json['ai_analysis_status']);
        $this->assertNotNull($fresh->metadata_json['ai_analysis_started_at']);
    }

    public function test_job_clears_pending_status_and_marks_finding_investigating_on_success(): void
    {
        $mockGatherer = Mockery::mock('overload:' . DataContextGatherer::class);
        $mockGatherer->shouldReceive('gatherContext')->once()->andReturn([
            'commerce'        => [],
            'behavioral'      => [],
            'performance'     => [],
            'email_marketing' => [],
            'deployments'     => [],
            'server_logs'     => [],
            'data_sources'    => [],
        ]);

        $mockAnalyst = Mockery::mock('overload:' . AIAnalyst::class);
        $mockAnalyst->shouldReceive('analyseWithContext')->once()->andReturn(
            Recommendation::create([
                'finding_id'          => $this->finding->id,
                'ai_summary'          => 'Test Analysis',
                'recommendation_text' => 'Test Action',
                'model_used'          => 'test-model',
            ])
        );

        $job = new GenerateAIAnalysis($this->finding);
        $job->handle();

        $fresh = $this->finding->fresh();
        $this->assertSame(FindingStatus::Investigating, $fresh->status);
        $this->assertArrayNotHasKey('ai_analysis_status', $fresh->metadata_json ?? []);
    }

    public function test_job_marks_failed_status_when_no_recommendation_is_returned(): void
    {
        $mockGatherer = Mockery::mock('overload:' . DataContextGatherer::class);
        $mockGatherer->shouldReceive('gatherContext')->once()->andReturn([
            'commerce'        => [],
            'behavioral'      => [],
            'performance'     => [],
            'email_marketing' => [],
            'deployments'     => [],
            'server_logs'     => [],
            'data_sources'    => [],
        ]);

        $mockAnalyst = Mockery::mock('overload:' . AIAnalyst::class);
        $mockAnalyst->shouldReceive('analyseWithContext')->once()->andReturn(null);

        $job = new GenerateAIAnalysis($this->finding);
        $job->handle();

        $fresh = $this->finding->fresh();
        $this->assertSame('failed', $fresh->metadata_json['ai_analysis_status'] ?? null);
        $this->assertNotNull($fresh->metadata_json['ai_analysis_error'] ?? null);
    }

    public function test_job_failed_handler_sets_failed_metadata(): void
    {
        $job = new GenerateAIAnalysis($this->finding);
        $job->failed(new \RuntimeException('Connection timeout to OpenRouter'));

        $fresh = $this->finding->fresh();
        $this->assertSame('failed', $fresh->metadata_json['ai_analysis_status']);
        $this->assertSame('Connection timeout to OpenRouter', $fresh->metadata_json['ai_analysis_error']);
    }
}
