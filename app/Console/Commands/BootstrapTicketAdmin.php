<?php

namespace App\Console\Commands;

use App\Services\TicketStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class BootstrapTicketAdmin extends Command
{
    protected $signature = 'tickets:bootstrap-admin {email : Email of an existing registered account} {--verify-local : Mark email verified in local development only}';

    protected $description = 'Configure the first administrator; refuses when an admin already exists';

    public function handle(TicketStore $store): int
    {
        $email = $this->argument('email');
        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error('Enter a valid registered email.');

            return self::FAILURE;
        }
        if ($this->option('verify-local') && ! app()->environment('local')) {
            $this->error('--verify-local can only be used in the local environment.');

            return self::FAILURE;
        }
        $store->call('AdminBootstrap', [$email, (int) $this->option('verify-local')]);
        $this->info('First administrator configured. The existing password is unchanged.');

        return self::SUCCESS;
    }
}
