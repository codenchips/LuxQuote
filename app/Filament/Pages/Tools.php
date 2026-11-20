<?php

namespace App\Filament\Pages;

use App\Services\SalesforcePushControl;
use App\Services\ScheduledMaintenance;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

class Tools extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.tools';

    public bool $salesforcePushDisabled = false;

    public string $maintenanceStart = '';

    public string $maintenanceEnd = '';

    public bool $maintenanceScheduled = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->salesforcePushDisabled = app(SalesforcePushControl::class)->disabled();
        $settings = app(ScheduledMaintenance::class)->settings();
        $this->maintenanceScheduled = (bool) ($settings['enabled'] ?? false);
        $this->maintenanceStart = isset($settings['start']) ? Carbon::parse($settings['start'])->timezone(config('app.timezone'))->format('Y-m-d\TH:i') : '';
        $this->maintenanceEnd = isset($settings['end']) ? Carbon::parse($settings['end'])->timezone(config('app.timezone'))->format('Y-m-d\TH:i') : '';
    }

    public function updatedMaintenanceStart(): void
    {
        try {
            $this->maintenanceEnd = Carbon::parse($this->maintenanceStart, config('app.timezone'))->addMinutes(15)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            $this->maintenanceEnd = '';
        }
    }

    public function scheduleMaintenance(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate([
            'maintenanceStart' => ['required', 'date', 'after:now'],
            'maintenanceEnd' => ['required', 'date', 'after:maintenanceStart'],
        ]);
        $maintenance = app(ScheduledMaintenance::class);
        abort_if($maintenance->active(), 409);
        $maintenance->schedule(Carbon::parse($this->maintenanceStart, config('app.timezone')), Carbon::parse($this->maintenanceEnd, config('app.timezone')));
        $this->maintenanceScheduled = true;
        Notification::make()->title('Maintenance scheduled')->success()->send();
    }

    public function cancelMaintenance(): void
    {
        abort_unless(static::canAccess(), 403);
        $maintenance = app(ScheduledMaintenance::class);
        abort_if($maintenance->active(), 409);
        $maintenance->clear();
        $this->maintenanceScheduled = false;
        Notification::make()->title('Maintenance cancelled')->success()->send();
    }

    public function toggleSalesforcePushDisabled(): void
    {
        abort_unless(static::canAccess(), 403);

        $pushControl = app(SalesforcePushControl::class);
        $pushControl->setDisabled(! $pushControl->disabled());
        $this->salesforcePushDisabled = $pushControl->disabled();
    }
}
