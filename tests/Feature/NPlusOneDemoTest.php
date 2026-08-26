<?php

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('count the queries the dashboard runs', function () {
    $user = User::factory()->create();
    $now = now();

    Asset::factory()->count(5)->for($user)->create()->each(
        fn (Asset $asset) => $asset->values()->create([
            'year' => $now->year,
            'month' => $now->month,
            'value' => 10000,
        ])
    );

    $this->actingAs($user);

    DB::enableQueryLog();

    Livewire::test('pages::dashboard')->assertOk();

    $log = DB::getQueryLog();

    fwrite(STDERR, PHP_EOL.'--- '.count($log).' queries ---'.PHP_EOL);
    foreach ($log as $i => $query) {
        fwrite(STDERR, ($i + 1).'. '.$query['query'].PHP_EOL);
    }
});
