<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\EmailVerificationNotificationSentResponse;
use App\Http\Responses\RegisterResponse;
use App\Services\VerificationEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(VerificationEmail::class);
        $this->app->bind(\Laravel\Fortify\Contracts\RegisterResponse::class, RegisterResponse::class);
        $this->app->bind(\Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse::class, EmailVerificationNotificationSentResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(NotificationSent::class, fn (NotificationSent $event) => app(VerificationEmail::class)->recordSent($event));
        ResetPassword::createUrlUsing(fn ($user, string $token): string => rtrim(config('app.frontend_url'), '/').'/reset-password/'.$token.'?email='.urlencode($user->getEmailForPasswordReset()));
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        $frontendRequired = fn () => response()->json([
            'message' => 'Authentication pages are served by the separate Vue frontend.',
        ], 404);

        Fortify::loginView($frontendRequired);
        Fortify::resetPasswordView($frontendRequired);
        Fortify::requestPasswordResetLinkView($frontendRequired);
        Fortify::verifyEmailView($frontendRequired);
        Fortify::registerView($frontendRequired);
        Fortify::twoFactorChallengeView($frontendRequired);
        Fortify::confirmPasswordView($frontendRequired);
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
