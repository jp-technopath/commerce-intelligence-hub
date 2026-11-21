<?php

namespace App\Services\Intelligence;

use App\Models\Client;
use App\Models\CommerceCustomerIdentityMapping;
use App\Models\CommerceOrder;
use App\Services\Security\CustomerIdentityHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CustomerIdentityResolverService
 *
 * Manages canonical purchasing account identities, multi-tier entity resolution,
 * and provenance tracking for commerce orders and findings without mutating source hashes.
 */
class CustomerIdentityResolverService
{
    /**
     * Common generic consumer email domains that must never be grouped into corporate companies.
     */
    public const CONSUMER_EMAIL_DOMAINS = [
        'gmail.com',
        'yahoo.com',
        'hotmail.com',
        'aol.com',
        'outlook.com',
        'icloud.com',
        'comcast.net',
        'sbcglobal.net',
        'verizon.net',
        'att.net',
        'msn.com',
        'live.com',
        'mail.com',
        'ymail.com',
        'cox.net',
        'charter.net',
    ];

    public function __construct(
        protected CustomerIdentityHasher $hasher
    ) {}

    /**
     * Determine if an email address belongs to a consumer email provider.
     */
    public function isConsumerEmail(?string $email): bool
    {
        if (empty($email) || ! str_contains($email, '@')) {
            return false;
        }

        $domain = strtolower(substr(strrchr($email, '@'), 1));
        return in_array($domain, self::CONSUMER_EMAIL_DOMAINS, true);
    }

    /**
     * Generate a deterministic canonical purchasing-account key.
     */
    public function generateCanonicalAccountId(Client $client, ?string $customerId = null, ?string $email = null): string
    {
        $prefix = "client_{$client->id}";

        if ($customerId !== null && trim($customerId) !== '') {
            return "{$prefix}:cid_" . trim($customerId);
        }

        if ($email !== null && trim($email) !== '') {
            $normEmail = $this->hasher->normalizeEmail($email);
            return "{$prefix}:guest_" . hash('sha256', $normEmail);
        }

        return "{$prefix}:anon_" . uniqid();
    }

    /**
     * Resolve the canonical account ID for an order or customer reference.
     * Looks up existing mappings first, falling back to deterministic generation.
     */
    public function resolveCanonicalAccountId(
        Client $client,
        ?string $customerId = null,
        ?string $email = null,
        ?string $sourceHash = null
    ): string {
        $clientId = $client->id;

        // 1. If registered customer ID is provided, look for registered customer mapping
        if (! empty($customerId)) {
            $cidMapping = CommerceCustomerIdentityMapping::where('client_id', $clientId)
                ->where('identity_type', 'registered_customer_id')
                ->where('identity_value', (string) $customerId)
                ->where('is_active', true)
                ->value('canonical_account_id');

            if ($cidMapping) {
                return $cidMapping;
            }

            return $this->generateCanonicalAccountId($client, customerId: (string) $customerId);
        }

        // 2. If email is provided, check email mapping
        if (! empty($email)) {
            $normEmail = $this->hasher->normalizeEmail($email);
            $emailMapping = CommerceCustomerIdentityMapping::where('client_id', $clientId)
                ->where('identity_type', 'normalized_email')
                ->where('identity_value', $normEmail)
                ->where('is_active', true)
                ->value('canonical_account_id');

            if ($emailMapping) {
                return $emailMapping;
            }

            return $this->generateCanonicalAccountId($client, email: $normEmail);
        }

        // 3. Fallback to source hash mapping if present
        if (! empty($sourceHash)) {
            $hashMapping = CommerceCustomerIdentityMapping::where('client_id', $clientId)
                ->where('identity_type', 'source_identity_hash')
                ->where('identity_value', $sourceHash)
                ->where('is_active', true)
                ->value('canonical_account_id');

            if ($hashMapping) {
                return $hashMapping;
            }

            return "client_{$clientId}:hash_{$sourceHash}";
        }

        return "client_{$clientId}:unknown";
    }

    /**
     * Record an identity mapping row linking an identity type/value to a canonical account.
     */
    public function recordMapping(
        Client $client,
        string $canonicalAccountId,
        string $identityType,
        string $identityValue,
        array $provenance = [],
        float $confidence = 1.0,
        string $sourceSystem = 'adobe_commerce'
    ): CommerceCustomerIdentityMapping {
        return CommerceCustomerIdentityMapping::updateOrCreate(
            [
                'client_id'      => $client->id,
                'identity_type'  => $identityType,
                'identity_value' => $identityValue,
            ],
            [
                'canonical_account_id' => $canonicalAccountId,
                'source_system'        => $sourceSystem,
                'confidence_score'     => $confidence,
                'provenance_metadata'  => $provenance,
                'is_active'            => true,
            ]
        );
    }

    /**
     * Link all identity records (CID, email, hashes) for an account to a single canonical account.
     */
    public function registerAccountIdentities(
        Client $client,
        string $canonicalAccountId,
        ?string $customerId = null,
        ?string $email = null,
        array $sourceHashes = [],
        array $metadata = []
    ): void {
        if (! empty($customerId)) {
            $this->recordMapping(
                $client,
                $canonicalAccountId,
                'registered_customer_id',
                (string) $customerId,
                array_merge($metadata, ['type' => 'registered_customer_id']),
                1.0
            );
        }

        if (! empty($email)) {
            $normEmail = $this->hasher->normalizeEmail($email);
            $this->recordMapping(
                $client,
                $canonicalAccountId,
                'normalized_email',
                $normEmail,
                array_merge($metadata, ['type' => 'normalized_email', 'raw_email' => $email]),
                1.0
            );
        }

        foreach (array_unique(array_filter($sourceHashes)) as $hash) {
            $this->recordMapping(
                $client,
                $canonicalAccountId,
                'source_identity_hash',
                $hash,
                array_merge($metadata, ['type' => 'source_identity_hash']),
                1.0
            );
        }
    }
}
