<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ProjectVisibility;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'user_id',
    'name',
    'reference_number',
    'customer_name',
    'contractor',
    'site_location',
    'owner_email',
    'created_by_email',
    'department',
    'date',
    'revision',
    'active_revision_id',
    'visibility',
    'status',
    'branch_name',
    'cover_percentage',
    'quote_notes',
    'internal_notes',
    'general_notes',
    'last_edited_at',
    'last_edited_by',
    'salesforce_project',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_edited_at' => 'datetime',
            'visibility' => ProjectVisibility::class,
            'status' => ProjectStatus::class,
            'date' => 'date',
            'cover_percentage' => 'decimal:2',
            'salesforce_project' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ProjectRevision::class)->orderBy('revision_number');
    }

    public function activeRevision(): BelongsTo
    {
        return $this->belongsTo(ProjectRevision::class, 'active_revision_id');
    }

    /** @deprecated Use areas through a specific revision instead */
    public function areas(): HasMany
    {
        return $this->hasMany(ProjectArea::class)->orderBy('sort_order');
    }

    /**
     * Users currently viewing this project (presence within the last 90 seconds),
     * excluding the authenticated user.
     *
     * @return HasManyThrough<User, ProjectPresence>
     */
    public function activeViewers(): HasManyThrough
    {
        return $this->hasManyThrough(
            User::class,
            ProjectPresence::class,
            'project_id',
            'id',
            'id',
            'user_id',
        )->where('project_presences.last_seen_at', '>=', now()->subSeconds(90))
            ->where('project_presences.user_id', '!=', auth()->id() ?? 0);
    }

    protected static function booted(): void
    {
        static::created(function (Project $project): void {
            $revision = ProjectRevision::create([
                'project_id' => $project->id,
                'revision_number' => 1,
                'created_by' => $project->user_id,
            ]);

            ProjectArea::create([
                'project_id' => $project->id,
                'project_revision_id' => $revision->id,
                'name' => 'Ground Floor',
                'sort_order' => 0,
            ]);

            $project->updateQuietly(['active_revision_id' => $revision->id]);
        });
    }
}
