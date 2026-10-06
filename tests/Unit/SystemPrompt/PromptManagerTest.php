<?php

namespace Tests\Unit\SystemPrompt;

use App\Models\SystemPrompt;
use App\Models\SystemPromptVersion;
use App\Services\SystemPrompt\Definitions\MeetingPrepPromptDefinition;
use App\Services\SystemPrompt\PromptManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PromptManagerTest extends TestCase
{
    use RefreshDatabase;

    private PromptManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new PromptManager();
        Cache::flush();
    }

    public function test_it_registers_and_retrieves_definitions(): void
    {
        $def = $this->manager->getDefinition('meeting_prep');
        $this->assertInstanceOf(MeetingPrepPromptDefinition::class, $def);
        $this->assertEquals('meeting_prep', $def->getKey());
        $this->assertArrayHasKey('client_name', $def->getAvailableVariables());
    }

    public function test_it_extracts_variables_correctly(): void
    {
        $template = "Hello {{ client_name }}, your meeting is on {{ meeting_date }} at {{meeting_time}}.";
        $vars = $this->manager->extractVariables($template);

        $this->assertEqualsCanonicalizing(['client_name', 'meeting_date', 'meeting_time'], $vars);
    }

    public function test_it_validates_template_variables_succeeds_when_all_variables_registered(): void
    {
        $template = "Client: {{ client_name }}, Contact: {{ client_contact_name }}";
        $result = $this->manager->validateTemplateVariables('meeting_prep', $template);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['unregistered_variables']);
    }

    public function test_it_validates_template_variables_fails_when_unregistered_variable_present(): void
    {
        $template = "Client: {{ client_nmae }}, Contact: {{ unknown_field }}";
        $result = $this->manager->validateTemplateVariables('meeting_prep', $template);

        $this->assertFalse($result['valid']);
        $this->assertEqualsCanonicalizing(['client_nmae', 'unknown_field'], $result['unregistered_variables']);
    }

    public function test_it_interpolates_variables_without_altering_xml_tags(): void
    {
        $template = "<JIRA_DATA>\n{{ jira_data }}\n</JIRA_DATA>";
        $interpolated = $this->manager->interpolate($template, ['jira_data' => '{"issues": 5}']);

        $this->assertEquals("<JIRA_DATA>\n{\"issues\": 5}\n</JIRA_DATA>", $interpolated);
    }

    public function test_it_resolves_fallback_definition_when_no_published_record(): void
    {
        $resolved = $this->manager->resolve('meeting_prep', ['client_name' => 'Acme Corp']);

        $this->assertEquals('default', $resolved['version_number']);
        $this->assertStringContainsString('Acme Corp', $resolved['user_prompt']);
        $this->assertStringContainsString('senior project manager assistant', $resolved['system_prompt']);
    }

    public function test_it_resolves_active_published_version_when_available(): void
    {
        $prompt = SystemPrompt::create([
            'key'                        => 'meeting_prep',
            'name'                       => 'Meeting Prep',
            'category'                   => 'meetings',
            'draft_system_prompt'        => 'Custom System Instructions',
            'draft_user_prompt_template' => 'Custom User Template: {{ client_name }}',
            'is_active'                  => true,
        ]);

        $version = SystemPromptVersion::create([
            'system_prompt_id'     => $prompt->id,
            'version_number'       => 1,
            'system_prompt'        => 'Published Custom System Instructions',
            'user_prompt_template' => 'Published Template for {{ client_name }}',
            'created_at'           => now(),
        ]);

        $prompt->update(['published_version_id' => $version->id]);
        $this->manager->clearCache('meeting_prep');

        $resolved = $this->manager->resolve('meeting_prep', ['client_name' => 'Cyberdyne']);

        $this->assertEquals(1, $resolved['version_number']);
        $this->assertEquals('Published Custom System Instructions', $resolved['system_prompt']);
        $this->assertEquals('Published Template for Cyberdyne', $resolved['user_prompt']);
    }

    public function test_preview_draft_fills_examples_and_reports_validation(): void
    {
        $preview = $this->manager->previewDraft(
            'meeting_prep',
            'System draft instructions',
            'Hello {{ client_name }}, your contact is {{ client_contact_name }}'
        );

        $this->assertTrue($preview['valid']);
        $this->assertStringContainsString('Acme Foodservice Equipment', $preview['user_prompt']);
        $this->assertStringContainsString('Sarah Jenkins', $preview['user_prompt']);
    }
}
