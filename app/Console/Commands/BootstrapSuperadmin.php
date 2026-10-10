<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BootstrapSuperadmin extends Command
{
    protected $signature = 'tickets:bootstrap-superadmin {email : Existing registered account}';

    protected $description = 'Configure the first superadmin without changing the password or email verification';

    public function handle(): int
    {
        $data = Validator::make(['email' => $this->argument('email')], ['email' => ['required', 'email']])->validate();
        if (DB::getDriverName() !== 'mysql') {
            $this->error('MySQL is required.');

            return self::FAILURE;
        }
        DB::selectResultSets('CALL SuperadminBootstrap(?)', [$data['email']], false);
        $this->info('First superadmin configured. Password and email verification are unchanged.');

        return self::SUCCESS;
    }
}
