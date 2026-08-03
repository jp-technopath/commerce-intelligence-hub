<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class VisibilityService
{
    public const CLASSIFICATION_INTERNAL = 'internal';
    public const CLASSIFICATION_CUSTOMER_VISIBLE = 'customer_visible';
    public const CLASSIFICATION_RESTRICTED_TECHNICAL = 'restricted_technical';
    public const CLASSIFICATION_RESTRICTED_FINANCIAL = 'restricted_financial';

    /**
     * Determine if a user can view a record based on its visibility classification.
     */
    public static function canViewRecord(User $user, Model $record): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Determine record classification (defaults to 'internal' if not set)
        $classification = $record->visibility_classification
            ?? $record->classification
            ?? static::inferClassification($record);

        $clientId = $record->client_id ?? $record->project?->client_id;
        $projectId = $record->project_id ?? ($record instanceof \App\Models\Project ? $record->id : null);

        switch ($classification) {
            case self::CLASSIFICATION_CUSTOMER_VISIBLE:
                return $user->hasRole(
                    [Role::ROLE_CUSTOMER_ADMIN, Role::ROLE_CLIENT_USER, Role::ROLE_PRODUCT_OWNER, Role::ROLE_ANALYST, Role::ROLE_ENGINEER],
                    $clientId,
                    $projectId
                );

            case self::CLASSIFICATION_INTERNAL:
                // Customer Admin and Client_User cannot view internal records
                if ($user->hasRole([Role::ROLE_CUSTOMER_ADMIN, Role::ROLE_CLIENT_USER], $clientId, $projectId)
                    && ! $user->hasRole([Role::ROLE_PRODUCT_OWNER, Role::ROLE_ANALYST, Role::ROLE_ENGINEER], $clientId, $projectId)) {
                    return false;
                }
                return true;

            case self::CLASSIFICATION_RESTRICTED_TECHNICAL:
                return $user->hasRole([Role::ROLE_ENGINEER], $clientId, $projectId);

            case self::CLASSIFICATION_RESTRICTED_FINANCIAL:
                return $user->hasPermission('financials.view_internal', $clientId, $projectId);

            default:
                return true;
        }
    }

    /**
     * Infer classification based on model type or attributes.
     */
    protected static function inferClassification(Model $record): string
    {
        if (method_exists($record, 'isCustomerVisible') && $record->isCustomerVisible()) {
            return self::CLASSIFICATION_CUSTOMER_VISIBLE;
        }

        if (property_exists($record, 'is_customer_visible') && $record->is_customer_visible) {
            return self::CLASSIFICATION_CUSTOMER_VISIBLE;
        }

        return self::CLASSIFICATION_INTERNAL;
    }
}
