<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'finding_id',
        'user_id',
        'root_cause',
        'fix_implemented',
        'outcome',
        'lessons_learned',
    ];

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
