<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_CUSTOMER_ADMIN = 'customer_admin';
    public const ROLE_PRODUCT_OWNER = 'product_owner';
    public const ROLE_ANALYST = 'analyst';
    public const ROLE_CLIENT_USER = 'client_user';
    public const ROLE_ENGINEER = 'engineer';

    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * Scoped user role assignments.
     */
    public function assignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    /**
     * The users that belong to the role (legacy table).
     * @deprecated Use userRoleAssignments / activeRoleAssignments instead.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * The permissions that belong to the role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }
}
