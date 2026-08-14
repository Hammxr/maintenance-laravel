<?php

namespace App\Console\Commands;

use App\Services\MaintenanceSchedulingService;
use Illuminate\Console\Command;

#[\Illuminate\Console\Attributes\Description('Automatically create work orders for maintenance schedules that are due, by calendar date or by equipment operating hours')]
#[\Illuminate\Console\Attributes\Signature('maintenance:generate-work-orders')]
class GenerateScheduledWorkOrdersCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MaintenanceSchedulingService $schedulingService)
    {
        $this->info('Checking active maintenance schedules for due work...');

        $created = $schedulingService->checkAllActiveSchedules();

        if ($created === []) {
            $this->info('No schedules are due right now.');

            return Command::SUCCESS;
        }

        $this->info('Created ' . count($created) . ' work order(s):');

        foreach ($created as $workOrder) {
            $this->line("  - #{$workOrder->id}: {$workOrder->title}");
        }

        return Command::SUCCESS;
    }
}
