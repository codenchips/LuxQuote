<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Support\BadgeStyle;
use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('site')
                    ->label('Site')
                    ->badge()
                    ->color(fn (?string $state): string|array => BadgeStyle::filamentColor($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_name')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('price')
                    ->label('Price')
                    ->money('GBP')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('type_name')
                    ->label('Type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(80)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('site_type')
                    ->form([
                        Select::make('site')
                            ->label('Site')
                            ->placeholder('All sites')
                            ->options(fn (): array => Product::query()
                                ->whereNotNull('site')
                                ->distinct()
                                ->orderBy('site')
                                ->pluck('site', 'site')
                                ->toArray()
                            )
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('type_name', null)),

                        Select::make('type_name')
                            ->label('Type')
                            ->placeholder('All types')
                            ->options(fn (Get $get): array => Product::query()
                                ->whereNotNull('type_name')
                                ->when($get('site'), fn (Builder $q, string $site) => $q->where('site', $site))
                                ->distinct()
                                ->orderBy('type_name')
                                ->pluck('type_name', 'type_name')
                                ->toArray()
                            ),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['site'] ?? null, fn (Builder $q, string $v) => $q->where('site', $v))
                        ->when($data['type_name'] ?? null, fn (Builder $q, string $v) => $q->where('type_name', $v))
                    )
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['site'] ?? null) {
                            $indicators[] = 'Site: '.$data['site'];
                        }

                        if ($data['type_name'] ?? null) {
                            $indicators[] = 'Type: '.$data['type_name'];
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->paginated([25, 50, 100]);
    }
}
