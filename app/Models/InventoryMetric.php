<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'date',
        'source',
        'total_products',
        'in_stock_count',
        'out_of_stock_count',
        'low_stock_count',
        'out_of_stock_rate',
        'low_stock_rate',
        'inventory_turnover',
        'backorder_count',
        'metadata_json',
    ];

    protected $casts = [
        'date'          => 'date',
        'metadata_json' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
