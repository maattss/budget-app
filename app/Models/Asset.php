<?php

namespace App\Models;

use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'type' => AssetType::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AssetValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AssetValue::class);
    }
}
