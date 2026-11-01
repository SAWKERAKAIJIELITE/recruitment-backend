<?php

namespace App\Modules\Contracts\Console;

use App\Modules\Contracts\Actions\RemindExpiringContracts;
use Illuminate\Console\Command;

/** Daily (routes/console.php): notifies staff of contracts ending within the reminder window (D-065). */
class RemindExpiringContractsCommand extends Command
{
    protected $signature = 'contracts:remind-expiring';

    protected $description = 'Notify staff of contracts that end soon and are not renewed';

    public function handle(RemindExpiringContracts $remind): int
    {
        $count = $remind->run();
        $this->info("Contracts reminded: {$count}");

        return self::SUCCESS;
    }
}
