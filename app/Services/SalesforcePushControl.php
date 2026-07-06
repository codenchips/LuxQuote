<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Schema;

class SalesforcePushControl
{
    private const SettingKey = 'salesforce_push_disabled';

    public function disabled(): bool
    {
        if (! Schema::hasTable('app_settings')) {
            return false;
        }

        $setting = AppSetting::query()
            ->where('key', self::SettingKey)
            ->first();

        $value = $setting?->value;

        if (is_array($value)) {
            return (bool) ($value['disabled'] ?? false);
        }

        return (bool) $value;
    }

    public function enabled(): bool
    {
        return ! $this->disabled();
    }

    public function setDisabled(bool $disabled): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => self::SettingKey],
            ['value' => ['disabled' => $disabled]],
        );
    }
}
