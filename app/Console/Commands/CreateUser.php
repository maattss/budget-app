<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

/**
 * Create a user from the command line.
 *
 * This exists because public registration is disabled (see config/fortify.php), so
 * there is no sign-up form in production and an account has to be made server-side.
 *
 * Every option can be passed as a flag so the command runs non-interactively — a remote
 * command runner has no TTY to prompt against. It falls back to prompting when run
 * locally with options missing.
 */
class CreateUser extends Command
{
    protected $signature = 'app:create-user
                            {--name= : The user\'s display name}
                            {--email= : The email address they will log in with}
                            {--password= : Their password; read from INITIAL_USER_PASSWORD if omitted}
                            {--unverified : Leave email_verified_at null; harmless today, but a lockout if MustVerifyEmail is ever enabled}';

    protected $description = 'Create a user account, since public registration is disabled';

    /**
     * Read an option as a non-empty string, or null.
     *
     * Command::option() is typed bool|string|array|null - a value-less flag comes back
     * as true - so the value needs narrowing before it reaches Hash::make() or the
     * validator.
     */
    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function handle(): int
    {
        // Prompting is only safe when something is there to answer. A remote command
        // runner has no TTY, and Laravel Prompts throws NonInteractiveValidationException
        // ("Required.") on a required prompt - an error that says nothing about which
        // input was missing or how to supply it. So when input is non-interactive,
        // collect what is missing and report it properly instead.
        $interactive = $this->input->isInteractive();

        $name = $this->stringOption('name');
        $email = $this->stringOption('email');

        // Prefer an environment variable over a flag: anything passed on a command line
        // may be recorded by the host's command log or shell history.
        //
        // getenv(), not env(): the release process runs `artisan config:cache`, after
        // which Laravel never loads .env and the env() helper returns null. getenv()
        // reads the actual process environment, which is where a host like Laravel Cloud
        // injects its variables, so it keeps working with a cached config.
        $password = $this->stringOption('password') ?? (getenv('INITIAL_USER_PASSWORD') ?: null);

        if (! $interactive) {
            $missing = array_keys(array_filter([
                '--name' => $name === null,
                '--email' => $email === null,
                '--password (or the INITIAL_USER_PASSWORD environment variable)' => $password === null,
            ]));

            if ($missing !== []) {
                $this->components->error('Cannot prompt for input here, and these are missing: '.implode(', ', $missing).'.');
                $this->line('');
                $this->line('  Pass them as flags, for example:');
                $this->line('    <fg=gray>php artisan app:create-user --name="Your Name" --email="you@example.com"</>');
                $this->line('');
                $this->line('  The password is read from INITIAL_USER_PASSWORD when --password is omitted.');
                $this->line('  If you set that variable on the host, the app may need a redeploy or restart');
                $this->line('  before the running process can see it.');

                return self::FAILURE;
            }
        }

        $name ??= text('Name', required: true);
        $email ??= text('Email', required: true);
        $password ??= promptPassword('Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // forceFill, not a fourth key above: User declares
        // #[Fillable(['name', 'email', 'password'])], so passing email_verified_at to
        // create() is silently dropped. (Model::preventSilentlyDiscardingAttributes(),
        // the second flag in shouldBeStrict(), turns that silence into an exception.)
        //
        // Marked verified by default so the account is usable the moment it exists. The
        // `verified` middleware happens to be inert today — User does not implement
        // MustVerifyEmail, it is commented out — but MAIL_MAILER=log means no
        // verification mail would ever arrive if that were enabled, so a stored
        // timestamp is the safe default rather than something to fix later.
        if (! $this->option('unverified')) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $this->components->info("Created user #{$user->id} <{$user->email}>.");

        if (! $this->option('unverified')) {
            $this->components->twoColumnDetail('Email verified', 'yes');
        }

        if ($this->option('password')) {
            $this->components->warn(
                'The password was passed as a command-line flag and may appear in host '
                .'command logs. Change it from the profile settings page, or use '
                .'INITIAL_USER_PASSWORD next time.'
            );
        }

        return self::SUCCESS;
    }
}
