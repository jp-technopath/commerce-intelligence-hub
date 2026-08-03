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
     * All role assignments (active and inactive for audit trail).
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    /**
     * Currently active and non-expired role assignments.
     */
    public function activeRoleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class)->currentlyActive();
    }

    /**
     * The roles that belong to the user (legacy table).
     * @deprecated Use roleAssignments / activeRoleAssignments instead.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Check if user is a Super Admin (via is_admin flag or super_admin role assignment).
     */
    public function isSuperAdmin(): bool
    {
        if ($this->is_admin) {
            return true;
        }

        $hasSuperAdminRole = $this->activeRoleAssignments()
            ->whereHas('role', function ($q) {
                $q->where('name', Role::ROLE_SUPER_ADMIN)
                  ->orWhere('name', 'Super Admin');
            })
            ->exists();

        if ($hasSuperAdminRole) {
            return true;
        }

        // Fallback for Technopath internal staff and unassigned users:
        // Grant super admin access if user email is @technopath.co or if no explicit role assignments exist yet
        if ($this->email && str_ends_with(strtolower($this->email), '@technopath.co')) {
            if (! $this->activeRoleAssignments()->exists() && ! $this->roles()->exists()) {
                return true;
            }
        }

        // Global fallback: If no role assignments exist across the system yet, default all users to super admin
        if (\App\Models\UserRoleAssignment::count() === 0 && ! $this->isClientOnly()) {
            return true;
        }

        return false;
    }

    /**
     * Check if the user is strictly scoped as a Client role (and not internal staff or super admin).
     */
    public function isClientOnly(): bool
    {
        if ($this->isSuperAdmin()) {
            return false;
        }

        $nonClientAssignments = $this->activeRoleAssignments()
            ->whereHas('role', fn ($q) => $q->whereNotIn('name', [Role::ROLE_CLIENT_USER, 'Client']))
            ->exists();

        if ($nonClientAssignments) {
            return false;
        }

        return $this->activeRoleAssignments()
            ->whereHas('role', fn ($q) => $q->whereIn('name', [Role::ROLE_CLIENT_USER, 'Client']))
            ->exists()
            || $this->roles()->whereIn('name', [Role::ROLE_CLIENT_USER, 'Client'])->exists();
    }

    /**
     * Check if the user has a specific role, optionally scoped by client or project.
     */
    public function hasRole(string|array $role, ?int $clientId = null, ?int $projectId = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $roleNames = is_array($role) ? $role : [$role];

        // Check active scoped role assignments
        $query = $this->activeRoleAssignments()
            ->whereHas('role', function ($q) use ($roleNames) {
                $q->whereIn('name', $roleNames);
            });

        if ($clientId !== null) {
            $query->where(function ($q) use ($clientId) {
                $q->whereNull('client_id')->orWhere('client_id', $clientId);
            });
        }

        if ($projectId !== null) {
            $query->where(function ($q) use ($projectId) {
                $q->whereNull('project_id')->orWhere('project_id', $projectId);
            });
        }

        if ($query->exists()) {
            return true;
        }

        // Backward compatibility fallback to legacy role_user pivot
        return $this->roles()->whereIn('name', $roleNames)->exists();
    }

    /**
     * Check if the user has a specific permission (via scoped roles or legacy roles).
     */
    public function hasPermission(string $permission, ?int $clientId = null, ?int $projectId = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Search active role assignments matching scope
        $assignments = $this->activeRoleAssignments()
            ->with(['role.permissions'])
            ->get();

        foreach ($assignments as $assignment) {
            // Check scope match
            if ($clientId !== null && $assignment->client_id !== null && (int)$assignment->client_id !== (int)$clientId) {
                continue;
            }

            if ($projectId !== null && $assignment->project_id !== null && (int)$assignment->project_id !== (int)$projectId) {
                continue;
            }

            if ($assignment->role && $assignment->role->permissions->contains('name', $permission)) {
                return true;
            }
        }

        // Backward compatibility fallback to legacy role_user pivot
        return $this->roles()
            ->whereHas('permissions', function ($query) use ($permission) {
                $query->where('name', $permission);
            })
            ->exists();
    }

    /**
     * Get list of client IDs assigned to this user (or ['*'] if Super Admin).
     * @return array<int|string>
     */
    public function getAssignedClientIds(?string $roleName = null): array
    {
        if ($this->isSuperAdmin()) {
            return ['*'];
        }

        $query = $this->activeRoleAssignments();

        if ($roleName) {
            $query->whereHas('role', fn ($q) => $q->where('name', $roleName));
        }

        $clientIds = $query->whereNotNull('client_id')->pluck('client_id')->toArray();

        // Also include clients from project-scoped assignments
        $projectClientIds = Project::whereIn('id', $this->getAssignedProjectIds($roleName))
            ->pluck('client_id')
            ->toArray();

        return array_values(array_unique(array_filter(array_merge($clientIds, $projectClientIds))));
    }

    /**
     * Get list of project IDs assigned to this user (or ['*'] if Super Admin).
     * @return array<int|string>
     */
    public function getAssignedProjectIds(?string $roleName = null): array
    {
        if ($this->isSuperAdmin()) {
            return ['*'];
        }

        $query = $this->activeRoleAssignments();

        if ($roleName) {
            $query->whereHas('role', fn ($q) => $q->where('name', $roleName));
        }

        $assignments = $query->get();
        $projectIds = [];

        foreach ($assignments as $assignment) {
            if ($assignment->project_id) {
                $projectIds[] = (int) $assignment->project_id;
            } elseif ($assignment->client_id) {
                // Client-level assignment grants access to all projects under that client
                $ids = Project::where('client_id', $assignment->client_id)->pluck('id')->toArray();
                $projectIds = array_merge($projectIds, $ids);
            }
        }

        return array_values(array_unique(array_filter($projectIds)));
    }

    /**
     * Get repository IDs assigned to this user (or ['*'] if Super Admin).
     * @return array<int|string>
     */
    public function getAssignedRepositoryIds(): array
    {
        if ($this->isSuperAdmin()) {
            return ['*'];
        }

        return $this->activeRoleAssignments()
            ->whereNotNull('repository_id')
            ->pluck('repository_id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Self-approval prevention helper.
     */
    public function canApproveWork(\Illuminate\Database\Eloquent\Model $record, string $actionType): bool
    {
        return \App\Services\ApprovalService::canApprove($this, $record, $actionType);
    }
}
