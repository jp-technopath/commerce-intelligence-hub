<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceCustomerPurchaseFact extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'customer_identity_hash',
        'customer_id',
        'first_valid_order_at',
        'latest_valid_order_at',
        'lifetime_valid_order_count',
        'lifetime_net_revenue',
        'is_registered_customer',
        'refreshed_at',
    ];

    protected $casts = [
        'first_valid_order_at'  => 'datetime',
        'latest_valid_order_at' => 'datetime',
        'refreshed_at'          => 'datetime',
        'lifetime_net_revenue'  => 'decimal:2',
        'is_registered_customer'=> 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
