<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\PmConnection;
use App\Models\PmWorkItem;
use App\Services\PM\Providers\JiraProvider;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class P1P2FoundationAndDashboardTest extends TestCase
{
    public function test_jira_token_refresh_does_not_mutate_expires_at_carbon_instance(): void
    {
        $account = new ConnectedAccount();
        $account->provider = 'jira';
        $account->token_expires_at = Carbon::now()->addMinutes(10);
        $account->credentials_json = ['access_token' => 'mock_token'];

        $originalExpiresAt = $account->token_expires_at->copy();
        $token = $account->refreshJiraTokenIfNeeded();

        $this->assertEquals('mock_token', $token);
        $this->assertEquals($originalExpiresAt->toDateTimeString(), $account->token_expires_at->toDateTimeString());
    }

    public function test_jira_status_mapping_maps_ready_for_deployment_correctly(): void
    {
        $provider = new JiraProvider();
        $connection = new PmConnection();

        $status = $provider->mapJiraStatusToForge('Ready for Deployment', $connection);
        $this->assertEquals('ready_for_deployment', $status);

        $toDoStatus = $provider->mapJiraStatusToForge('To Do', $connection);
        $this->assertEquals('ready', $toDoStatus);

        $readyStatus = $provider->mapJiraStatusToForge('Ready', $connection);
        $this->assertEquals('ready', $readyStatus);
    }

    public function test_pm_work_item_delivery_status_label_attribute(): void
    {
        $item = new PmWorkItem(['normalized_delivery_status' => 'ready_for_deployment']);
        $this->assertEquals('Ready for Deployment', $item->delivery_status_label);

        $devItem = new PmWorkItem(['normalized_delivery_status' => 'ready']);
        $this->assertEquals('Ready for Dev', $devItem->delivery_status_label);
    }
}
