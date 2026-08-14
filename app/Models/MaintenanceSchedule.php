<?php

namespace App\Models;

use App\Notifications\TaskAssignedNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'name',
    'description',
    'equipment_id',
    'frequency_type',
    'frequency_value',
    'next_due_date',
    'last_completed_date',
    'hours_at_last_maintenance',
    'estimated_duration',
    'priority',
    'status',
    'assigned_to',
    'instructions',
    'checklist_id',
    'team_id',
])]
class MaintenanceSchedule extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Feeds the "Change Log" report — logs only the fields someone would
     * meaningfully edit on a maintenance schedule, and only when they
     * actually changed. Deliberately excludes hours_at_last_maintenance and
     * next_due_date, since those are advanced automatically by
     * markCompleted() rather than hand-edited — logging them would flood
     * the report with routine completions rather than actual schedule
     * changes.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('maintenance_schedule')
            ->logOnly([
                'name',
                'description',
                'equipment_id',
                'frequency_type',
                'frequency_value',
                'estimated_duration',
                'priority',
                'status',
                'assigned_to',
                'instructions',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * The relationships that should be eagerly loaded.
     *
     * @var array
     */
    #[\Override]
    protected $with = [];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function overdue($query)
    {
        return $query->where('next_due_date', '<', now())
                    ->where('status', 'active');
    }

    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function dueSoon($query, $days = 7)
    {
        return $query->whereBetween('next_due_date', [now(), now()->addDays($days)])
                    ->where('status', 'active');
    }

    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function active($query)
    {
        return $query->where('status', 'active');
    }

    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function inactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Calculate the next calendar due date. Not used for frequency_type
     * 'hours' — hour-based schedules are driven by equipment usage hours
     * (see isDueByHours()), not by adding hours to a calendar date.
     *
     * Reads $this->last_completed_date, so callers that just updated that
     * attribute in memory (like markCompleted() below) get the correct
     * "from" date without needing a round trip to the database.
     */
    public function calculateNextDueDate()
    {
        if ($this->frequency_type === 'hours') {
            return $this->next_due_date;
        }

        // A schedule that has never been completed hasn't finished a cycle,
        // so there is no new one to calculate — the next_due_date already on
        // it is the first-service date somebody deliberately chose. Measuring
        // an interval from now() instead would silently overwrite that with
        // "a full interval from today", and push it further out on every
        // call. Same principle as the 'hours' branch above and the default
        // arm below: with no cycle to advance, keep the date we have.
        if ($this->last_completed_date === null && $this->next_due_date !== null) {
            return $this->next_due_date;
        }

        // Nothing to preserve (never completed and no due date set), so now()
        // is the only sensible base to seed a first date from.
        $from = $this->last_completed_date ?? now();

        return match ($this->frequency_type) {
            'daily' => $from->copy()->addDays($this->frequency_value),
            'weekly' => $from->copy()->addWeeks($this->frequency_value),
            'monthly' => $from->copy()->addMonths($this->frequency_value),
            'yearly' => $from->copy()->addYears($this->frequency_value),
            default => $this->next_due_date,
        };
    }

    /**
     * Is this schedule currently due for hour-based maintenance? Compares
     * the equipment's current operating hours against the hours reading
     * recorded the last time THIS schedule was completed (or 0, if it's
     * never been completed before) plus the required interval.
     */
    public function isDueByHours(): bool
    {
        if ($this->frequency_type !== 'hours') {
            return false;
        }

        $currentHours = $this->equipment?->current_hours;

        if ($currentHours === null) {
            return false;
        }

        $baseline = $this->hours_at_last_maintenance ?? 0;

        return ($currentHours - $baseline) >= $this->frequency_value;
    }

    /**
     * How many hours overdue this schedule is, or null if it's not an
     * hour-based schedule, isn't overdue, or the equipment has no hours
     * reading yet.
     */
    public function hoursOverdueBy(): ?int
    {
        if ($this->frequency_type !== 'hours') {
            return null;
        }

        $currentHours = $this->equipment?->current_hours;

        if ($currentHours === null) {
            return null;
        }

        $baseline = $this->hours_at_last_maintenance ?? 0;
        $overBy = ($currentHours - $baseline) - $this->frequency_value;

        return $overBy > 0 ? (int) $overBy : null;
    }

    public function isDueByCalendar(): bool
    {
        return $this->frequency_type !== 'hours'
            && $this->next_due_date !== null
            && $this->next_due_date->lte(now());
    }

    /**
     * Is this schedule due right now, whether by calendar date or by
     * equipment operating hours?
     */
    public function isDue(): bool
    {
        return $this->status === 'active' && ($this->isDueByCalendar() || $this->isDueByHours());
    }

    /**
     * Mark this schedule as completed and advance it to its next cycle.
     *
     * @param \Carbon\Carbon|null $completedAt When the work was actually
     *        performed (defaults to now).
     * @param int|null $currentHours For hour-based schedules, the equipment
     *        hour reading at the time of completion — this becomes the new
     *        baseline the next interval is measured from. Falls back to
     *        the equipment's current_hours if not given.
     */
    public function markCompleted(?\Carbon\Carbon $completedAt = null, ?int $currentHours = null)
    {
        $completedAt = $completedAt ?? now();

        // Set in memory first so calculateNextDueDate() (called below via
        // the $updates array) uses this completion date, not whatever the
        // previous cycle's last_completed_date happened to be.
        $this->last_completed_date = $completedAt;

        $updates = [
            'last_completed_date' => $completedAt,
        ];

        if ($this->frequency_type === 'hours') {
            $updates['hours_at_last_maintenance'] = $currentHours ?? $this->equipment?->current_hours ?? $this->hours_at_last_maintenance;
        } else {
            $updates['next_due_date'] = $this->calculateNextDueDate();
        }

        $this->update($updates);

        // Update equipment status if it was under maintenance
        if ($this->equipment && $this->equipment->status === 'under_maintenance') {
            // Check if there are any other active maintenance activities
            $hasActiveWorkOrders = $this->equipment->workOrders()
                ->whereIn('status', ['pending', 'approved', 'in_progress'])
                ->exists();

            // If no active work orders, set equipment back to active
            if (!$hasActiveWorkOrders) {
                $this->equipment->update(['status' => 'active']);
            }
        }

        // Send notification to assigned user about completion
        if ($this->assignedUser) {
            $this->assignedUser->notify(new TaskAssignedNotification($this, 'maintenance_schedule'));
        }
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Scope to get schedules with related data for listings
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function withRelatedData($query)
    {
        return $query->with([
            'equipment:id,name,serial_number,status',
            'assignedUser:id,name',
            'checklist:id,name',
            'team:id,name',
        ]);
    }

    /**
     * Scope to get schedules with work order count
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function withWorkOrderCount($query)
    {
        return $query->withCount([
            'workOrders',
            'workOrders as completed_work_orders_count' => function ($query) {
                $query->where('status', 'completed');
            }
        ]);
    }

    /**
     * Scope for upcoming maintenance (next 30 days)
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function upcoming($query, $days = 30)
    {
        return $query->where('status', 'active')
            ->whereBetween('next_due_date', [now(), now()->addDays($days)])
            ->orderBy('next_due_date');
    }
    protected function casts(): array
    {
        return [
            'next_due_date' => 'date',
            'last_completed_date' => 'date',
            'estimated_duration' => 'integer',
            'hours_at_last_maintenance' => 'integer',
        ];
    }
}