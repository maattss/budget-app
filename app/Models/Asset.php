<?php

namespace App\Models;

use App\Enums\AssetType;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property AssetType $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AssetValue> $values
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

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
     * This asset's recorded values, oldest first.
     *
     * The ordering lives on the relation rather than at each call site so that
     * valueAt() below can rely on it however the relation was loaded - eagerly, lazily,
     * or already in memory. A method whose correctness depends on the caller having
     * remembered an orderBy is a method that will eventually be called wrongly.
     *
     * @return HasMany<AssetValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AssetValue::class)
            ->orderBy('year')
            ->orderBy('month');
    }

    /**
     * What this asset was worth in a given month, carrying the last known value forward.
     *
     * A recorded value is a standing claim about worth, not a statement about one
     * calendar month: a house valued in March is still worth roughly that in August,
     * and nobody re-appraises a house monthly. Reading only the exact month meant net
     * worth collapsed to zero on the first of every month.
     *
     * Forward only, never backward. Before an asset's first recorded value it did not
     * exist as far as this app knows, and back-filling would rewrite history - a house
     * bought in June would appear to have been owned all year. Hence zero, which is the
     * honest answer for "you did not own this yet".
     *
     * Reads the loaded collection rather than querying, so a page that has eager loaded
     * values can call this for every asset and every month without a single extra query.
     */
    /**
     * The value actually recorded in one month, or null if none was.
     *
     * The counterpart to valueAt(), and deliberately not the same thing. valueAt()
     * answers "what was this worth then", carrying earlier values forward; this answers
     * "what did the user type for this month", and carries nothing.
     *
     * The month form needs this one. Prefilling its fields with a carried-forward figure
     * would put a number in an empty box that the user never entered, and saving the
     * form would then write it back as though they had - one unedited visit to an old
     * month and a guess becomes a record.
     */
    public function recordedValueIn(int $year, int $month): ?AssetValue
    {
        return $this->values->first(
            fn (AssetValue $value): bool => $value->year === $year && $value->month === $month
        );
    }

    public function valueAt(int $year, int $month): float
    {
        $target = $year * 100 + $month;

        foreach ($this->values->reverse() as $value) {
            if ($value->year * 100 + $value->month <= $target) {
                return (float) $value->value;
            }
        }

        return 0.0;
    }
}
