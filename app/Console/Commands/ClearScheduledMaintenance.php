<?php

namespace App\Console\Commands;

use App\Services\ScheduledMaintenance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:scheduled-maintenance {action : start or clear}')]
#[Description('Start or clear scheduled maintenance during deployment')]
class ClearScheduledMaintenance extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ScheduledMaintenance $maintenance): int
    {
        if ($this->argument('action') === 'start') {
            $maintenance->startDeployment();
        } elseif ($this->argument('action') === 'clear') {
            $maintenance->clear();
        } else {
            $this->error('Use start or clear.');

            return self::INVALID;
        }

        return self::SUCCESS;
    }
}
