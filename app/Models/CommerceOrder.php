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
        'customer_identity_hash',
        'registered_customer_id',
        'order_date',
        'refund_date',
        'gross_revenue',
        'refunded_revenue',
        'net_revenue',
        'tax_amount',
        'shipping_amount',
        'discount_amount',
        'currency',
        'base_currency',
        'reporting_currency',
        'exchange_rate',
        'is_valid',
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
        'refunded_revenue'          => 'decimal:2',
        'net_revenue'               => 'decimal:2',
        'tax_amount'                => 'decimal:2',
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
