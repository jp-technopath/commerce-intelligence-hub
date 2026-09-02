<?php

namespace App\Filament\Pages\Placeholders;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class AgentRunsPage extends BasePlaceholderPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $navigationGroup = 'Delivery';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Agent Runs';
    protected static ?string $title = 'Agent Execution Runs';
    protected static ?string $slug = 'delivery/agent-runs';
    protected static string $view = 'filament.pages.agent-runs';

    public function getModuleTitle(): string
    {
        return 'Agent Runs';
    }

    public function getModuleDescription(): string
    {
        return 'Review queued and completed agent jobs, their current status, progress, and audit events.';
    }

    public function getPrimaryActionLabel(): ?string
    {
        // Starting a run belongs on a development request, where routing and
        // the explicit human confirmation are enforced.
        return null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(static::getEloquentQuery())
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('job_identifier')
                    ->label('Run')
                    ->formatStateUsing(fn (string $state): string => Str::limit($state, 14, '…'))
                    ->tooltip(fn (AgentJob $record): string => $record->job_identifier)
                    ->copyable()
                    ->fontFamily('mono')
                    ->searchable(),

                Tables\Columns\TextColumn::make('developmentRequest.client.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('developmentRequest.project.name')
                    ->label('Project')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('developmentRequest.pmWorkItem.external_item_key')
                    ->label('Task')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('role')
                    ->label('Agent')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? ucfirst(str_replace(['_', '-'], ' ', $state))
                        : 'Unknown')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (AgentJobStatus|string|null $state): string => static::statusLabel($state))
                    ->color(fn (AgentJobStatus|string|null $state): string => static::statusColor($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('progress_percent')
                    ->label('Progress')
                    ->suffix('%')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->getStateUsing(fn (AgentJob $record) => $record->claimed_at ?? $record->created_at)
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('Not started')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('claimed_at', $direction)),

                Tables\Columns\TextColumn::make('events_count')
                    ->label('Events')
                    ->counts('events')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(AgentJobStatus::cases())->mapWithKeys(
                        fn (AgentJobStatus $status): array => [$status->value => static::statusLabel($status)]
                    )->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('role')
                    ->options(fn (): array => AgentJob::query()
                        ->whereNotNull('role')
                        ->distinct()
                        ->orderBy('role')
                        ->pluck('role', 'role')
                        ->mapWithKeys(fn (string $role): array => [
                            $role => ucfirst(str_replace(['_', '-'], ' ', $role)),
                        ])
                        ->all())
                    ->multiple(),
            ])
            ->actions([
                Action::make('view_details')
                    ->label('Details')
                    ->icon('heroicon-m-eye')
                    ->modalHeading(fn (AgentJob $record): string => 'Agent run '.Str::limit($record->job_identifier, 18, '…'))
                    ->modalWidth(MaxWidth::SixExtraLarge)
                    ->form(fn (AgentJob $record): array => [
                        Placeholder::make('run_details')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => static::runDetails($record)),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No agent runs yet')
            ->emptyStateDescription('Agent runs appear here after a development request is explicitly started.')
            ->emptyStateIcon('heroicon-o-cpu-chip');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = AgentJob::query()
            ->with([
                'developmentRequest.client',
                'developmentRequest.project',
                'developmentRequest.pmWorkItem',
                'projectEnvironmentMapping',
                'events',
            ]);

        /** @var User|null $user */
        $user = Auth::user();
        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $projectIds = $user->getAssignedProjectIds();
        $clientIds = $user->getAssignedClientIds();

        return $query->whereHas('developmentRequest', function (Builder $request) use ($projectIds, $clientIds): void {
            $request->where(function (Builder $scope) use ($projectIds, $clientIds): void {
                $hasScope = false;

                if ($projectIds !== [] && ! in_array('*', $projectIds, true)) {
                    $scope->whereIn('project_id', $projectIds);
                    $hasScope = true;
                }

                if ($clientIds !== [] && ! in_array('*', $clientIds, true)) {
                    $hasScope
                        ? $scope->orWhereIn('client_id', $clientIds)
                        : $scope->whereIn('client_id', $clientIds);
                    $hasScope = true;
                }

                if (! $hasScope) {
                    $scope->whereRaw('1 = 0');
                }
            });
        });
    }

    private static function statusLabel(AgentJobStatus|string|null $status): string
    {
        $value = $status instanceof AgentJobStatus ? $status->value : $status;

        return match ($value) {
            'queued' => 'Queued',
            'claimed' => 'Claimed',
            'running' => 'Running',
            'result_received' => 'Result received',
            'cancelling' => 'Cancelling',
            'cancelled' => 'Cancelled',
            'failed' => 'Failed',
            'completed' => 'Completed',
            default => $value ? ucfirst(str_replace('_', ' ', $value)) : 'Unknown',
        };
    }

    private static function statusColor(AgentJobStatus|string|null $status): string
    {
        $value = $status instanceof AgentJobStatus ? $status->value : $status;

        return match ($value) {
            'completed' => 'success',
            'failed', 'cancelled' => 'danger',
            'running', 'result_received' => 'primary',
            'claimed', 'cancelling' => 'warning',
            default => 'info',
        };
    }

    private static function runDetails(AgentJob $record): HtmlString
    {
        $request = $record->developmentRequest;
        $workItem = $request?->pmWorkItem;
        $events = $record->events
            ->map(function ($event): string {
                $metadata = static::jsonText($event->metadata);

                return '<li class="border-b border-gray-100 dark:border-gray-800 py-3 last:border-0">'
                    .'<div class="flex flex-wrap items-center justify-between gap-2">'
                    .'<span class="font-medium">'.e($event->event_type).'</span>'
                    .'<span class="text-xs text-gray-500">'.e(optional($event->created_at)->format('M j, Y g:i A')).'</span>'
                    .'</div>'
                    .'<div class="mt-1 text-xs text-gray-500">Operation: '.e($event->operation ?: '—').'</div>'
                    .($metadata !== '—'
                        ? '<pre class="mt-2 overflow-x-auto rounded bg-gray-50 p-2 text-xs text-gray-700 dark:bg-gray-950 dark:text-gray-300">'.e($metadata).'</pre>'
                        : '')
                    .'</li>';
            })
            ->implode('');

        $events = $events !== ''
            ? '<ul class="divide-y divide-gray-100 dark:divide-gray-800">'.$events.'</ul>'
            : '<p class="text-sm text-gray-500">No audit events recorded.</p>';

        $payload = static::jsonText($record->payload);
        $result = static::jsonText($record->result);
        $failure = static::jsonText($record->failure);

        $html = '<div class="space-y-6 text-sm text-gray-900 dark:text-gray-100">'
            .'<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">'
            .static::detailItem('Status', static::statusLabel($record->status))
            .static::detailItem('Agent', ucfirst(str_replace(['_', '-'], ' ', (string) $record->role)))
            .static::detailItem('Task', $workItem?->external_item_key ?? '—')
            .static::detailItem('Attempt', (string) ($record->attempt ?? '—'))
            .static::detailItem('Client', $request?->client?->name ?? '—')
            .static::detailItem('Project', $request?->project?->name ?? '—')
            .static::detailItem('Environment', $request?->environment_key ?? '—')
            .static::detailItem('Worker identity', $record->worker_service_account_email ?: '—')
            .'</div>'
            .'<div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">'
            .'<h3 class="font-semibold">Request</h3>'
            .'<p class="mt-1">'.e($request?->title ?? '—').'</p>'
            .'<p class="mt-1 text-xs text-gray-500">Run ID: '.e($record->job_identifier).'</p>'
            .'</div>'
            .'<div class="grid gap-4 lg:grid-cols-3">'
            .static::jsonPanel('Payload', $payload)
            .static::jsonPanel('Result', $result)
            .static::jsonPanel('Failure', $failure)
            .'</div>'
            .'<div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">'
            .'<h3 class="font-semibold">Audit events</h3>'
            .'<div class="mt-2">'.$events.'</div>'
            .'</div>'
            .'</div>';

        return new HtmlString($html);
    }

    private static function detailItem(string $label, string $value): string
    {
        return '<div><div class="text-xs font-medium uppercase tracking-wide text-gray-500">'.e($label).'</div>'
            .'<div class="mt-1 break-words">'.e($value).'</div></div>';
    }

    private static function jsonPanel(string $label, string $value): string
    {
        return '<div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">'
            .'<h3 class="font-semibold">'.e($label).'</h3>'
            .'<pre class="mt-2 max-h-56 overflow-auto whitespace-pre-wrap break-words rounded bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-950 dark:text-gray-300">'.e($value).'</pre>'
            .'</div>';
    }

    private static function jsonText(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '—';
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? 'Unable to display details.' : $encoded;
    }
}
