<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ScheduledMaintenance
{
    private const Key = 'scheduled_maintenance';

    /** @return array<string, mixed> */
    public function settings(): array
    {
        if (! Schema::hasTable('app_settings')) {
            return [];
        }

        $settings = AppSetting::query()->where('key', self::Key)->value('value') ?? [];

        if (($settings['enabled'] ?? false) && isset($settings['id'], $settings['start'], $settings['end']) && now()->greaterThanOrEqualTo(Carbon::parse($settings['end']))) {
            $expiredId = $settings['id'];
            $settings['last_id'] = $expiredId;
            $settings['enabled'] = false;

            $updated = AppSetting::query()->where('key', self::Key)
                ->where('value->id', $expiredId)
                ->where('value->enabled', true)
                ->update(['value' => json_encode($settings, JSON_THROW_ON_ERROR)]);

            if (! $updated) {
                return $this->settings();
            }
        }

        return $settings;
    }

    public function schedule(Carbon $start, Carbon $end): void
    {
        $settings = $this->settings();
        $settings['enabled'] = true;
        $settings['start'] = $start->utc()->toIso8601String();
        $settings['end'] = $end->utc()->toIso8601String();
        $settings['id'] = (string) Str::uuid();
        $this->save($settings);
    }

    public function active(?array $settings = null): bool
    {
        $settings ??= $this->settings();

        return ($settings['enabled'] ?? false) && isset($settings['start']) && now()->greaterThanOrEqualTo(Carbon::parse($settings['start']));
    }

    public function cutoff(?array $settings = null): ?string
    {
        $settings ??= $this->settings();

        return $this->active($settings) ? ($settings['id'] ?? null) : ($settings['last_id'] ?? null);
    }

    public function startDeployment(): void
    {
        $settings = $this->settings();
        if (($settings['enabled'] ?? false) && ! $this->active($settings)) {
            $settings['start'] = now()->utc()->toIso8601String();
            $this->save($settings);
        }
    }

    public function clear(): void
    {
        $settings = $this->settings();
        if (! ($settings['enabled'] ?? false)) {
            return;
        }
        if ($this->active($settings)) {
            $settings['last_id'] = $settings['id'];
        }
        $settings['enabled'] = false;
        $this->save($settings);
    }

    public function invalidateExistingSessions(): void
    {
        $settings = $this->settings();
        $settings['enabled'] = false;
        $settings['last_id'] = (string) Str::uuid();
        $this->save($settings);
    }

    /** @param array<string, mixed> $settings */
    private function save(array $settings): void
    {
        AppSetting::query()->updateOrCreate(['key' => self::Key], ['value' => $settings]);
    }
}
