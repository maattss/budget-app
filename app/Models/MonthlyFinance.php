<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyFinance extends Model
{
    protected $fillable = [
        'user_id',
        'year',
        'month',
        'income',
        'spending',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'income' => 'decimal:2',
            'spending' => 'decimal:2',
        ];
    }

    /**
     * The amount saved this month, derived from income and spending.
     *
     * @return Attribute<float, never>
     */
    protected function savings(): Attribute
    {
        return Attribute::get(fn (): float => (float) $this->income - (float) $this->spending);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
