<?php

namespace App\Providers;

use App\Listeners\CheckApplicationStorage;
use App\Models\User;
use App\Services\TicketRealtime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Event::listen(DiagnosingHealth::class, CheckApplicationStorage::class);
        User::saved(function (User $user): void {
            if ($user->wasRecentlyCreated || $user->wasChanged(['name', 'email', 'email_verified_at', 'avatar_path', 'role'])) {
                DB::afterCommit(fn () => app(TicketRealtime::class)->afterWrite('AdminUsersChanged', [$user->id]));
            }
        });
        User::deleted(function (): void {
            DB::afterCommit(fn () => app(TicketRealtime::class)->afterWrite('AdminUsersChanged', []));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        if (app()->isProduction()) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
