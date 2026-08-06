<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\MaintenanceSchedule;
use App\Models\WorkOrder;

class MaintenanceSchedulingService
{
    /**
     * Check a single schedule and, if it's due (by calendar date or by
     * equipment operating hours) and doesn't already have an open work
     * order against it, create the next one automatically — assigned to
     * the same technician as the schedule, and already Approved so it
     * lands straight in their queue without needing a separate manual
     * approval step each cycle.
     */
    public function checkSchedule(MaintenanceSchedule $schedule): ?WorkOrder
    {
        if ($schedule->status !== 'active') {
            return null;
        }

        if (!$schedule->isDue()) {
            return null;
        }

        $hasOpenWorkOrder = WorkOrder::query()
            ->where('maintenance_schedule_id', $schedule->id)
            ->whereIn('status', ['pending', 'approved', 'in_progress'])
            ->exists();

        if ($hasOpenWorkOrder) {
            return null;
        }

        return WorkOrder::create([
            'title' => $schedule->name,
            'description' => $schedule->description,
            // Schedule priorities include 'critical', which isn't one of
            // the work order priority options ('urgent' is the closest
            // equivalent there).
            'priority' => $schedule->priority === 'critical' ? 'urgent' : ($schedule->priority ?? 'medium'),
            'status' => 'approved',
            'equipment_id' => $schedule->equipment_id,
            'maintenance_schedule_id' => $schedule->id,
            'checklist_id' => $schedule->checklist_id,
            'assigned_to' => $schedule->assigned_to,
            'team_id' => $schedule->team_id,
            'submitted_at' => now(),
            'due_date' => $schedule->frequency_type === 'hours' ? now() : $schedule->next_due_date,
        ]);
    }

    /**
     * Re-check every active schedule on a piece of equipment. Called right
     * after that equipment's current_hours reading is updated, so an
     * hour-based schedule that just became overdue gets its work order
     * pushed immediately rather than waiting for the next daily batch run.
     *
     * @return array<int, WorkOrder>
     */
    public function checkSchedulesForEquipment(Equipment $equipment): array
    {
        return $equipment->maintenanceSchedules()
            ->active()
            ->get()
            ->map(fn (MaintenanceSchedule $schedule) => $this->checkSchedule($schedule))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Check every active schedule system-wide. Used by the daily scheduled
     * command to catch calendar-based schedules coming due (hour-based ones
     * are also re-checked here as a safety net, in case an hours update
     * didn't fire the immediate check for some reason).
     *
     * @return array<int, WorkOrder>
     */
    public function checkAllActiveSchedules(): array
    {
        return MaintenanceSchedule::active()
            ->with('equipment')
            ->get()
            ->map(fn (MaintenanceSchedule $schedule) => $this->checkSchedule($schedule))
            ->filter()
            ->values()
            ->all();
    }
}
