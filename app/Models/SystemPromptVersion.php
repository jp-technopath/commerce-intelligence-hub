<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemPromptVersion extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'system_prompt_id',
        'version_number',
        'system_prompt',
        'user_prompt_template',
        'publish_notes',
        'published_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'created_at'     => 'datetime',
    ];

    public function systemPrompt(): BelongsTo
    {
        return $this->belongsTo(SystemPrompt::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
