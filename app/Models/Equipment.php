<?php

namespace App\Models;

use App\Services\MaintenanceSchedulingService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'name',
    'description',
    'serial_number',
    'model',
    'manufacturer',
    'category',
    'location',
    'purchase_date',
    'warranty_expiry',
    'status',
    'criticality',
    'notes',
    'current_hours',
    'current_hours_recorded_at',
    'company_id',
    'team_id',
    'line_leader_id',
    'sensor_enabled',
    'sensor_type',
    'sensor_id',
    'sensor_config',
    'last_sensor_reading_at',
])]
class Equipment extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Feeds the "Change Log" report — logs only the fields a technician
     * would meaningfully edit, and only when they actually changed, so the
     * log doesn't fill up with noise from unrelated timestamp touches.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('equipment')
            ->logOnly([
                'name',
                'description',
                'serial_number',
                'model',
                'manufacturer',
                'category',
                'location',
                'status',
                'criticality',
                'notes',
                'current_hours',
                'line_leader_id',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The line leader this equipment is filed under. Optional — not all
     * equipment belongs to one. Distinct from team(), which is the tenant.
     */
    public function lineLeader(): BelongsTo
    {
        return $this->belongsTo(LineLeader::class);
    }

    /**
     * Get all sensor readings for this equipment.
     */
    public function sensorReadings(): HasMany
    {
        return $this->hasMany(IotSensorReading::class);
    }

    /**
     * Get sensor readings from the last 24 hours for this equipment.
     */
    public function recentSensorReadings(): HasMany
    {
        return $this->hasMany(IotSensorReading::class)
            ->where('reading_time', '>=', now()->subHours(24))
            ->orderBy('reading_time', 'desc');
    }

    /**
     * Get critical status sensor readings for this equipment.
     */
    public function criticalSensorReadings(): HasMany
    {
        return $this->hasMany(IotSensorReading::class)
            ->where('status', 'critical')
            ->orderBy('reading_time', 'desc');
    }

    #[Scope]
    protected function active($query)
    {
        return $query->where('status', 'active');
    }

    #[Scope]
    protected function inactive($query)
    {
        return $query->where('status', 'inactive');
    }

    #[Scope]
    protected function underMaintenance($query)
    {
        return $query->where('status', 'under_maintenance');
    }

    #[Scope]
    protected function critical($query)
    {
        return $query->where('criticality', 'critical');
    }

    #[Scope]
    protected function high($query)
    {
        return $query->where('criticality', 'high');
    }

    #[Scope]
    protected function sensorEnabled($query)
    {
        return $query->where('sensor_enabled', true);
    }

    #[Scope]
    protected function withCriticalReadings($query)
    {
        return $query->whereHas('sensorReadings', function ($q) {
            $q->where('status', 'critical')
                ->where('reading_time', '>=', now()->subHours(24));
        });
    }

    /**
     * Get the health status based on recent sensor readings
     */
    public function getHealthStatus(): string
    {
        if (! $this->sensor_enabled) {
            return 'unknown';
        }

        $criticalCount = $this->sensorReadings()
            ->where('status', 'critical')
            ->where('reading_time', '>=', now()->subHours(24))
            ->count();

        $warningCount = $this->sensorReadings()
            ->where('status', 'warning')
            ->where('reading_time', '>=', now()->subHours(24))
            ->count();

        if ($criticalCount > 0) {
            return 'critical';
        }

        if ($warningCount > 0) {
            return 'warning';
        }

        return 'healthy';
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Check if equipment has any active work orders
     */
    public function hasActiveWorkOrders(): bool
    {
        return $this->workOrders()
            ->whereIn('status', ['pending', 'approved', 'in_progress'])
            ->exists();
    }

    /**
     * Check if equipment can be set to active status
     */
    public function canBeSetToActive(): bool
    {
        return ! $this->hasActiveWorkOrders();
    }

    /**
     * Update this equipment's current operating-hour reading and
     * immediately re-check its hour-based maintenance schedules, so a
     * schedule that just became overdue gets its work order pushed right
     * away rather than waiting for the next daily check.
     *
     * @return array<int, WorkOrder> Any work orders created as a result.
     */
    public function updateCurrentHours(int $hours): array
    {
        $this->update([
            'current_hours' => $hours,
            'current_hours_recorded_at' => now(),
        ]);

        return app(MaintenanceSchedulingService::class)
            ->checkSchedulesForEquipment($this);
    }

    /**
     * Automatically update equipment status based on work orders
     */
    public function syncStatusWithWorkOrders(): void
    {
        if ($this->hasActiveWorkOrders() && $this->status !== 'under_maintenance') {
            $this->update(['status' => 'under_maintenance']);
        } elseif (! $this->hasActiveWorkOrders() && $this->status === 'under_maintenance') {
            $this->update(['status' => 'active']);
        }
    }

    /**
     * Scope to get equipment with work order counts
     */
    #[Scope]
    protected function withWorkOrderCounts($query)
    {
        return $query->withCount([
            'workOrders',
            'workOrders as pending_work_orders_count' => function ($query) {
                $query->where('status', 'pending');
            },
            'workOrders as active_work_orders_count' => function ($query) {
                $query->whereIn('status', ['approved', 'in_progress']);
            },
        ]);
    }

    /**
     * Scope to get equipment with maintenance schedule counts
     */
    #[Scope]
    protected function withMaintenanceCounts($query)
    {
        return $query->withCount([
            'maintenanceSchedules',
            'maintenanceSchedules as overdue_schedules_count' => function ($query) {
                $query->where('next_due_date', '<', now())
                    ->where('status', 'active');
            },
            'maintenanceSchedules as due_soon_schedules_count' => function ($query) {
                $query->whereBetween('next_due_date', [now(), now()->addDays(7)])
                    ->where('status', 'active');
            },
        ]);
    }

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'warranty_expiry' => 'date',
            'sensor_enabled' => 'boolean',
            'sensor_config' => 'array',
            'last_sensor_reading_at' => 'datetime',
            'current_hours' => 'integer',
            'current_hours_recorded_at' => 'datetime',
        ];
    }
}
