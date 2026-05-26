<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Services\ProductImportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetchProducts')
                ->label('Fetch Products')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->can('import-products') ?? false)
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
}
