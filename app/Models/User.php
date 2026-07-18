<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar_url',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_admin'          => 'boolean',
        ];
    }

    /**
     * Allow all authenticated users to access the Filament admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    // ── Meeting Agent Relationships ──────────────────────────────────────

    public function ownedMeetings(): HasMany
    {
        return $this->hasMany(ClientMeeting::class, 'internal_owner_id');
    }

    public function connectedAccounts(): HasMany
    {
        return $this->hasMany(ConnectedAccount::class);
    }

    // ── Google Workspace Helpers ─────────────────────────────────────────

    public function googleWorkspaceAccount(): ?ConnectedAccount
    {
        return $this->connectedAccounts()
            ->active()
            ->provider('google_workspace')
            ->first();
    }

    public function hasGoogleWorkspace(): bool
    {
        return $this->googleWorkspaceAccount() !== null;
    }

    public function hasMeetingAgentScope(string $scope): bool
    {
        return $this->googleWorkspaceAccount()?->hasScope($scope) ?? false;
    }

    // ── Jira Integration Helpers ─────────────────────────────────────────

    public function jiraAccount(): ?ConnectedAccount
    {
        return $this->connectedAccounts()
            ->active()
            ->provider('jira')
            ->first();
    }

    public function hasJira(): bool
    {
        return $this->jiraAccount() !== null;
    }

    // ── Roles and Permissions Helpers ─────────────────────────────────────

    /**
     * The roles that belong to the user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Check if the user has a specific role.
     */
    public function hasRole(string|array $role): bool
    {
        if (is_array($role)) {
            return $this->roles()->whereIn('name', $role)->exists();
        }

        return $this->roles()->where('name', $role)->exists();
    }

    /**
     * Check if the user has a specific permission (via roles).
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->is_admin) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', function ($query) use ($permission) {
                $query->where('name', $permission);
            })
            ->exists();
    }
}
