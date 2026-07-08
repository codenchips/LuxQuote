<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\AppSetting;
use App\Services\ProductImportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use RuntimeException;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('lastProductDataPull')
                ->label(fn (): string => 'Last Product Data Pull: '.$this->lastProductDataPullLabel())
                ->color('gray')
                ->disabled()
                ->extraAttributes([
                    'class' => 'pointer-events-none opacity-100',
                ]),

            Action::make('fetchProducts')
                ->label('Fetch Products')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->can('products.import') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Fetch Products from Server')
                ->modalDescription('This will replace all existing product data with the latest from the external server. Continue?')
                ->modalSubmitActionLabel('Fetch')
                ->action(function () {
                    try {
                        $count = app(ProductImportService::class)->import();

                        Notification::make()
                            ->title(number_format($count).' products imported successfully.')
                            ->success()
                            ->send();
                    } catch (RuntimeException $e) {
                        Notification::make()
                            ->title('Import failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    private function lastProductDataPullLabel(): string
    {
        $setting = AppSetting::query()
            ->where('key', ProductImportService::LastPulledAtSettingKey)
            ->first();

        $pulledAt = is_array($setting?->value)
            ? ($setting->value['pulled_at'] ?? null)
            : null;

        if (blank($pulledAt)) {
            return 'Never';
        }

        return Carbon::parse($pulledAt)
            ->timezone(config('app.timezone'))
            ->format('M d Y H:i');
    }
}
