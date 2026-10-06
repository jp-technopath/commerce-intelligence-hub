<?php

namespace Tests\Feature\SystemPrompt;

use App\Models\SystemPrompt;
use App\Models\SystemPromptVersion;
use App\Models\User;
use App\Services\SystemPrompt\PromptManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SystemPromptVersioningTest extends TestCase
{
    use RefreshDatabase;

    private SystemPrompt $prompt;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->prompt = SystemPrompt::create([
            'key'                        => 'meeting_prep',
            'name'                       => 'Meeting Prep',
            'category'                   => 'meetings',
            'draft_system_prompt'        => 'Draft System v1',
            'draft_user_prompt_template' => 'Draft Template: {{ client_name }}',
            'is_active'                  => true,
        ]);
    }

    public function test_publish_draft_creates_immutable_version_and_updates_published_id(): void
    {
        $version = $this->prompt->publishDraft('First release', $this->user);

        $this->assertInstanceOf(SystemPromptVersion::class, $version);
        $this->assertEquals(1, $version->version_number);
        $this->assertEquals('Draft System v1', $version->system_prompt);
        $this->assertEquals('Draft Template: {{ client_name }}', $version->user_prompt_template);
        $this->assertEquals('First release', $version->publish_notes);
        $this->assertEquals($this->user->id, $version->published_by_user_id);

        $this->prompt->refresh();
        $this->assertEquals($version->id, $this->prompt->published_version_id);
    }

    public function test_publish_fails_when_template_contains_unregistered_variable(): void
    {
        $this->prompt->update([
            'draft_user_prompt_template' => 'Draft with typo: {{ client_nmae }} and {{ random_var }}',
        ]);

        $this->expectException(ValidationException::class);
        $this->prompt->publishDraft('Invalid publish attempt', $this->user);
    }

    public function test_rollback_creates_new_version_with_target_prompts_and_updates_draft(): void
    {
        // 1. Publish version 1
        $v1 = $this->prompt->publishDraft('Version 1 notes', $this->user);

        // 2. Update and publish version 2
        $this->prompt->update([
            'draft_system_prompt'        => 'Draft System v2 (Changed tone)',
            'draft_user_prompt_template' => 'Draft Template v2: {{ client_name }} - {{ client_contact_name }}',
        ]);
        $v2 = $this->prompt->publishDraft('Version 2 notes', $this->user);

        $this->assertEquals(2, $v2->version_number);
        $this->prompt->refresh();
        $this->assertEquals($v2->id, $this->prompt->published_version_id);

        // 3. Rollback to v1
        $v3 = $this->prompt->rollbackTo($v1, 'Rolling back to v1 due to feedback', $this->user);

        $this->assertEquals(3, $v3->version_number);
        $this->assertEquals($v1->system_prompt, $v3->system_prompt);
        $this->assertEquals($v1->user_prompt_template, $v3->user_prompt_template);

        $this->prompt->refresh();
        $this->assertEquals($v3->id, $this->prompt->published_version_id);
        $this->assertEquals($v1->system_prompt, $this->prompt->draft_system_prompt);
        $this->assertEquals($v1->user_prompt_template, $this->prompt->draft_user_prompt_template);
    }

    public function test_reset_to_factory_default_restores_code_baseline(): void
    {
        $this->prompt->update([
            'draft_system_prompt'        => 'Altered text that deviates from default',
            'draft_user_prompt_template' => 'Altered template',
        ]);

        $this->prompt->resetDraftToFactoryDefault($this->user);
        $this->prompt->refresh();

        $def = app(PromptManager::class)->getDefinition('meeting_prep');
        $this->assertEquals($def->getDefaultSystemPrompt(), $this->prompt->draft_system_prompt);
        $this->assertEquals($def->getDefaultUserPromptTemplate(), $this->prompt->draft_user_prompt_template);
    }

    public function test_concurrent_publish_cannot_create_duplicate_version_numbers(): void
    {
        // Publish version 1 first
        $v1 = $this->prompt->publishDraft('Initial', $this->user);
        $this->assertEquals(1, $v1->version_number);

        // Simulate two sequential / concurrent transactions attempting to publish
        $v2 = $this->prompt->publishDraft('Second publish', $this->user);
        $v3 = $this->prompt->publishDraft('Third publish', $this->user);

        $this->assertEquals(2, $v2->version_number);
        $this->assertEquals(3, $v3->version_number);

        $versionNumbers = $this->prompt->versions()->pluck('version_number')->all();
        $this->assertCount(3, $versionNumbers);
        $this->assertEquals([3, 2, 1], $versionNumbers);
    }
}
