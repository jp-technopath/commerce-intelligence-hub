<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehavioralMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'date',
        'rage_clicks',
        'dead_clicks',
        'quick_backs',
        'excessive_scrolling',
        'script_errors',
        'error_clicks',
        'scroll_depth',
        'engagement_time',
        'traffic',
        'friction_score',
        'metadata_json',
    ];

    protected $casts = [
        'date'          => 'date',
        'scroll_depth'  => 'decimal:2',
        'engagement_time' => 'decimal:2',
        'friction_score'  => 'decimal:2',
        'metadata_json'   => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Return the device/browser dimension breakdown from metadata.
     */
    public function deviceBrowserBreakdown(): ?array
    {
        return $this->metadata_json['device_browser'] ?? null;
    }

    /**
     * Return the traffic source dimension breakdown from metadata.
     */
    public function trafficSourceBreakdown(): ?array
    {
        return $this->metadata_json['traffic_source'] ?? null;
    }

    /**
     * Return the page/device dimension breakdown from metadata.
     */
    public function pageDeviceBreakdown(): ?array
    {
        return $this->metadata_json['page_device'] ?? null;
    }
}
