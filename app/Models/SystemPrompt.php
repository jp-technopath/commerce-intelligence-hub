<?php

namespace App\Models;

use App\Services\SystemPrompt\PromptManager;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SystemPrompt extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'category',
        'description',
        'draft_system_prompt',
        'draft_user_prompt_template',
        'published_version_id',
        'is_active',
        'draft_updated_by_user_id',
        'draft_updated_at',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'draft_updated_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(SystemPromptVersion::class, 'published_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SystemPromptVersion::class, 'system_prompt_id')
            ->orderBy('version_number', 'desc');
    }

    public function draftUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_updated_by_user_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function getActiveSystemPrompt(): string
    {
        if ($this->publishedVersion) {
            return $this->publishedVersion->system_prompt;
        }

        return app(PromptManager::class)->getDefinition($this->key)->getDefaultSystemPrompt();
    }

    public function getActiveUserPromptTemplate(): string
    {
        if ($this->publishedVersion) {
            return $this->publishedVersion->user_prompt_template;
        }

        return app(PromptManager::class)->getDefinition($this->key)->getDefaultUserPromptTemplate();
    }

    /**
     * Publishes the current draft into an immutable SystemPromptVersion within a DB transaction with row locking.
     * Blocks publishing if the draft contains unregistered variables.
     *
     * @param string|null $publishNotes
     * @param User|null $user
     * @return SystemPromptVersion
     * @throws ValidationException
     */
    public function publishDraft(?string $publishNotes = null, ?User $user = null): SystemPromptVersion
    {
        return DB::transaction(function () use ($publishNotes, $user) {
            /** @var static $locked */
            $locked = static::query()
                ->where('id', $this->id)
                ->lockForUpdate()
                ->firstOrFail();

            $draftUserTemplate = $locked->draft_user_prompt_template ?? '';

            // 1. Validate template variables against registered prompt definition
            $promptManager = app(PromptManager::class);
            $validation = $promptManager->validateTemplateVariables($locked->key, $draftUserTemplate);

            if (! $validation['valid']) {
                $unregisteredList = implode(', ', $validation['unregistered_variables']);
                throw ValidationException::withMessages([
                    'draft_user_prompt_template' => "Cannot publish template containing unregistered variables: {$unregisteredList}. Please check the variables cheat-sheet.",
                ]);
            }

            // 2. Determine next version number atomically
            $maxVersion = (int) $locked->versions()->lockForUpdate()->max('version_number');
            $nextVersionNumber = $maxVersion + 1;

            // 3. Create immutable version snapshot
            $version = SystemPromptVersion::create([
                'system_prompt_id'     => $locked->id,
                'version_number'       => $nextVersionNumber,
                'system_prompt'        => $locked->draft_system_prompt ?? '',
                'user_prompt_template' => $draftUserTemplate,
                'publish_notes'        => $publishNotes,
                'published_by_user_id' => $user?->id ?? auth()->id(),
                'created_at'           => now(),
            ]);

            // 4. Update published version reference on parent
            $locked->update([
                'published_version_id' => $version->id,
            ]);

            // 5. Invalidate cache
            $promptManager->clearCache($locked->key);

            // Sync instance in memory
            $this->published_version_id = $version->id;

            return $version;
        });
    }

    /**
     * Rolls back to a previous SystemPromptVersion by creating a new version with that snapshot.
     *
     * @param SystemPromptVersion $targetVersion
     * @param string|null $rollbackNotes
     * @param User|null $user
     * @return SystemPromptVersion
     */
    public function rollbackTo(
        SystemPromptVersion $targetVersion,
        ?string $rollbackNotes = null,
        ?User $user = null
    ): SystemPromptVersion {
        return DB::transaction(function () use ($targetVersion, $rollbackNotes, $user) {
            /** @var static $locked */
            $locked = static::query()
                ->where('id', $this->id)
                ->lockForUpdate()
                ->firstOrFail();

            $maxVersion = (int) $locked->versions()->lockForUpdate()->max('version_number');
            $nextVersionNumber = $maxVersion + 1;

            $notes = $rollbackNotes ?: "Rolled back to version {$targetVersion->version_number}";

            $version = SystemPromptVersion::create([
                'system_prompt_id'     => $locked->id,
                'version_number'       => $nextVersionNumber,
                'system_prompt'        => $targetVersion->system_prompt,
                'user_prompt_template' => $targetVersion->user_prompt_template,
                'publish_notes'        => $notes,
                'published_by_user_id' => $user?->id ?? auth()->id(),
                'created_at'           => now(),
            ]);

            $locked->update([
                'published_version_id'        => $version->id,
                'draft_system_prompt'        => $targetVersion->system_prompt,
                'draft_user_prompt_template' => $targetVersion->user_prompt_template,
                'draft_updated_at'           => now(),
                'draft_updated_by_user_id'   => $user?->id ?? auth()->id(),
            ]);

            app(PromptManager::class)->clearCache($locked->key);

            $this->published_version_id = $version->id;

            return $version;
        });
    }

    /**
     * Resets the working draft to the canonical factory baseline defined in code.
     */
    public function resetDraftToFactoryDefault(?User $user = null): void
    {
        $definition = app(PromptManager::class)->getDefinition($this->key);

        $this->update([
            'draft_system_prompt'        => $definition->getDefaultSystemPrompt(),
            'draft_user_prompt_template' => $definition->getDefaultUserPromptTemplate(),
            'draft_updated_at'           => now(),
            'draft_updated_by_user_id'   => $user?->id ?? auth()->id(),
        ]);
    }
}
