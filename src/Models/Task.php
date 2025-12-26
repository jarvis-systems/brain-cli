<?php

declare(strict_types=1);

namespace BrainCLI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 *
 * create table main.tasks
 * (
 * id           INTEGER
 * primary key autoincrement,
 * parent_id    INTEGER,
 * status       TEXT                  not null,
 * title        TEXT                  not null,
 * content      TEXT                  not null,
 * comment      TEXT,
 * content_hash TEXT                  not null
 * unique,
 * created_at   TEXT                  not null,
 * start_at     TEXT,
 * finish_at    TEXT,
 * priority     TEXT default 'medium' not null,
 * tags         TEXT,
 * estimate     REAL,
 * "order"      INTEGER,
 * time_spent   REAL default 0.0
 * );
 *
 * create index main.idx_task_created
 * on main.tasks (created_at);
 *
 * create index main.idx_task_hash
 * on main.tasks (content_hash);
 *
 * create index main.idx_task_parent
 * on main.tasks (parent_id);
 *
 * create index main.idx_task_parent_order
 * on main.tasks (parent_id, "order");
 *
 * create index main.idx_task_priority
 * on main.tasks (status, priority, created_at);
 *
 * create index main.idx_task_status
 * on main.tasks (status);
 *
 * create index main.idx_task_tags
 * on main.tasks (tags);
 */
class Task extends Model
{
    protected $connection = 'tasks';

    protected $table = 'tasks';

    protected $fillable = [
        'parent_id',
        'status',
        'title',
        'content',
        'comment',
        'content_hash',
        'created_at',
        'start_at',
        'finish_at',
        'priority',
        'tags',
        'estimate',
        'order',
        'time_spent',
    ];

    protected function casts(): array
    {
        return [
            'parent_id' => 'int',
            'content_hash' => 'string',
            'created_at' => 'datetime',
            'start_at' => 'datetime',
            'finish_at' => 'datetime',
            'tags' => 'array',
            'estimate' => 'float',
            'order' => 'int',
            'time_spent' => 'float',
        ];
    }

    /**
     * Get the parent task.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    /**
     * Get the child tasks.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }
}

