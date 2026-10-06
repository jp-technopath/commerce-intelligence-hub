<?php

namespace App\Services\SystemPrompt;

use App\Models\SystemPrompt;
use App\Services\SystemPrompt\Contracts\PromptDefinitionInterface;
use App\Services\SystemPrompt\Definitions\MeetingFollowUpPromptDefinition;
use App\Services\SystemPrompt\Definitions\MeetingPrepPromptDefinition;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class PromptManager
{
    /**
     * @var array<string, PromptDefinitionInterface>
     */
    protected array $definitions = [];

    public function __construct()
    {
        $this->registerDefaultDefinitions();
    }

    protected function registerDefaultDefinitions(): void
    {
        $this->register(new MeetingPrepPromptDefinition());
        $this->register(new MeetingFollowUpPromptDefinition());
    }

    public function register(PromptDefinitionInterface $definition): void
    {
        $this->definitions[$definition->getKey()] = $definition;
    }

    public function getDefinition(string $key): PromptDefinitionInterface
    {
        if (! isset($this->definitions[$key])) {
            throw new InvalidArgumentException("No prompt definition registered for key: '{$key}'.");
        }

        return $this->definitions[$key];
    }

    /**
     * @return array<string, PromptDefinitionInterface>
     */
    public function getAllDefinitions(): array
    {
        return $this->definitions;
    }

    public static function cacheKey(string $key): string
    {
        return "sys_prompt_published_{$key}";
    }

    public function clearCache(string $key): void
    {
        Cache::forget(self::cacheKey($key));
    }

    /**
     * Extracts all {{ variable }} tokens from a template string.
     *
     * @param string $template
     * @return array<string> List of unique variable names found in the template.
     */
    public function extractVariables(string $template): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $template, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Validates that all variables used in the template are registered in the prompt definition.
     *
     * @param string $key
     * @param string $template
     * @return array{valid: bool, unregistered_variables: array<string>}
     */
    public function validateTemplateVariables(string $key, string $template): array
    {
        $definition = $this->getDefinition($key);
        $allowed = array_keys($definition->getAvailableVariables());
        $found = $this->extractVariables($template);

        $unregistered = array_values(array_diff($found, $allowed));

        return [
            'valid'                  => empty($unregistered),
            'unregistered_variables' => $unregistered,
        ];
    }

    /**
     * Safely interpolates dynamic variables into a template.
     * Only interpolates provided variables without altering surrounding tags.
     *
     * @param string $template
     * @param array<string, mixed> $variables
     * @return string
     */
    public function interpolate(string $template, array $variables): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($matches) use ($variables) {
            $varName = $matches[1];

            if (array_key_exists($varName, $variables)) {
                $val = $variables[$varName];
                if (is_null($val)) {
                    return '';
                }
                if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                    return (string) $val;
                }
                return json_encode($val, JSON_UNESCAPED_SLASHES) ?: '';
            }

            // Leave un-substituted if not present in the variables dictionary
            return $matches[0];
        }, $template);
    }

    /**
     * Resolve the active published prompt (or factory fallback) and interpolate variables.
     *
     * @param string $key
     * @param array<string, mixed> $variables
     * @return array{system_prompt: string, user_prompt: string, version_number: int|string}
     */
    public function resolve(string $key, array $variables = []): array
    {
        $data = Cache::rememberForever(self::cacheKey($key), function () use ($key) {
            $definition = $this->getDefinition($key);

            $record = SystemPrompt::query()
                ->where('key', $key)
                ->where('is_active', true)
                ->with('publishedVersion')
                ->first();

            if ($record && $record->publishedVersion) {
                return [
                    'system_prompt'        => $record->publishedVersion->system_prompt,
                    'user_prompt_template' => $record->publishedVersion->user_prompt_template,
                    'version_number'       => $record->publishedVersion->version_number,
                ];
            }

            return [
                'system_prompt'        => $definition->getDefaultSystemPrompt(),
                'user_prompt_template' => $definition->getDefaultUserPromptTemplate(),
                'version_number'       => 'default',
            ];
        });

        return [
            'system_prompt'  => $data['system_prompt'],
            'user_prompt'    => $this->interpolate($data['user_prompt_template'], $variables),
            'version_number' => $data['version_number'],
        ];
    }

    /**
     * Preview a draft prompt against provided or sample variables.
     *
     * @param string $key
     * @param string $draftSystemPrompt
     * @param string $draftUserTemplate
     * @param array<string, mixed> $variables
     * @return array{system_prompt: string, user_prompt: string, valid: bool, unregistered_variables: array<string>}
     */
    public function previewDraft(
        string $key,
        string $draftSystemPrompt,
        string $draftUserTemplate,
        array $variables = []
    ): array {
        $validation = $this->validateTemplateVariables($key, $draftUserTemplate);

        // If variables array is empty, fill with definition example values
        if (empty($variables)) {
            $definition = $this->getDefinition($key);
            foreach ($definition->getAvailableVariables() as $varKey => $meta) {
                $variables[$varKey] = $meta['example'] ?? "[{$varKey}]";
            }
        }

        return [
            'system_prompt'          => $draftSystemPrompt,
            'user_prompt'            => $this->interpolate($draftUserTemplate, $variables),
            'valid'                  => $validation['valid'],
            'unregistered_variables' => $validation['unregistered_variables'],
        ];
    }
}
