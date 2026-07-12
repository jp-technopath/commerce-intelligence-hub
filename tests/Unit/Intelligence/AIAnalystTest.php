<?php

namespace Tests\Unit\Intelligence;

use App\Models\Client;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Services\Intelligence\AIAnalyst;
use App\Services\MeetingAgent\AiProviderService;
use App\Enums\ClientStatus;
use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AIAnalystTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyse_with_context_creates_recommendation(): void
    {
        $client = Client::create([
            'name' => 'Test Client',
            'industry' => 'commercial foodservice/hospitality',
            'platform_type' => 'magento',
            'status' => ClientStatus::Active,
        ]);

        $finding = Finding::create([
            'client_id' => $client->id,
            'finding_type' => 'conversion_decrease',
            'finding_category' => FindingCategory::Conversion,
            'title' => 'Conversion rate dropped',
            'description' => 'Test description',
            'severity' => FindingSeverity::High,
            'status' => FindingStatus::New,
            'detected_at' => now(),
        ]);

        $mockResponse = [
            'investigation_report' => 'This is the AI analysis report.',
            'data_evidence'        => 'Evidence list.',
            'conclusion'           => 'Recommended actions.',
        ];

        $mockAiProvider = $this->createMock(AiProviderService::class);
        $mockAiProvider->expects($this->once())
            ->method('completeJson')
            ->willReturn($mockResponse);

        $mockAiProvider->expects($this->any())
            ->method('getModelName')
            ->willReturn('openai/gpt-4o');

        $analyst = new AIAnalyst($mockAiProvider);
        $recommendation = $analyst->analyseWithContext($finding, [
            'commerce'        => [],
            'behavioral'      => [],
            'performance'     => [],
            'email_marketing' => [],
            'deployments'     => [],
            'data_sources'    => [],
            'server_logs'     => [],
        ]);

        $this->assertInstanceOf(Recommendation::class, $recommendation);
        $this->assertSame($finding->id, $recommendation->finding_id);
        $this->assertSame('This is the AI analysis report.', $recommendation->ai_summary);
        $this->assertSame('Evidence list.', $recommendation->recommendation_text);
        $this->assertSame('Recommended actions.', $recommendation->confidence_reasoning);
        $this->assertSame('openai/gpt-4o', $recommendation->model_used);
    }
}
