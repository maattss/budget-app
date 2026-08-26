<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('it creates a verified user non-interactively', function () {
    $this->artisan('app:create-user', [
        '--name' => 'Mats',
        '--email' => 'mats@example.test',
        '--password' => 'a-long-enough-password',
    ])->assertSuccessful();

    $user = User::where('email', 'mats@example.test')->sole();

    expect($user->name)->toBe('Mats')
        ->and(Hash::check('a-long-enough-password', $user->password))->toBeTrue()
        // The critical bit: the app's routes sit behind `verified`, and with
        // MAIL_MAILER=log an unverified user is stuck at a wall they cannot clear.
        ->and($user->email_verified_at)->not->toBeNull();
});

test('the created user can actually reach the dashboard', function () {
    // The real assertion. "A user row exists" is not the same as "you can log in and
    // use the app" - the verified middleware sits between the two.
    $this->artisan('app:create-user', [
        '--name' => 'Mats',
        '--email' => 'mats@example.test',
        '--password' => 'a-long-enough-password',
    ])->assertSuccessful();

    $this->actingAs(User::where('email', 'mats@example.test')->sole())
        ->get(route('dashboard'))
        ->assertOk();
});

test('--unverified leaves the timestamp unset', function () {
    $this->artisan('app:create-user', [
        '--name' => 'Mats',
        '--email' => 'unverified@example.test',
        '--password' => 'a-long-enough-password',
        '--unverified' => true,
    ])->assertSuccessful();

    expect(User::where('email', 'unverified@example.test')->sole()->email_verified_at)->toBeNull();
});

test('the verified middleware is currently inert, so an unverified user is not blocked', function () {
    // Documents a real surprise rather than an intention: routes are wrapped in
    // ['auth', 'verified'], but User does not implement MustVerifyEmail (it is
    // commented out in the model), so the middleware lets everyone through. If that
    // import is ever uncommented this test flips to a redirect - which is exactly the
    // signal you would want, because MAIL_MAILER=log means no verification mail
    // arrives and existing users would be locked out.
    $this->artisan('app:create-user', [
        '--name' => 'Mats',
        '--email' => 'inert@example.test',
        '--password' => 'a-long-enough-password',
        '--unverified' => true,
    ])->assertSuccessful();

    $this->actingAs(User::where('email', 'inert@example.test')->sole())
        ->get(route('dashboard'))
        ->assertOk();
});

test('it refuses a duplicate email rather than creating a second account', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->artisan('app:create-user', [
        '--name' => 'Someone',
        '--email' => 'taken@example.test',
        '--password' => 'a-long-enough-password',
    ])->assertFailed();

    expect(User::where('email', 'taken@example.test')->count())->toBe(1);
});

test('it refuses a short password and writes nothing', function () {
    $this->artisan('app:create-user', [
        '--name' => 'Someone',
        '--email' => 'short@example.test',
        '--password' => 'abc',
    ])->assertFailed();

    expect(User::where('email', 'short@example.test')->exists())->toBeFalse();
});

test('it reads the password from the environment when no flag is given', function () {
    // Preferred over a flag in production: a command line may land in the host's
    // command log, an environment variable does not.
    putenv('INITIAL_USER_PASSWORD=from-the-environment');

    $this->artisan('app:create-user', [
        '--name' => 'Mats',
        '--email' => 'env@example.test',
    ])->assertSuccessful();

    expect(Hash::check('from-the-environment', User::where('email', 'env@example.test')->sole()->password))->toBeTrue();

    putenv('INITIAL_USER_PASSWORD');
});
