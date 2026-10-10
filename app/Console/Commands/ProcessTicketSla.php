<?php

namespace App\Console\Commands;

use App\Services\TicketStore;
use Illuminate\Console\Command;

class ProcessTicketSla extends Command
{
    protected $signature = 'tickets:process-sla';

    protected $description = 'Process overdue SLA alerts and raise unresolved ticket priority after seven days';

    public function handle(TicketStore $store): int
    {
        if (! $store->ready()) {
            $this->error('MySQL is required.');

            return self::FAILURE;
        }
        $rows = $store->call('TicketEscalate', []);
        $this->info('Processed '.($rows[0]->processed ?? 0).' ticket SLA/priority escalations.');

        return self::SUCCESS;
    }
}
