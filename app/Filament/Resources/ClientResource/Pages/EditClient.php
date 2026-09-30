<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\ClientFindingConfiguration;
use App\Services\Intelligence\FindingConfigurationService;
use App\Services\Intelligence\FindingConfigurationValidator;
use App\Services\Intelligence\FindingRulePreviewer;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        $client = $this->getRecord();

        return [
            Actions\ActionGroup::make([
                // 1. Download JSON Template
                Actions\Action::make('download_rules_template')
                    ->label('Download Rules Template (JSON)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function () use ($client) {
                        $service = new FindingConfigurationService();
                        $template = $service->generateTemplate($client);
                        $filename = "finding-rules-client-{$client->id}.json";

                        return response()->streamDownload(function () use ($template) {
                            echo json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        }, $filename, ['Content-Type' => 'application/json']);
                    }),

                // 2. Download JSON Schema
                Actions\Action::make('download_rules_schema')
                    ->label('Download JSON Schema')
                    ->icon('heroicon-o-document-text')
                    ->action(function () use ($client) {
                        $service = new FindingConfigurationService();
                        $schema = $service->generateJsonSchema($client);
                        $filename = "finding-rules-schema-client-{$client->id}.json";

                        return response()->streamDownload(function () use ($schema) {
                            echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        }, $filename, ['Content-Type' => 'application/json']);
                    }),

                // 3. Download Full Package (ZIP)
                Actions\Action::make('download_rules_package')
                    ->label('Download Package (ZIP)')
                    ->icon('heroicon-o-archive-box')
                    ->action(function () use ($client) {
                        $service = new FindingConfigurationService();
                        $zipPath = $service->generateDownloadZip($client);
                        $filename = "finding-rules-package-client-{$client->id}.zip";

                        return response()->download($zipPath, $filename)->deleteFileAfterSend(true);
                    }),

                // 4. Upload & Validate
                Actions\Action::make('upload_findings_config')
                    ->label('Upload Findings Configuration')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->modalHeading('Upload Findings Configuration')
                    ->modalDescription('Upload your customized JSON configuration. The system will strictly validate metrics, operators, and quality gates before allowing activation.')
                    ->modalSubmitActionLabel('Validate & Save')
                    ->form([
                        Forms\Components\FileUpload::make('config_file')
                            ->label('Upload JSON File')
                            ->acceptedFileTypes(['application/json', 'text/json', 'text/plain'])
                            ->disk('local')
                            ->directory('temp-uploads'),

                        Forms\Components\Textarea::make('config_json')
                            ->label('Or Paste Configuration JSON')
                            ->rows(8)
                            ->placeholder('{"schema_version": "1.0", "client": {"id": ' . $client->id . ' ...'),

                        Forms\Components\Toggle::make('activate_now')
                            ->label('Activate immediately if valid')
                            ->default(true)
                            ->helperText('If checked, this version becomes active immediately. If unchecked, saved as draft.'),
                    ])
                    ->action(function (array $data) use ($client): void {
                        $rawJson = null;

                        if (! empty($data['config_file'])) {
                            $filePath = Storage::disk('local')->path($data['config_file']);
                            if (file_exists($filePath)) {
                                $rawJson = file_get_contents($filePath);
                                @unlink($filePath);
                            }
                        }

                        if (empty($rawJson) && ! empty($data['config_json'])) {
                            $rawJson = trim($data['config_json']);
                        }

                        if (empty($rawJson)) {
                            Notification::make()
                                ->title('No Configuration Provided')
                                ->body('Please either upload a JSON file or paste JSON content.')
                                ->danger()
                                ->send();
                            return;
                        }

                        $validator = new FindingConfigurationValidator();
                        $res = $validator->validate($client, $rawJson);

                        if (! $res['is_valid']) {
                            Notification::make()
                                ->title('Configuration Validation Failed')
                                ->body(implode("\n", $res['errors']))
                                ->danger()
                                ->persistent()
                                ->send();
                            return;
                        }

                        $nextVersion = ClientFindingConfiguration::nextVersionForClient($client->id);
                        $activate = ! empty($data['activate_now']);

                        $configRecord = ClientFindingConfiguration::create([
                            'client_id'          => $client->id,
                            'version'            => $nextVersion,
                            'configuration_json' => $res['validated_data'],
                            'rules_count'        => count($res['validated_data']['rules']),
                            'status'             => $activate ? ClientFindingConfiguration::STATUS_ACTIVE : ClientFindingConfiguration::STATUS_DRAFT,
                            'created_by'         => auth()->id(),
                            'activated_at'       => $activate ? now() : null,
                        ]);

                        if ($activate) {
                            $configRecord->activate();
                        }

                        $previewer = new FindingRulePreviewer();
                        $previewMarkdown = $previewer->toMarkdown($res['validated_data']);

                        Notification::make()
                            ->title($activate ? "Configuration v{$nextVersion} Activated" : "Configuration v{$nextVersion} Saved (Draft)")
                            ->body("Validated " . count($res['validated_data']['rules']) . " rule(s) successfully.\n\n" . substr($previewMarkdown, 0, 300) . "...")
                            ->success()
                            ->persistent()
                            ->send();
                    }),

                // 5. View Active Rules & Preview
                Actions\Action::make('view_active_config')
                    ->label('View Active Rules')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading("Active Finding Rules for {$client->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function () use ($client) {
                        $active = ClientFindingConfiguration::where('client_id', $client->id)->active()->first();
                        $previewer = new FindingRulePreviewer();
                        $preview = $active ? $previewer->generatePreview($active->configuration_json) : [];

                        return view('filament.components.finding-rules-preview', [
                            'preview' => $preview,
                            'config'  => $active,
                        ]);
                    }),

                // 6. Version History & Rollback
                Actions\Action::make('version_history')
                    ->label('Version History & Rollback')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading("Finding Rules Version History — {$client->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function () use ($client) {
                        $versions = ClientFindingConfiguration::where('client_id', $client->id)
                            ->orderBy('version', 'desc')
                            ->get();

                        return view('filament.components.finding-rules-history', [
                            'versions' => $versions,
                            'client'   => $client,
                        ]);
                    }),
            ])
            ->label('Findings Rules')
            ->icon('heroicon-o-cog-6-tooth')
            ->button(),

            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
