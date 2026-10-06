<?php

namespace Tests\Feature\SystemPrompt;

use App\Filament\Resources\SystemPromptResource;
use App\Models\Role;
use App\Models\SystemPrompt;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SystemPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemPromptResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $productOwner;
    protected User $clientUser;
    protected SystemPrompt $systemPrompt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SystemPromptSeeder::class);

        $this->superAdmin = User::factory()->create(['is_admin' => true]);
        $superAdminRole = Role::where('name', Role::ROLE_SUPER_ADMIN)->firstOrFail();
        UserRoleAssignment::create([
            'user_id'   => $this->superAdmin->id,
            'role_id'   => $superAdminRole->id,
            'is_active' => true,
        ]);

        $this->productOwner = User::factory()->create(['is_admin' => false]);
        $poRole = Role::where('name', Role::ROLE_PRODUCT_OWNER)->firstOrFail();
        UserRoleAssignment::create([
            'user_id'   => $this->productOwner->id,
            'role_id'   => $poRole->id,
            'is_active' => true,
        ]);

        $this->clientUser = User::factory()->create(['is_admin' => false]);
        $clientRole = Role::where('name', Role::ROLE_CLIENT_USER)->firstOrFail();
        UserRoleAssignment::create([
            'user_id'   => $this->clientUser->id,
            'role_id'   => $clientRole->id,
            'is_active' => true,
        ]);

        $this->systemPrompt = SystemPrompt::where('key', 'meeting_prep')->firstOrFail();
    }

    public function test_super_admin_has_full_access(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertTrue(SystemPromptResource::canViewAny());
        $this->assertTrue(SystemPromptResource::canEdit($this->systemPrompt));
    }

    public function test_product_owner_with_permission_can_view_and_edit(): void
    {
        $this->actingAs($this->productOwner);

        $this->assertTrue(SystemPromptResource::canViewAny());
        $this->assertTrue(SystemPromptResource::canEdit($this->systemPrompt));
    }

    public function test_client_user_without_permission_cannot_access(): void
    {
        $this->actingAs($this->clientUser);

        $this->assertFalse(SystemPromptResource::canViewAny());
        $this->assertFalse(SystemPromptResource::canEdit($this->systemPrompt));
    }
}
