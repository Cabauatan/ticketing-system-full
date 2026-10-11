<?php

namespace App\Console\Commands;

use App\Listeners\CheckApplicationStorage;
use Illuminate\Console\Command;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Throwable;

class CheckApplicationStorageCommand extends Command
{
    protected $signature = 'app:check-storage';

    protected $description = 'Check application encryption and configured session/cache storage without reading stored values';

    public function handle(CheckApplicationStorage $storage): int
    {
        try {
            $storage->handle(new DiagnosingHealth);
        } catch (Throwable $exception) {
            $this->error('Application storage readiness failed.');
            $this->line($this->diagnostic($exception));

            return self::FAILURE;
        }

        $this->info('Application storage readiness passed.');

        return self::SUCCESS;
    }

    private function diagnostic(Throwable $exception): string
    {
        $secrets = [config('app.key'), ...config('app.previous_keys', [])];
        foreach (config('database.connections', []) as $connection) {
            $secrets[] = $connection['password'] ?? null;
            $secrets[] = $connection['url'] ?? null;
        }

        $secrets = array_values(array_unique(array_filter($secrets, fn ($secret) => is_string($secret) && $secret !== '')));
        $secrets = array_unique([...$secrets, ...array_map('rawurlencode', $secrets)]);
        usort($secrets, fn (string $left, string $right) => strlen($right) <=> strlen($left));

        return mb_strimwidth(str_replace($secrets, '[redacted]', $exception->getMessage()), 0, 2000, '…');
    }
}
