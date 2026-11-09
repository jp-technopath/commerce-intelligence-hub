<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Intelligence\CustomerPurchaseFactsReconciler;
use Illuminate\Console\Command;

class ReconcileCustomerFactsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'commerce:reconcile-customer-facts 
                            {--client= : The ID of the client to reconcile (optional, reconciles all if omitted)}
                            {--dry-run : Inspect discrepancies and invariants without writing changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely rebuild commerce_customer_purchase_facts from authoritative validated orders in commerce_orders';

    protected CustomerPurchaseFactsReconciler $reconciler;

    public function __construct(CustomerPurchaseFactsReconciler $reconciler)
    {
        parent::__construct();
        $this->reconciler = $reconciler;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $clientId = $this->option('client');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== Commerce Customer Purchase Facts Reconciliation ===');
        if ($dryRun) {
            $this->warn('Running in DRY-RUN mode. No changes will be written.');
        }

        $clients = $clientId
            ? Client::where('id', $clientId)->get()
            : Client::where('is_internal', false)->get();

        if ($clients->isEmpty()) {
            $this->error('No matching clients found.');
            return self::FAILURE;
        }

        $allBalanced = true;

        foreach ($clients as $client) {
            $this->newLine();
            $this->info("Reconciling Client #{$client->id} ({$client->name})...");

            $result = $this->reconciler->reconcileClient($client, $dryRun);

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Client ID', $result['client_id']],
                    ['Client Name', $result['client_name']],
                    ['Dry Run Mode', $result['dry_run'] ? 'YES' : 'NO'],
                    ['Initial Facts Count', number_format($result['initial_fact_count'])],
                    ['Authoritative Customers', number_format($result['authoritative_customer_count'])],
                    ['Authoritative Valid Orders', number_format($result['authoritative_valid_orders'])],
                    ['Authoritative Net Revenue', '$' . number_format($result['authoritative_net_revenue'], 2)],
                    ['Authoritative Repeat Customers', number_format($result['authoritative_repeat_customers']) . " ({$result['authoritative_repeat_rate_pct']}%)"],
                    ['Orphaned Facts Archived', number_format($result['orphaned_facts_to_archive'])],
                    ['Final Facts Count', number_format($result['post_reconciled_fact_count'])],
                    ['Invariants Balanced (100%)', $result['is_balanced'] ? '✓ YES (Balanced)' : '✗ NO (Discrepancy)'],
                ]
            );

            if (! $result['is_balanced']) {
                $allBalanced = false;
            }
        }

        $this->newLine();
        if ($allBalanced) {
            $this->info('✓ Reconciliation completed successfully with 100% balance across all verified clients.');
            return self::SUCCESS;
        } else {
            $this->error('⚠ One or more clients failed invariant balance checks.');
            return self::FAILURE;
        }
    }
}
