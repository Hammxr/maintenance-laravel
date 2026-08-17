<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'team_id',
])]
class EquipmentCategory extends Model
{
    use HasFactory;

    /**
     * The tenant that owns this category. Each team defines its own
     * vocabulary, so categories are not shared between teams.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }
}
