<?php

namespace App\Services\Connectors;

use App\Models\Integration;

/**
 * Shopify Connector — Phase 2
 *
 * Pulls: Orders, Revenue, Products, Customers
 * Auth: Custom App Access Token (stored encrypted in integrations.credentials_json)
 * API Version: configured via SHOPIFY_API_VERSION env var
 */
class ShopifyConnector
{
    public function sync(Integration $integration, \DateTimeInterface $date): array
    {
        // TODO: Phase 2 — implement Shopify REST API sync
        throw new \RuntimeException('ShopifyConnector not yet implemented. Scheduled for Phase 2.');
    }

    public function testConnection(Integration $integration): bool
    {
        // TODO: Phase 2 — ping the Shopify API to verify credentials
        return false;
    }
}
