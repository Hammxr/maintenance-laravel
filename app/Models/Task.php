<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'name',
    'description',
    'equipment_id',
    'due_date',
    'status',
    'completed_at',
    'priority',
    'contact_id',
    'company_id',
    'opportunity_id',
    'assigned_to',
    'team_id',
])]
class Task extends Model
{
    use HasFactory;

    #[\Override]
    protected $primaryKey = 'task_id';

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'contact_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function opportunity()
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id', 'opportunity_id');
    }

    public function equipment()
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Auto-stamp completed_at the moment a task's status is set to
     * 'completed' — the "unplanned maintenance" report is keyed off this
     * timestamp (same as work_orders.completed_at), so it has to be set
     * automatically rather than relying on someone remembering to fill in a
     * date field by hand. Clearing the status back off 'completed' clears
     * the timestamp too, so re-opening a task doesn't leave a stale
     * completion date behind.
     */
    protected static function booted(): void
    {
        static::saving(function (Task $task) {
            if ($task->isDirty('status')) {
                if ($task->status === 'completed' && !$task->completed_at) {
                    $task->completed_at = now();
                } elseif ($task->status !== 'completed') {
                    $task->completed_at = null;
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }
}
