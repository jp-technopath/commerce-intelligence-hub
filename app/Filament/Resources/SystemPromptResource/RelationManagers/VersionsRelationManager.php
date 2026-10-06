<?php

namespace App\Filament\Resources\SystemPromptResource\RelationManagers;

use App\Models\SystemPrompt;
use App\Models\SystemPromptVersion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';
    protected static ?string $title = 'Version History & Rollback';
    protected static ?string $recordTitleAttribute = 'version_number';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('version_number')
                ->label('Version Number')
                ->disabled(),
            Forms\Components\Textarea::make('publish_notes')
                ->label('Publish Notes')
                ->disabled(),
            Forms\Components\Textarea::make('system_prompt')
                ->label('System Prompt')
                ->rows(10)
                ->disabled()
                ->columnSpanFull(),
            Forms\Components\Textarea::make('user_prompt_template')
                ->label('User Prompt Template')
                ->rows(12)
                ->disabled()
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var SystemPrompt $owner */
        $owner = $this->getOwnerRecord();

        return $table
            ->defaultSort('version_number', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('version_number')
                    ->label('Version')
                    ->formatStateUsing(fn ($state) => "v{$state}")
                    ->weight('bold')
                    ->badge()
                    ->color(fn (SystemPromptVersion $record) => $record->id === $owner->published_version_id ? 'success' : 'gray'),

                Tables\Columns\IconColumn::make('is_current')
                    ->label('Live Active')
                    ->state(fn (SystemPromptVersion $record) => $record->id === $owner->published_version_id)
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('publish_notes')
                    ->label('Change Notes')
                    ->limit(60)
                    ->placeholder('No notes provided')
                    ->wrap(),

                Tables\Columns\TextColumn::make('publishedBy.name')
                    ->label('Published By')
                    ->placeholder('System Factory'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Published At')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Inspect Snapshot')
                    ->modalHeading(fn (SystemPromptVersion $record) => "Version {$record->version_number} Snapshot")
                    ->slideOver(),

                Tables\Actions\Action::make('rollback')
                    ->label('Rollback to This')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (SystemPromptVersion $record) => "Rollback to Version {$record->version_number}?")
                    ->modalDescription(fn (SystemPromptVersion $record) => "This will instantly publish a new version with Version {$record->version_number}'s prompts as active production prompts and update the working draft.")
                    ->visible(function (SystemPromptVersion $record) use ($owner) {
                        if ($record->id === $owner->published_version_id) {
                            return false;
                        }

                        /** @var \App\Models\User|null $user */
                        $user = auth()->user();
                        if (! $user) {
                            return false;
                        }

                        return $user->isSuperAdmin()
                            || $user->hasPermission('system_prompts.rollback')
                            || $user->hasPermission('system_prompts.update');
                    })
                    ->action(function (SystemPromptVersion $record) use ($owner) {
                        $newVersion = $owner->rollbackTo($record, "Rolled back to version {$record->version_number}", auth()->user());

                        Notification::make()
                            ->title("Rolled back to Version {$record->version_number}")
                            ->body("Active production prompt is now updated as Version {$newVersion->version_number}.")
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }
}
