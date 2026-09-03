<?php

namespace App\Models;

use App\Enums\ProjectVisibility;
use Database\Factories\DocumentPackTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['user_id', 'name', 'visibility', 'team_id', 'created_by', 'updated_by'])]
class DocumentPackTemplate extends Model
{
    /** @use HasFactory<DocumentPackTemplateFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (DocumentPackTemplate $template): void {
            $template->items()->get()->each(function (DocumentPackTemplateItem $item): void {
                if ($item->hasManagedFile()) {
                    Storage::disk($item->file_disk ?? 'local')->delete($item->file_path);
                }
            });
        });
    }

    protected function casts(): array
    {
        return [
            'visibility' => ProjectVisibility::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentPackTemplateItem::class)->orderBy('sort_order');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdministrator()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('visibility', ProjectVisibility::Open)
                ->orWhere('user_id', $user->id)
                ->orWhere(function (Builder $query) use ($user): void {
                    $query->where('visibility', ProjectVisibility::Team)
                        ->whereHas('team.users', fn (Builder $query): Builder => $query->whereKey($user->id));
                });
        });
    }

    public function isVisibleTo(User $user): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        if ($this->visibility === ProjectVisibility::Open || $this->user_id === $user->id) {
            return true;
        }

        if ($this->visibility !== ProjectVisibility::Team || $this->team_id === null) {
            return false;
        }

        return $user->teams()->whereKey($this->team_id)->exists();
    }
}
