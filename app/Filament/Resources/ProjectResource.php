<?php

namespace App\Filament\Resources;

use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource\Pages;
use App\Models\Project;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Pages\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;
    protected static ?string $navigationIcon = 'heroicon-o-folder-open';
    protected static ?string $navigationGroup = 'Clients';
    protected static ?int $navigationSort = 2;
    protected static ?string $recordTitleAttribute = 'name';

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        /** @var \App\Models\User|null $currentUser */
        $currentUser = \Illuminate\Support\Facades\Auth::user();

        if (! $currentUser || $currentUser->isSuperAdmin()) {
            return $query;
        }

        $assignedProjectIds = $currentUser->getAssignedProjectIds();

        return $query->whereIn('id', $assignedProjectIds);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Project General Information')
                ->schema([
                    Forms\Components\Select::make('client_id')
                        ->relationship('client', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. B2B Wholesale Portal Upgrade'),

                    Forms\Components\TextInput::make('code')
                        ->label('Project Key / Code')
                        ->placeholder('e.g. B2B-UPG')
                        ->maxLength(50)
                        ->nullable(),

                    Forms\Components\Select::make('status')
                        ->options(collect(ProjectStatus::cases())->mapWithKeys(
                            fn ($case) => [$case->value => $case->label()]
                        ))
                        ->default(ProjectStatus::Active->value)
                        ->required(),

                    Forms\Components\Select::make('owner_id')
                        ->label('Project Lead / Owner')
                        ->relationship('owner', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Forms\Components\TextInput::make('jira_project_key')
                        ->label('Jira Project Key')
                        ->placeholder('e.g. TECH')
                        ->maxLength(50)
                        ->nullable(),

                    Forms\Components\TextInput::make('repository_url')
                        ->label('Repository URL')
                        ->url()
                        ->placeholder('https://github.com/org/repo')
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->rows(4)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('code')
                    ->label('Key')
                    ->fontFamily('mono')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->formatStateUsing(fn ($state) => $state instanceof ProjectStatus ? $state->label() : $state)
                    ->color(fn ($state) => $state instanceof ProjectStatus ? $state->color() : 'gray'),

                Tables\Columns\TextColumn::make('owner.name')
                    ->label('Project Lead')
                    ->placeholder('Unassigned')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('client_id')
                    ->relationship('client', 'name', function ($query) {
                        /** @var User|null $user */
                        $user = \Illuminate\Support\Facades\Auth::user();
                        if (! $user) return $query;

                        if (! $user->isSuperAdmin()) {
                            $assignedClientIds = $user->getAssignedClientIds();
                            if (! in_array('*', $assignedClientIds)) {
                                $query->whereIn('id', $assignedClientIds);
                            }
                        }
                        return $query;
                    })
                    ->label('Client')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(ProjectStatus::cases())->mapWithKeys(
                        fn ($case) => [$case->value => $case->label()]
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Project Overview')
                ->schema([
                    Infolists\Components\TextEntry::make('name')
                        ->weight('bold'),
                    Infolists\Components\TextEntry::make('client.name')
                        ->label('Client')
                        ->badge(),
                    Infolists\Components\TextEntry::make('code')
                        ->label('Project Key')
                        ->fontFamily('mono')
                        ->default('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state instanceof ProjectStatus ? $state->label() : $state),
                    Infolists\Components\TextEntry::make('owner.name')
                        ->label('Project Lead')
                        ->default('Unassigned'),
                    Infolists\Components\TextEntry::make('jira_project_key')
                        ->label('Jira Key')
                        ->default('—'),
                    Infolists\Components\TextEntry::make('repository_url')
                        ->label('Repository')
                        ->url(fn ($state) => $state, true)
                        ->default('—')
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('description')
                        ->default('No description provided.')
                        ->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            Pages\ViewProject::class,
            Pages\ProjectRequirements::class,
            Pages\ProjectAgentTasks::class,
            Pages\ProjectCodePrs::class,
            Pages\ProjectDeployments::class,
            Pages\ProjectSettings::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index'        => Pages\ListProjects::route('/'),
            'create'       => Pages\CreateProject::route('/create'),
            'view'         => Pages\ViewProject::route('/{record}'),
            'requirements' => Pages\ProjectRequirements::route('/{record}/requirements'),
            'agent-tasks'  => Pages\ProjectAgentTasks::route('/{record}/agent-tasks'),
            'code-prs'     => Pages\ProjectCodePrs::route('/{record}/code-prs'),
            'deployments'  => Pages\ProjectDeployments::route('/{record}/deployments'),
            'settings'     => Pages\ProjectSettings::route('/{record}/settings'),
            'edit'         => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
