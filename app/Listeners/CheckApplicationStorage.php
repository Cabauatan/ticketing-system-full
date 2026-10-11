<?php

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

class CheckApplicationStorage
{
    public function handle(DiagnosingHealth $event): void
    {
        // Web sessions require a valid encryption key even before authentication.
        app('encrypter');

        // Zero-row reads validate connectivity and required tables without loading
        // session tokens or cached values into the health response or logs.
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))->limit(0)->get();
        }

        $cache = config('cache.stores.'.config('cache.default'), []);
        if (($cache['driver'] ?? null) === 'database') {
            DB::connection($cache['connection'] ?? null)
                ->table($cache['table'] ?? 'cache')->limit(0)->get();
            DB::connection($cache['lock_connection'] ?? $cache['connection'] ?? null)
                ->table($cache['lock_table'] ?? 'cache_locks')->limit(0)->get();
        }
    }
}
