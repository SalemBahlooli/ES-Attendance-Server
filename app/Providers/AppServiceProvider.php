<?php

namespace App\Providers;

use App\Models\User;
use App\Services\SignInLog;
use App\Support\Permissions\PermissionTree;
use App\Support\Settings;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        $this->applySettings();
        $this->registerPermissions();
        $this->registerPasswordRules();
        $this->registerRateLimiters();

        // Every successful sign-in, whatever the path, lands in the sign-in log.
        Event::listen(Login::class, fn (Login $event) => $event->user instanceof User
            ? SignInLog::success($event->user, $event->remember)
            : null);

        // "Sign in with Microsoft"
        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);

        Paginator::defaultView('components.pagination');

        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }

    /** Push values from Settings into the framework config. */
    private function applySettings(): void
    {
        $settings = $this->app->make(Settings::class);

        config([
            'app.name' => $settings->get('app_name') ?: config('app.name'),
            'session.lifetime' => (int) $settings->get('session_lifetime', config('session.lifetime')),
            'mail.from.name' => $settings->get('mail_from_name') ?: ($settings->get('app_name') ?: config('mail.from.name')),
            'mail.from.address' => $settings->get('mail_from_address') ?: config('mail.from.address'),
        ]);
    }

    /**
     * Every name in config/permission_tree.php is a Gate ability:
     *
     *   @can('users.edit')   ->middleware('can:users.edit')   Gate::allows('users.edit')
     * Other abilities (policies) are left to Laravel.
     */
    private function registerPermissions(): void
    {
        Gate::before(function (User $user, string $ability) {
            if (PermissionTree::exists($ability)) {
                return $user->hasAccess($ability);
            }

            return null;
        });
    }

    /** Password rules from Settings → Security, used everywhere a password is chosen. */
    private function registerPasswordRules(): void
    {
        Password::defaults(function () {
            $rule = Password::min((int) setting('password_min_length', 8))->max(255);
            if (setting('password_mixed_case')) {
                $rule->mixedCase();
            }
            if (setting('password_numbers')) {
                $rule->numbers();
            }
            if (setting('password_symbols')) {
                $rule->symbols();
            }
            if (setting('password_uncompromised') && ! app()->runningUnitTests()) {
                $rule->uncompromised();
            }

            return $rule;
        });
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Forms that send email or take a code: per IP, so they can't be used to flood or guess.
        RateLimiter::for('auth-sensitive', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));
    }
}
