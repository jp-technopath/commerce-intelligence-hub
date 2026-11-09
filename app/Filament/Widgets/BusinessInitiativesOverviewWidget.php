<?php

namespace App\Filament\Widgets;

use App\Enums\FindingExecutionStatus;
use App\Enums\FindingPillar;
use App\Enums\FindingStatus;
use App\Enums\TargetBusinessRole;
use App\Models\Client;
use App\Models\Finding;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\User;
use App\Services\Intelligence\CustomerPurchaseAnalysisService;
use App\Services\Intelligence\InitiativeAggregationService;
use App\Services\PM\FindingPmSyncService;
use App\Services\PM\ProjectManagementProvider;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

class BusinessInitiativesOverviewWidget extends Widget
{
    protected static string $view = 'filament.widgets.business-initiatives-overview-widget';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    #[Url]
    public ?int $selectedClientId = null;

    #[Url]
    public ?string $selectedPillar = null;

    #[Url]
    public ?string $selectedInitiativeId = null;

    // Modal state for assigning finding
    #[Url]
    public ?int $assignFindingId = null;
    public ?int $assigneeUserId = null;
    public ?string $assignTargetRole = 'sales';
    public ?string $assignNotes = null;

    // Modal state for creating PM work item
    #[Url]
    public ?int $createPmFindingId = null;
    public ?int $createPmProjectId = null;
    public ?string $createPmTitle = null;
    public ?string $createPmDescription = null;
    public ?string $createPmPriority = 'Medium';
    public ?string $createPmIssueType = 'Task';

    // Modal state for linking PM work item
    #[Url]
    public ?int $linkPmFindingId = null;
    public ?int $linkPmWorkItemId = null;

    // Modal state for resolving finding
    #[Url]
    public ?int $resolveFindingId = null;
    public ?string $resolutionNotes = null;
    public ?string $businessOutcome = 'reorder_secured';

    // Modal state for dismissing finding
    public ?int $dismissFindingId = null;
    public ?string $dismissalReason = null;

    // Batch approval confirmation modal state
    #[Url]
    public ?string $batchApproveInitiativeId = null;
    public array $batchApproveSummary = [];

    // Audit trail view
    #[Url]
    public ?int $auditFindingId = null;

    public ?array $activeFindingCustomerDisplay = null;

    public function getActiveFindingCustomerDisplay(): ?array
    {
        if ($this->activeFindingCustomerDisplay !== null) {
            return $this->activeFindingCustomerDisplay;
        }

        $findingId = $this->assignFindingId
            ?: ($this->createPmFindingId
            ?: ($this->resolveFindingId
            ?: $this->auditFindingId));

        if (! $findingId) {
            return null;
        }

        $finding = Finding::find($findingId);
        if (! $finding || ! $finding->account_identity_hash) {
            return null;
        }

        return app(CustomerPurchaseAnalysisService::class)
            ->resolveCustomerIdentityDisplay($finding->client_id, $finding->account_identity_hash);
    }

    public function mount(): void
    {
        if (request()->has('createPmFindingId')) {
            $this->createPmFindingId = (int) request()->query('createPmFindingId');
            $this->openCreatePmModal($this->createPmFindingId);
        }
        if (request()->has('linkPmFindingId')) {
            $this->linkPmFindingId = (int) request()->query('linkPmFindingId');
            $this->openLinkPmModal($this->linkPmFindingId);
        }
        if (request()->has('selectedClientId')) {
            $this->selectedClientId = (int) request()->query('selectedClientId');
        }
        if (request()->has('selectedPillar')) {
            $this->selectedPillar = request()->query('selectedPillar');
        }
        if (request()->has('selectedInitiativeId')) {
            $this->selectedInitiativeId = request()->query('selectedInitiativeId');
        }
        if (request()->has('batchApproveInitiativeId')) {
            $this->batchApproveInitiativeId = request()->query('batchApproveInitiativeId');
            $this->openBatchApproveModal($this->batchApproveInitiativeId);
        }
        if (request()->has('assignFindingId')) {
            $this->openAssignModal((int) request()->query('assignFindingId'));
        }
        if (request()->has('resolveFindingId')) {
            $this->openResolveModal((int) request()->query('resolveFindingId'));
        }
        if (request()->has('auditFindingId')) {
            $this->openAuditModal((int) request()->query('auditFindingId'));
        }

        // Default to all clients (or first assigned client if restricted)
        $user = Auth::user();
        if ($user && ! $user->isSuperAdmin() && ! $this->selectedClientId) {
            $assigned = $user->getAssignedClientIds();
            if (! in_array('*', $assigned, true) && count($assigned) === 1) {
                $this->selectedClientId = (int) $assigned[0];
            }
        }
    }

