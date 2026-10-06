<?php

namespace Tests\Feature\SystemPrompt;

use App\Filament\Resources\SystemPromptResource\Pages\EditSystemPrompt;
use App\Models\Role;
use App\Models\SystemPrompt;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SystemPromptSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SystemPromptResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected SystemPrompt $systemPrompt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SystemPromptSeeder::class);

        $this->adminUser = User::factory()->create(['is_admin' => true]);
        $superAdminRole = Role::where('name', Role::ROLE_SUPER_ADMIN)->firstOrFail();
        UserRoleAssignment::create([
            'user_id'   => $this->adminUser->id,
            'role_id'   => $superAdminRole->id,
            'is_active' => true,
        ]);

        $this->systemPrompt = SystemPrompt::where('key', 'meeting_prep')->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_edit_page_renders_successfully(): void
    {
        $response = $this->actingAs($this->adminUser)->get("/admin/system-prompts/{$this->systemPrompt->id}/edit");
        $response->assertStatus(200);
    }

    public function test_can_mount_preview_action_and_render_modal(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(EditSystemPrompt::class, [
            'record' => $this->systemPrompt->getRouteKey(),
        ])
            ->mountAction('preview')
            ->assertActionMounted('preview')
            ->assertSee('System Instructions')
            ->assertSee('Interpolated User Prompt')
            ->assertSee('All template variables match registered definitions');
    }

    public function test_preview_action_reflects_unsaved_draft_changes(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(EditSystemPrompt::class, [
            'record' => $this->systemPrompt->getRouteKey(),
        ])
            ->set('data.draft_system_prompt', 'Custom draft persona instructions for testing preview.')
            ->mountAction('preview')
            ->assertActionMounted('preview')
            ->assertSee('Custom draft persona instructions for testing preview.');
    }

    public function test_preview_warns_on_unregistered_variables(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(EditSystemPrompt::class, [
            'record' => $this->systemPrompt->getRouteKey(),
        ])
            ->set('data.draft_user_prompt_template', 'Client: {{ client_name }} and Unknown: {{ non_existent_var }}')
            ->mountAction('preview')
            ->assertActionMounted('preview')
            ->assertSee('Unregistered variables detected')
            ->assertSee('non_existent_var');
    }

    public function test_can_publish_draft_via_action(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(EditSystemPrompt::class, [
            'record' => $this->systemPrompt->getRouteKey(),
        ])
            ->callAction('publish', [
                'publish_notes' => 'Testing release from admin action',
            ])
            ->assertHasNoActionErrors();

        $this->systemPrompt->refresh();
        $this->assertEquals(2, $this->systemPrompt->publishedVersion->version_number);
        $this->assertEquals('Testing release from admin action', $this->systemPrompt->publishedVersion->publish_notes);
    }

    public function test_publishing_blocks_when_unknown_variable_present(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(EditSystemPrompt::class, [
            'record' => $this->systemPrompt->getRouteKey(),
        ])
            ->set('data.draft_user_prompt_template', 'Invalid prompt with {{ bogus_variable }}')
            ->callAction('publish', [
                'publish_notes' => 'Should fail',
            ]);

        $this->systemPrompt->refresh();
        // Version must still be 1 (blocked)
        $this->assertEquals(1, $this->systemPrompt->publishedVersion->version_number);
    }

    public function test_can_reset_draft_to_factory_default(): void
    {
        $this->actingAs($this->adminUser);

        $this->systemPrompt->update([
            'draft_system_prompt' => 'Modified system prompt before reset',
        ]);

        Livewire::test(EditSystemPrompt::class, [
            'record' => $this->systemPrompt->getRouteKey(),
        ])
            ->callAction('reset_default');

        $this->systemPrompt->refresh();
        $this->assertStringContainsString('senior project manager assistant', $this->systemPrompt->draft_system_prompt);
    }
}
