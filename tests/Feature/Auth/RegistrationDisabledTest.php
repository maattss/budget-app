<?php

use Illuminate\Support\Facades\Route;

/**
 * The suite runs with FORTIFY_REGISTRATION=true (phpunit.xml), which is the opposite of
 * production, where it defaults to false. That divergence hid a 500: disabling the
 * Fortify feature removes the `register` route, and the login view linked to it
 * unconditionally, so every visit to /login threw
 * "Route [register] not defined".
 *
 * These tests boot a fresh application with the flag off, so the production
 * configuration is actually exercised rather than assumed.
 */
afterEach(function () {
    // Restore the suite-wide value so test order cannot matter.
    putenv('FORTIFY_REGISTRATION=true');
    $_ENV['FORTIFY_REGISTRATION'] = 'true';
    $_SERVER['FORTIFY_REGISTRATION'] = 'true';
});

test('disabling registration really does remove the route', function () {
    putenv('FORTIFY_REGISTRATION=false');
    $_ENV['FORTIFY_REGISTRATION'] = 'false';
    $_SERVER['FORTIFY_REGISTRATION'] = 'false';
    $this->refreshApplication();

    expect(config('fortify.features'))->not->toContain('registration')
        ->and(Route::has('register'))->toBeFalse();
});

test('the login page renders with registration disabled', function () {
    putenv('FORTIFY_REGISTRATION=false');
    $_ENV['FORTIFY_REGISTRATION'] = 'false';
    $_SERVER['FORTIFY_REGISTRATION'] = 'false';
    $this->refreshApplication();

    // This is the regression. Before the fix it threw a ViewException wrapping
    // RouteNotFoundException, which surfaced as a 500 on the deployed app.
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Sign up');
});

test('the register route 404s rather than erroring when disabled', function () {
    putenv('FORTIFY_REGISTRATION=false');
    $_ENV['FORTIFY_REGISTRATION'] = 'false';
    $_SERVER['FORTIFY_REGISTRATION'] = 'false';
    $this->refreshApplication();

    $this->get('/register')->assertNotFound();
});

test('the login page still offers a password reset link', function () {
    putenv('FORTIFY_REGISTRATION=false');
    $_ENV['FORTIFY_REGISTRATION'] = 'false';
    $_SERVER['FORTIFY_REGISTRATION'] = 'false';
    $this->refreshApplication();

    // resetPasswords stays enabled, so turning registration off must not take the
    // forgot-password affordance with it.
    $this->get(route('login'))->assertOk()->assertSee('Forgot your password?');
});
