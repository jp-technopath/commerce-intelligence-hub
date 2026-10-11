<?php

namespace Tests\Feature;

use App\Filament\Pages\EngineerDashboard;
use App\Models\User;
use App\Services\PM\IdentityResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JiraUserIdentityResolutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function identity_resolver_resolves_by_account_id_email_and_name_aliases()
    {
        // 1. By Account ID
        $this->assertEquals(2, IdentityResolverService::resolveUserId('jira', '557058:32896f1a-9055-4a58-a03b-0bd86412a2db'));
        $this->assertEquals(4, IdentityResolverService::resolveUserId('jira', '712020:7fcac433-e79a-4587-8eda-11ef61607fc2'));
        $this->assertEquals(3, IdentityResolverService::resolveUserId('jira', '64007b8f896d10ebd471046d'));

        // 2. By Email Aliases
        $this->assertEquals(2, IdentityResolverService::resolveUserId('jira', null, 'jeanpaulhanna@gmail.com'));
        $this->assertEquals(4, IdentityResolverService::resolveUserId('jira', null, 'nour.ayman91@gmail.com'));
        $this->assertEquals(3, IdentityResolverService::resolveUserId('jira', null, 'doniaakhaled@gmail.com'));

        // 3. By Display Name Aliases
        $this->assertEquals(2, IdentityResolverService::resolveUserId('jira', null, null, 'Jean Paul Hanna'));
        $this->assertEquals(4, IdentityResolverService::resolveUserId('jira', null, null, 'Nour eldin ayman'));
        $this->assertEquals(3, IdentityResolverService::resolveUserId('jira', null, null, 'Donia Khaled'));
    }

    #[Test]
    public function effective_user_resolution_aliases_google_and_po_accounts()
    {
        $userJp = User::create([
            'id'       => 2,
            'name'     => 'Jean Paul Hanna',
            'email'    => 'jp@technopath.co',
            'password' => Hash::make('password'),
        ]);

        $userGoogle = User::create([
            'id'       => 6,
            'name'     => 'Jean Paul',
            'email'    => 'jeanpaulhanna@gmail.com',
            'password' => Hash::make('password'),
        ]);

        $effective = IdentityResolverService::resolveEffectiveUser($userGoogle);
        $this->assertEquals($userJp->id, $effective->id);
        $this->assertEquals($userJp->name, $effective->name);

        $effectiveSelf = IdentityResolverService::resolveEffectiveUser($userJp);
        $this->assertEquals($userJp->id, $effectiveSelf->id);
    }

    #[Test]
    public function engineer_dashboard_smartly_selects_active_engineer_for_admin_or_aliased_users()
    {
        $admin = User::create([
            'id'       => 1,
            'name'     => 'Technopath Admin',
            'email'    => 'admin@technopath.com.au',
            'password' => Hash::make('password'),
        ]);

        $userJp = User::create([
            'id'       => 2,
            'name'     => 'Jean Paul Hanna',
            'email'    => 'jp@technopath.co',
            'password' => Hash::make('password'),
        ]);

        $dashboard = new EngineerDashboard();

        // Admin login defaults to User 2
        Auth::login($admin);
        $selected = $dashboard->getSelectedUser();
        $this->assertNotNull($selected);
        $this->assertEquals(2, $selected->id);

        // Google user login resolves to User 2
        $userGoogle = User::create([
            'id'       => 6,
            'name'     => 'Jean Paul',
            'email'    => 'jeanpaulhanna@gmail.com',
            'password' => Hash::make('password'),
        ]);
        Auth::login($userGoogle);
        $selectedGoogle = $dashboard->getSelectedUser();
        $this->assertNotNull($selectedGoogle);
        $this->assertEquals(2, $selectedGoogle->id);
    }
}
