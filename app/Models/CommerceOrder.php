<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'integration_id',
        'source',
        'source_order_id',
        'source_increment_id',
        'order_status',
        'order_state',
        'payment_method',
        'customer_identity_hash',
        'canonical_account_id',
        'registered_customer_id',
        'customer_email',
        'customer_name',
        'company_name',
        'order_date',
        'refund_date',
        'gross_revenue',
        'invoiced_revenue',
        'paid_revenue',
        'refunded_revenue',
        'canceled_revenue',
        'net_revenue',
        'qualifying_order_value',
        'outstanding_receivable',
        'settlement_unverified_revenue',
        'refundable_core_deposit',
        'core_deposit_refunded',
        'outstanding_core_deposit',
        'tax_amount',
        'shipping_amount',
        'discount_amount',
        'currency',
        'base_currency',
        'reporting_currency',
        'exchange_rate',
        'is_valid',
        'qualification_classification',
        'qualification_reason',
        'exclusion_reason',
        'source_updated_at',
        'financial_last_changed_at',
        'collected_at',
        'metadata_json',
    ];

    protected $casts = [
        'order_date'                => 'datetime',
        'refund_date'               => 'datetime',
        'source_updated_at'         => 'datetime',
        'financial_last_changed_at' => 'datetime',
        'collected_at'              => 'datetime',
        'gross_revenue'             => 'decimal:2',
        'invoiced_revenue'          => 'decimal:2',
        'paid_revenue'              => 'decimal:2',
        'refunded_revenue'          => 'decimal:2',
        'canceled_revenue'          => 'decimal:2',
        'net_revenue'                   => 'decimal:2',
        'qualifying_order_value'        => 'decimal:2',
        'outstanding_receivable'        => 'decimal:2',
        'settlement_unverified_revenue' => 'decimal:2',
        'refundable_core_deposit'       => 'decimal:2',
        'core_deposit_refunded'         => 'decimal:2',
        'outstanding_core_deposit'      => 'decimal:2',
        'tax_amount'                    => 'decimal:2',
        'shipping_amount'           => 'decimal:2',
        'discount_amount'           => 'decimal:2',
        'exchange_rate'             => 'decimal:6',
        'is_valid'                  => 'boolean',
        'metadata_json'             => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
