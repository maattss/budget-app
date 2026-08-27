<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a set of assets is worth, and how it splits into owned and owed.
 *
 * This lives in app/ for the same reason App\Support\Chart does: phpstan.neon does not
 * scan resources/, so arithmetic written inside a Blade page component is neither
 * type-checked nor reachable from a unit test. Net worth is the one number this app
 * exists to produce - it does not belong in the one directory the checker cannot see.
 *
 * It was also written three times over. The dashboard, the assets page and the month
 * form each carried their own copy of the owned/owed split and their own totals, and
 * the copies had already drifted: two of them read the current month only while the
 * third read whatever the eager load happened to contain.
 *
 * Operates purely on an in-memory collection. Nothing here queries, so a page loads its
 * assets once - with values eager loaded - and asks as many questions as it likes
 * without a further round trip.
 */
class Portfolio
{
    /**
     * @param  Collection<int, Asset>  $all  every asset, owned and owed alike, with values loaded
     */
    final public function __construct(protected Collection $all) {}

    /**
     * Load one user's whole portfolio.
     *
     * Two queries whatever the asset count: one for the assets, one for their values.
     * The value history is deliberately unbounded - Asset::valueAt() carries the last
     * recorded value forward, so clipping the load to a window would hide exactly the
     * row it needs. A personal budget accrues twelve rows per asset per year.
     */
    public static function for(User $user): static
    {
        return new static(
            $user->assets()->orderBy('name')->with('values')->get()
        );
    }

    /**
     * Every asset, owned and owed, in display order.
     *
     * @return Collection<int, Asset>
     */
    public function all(): Collection
    {
        return $this->all;
    }

    /**
     * The things the user owns.
     *
     * @return Collection<int, Asset>
     */
    public function owned(): Collection
    {
        return $this->all->reject(fn (Asset $asset): bool => $asset->type->isLiability());
    }

    /**
     * The things the user owes.
     *
     * @return Collection<int, Asset>
     */
    public function owed(): Collection
    {
        return $this->all->filter(fn (Asset $asset): bool => $asset->type->isLiability());
    }

    /**
     * Total value of everything owned, in a given month.
     */
    public function ownedTotal(int $year, int $month): float
    {
        return $this->totalOf($this->owned(), $year, $month);
    }

    /**
     * Total value of everything owed, in a given month.
     */
    public function owedTotal(int $year, int $month): float
    {
        return $this->totalOf($this->owed(), $year, $month);
    }

    /**
     * Assets minus liabilities, in a given month.
     */
    public function netWorth(int $year, int $month): float
    {
        return $this->ownedTotal($year, $month) - $this->owedTotal($year, $month);
    }

    /**
     * Whether anything at all had been recorded by the end of a given month.
     *
     * This is where "you had nothing" and "you had not started tracking" divide.
     * Charting the months before the first entry as zero would claim the user was
     * broke, when the data only says they had not begun - and on a net worth chart
     * that reads as having been wiped out. After the first entry every month has a
     * defined net worth, because values carry forward, so a series is continuous from
     * that point rather than gapped wherever a month went unrecorded.
     */
    public function hasAnyValueBy(int $year, int $month): bool
    {
        $target = $year * 100 + $month;

        foreach ($this->all as $asset) {
            $first = $asset->values->first();

            if ($first !== null && $first->year * 100 + $first->month <= $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * What is owned in a given month, grouped by type and ordered largest first.
     *
     * Liabilities are excluded: a part-to-whole chart mixing what you own with what you
     * owe has no meaningful whole. Types with nothing in them are left out rather than
     * charted as a zero-width slice.
     *
     * @return array<int, array{name: string, value: float, var: string}>
     */
    public function allocation(int $year, int $month): array
    {
        $totals = [];

        foreach ($this->owned() as $asset) {
            $amount = $asset->valueAt($year, $month);

            if ($amount <= 0) {
                continue;
            }

            $key = $asset->type->value;
            $totals[$key] ??= [
                'name' => $asset->type->label(),
                'value' => 0.0,
                'var' => '--viz-'.$asset->type->seriesSlot(),
            ];
            $totals[$key]['value'] += $amount;
        }

        usort($totals, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $totals;
    }

    /**
     * One month's total across a group of assets.
     *
     * @param  Collection<int, Asset>  $assets
     */
    protected function totalOf(Collection $assets, int $year, int $month): float
    {
        return (float) $assets->sum(fn (Asset $asset): float => $asset->valueAt($year, $month));
    }
}
