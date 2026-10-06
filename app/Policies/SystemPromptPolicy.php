<?php

namespace App\Policies;

use App\Models\SystemPrompt;
use App\Models\User;

class SystemPromptPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('system_prompts.view_any')
            || $user->hasPermission('system_prompts.view');
    }

    public function view(User $user, SystemPrompt $systemPrompt): bool
    {
        return $user->hasPermission('system_prompts.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SystemPrompt $systemPrompt): bool
    {
        return $user->hasPermission('system_prompts.update');
    }

    public function publish(User $user, SystemPrompt $systemPrompt): bool
    {
        return $user->hasPermission('system_prompts.publish')
            || $user->hasPermission('system_prompts.update');
    }

    public function rollback(User $user, SystemPrompt $systemPrompt): bool
    {
        return $user->hasPermission('system_prompts.rollback')
            || $user->hasPermission('system_prompts.update');
    }

    public function delete(User $user, SystemPrompt $systemPrompt): bool
    {
        return false;
    }
}
