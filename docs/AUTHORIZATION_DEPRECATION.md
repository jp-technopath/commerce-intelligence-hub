# Authorization System Deprecation Path & Migration Guide

## Overview

The Commerce Intelligence Hub / Technopath Forge authorization system has been upgraded from a simple global role pivot (`role_user`) and `$user->is_admin` flag to a multi-layered, scoped authorization model centered around `UserRoleAssignment` (`user_role_assignments` table).

`UserRoleAssignment` is now the **long-term single source of truth** for roles, client/project/repository scopes, and access expiration.

---

## Deprecated Features & Schedule

### 1. `role_user` Pivot Table & `$user->roles()` Relationship
* **Status**: Deprecated.
* **Current Behavior**: Maintained as a read-only fallback in `$user->hasRole()` and `$user->hasPermission()` for backward compatibility.
* **Replacement**: Use `$user->roleAssignments()` and `$user->activeRoleAssignments()`.
* **Removal Timeline**: To be dropped in a future database migration once all legacy assignments are converted.

### 2. `$user->is_admin` Boolean Flag
* **Status**: Deprecated.
* **Current Behavior**: `is_admin = true` automatically grants `Super Admin` privileges via `User::$is_admin` and `Gate::before()`. All users with `is_admin = true` have been assigned an active `super_admin` `UserRoleAssignment`.
* **Replacement**: Assign the `super_admin` role via `UserRoleAssignment`.
* **Removal Timeline**: Field will be removed from the `users` table schema in the next major release.

---

## Code & API Migration

### Checking Roles
```php
// BEFORE (Deprecated)
$user->hasRole('Manager');

// AFTER (Scoped)
$user->hasRole(Role::ROLE_PRODUCT_OWNER, clientId: $client->id);
```

### Checking Permissions
```php
// BEFORE (Deprecated)
$user->hasPermission('findings.create');

// AFTER (Scoped)
$user->hasPermission('findings.create', clientId: $client->id, projectId: $project->id);
```

### Checking Super Admin Access
```php
// BEFORE (Deprecated)
if ($user->is_admin) { ... }

// AFTER
if ($user->isSuperAdmin()) { ... }
```

---

## Preservation of Audit History
Role assignments are never destructively deleted during privilege changes. Instead, assignments are deactivated (`is_active = false`, `deactivated_at = timestamp`) or soft-deleted, preserving complete historical audit trails in `user_role_assignments` and `authorization_audit_logs`.
