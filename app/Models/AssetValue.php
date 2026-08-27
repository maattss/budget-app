<?php

namespace App\Models;

use Database\Factories\AssetValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetValue extends Model
{
    /** @use HasFactory<AssetValueFactory> */
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'year',
        'month',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