    public function updatedSelectedClientId($value): void
    {
        $clientId = ! empty($value) ? (int) $value : null;
        $this->dispatch('finding-client-filter-changed', selectedClientId: $clientId);
        $this->dispatch('finding-filter-changed', selectedClientId: $clientId, selectedPillar: $this->selectedPillar);
    }

    public function updatedSelectedPillar($value): void
    {
        $pillar = ! empty($value) ? $value : null;
        $this->dispatch('finding-pillar-filter-changed', selectedPillar: $pillar);
        $this->dispatch('finding-filter-changed', selectedClientId: $this->selectedClientId ? (int) $this->selectedClientId : null, selectedPillar: $pillar);
    }

    #[On('finding-client-filter-changed')]
    public function handleExternalClientFilterChanged(?int $selectedClientId = null): void
    {
        if ($this->selectedClientId === $selectedClientId) {
            return;
        }

        $this->selectedClientId = $selectedClientId;
    }

    #[On('finding-pillar-filter-changed')]
    public function handleExternalPillarFilterChanged(?string $selectedPillar = null): void
    {
        if ($this->selectedPillar === $selectedPillar) {
            return;
        }

        $this->selectedPillar = $selectedPillar;
    }

    public function getClientsProperty(): Collection
    {
        $query = Client::where('status', 'active')->external()->orderBy('name');

        $user = Auth::user();
        if ($user && ! $user->isSuperAdmin()) {
            $assigned = $user->getAssignedClientIds();
            if (! in_array('*', $assigned, true)) {
                $query->whereIn('id', $assigned);
            }
        }

        return $query->pluck('name', 'id');
    }

    /**
     * Get team members authorized to work on the selected finding's customer.
     */
    public function getAssignableUsersProperty(): Collection
    {
        if ($this->assignFindingId) {
            $finding = Finding::find($this->assignFindingId);
            if ($finding) {
                return User::getAuthorizedUsersForClient($finding->client_id)->pluck('name', 'id');
            }
        }

        if ($this->selectedClientId) {
            return User::getAuthorizedUsersForClient($this->selectedClientId)->pluck('name', 'id');
        }

        return User::orderBy('name')->pluck('name', 'id');
    }

    public function getUsersProperty(): Collection
    {
        return $this->getAssignableUsersProperty();
    }

    public function getInitiativesProperty(): Collection
    {
        /** @var InitiativeAggregationService $service */
        $service = app(InitiativeAggregationService::class);

        $client = $this->selectedClientId ? Client::find($this->selectedClientId) : null;

        return $service->getInitiatives($client, $this->selectedPillar);
    }

    public function getSelectedInitiativeProperty(): ?array
    {
        if (! $this->selectedInitiativeId) {
            return null;
        }

        return $this->getInitiativesProperty()->firstWhere('id', $this->selectedInitiativeId);
    }

    public function getAuditFindingProperty(): ?Finding
    {
        if (! $this->auditFindingId) {
            return null;
        }

        $finding = Finding::with(['responsibleUser', 'approvedByUser', 'dismissedByUser'])->find($this->auditFindingId);
        if ($finding) {
            $this->authorizeFindingAccess($finding, 'view');
        }

        return $finding;
    }

    /**
     * Enforce customer-level authorization for finding interactions.
     */
    protected function authorizeFindingAccess(Finding $finding, string $action = 'update'): void
    {
        $user = Auth::user();
        if (! $user) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Unauthenticated.');
        }

        if ($user->isSuperAdmin()) {
            return;
        }

        if (! $user->canAccessClient($finding->client_id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Unauthorized access to customer {$finding->client_id} finding.");
        }
    }

    public function openDrillDown(string $initiativeId): void
    {
        $initiative = $this->getInitiativesProperty()->firstWhere('id', $initiativeId);
        if (! $initiative) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Initiative {$initiativeId} not found or unauthorized.");
        }

