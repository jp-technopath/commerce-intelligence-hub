<?php

namespace App\Services\Security;

use App\Models\Client;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CustomerIdentityHasher
 *
 * Provides client-scoped, HMAC-SHA256 privacy-safe identity hashing,
 * conservative email normalization, and GDPR/privacy anonymization workflows.
 */
class CustomerIdentityHasher
{
    /**
     * Compute a client-scoped HMAC-SHA256 hash for a customer identifier or guest email.
     */
    public function hashCustomerIdentity(Client $client, ?string $customerId = null, ?string $email = null): ?string
    {
        if ($customerId !== null && trim($customerId) !== '') {
            $identifier = 'cid:' . trim($customerId);
        } elseif ($email !== null && trim($email) !== '') {
            $identifier = 'email:' . $this->normalizeEmail($email);
        } else {
            return null;
        }

        $secretKey = $this->getClientHmacKey($client);

        return hash_hmac('sha256', $identifier, $secretKey);
    }

    /**
     * Apply conservative email normalization.
     *
     * Rules:
     * - Trim leading and trailing whitespace.
     * - Lowercase the domain portion.
     * - Preserve the local portion exactly (DO NOT strip plus-tags, DO NOT remove periods).
     */
    public function normalizeEmail(string $email): string
    {
        $trimmed = trim($email);

        if (! str_contains($trimmed, '@')) {
            return $trimmed;
        }

        [$local, $domain] = explode('@', $trimmed, 2);

        return $local . '@' . strtolower($domain);
    }

    /**
     * Retrieve or generate the client-specific encrypted HMAC secret key.
     */
    public function getClientHmacKey(Client $client): string
    {
        $config = $client->monitoring_config ?? [];
        $encryptedKey = $config['identity_hmac_key'] ?? null;

        if ($encryptedKey) {
            try {
                return Crypt::decryptString($encryptedKey);
            } catch (\Exception $e) {
                Log::warning('CustomerIdentityHasher: failed to decrypt HMAC key, re-generating', [
                    'client_id' => $client->id,
                ]);
            }
        }

        // Generate a new 32-byte secret key and encrypt it
        $rawKey = bin2hex(random_bytes(16));
        $config['identity_hmac_key'] = Crypt::encryptString($rawKey);

        $client->update(['monitoring_config' => $config]);

        return $rawKey;
    }

    /**
     * Anonymize or purge a customer's identity records for GDPR / privacy compliance.
     *
     * Anonymizes `customer_identity_hash` on `commerce_orders` while retaining
     * non-identifying accounting numbers (gross/net revenue, order dates, tax, shipping).
     * Purges records from `commerce_customer_purchase_facts`.
     *
     * @return array{anonymized_orders: int, purged_facts: int}
     */
    public function purgeCustomerIdentity(Client $client, string $identifier): array
    {
        $hash = str_contains($identifier, '@')
            ? $this->hashCustomerIdentity($client, email: $identifier)
            : $this->hashCustomerIdentity($client, customerId: $identifier);

        if (! $hash) {
            return ['anonymized_orders' => 0, 'purged_facts' => 0];
        }

        $anonymizedOrders = 0;
        $purgedFacts      = 0;

        DB::transaction(function () use ($client, $hash, &$anonymizedOrders, &$purgedFacts) {
            // Anonymize orders (retain financial totals for accounting)
            $anonymizedOrders = DB::table('commerce_orders')
                ->where('client_id', $client->id)
                ->where('customer_identity_hash', $hash)
                ->update([
                    'customer_identity_hash' => 'ANONYMIZED_' . substr(md5(uniqid()), 0, 8),
                    'registered_customer_id' => null,
                    'financial_last_changed_at' => now(),
                    'updated_at' => now(),
                ]);

            // Purge customer facts
            $purgedFacts = DB::table('commerce_customer_purchase_facts')
                ->where('client_id', $client->id)
                ->where('customer_identity_hash', $hash)
                ->delete();
        });

        Log::info('CustomerIdentityHasher: purged customer identity', [
            'client_id'         => $client->id,
            'anonymized_orders' => $anonymizedOrders,
            'purged_facts'      => $purgedFacts,
        ]);

        return [
            'anonymized_orders' => $anonymizedOrders,
            'purged_facts'      => $purgedFacts,
        ];
    }
}
