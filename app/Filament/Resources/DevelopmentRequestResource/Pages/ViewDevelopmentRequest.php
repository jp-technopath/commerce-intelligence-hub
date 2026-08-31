<?php

namespace App\Filament\Resources\DevelopmentRequestResource\Pages;

use App\Enums\DevelopmentRequestStatus;
use App\Filament\Resources\DevelopmentRequestResource;
use App\Models\DevelopmentRequest;
use App\Services\DevelopmentRequestLifecycleService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewDevelopmentRequest extends ViewRecord
{
    protected static string $resource = DevelopmentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (): bool => in_array($this->record->state, [
                    DevelopmentRequestStatus::Draft,
                    DevelopmentRequestStatus::ChangesRequested,
                ], true)),

            Actions\Action::make('submit')
                ->label('Start Agent')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Start this agent workflow?')
                ->modalDescription('Forge will lock in this request snapshot and queue the approved project, environment, and seniority routing.')
                ->visible(fn (): bool => in_array($this->record->state, [
                    DevelopmentRequestStatus::Draft,
                    DevelopmentRequestStatus::ChangesRequested,
                ], true) && auth()->user()?->can('update', $this->record))
                ->action(function (): void {
                    $request = $this->request();
                    app(DevelopmentRequestLifecycleService::class)->transitionState(
                        $request,
                        DevelopmentRequestStatus::Queued,
                        auth()->user(),
                        'Request confirmed and submitted from Forge.',
                        'ui-submit-'.$request->getKey().'-'.$request->updated_at->format('YmdHisv'),
                        $request->correlation_identifier,
                        'user',
                        auth()->user()?->name,
                        ['approved_context' => [
                            'jira_snapshot' => $request->jira_snapshot,
                            'routing_snapshot' => $request->routing_snapshot,
                            'selected_capability_tier' => $request->selected_capability_tier,
                        ]]
                    );

                    $this->record = $request->fresh();

                    Notification::make()
                        ->title('Agent workflow queued')
                        ->body(config('devforge.orchestration_enabled')
                            ? 'Forge will now prepare the mapped VM and worker.'
                            : 'Automated VM startup is disabled in this environment, so no VM was started.')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('approve')
                ->label('Approve Result')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->state === DevelopmentRequestStatus::AwaitingApproval
                    && auth()->user()?->can('approve', $this->record))
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Approval notes')
                        ->maxLength(5000),
                ])
                ->action(fn (array $data) => $this->transition(
                    DevelopmentRequestStatus::Approved,
                    $data['reason'] ?? 'Result approved in Forge.'
                )),

            Actions\Action::make('request_changes')
                ->label('Request Changes')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->visible(fn (): bool => $this->record->state === DevelopmentRequestStatus::AwaitingApproval
                    && auth()->user()?->can('approve', $this->record))
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Required changes')
                        ->required()
                        ->maxLength(5000),
                ])
                ->action(fn (array $data) => $this->transition(
                    DevelopmentRequestStatus::ChangesRequested,
                    $data['reason']
                )),

            Actions\Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->state === DevelopmentRequestStatus::AwaitingApproval
                    && auth()->user()?->can('approve', $this->record))
                ->requiresConfirmation()
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->maxLength(5000),
                ])
                ->action(fn (array $data) => $this->transition(
                    DevelopmentRequestStatus::Rejected,
                    $data['reason']
                )),
        ];
    }

    private function transition(DevelopmentRequestStatus $state, string $reason): void
    {
        $request = $this->request();
        app(DevelopmentRequestLifecycleService::class)->transitionState(
            $request,
            $state,
            auth()->user(),
            $reason,
            'ui-'.$state->value.'-'.$request->getKey().'-'.$request->updated_at->format('YmdHisv'),
            $request->correlation_identifier,
            'user',
            auth()->user()?->name
        );

        $this->record = $request->fresh();

        Notification::make()
            ->title('Request updated to '.$state->label())
            ->success()
            ->send();
    }

    private function request(): DevelopmentRequest
    {
        /** @var DevelopmentRequest $request */
        $request = $this->record;

        return $request;
    }
}