        $user = Auth::user();
        if ($user && ! $user->isSuperAdmin() && ! empty($initiative['client_id'])) {
            if (! $user->canAccessClient((int) $initiative['client_id'])) {
                throw new \Illuminate\Auth\Access\AuthorizationException("Unauthorized access to customer {$initiative['client_id']} initiatives.");
            }
        }

        $this->selectedInitiativeId = $initiativeId;
    }

    public function closeDrillDown(): void
    {
        $this->selectedInitiativeId = null;
    }

    public function reviewFinding(int $findingId): void
    {
        $finding = Finding::findOrFail($findingId);
        $this->authorizeFindingAccess($finding, 'update');

        $user = Auth::user();
        $finding->transitionTo(FindingStatus::UnderReview, $user, 'Reviewed in Initiative Management Console');

        Notification::make()
            ->title('Finding Marked Under Review')
            ->body("Account {$finding->title} is now under review.")
            ->success()
            ->send();
    }

    public function approveFinding(int $findingId): void
    {
        $finding = Finding::findOrFail($findingId);
        $this->authorizeFindingAccess($finding, 'approve');

        $user = Auth::user();
        $finding->transitionTo(FindingStatus::Approved, $user, 'Approved in Initiative Management Console');

        Notification::make()
            ->title('Finding Approved')
            ->body("Account {$finding->title} approved for sales engagement.")
            ->success()
            ->send();
    }

    public function openAssignModal(int $findingId): void
    {
        $finding = Finding::findOrFail($findingId);
        $this->authorizeFindingAccess($finding, 'update');

        $this->assignFindingId = $findingId;
        $this->assigneeUserId = $finding->responsible_user_id ?? Auth::id();
        $this->assignTargetRole = $finding->target_role ?? 'sales';
        $this->assignNotes = null;
        $this->activeFindingCustomerDisplay = app(CustomerPurchaseAnalysisService::class)->resolveCustomerIdentityDisplay($finding->client_id, $finding->account_identity_hash);
    }

    public function closeAssignModal(): void
    {
        $this->assignFindingId = null;
        $this->assigneeUserId = null;
        $this->assignTargetRole = 'sales';
        $this->assignNotes = null;
        $this->activeFindingCustomerDisplay = null;
    }

    public function confirmAssign(): void
    {
        if (! $this->assignFindingId || ! $this->assigneeUserId) {
            return;
        }

        $finding = Finding::findOrFail($this->assignFindingId);
        $this->authorizeFindingAccess($finding, 'update');

        $currentUser = Auth::user();
        $assignee = User::findOrFail($this->assigneeUserId);

        // Enforce that assignee is authorized for this customer
        $eligible = User::getAuthorizedUsersForClient($finding->client_id);
        if (! $eligible->contains('id', $assignee->id)) {
            $this->addError('assigneeUserId', "User {$assignee->name} is not authorized to work on this customer.");
            return;
        }

        $finding->transitionTo(FindingStatus::Assigned, $currentUser, $this->assignNotes, $assignee);

        if ($this->assignTargetRole) {
            $finding->update(['target_role' => $this->assignTargetRole]);
        }

        $this->closeAssignModal();

        Notification::make()
            ->title('Finding Assigned')
            ->body("Account {$finding->title} successfully assigned to {$assignee->name}.")
            ->success()
            ->send();
    }

    public function openCreatePmModal(int $findingId): void
    {
        $finding = Finding::with(['client', 'pmWorkItem'])->findOrFail($findingId);
        $this->authorizeFindingAccess($finding, 'update');

        if (! in_array($finding->status, [FindingStatus::Approved, FindingStatus::Accepted], true)) {
            Notification::make()
                ->title('Approval Required')
                ->body('PM work items can only be created after the initiative or finding has been officially approved.')
                ->warning()
                ->send();
            return;
        }

        if ($finding->pm_work_item_id && $finding->pmWorkItem) {
            Notification::make()
                ->title('Already Linked')
                ->body("This finding is already linked to PM work item {$finding->pmWorkItem->external_item_key}.")
                ->info()
                ->send();
            return;
        }

        $this->createPmFindingId = $findingId;
        $this->createPmTitle = "[{$finding->client->name}] " . $finding->title;

        $display = app(CustomerPurchaseAnalysisService::class)->resolveCustomerIdentityDisplay($finding->client_id, $finding->account_identity_hash);
        $this->activeFindingCustomerDisplay = $display;
        $accountLabel = $display['primary_name'] ?? ($display['customer_id'] ? "Customer #{$display['customer_id']}" : ($display['short_hash'] ? "Account #{$display['short_hash']}" : ($finding->metadata_json['account_label'] ?? 'Target Account')));
        $revenue = $finding->estimated_revenue_impact ? '$' . number_format((float) $finding->estimated_revenue_impact, 2) : 'N/A';

        $this->createPmDescription = "### Commercial Opportunity Summary\n\n"
            . "**Account:** {$accountLabel}\n"
            . "**Opportunity Value:** {$revenue}\n"
            . "**Strategic Pillar:** " . ($finding->finding_pillar?->label() ?? 'Growth Opportunity') . "\n\n"
            . "### Description\n"
            . $finding->description . "\n\n"
            . "### Recommended Next Actions\n"
            . "- Review historical purchasing cadence\n"
            . "- Coordinate outreach with client account team\n"
            . "- Record engagement notes and monitor subsequent order placement\n";

        $this->createPmPriority = 'Medium';
        $this->createPmIssueType = 'Task';

        $defaultProject = PmProject::where('client_id', $finding->client_id)->where('is_active', true)->first();
        $this->createPmProjectId = $defaultProject?->id;
    }

    public function closeCreatePmModal(): void
    {
        $this->createPmFindingId = null;
        $this->createPmProjectId = null;
        $this->createPmTitle = null;
        $this->createPmDescription = null;
        $this->activeFindingCustomerDisplay = null;
    }

    public function confirmCreatePmWorkItem(): void
    {
        if (! $this->createPmFindingId || ! $this->createPmProjectId) {
            $this->addError('createPmProjectId', 'Please select a PM project.');
            return;
        }

        $finding = Finding::findOrFail($this->createPmFindingId);
        $this->authorizeFindingAccess($finding, 'update');

        $project = PmProject::findOrFail($this->createPmProjectId);

        if ((int) $project->client_id !== (int) $finding->client_id) {
            $this->addError('createPmProjectId', 'Selected project does not belong to this customer.');
            return;
        }

        try {
            /** @var ProjectManagementProvider $provider */
            $provider = app(ProjectManagementProvider::class);
            $currentUser = Auth::user();

            $workItem = $provider->createWorkItem($project, [
                'finding'     => $finding,
                'title'       => $this->createPmTitle,
                'description' => $this->createPmDescription,
                'priority'    => $this->createPmPriority,
                'issue_type'  => $this->createPmIssueType,
            ], $currentUser);

            $finding->transitionExecutionState(
                FindingExecutionStatus::Assigned,
                'forge_ui',
                $currentUser,
                "Created Jira work item {$workItem->external_item_key}"
            );

            $this->closeCreatePmModal();

            Notification::make()
                ->title('Jira Work Item Created')
                ->body("Created {$workItem->external_item_key} in project {$project->name}.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Log::error("Failed to create PM work item: " . $e->getMessage());
            $this->addError('createPmTitle', 'Failed to create Jira issue: ' . $e->getMessage());
        }
    }

    public function openLinkPmModal(int $findingId): void
    {
        $finding = Finding::findOrFail($findingId);
        $this->authorizeFindingAccess($finding, 'update');

        $this->linkPmFindingId = $findingId;
        $this->linkPmWorkItemId = null;
    }

    public function closeLinkPmModal(): void
    {
        $this->linkPmFindingId = null;
        $this->linkPmWorkItemId = null;
    }

    public function confirmLinkPmWorkItem(): void
    {
        if (! $this->linkPmFindingId || ! $this->linkPmWorkItemId) {
            $this->addError('linkPmWorkItemId', 'Please select a PM work item to link.');
            return;
        }

        $finding = Finding::findOrFail($this->linkPmFindingId);
        $this->authorizeFindingAccess($finding, 'update');

        $workItem = PmWorkItem::findOrFail($this->linkPmWorkItemId);

        try {
            /** @var FindingPmSyncService $syncService */
            $syncService = app(FindingPmSyncService::class);
            $syncService->linkFindingToWorkItem($finding, $workItem, Auth::user());

            $this->closeLinkPmModal();

            Notification::make()
                ->title('Work Item Linked')
                ->body("Successfully linked finding to {$workItem->external_item_key}.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            $this->addError('linkPmWorkItemId', $e->getMessage());
        }
    }

    public function getProjectsForCreatePmProperty(): Collection
    {
        if (! $this->createPmFindingId) {
            return collect();
        }
        $finding = Finding::find($this->createPmFindingId);
        if (! $finding) {
            return collect();
        }
        return PmProject::where('client_id', $finding->client_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function getWorkItemsForLinkPmProperty(): Collection
    {
        if (! $this->linkPmFindingId) {
            return collect();
        }
        $finding = Finding::find($this->linkPmFindingId);
        if (! $finding) {
            return collect();
        }
        return PmWorkItem::where('client_id', $finding->client_id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function openResolveModal(int $findingId): void
    {
        $finding = Finding::findOrFail($findingId);
        $this->authorizeFindingAccess($finding, 'update');

        $this->resolveFindingId = $findingId;
        $this->resolutionNotes = null;
        $this->businessOutcome = 'reorder_secured';
        $this->activeFindingCustomerDisplay = app(CustomerPurchaseAnalysisService::class)->resolveCustomerIdentityDisplay($finding->client_id, $finding->account_identity_hash);
    }

    public function closeResolveModal(): void
    {
        $this->resolveFindingId = null;
        $this->resolutionNotes = null;
        $this->businessOutcome = 'reorder_secured';
        $this->activeFindingCustomerDisplay = null;
    }

    public function confirmResolve(): void
    {
        if (! $this->resolveFindingId) {
            return;
        }

        $finding = Finding::findOrFail($this->resolveFindingId);
        $this->authorizeFindingAccess($finding, 'update');

        $cleanNotes = trim((string) $this->resolutionNotes);
        if (empty($cleanNotes) || strlen($cleanNotes) < 10) {
            $this->addError('resolutionNotes', 'Please provide meaningful action notes (minimum 10 characters) detailing the action taken.');
            return;
        }

        $currentUser = Auth::user();
        $finding->transitionTo(FindingStatus::Resolved, $currentUser, $cleanNotes, null, $this->businessOutcome);

        $this->closeResolveModal();

        Notification::make()
            ->title('Action Marked Completed')
            ->body("Commercial action recorded for {$finding->title}. Transferred to outcome observation window (no revenue recovery is assumed).")
            ->success()
            ->send();
    }

    public function openDismissModal(int $findingId): void
    {
        $finding = Finding::findOrFail($findingId);
        $this->authorizeFindingAccess($finding, 'update');

        $this->dismissFindingId = $findingId;
        $this->dismissalReason = null;
    }

    public function closeDismissModal(): void
    {
        $this->dismissFindingId = null;
        $this->dismissalReason = null;
    }

    public function confirmDismiss(): void
    {
        if (! $this->dismissFindingId) {
            return;
        }

        $finding = Finding::findOrFail($this->dismissFindingId);
        $this->authorizeFindingAccess($finding, 'update');

        $cleanReason = trim((string) $this->dismissalReason);
        if (empty($cleanReason)) {
            $this->addError('dismissalReason', 'Please specify a dismissal reason.');
            return;
        }

        $currentUser = Auth::user();
        $finding->transitionTo(FindingStatus::Dismissed, $currentUser, $cleanReason);

        $this->closeDismissModal();

        Notification::make()
            ->title('Finding Dismissed')
            ->body("Account {$finding->title} dismissed.")
            ->info()
            ->send();
    }

    public function openAuditModal(int $findingId): void
    {
        $finding = Finding::findOrFail($findingId);
        $this->authorizeFindingAccess($finding, 'view');

        $this->auditFindingId = $findingId;
        $this->activeFindingCustomerDisplay = app(CustomerPurchaseAnalysisService::class)->resolveCustomerIdentityDisplay($finding->client_id, $finding->account_identity_hash);
    }

    public function closeAuditModal(): void
    {
        $this->auditFindingId = null;
        $this->activeFindingCustomerDisplay = null;
    }

    /**
     * Open the Batch Approval Confirmation Modal with verified safeguards.
     */
    public function openBatchApproveModal(string $initiativeId): void
    {
        $initiative = $this->getInitiativesProperty()->firstWhere('id', $initiativeId);
        if (! $initiative) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Initiative {$initiativeId} not found or unauthorized.");
        }

        $user = Auth::user();
        if ($user && ! $user->isSuperAdmin() && ! empty($initiative['client_id'])) {
            if (! $user->canAccessClient((int) $initiative['client_id'])) {
                throw new \Illuminate\Auth\Access\AuthorizationException("Unauthorized access to customer {$initiative['client_id']}.");
            }
        }

        $findingIds = collect($initiative['accounts'])->pluck('finding_id')->toArray();
        $findings = Finding::whereIn('id', $findingIds)->get();

        /** @var InitiativeAggregationService $service */
        $service = app(InitiativeAggregationService::class);

        $provenEligible = $findings->filter(function (Finding $f) use ($service) {
            return in_array($f->status?->value ?? (string)$f->status, [
                FindingStatus::New->value,
                FindingStatus::UnderReview->value,
                FindingStatus::Investigating->value,
            ], true) && ! $service->isProvisionalFinding($f);
        });

        $provisional = $findings->filter(fn (Finding $f) => $service->isProvisionalFinding($f));

        $alreadyApproved = $findings->filter(function (Finding $f) {
            return in_array($f->status?->value ?? (string)$f->status, [
                FindingStatus::Approved->value,
                FindingStatus::Assigned->value,
                FindingStatus::Accepted->value,
            ], true);
        });

        $excluded = $findings->filter(function (Finding $f) {
            return in_array($f->status?->value ?? (string)$f->status, [
                FindingStatus::Resolved->value,
                FindingStatus::Dismissed->value,
                FindingStatus::Ignored->value,
            ], true);
        });

        $this->batchApproveSummary = [
            'initiative_id'         => $initiativeId,
            'initiative_title'      => $initiative['initiative_title'],
            'client_name'           => $initiative['client_name'],
            'total_accounts'        => $findings->count(),
            'proven_eligible_count' => $provenEligible->count(),
            'proven_eligible_ids'   => $provenEligible->pluck('id')->toArray(),
            'provisional_count'     => $provisional->count(),
            'already_approved_count'=> $alreadyApproved->count(),
            'excluded_count'        => $excluded->count(),
        ];

        $this->batchApproveInitiativeId = $initiativeId;
    }

    public function closeBatchApproveModal(): void
    {
        $this->batchApproveInitiativeId = null;
        $this->batchApproveSummary = [];
    }

    /**
     * Execute Batch Approval on proven eligible findings with concurrency and eligibility checks.
     */
    public function confirmBatchApprove(): void
    {
        if (! $this->batchApproveInitiativeId || empty($this->batchApproveSummary)) {
            return;
        }

        $eligibleIds = $this->batchApproveSummary['proven_eligible_ids'] ?? [];
        $currentUser = Auth::user();

        $count = 0;
        $skipped = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($eligibleIds, $currentUser, &$count, &$skipped) {
            /** @var InitiativeAggregationService $service */
            $service = app(InitiativeAggregationService::class);

            $findings = Finding::whereIn('id', $eligibleIds)->lockForUpdate()->get();

            foreach ($findings as $finding) {
                // Safeguard: Ensure record has NOT changed eligibility between opening confirmation and submitting
                $isStillEligible = in_array($finding->status?->value ?? (string)$finding->status, [
                    FindingStatus::New->value,
                    FindingStatus::UnderReview->value,
                    FindingStatus::Investigating->value,
                ], true) && ! $service->isProvisionalFinding($finding);

                if (! $isStillEligible) {
                    $skipped++;
                    continue;
                }

                $this->authorizeFindingAccess($finding, 'approve');
                $finding->transitionTo(FindingStatus::Approved, $currentUser, 'Batch approved via Initiative Console');
                $count++;
            }
        });

        $provisionalCount = $this->batchApproveSummary['provisional_count'] ?? 0;
        $this->closeBatchApproveModal();

        Notification::make()
            ->title('Batch Approval Complete')
            ->body("Approved {$count} proven repeat account(s). {$provisionalCount} provisional account(s) skipped for manual review" . ($skipped > 0 ? " ({$skipped} changed status)." : "."))
            ->success()
            ->send();
    }

    /**
     * Direct action for batch approval with safeguards applied.
     */
    public function approveAllInInitiative(string $initiativeId): void
    {
        $this->openBatchApproveModal($initiativeId);
        $this->confirmBatchApprove();
    }
}
