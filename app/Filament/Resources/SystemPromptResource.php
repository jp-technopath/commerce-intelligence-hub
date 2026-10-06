<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SystemPromptResource\Pages;
use App\Filament\Resources\SystemPromptResource\RelationManagers\VersionsRelationManager;
use App\Models\SystemPrompt;
use App\Services\SystemPrompt\PromptManager;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class SystemPromptResource extends Resource
{
    protected static ?string $model = SystemPrompt::class;
    protected static ?string $navigationIcon = 'heroicon-o-command-line';
    protected static ?string $navigationGroup = 'Administration';
    protected static ?int $navigationSort = 5;
    protected static ?string $slug = 'system-prompts';
    protected static ?string $modelLabel = 'System Prompt';
    protected static ?string $pluralModelLabel = 'System Prompts';

    public static function canViewAny(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $user->isSuperAdmin()
            || $user->hasPermission('system_prompts.view_any')
            || $user->hasPermission('system_prompts.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $user->isSuperAdmin()
            || $user->hasPermission('system_prompts.update');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(3)
                    ->schema([
                        // Left / Top Column: Overview & Context
                        Forms\Components\Section::make('Prompt Definition & Active Status')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Display Name')
                                    ->disabled(),

                                Forms\Components\TextInput::make('key')
                                    ->label('Prompt Machine Key')
                                    ->disabled(),

                                Forms\Components\TextInput::make('category')
                                    ->label('Category')
                                    ->disabled(),

                                Forms\Components\Placeholder::make('active_version_badge')
                                    ->label('Active Published Version')
                                    ->content(function (?SystemPrompt $record) {
                                        if (! $record || ! $record->publishedVersion) {
                                            return new HtmlString('<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200">Default Code Factory</span>');
                                        }

                                        $v = $record->publishedVersion;
                                        $notes = e($v->publish_notes ?? 'No notes');
                                        $date = $v->created_at?->format('M j, Y g:i A') ?? '';

                                        return new HtmlString(
                                            "<div class='space-y-1'>" .
                                            "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'>Version {$v->version_number} (Active)</span>" .
                                            "<p class='text-xs text-gray-500'>Published: {$date}</p>" .
                                            "<p class='text-xs italic text-gray-600 dark:text-gray-400'>\"{$notes}\"</p>" .
                                            "</div>"
                                        );
                                    }),

                                Forms\Components\Textarea::make('description')
                                    ->label('Purpose & Instructions')
                                    ->rows(3)
                                    ->disabled(),
                            ])
                            ->columnSpan(1),

                        // Middle / Reference Column: Variables Cheat-Sheet & Response Contract
                        Forms\Components\Section::make('Template Contract & Variables')
                            ->schema([
                                Forms\Components\Placeholder::make('variables_cheat_sheet')
                                    ->label('Available Dynamic Variables')
                                    ->content(function (?SystemPrompt $record) {
                                        if (! $record) {
                                            return '';
                                        }

                                        try {
                                            $def = app(PromptManager::class)->getDefinition($record->key);
                                            $vars = $def->getAvailableVariables();

                                            $html = '<div class="space-y-2 text-xs">';
                                            $html .= '<p class="text-gray-600 dark:text-gray-400">Use double braces in your user prompt template: <code>{{ variable_name }}</code>. <strong class="text-amber-600 dark:text-amber-400">Publishing blocks if unknown variables are detected.</strong></p>';
                                            $html .= '<div class="grid grid-cols-1 gap-2 max-h-56 overflow-y-auto pr-1">';

                                            foreach ($vars as $varName => $info) {
                                                $lbl = e($info['label']);
                                                $desc = e($info['description']);
                                                $ex = e($info['example'] ?? '');

                                                $html .= "<div class='p-2 rounded border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50'>";
                                                $html .= "<div class='flex items-center justify-between'><code class='font-bold text-primary-600 dark:text-primary-400'>{{ {$varName} }}</code> <span class='text-gray-500 font-medium'>{$lbl}</span></div>";
                                                $html .= "<p class='text-gray-600 dark:text-gray-300 mt-0.5'>{$desc}</p>";
                                                if ($ex !== '') {
                                                    $html .= "<p class='text-gray-400 text-[11px] mt-0.5'>e.g. <em>{$ex}</em></p>";
                                                }
                                                $html .= "</div>";
                                            }

                                            $html .= '</div></div>';

                                            return new HtmlString($html);
                                        } catch (\Throwable $e) {
                                            return 'No variables available.';
                                        }
                                    }),

                                Forms\Components\Placeholder::make('response_schema_contract')
                                    ->label('Application Response Contract (Enforced in Code)')
                                    ->content(function (?SystemPrompt $record) {
                                        if (! $record) {
                                            return '';
                                        }

                                        try {
                                            $def = app(PromptManager::class)->getDefinition($record->key);
                                            $contract = $def->getResponseContract();

                                            $html = '<div class="space-y-2 text-xs">';
                                            $html .= '<p class="text-amber-600 dark:text-amber-400 font-semibold">Backend contract enforced in code. Prompt wording can be refined, but response keys cannot be changed.</p>';
                                            $html .= '<div class="border rounded border-gray-200 dark:border-gray-700 divide-y divide-gray-200 dark:divide-gray-700 max-h-48 overflow-y-auto">';

                                            foreach ($contract as $key => $info) {
                                                $type = e($info['type']);
                                                $req = ! empty($info['required']) ? '<span class="text-red-500 font-bold">*required</span>' : '<span class="text-gray-400">optional</span>';
                                                $desc = e($info['description']);

                                                $html .= "<div class='p-2 bg-white dark:bg-gray-800'>";
                                                $html .= "<div class='flex items-center justify-between font-mono font-medium text-gray-800 dark:text-gray-200'><span>{$key}</span> <span class='text-[11px] font-sans text-gray-500'>{$type} • {$req}</span></div>";
                                                $html .= "<p class='text-gray-500 dark:text-gray-400 mt-0.5 text-[11px]'>{$desc}</p>";
                                                $html .= "</div>";
                                            }

                                            $html .= '</div></div>';

                                            return new HtmlString($html);
                                        } catch (\Throwable $e) {
                                            return 'No schema contract defined.';
                                        }
                                    }),
                            ])
                            ->columnSpan(2),
                    ]),

                // Full Width Working Draft Section
                Forms\Components\Section::make('Working Draft Prompts')
                    ->description('Edit the system instructions and user prompt template below. Use "Test / Preview Draft" to verify before publishing.')
                    ->schema([
                        Forms\Components\Textarea::make('draft_system_prompt')
                            ->label('Draft System Prompt')
                            ->helperText('Defines persona, anti-hallucination guardrails, security boundaries, and required JSON response schema instructions.')
                            ->rows(14)
                            ->extraAttributes(['style' => 'font-family: monospace; font-size: 0.875rem;'])
                            ->required(),

                        Forms\Components\Textarea::make('draft_user_prompt_template')
                            ->label('Draft User Prompt Template')
                            ->helperText('Contains template text and dynamic {{ variable_name }} placeholders. XML tags (<JIRA_DATA>, <MEETING_NOTES>) are owned here.')
                            ->rows(18)
                            ->extraAttributes(['style' => 'font-family: monospace; font-size: 0.875rem;'])
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Prompt Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('key')
                    ->label('Identifier')
                    ->badge()
                    ->fontFamily('mono')
                    ->searchable(),

                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color('warning')
                    ->sortable(),

                Tables\Columns\TextColumn::make('publishedVersion.version_number')
                    ->label('Active Version')
                    ->formatStateUsing(fn ($state) => $state ? "v{$state}" : 'Factory Default')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit Draft'),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSystemPrompts::route('/'),
            'edit'  => Pages\EditSystemPrompt::route('/{record}/edit'),
        ];
    }
}
