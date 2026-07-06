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
                TextColumn::make('description')
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
                TextColumn::make('v_description')
                    ->label('Description')
                    ->limit(80)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('luminaire_wattage_w')
                    ->label('Wattage')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('lumens_lm')
                    ->label('Lumens')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('efficacy_llm_w')
                    ->label('Efficacy (llm/W)')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cct_k')
                    ->label('CCT (K)')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('colour_temp')
                    ->label('Colour Temp')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cri')
                    ->label('CRI')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ip_rating')
                    ->label('IP Rating')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ik_rating')
                    ->label('IK Rating')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('electrical_class')
                    ->label('Electrical Class')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('beam_angle_fwhm')
                    ->label('Beam Angle')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('length_mm')
                    ->label('Length (mm)')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('width_mm')
                    ->label('Width (mm)')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('depth_mm')
                    ->label('Depth (mm)')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('diameter_mm')
                    ->label('Diameter (mm)')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cut_out_mm')
                    ->label('Cut-out (mm)')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('weight_kg')
                    ->label('Weight (kg)')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('emergency_lumen_output')
                    ->label('Emergency Lumens')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('power')
                    ->label('Power')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('em_power')
                    ->label('EM Power')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('dali')
                    ->label('DALI')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vision_type')
                    ->label('Vision Type')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('emergency_type')
                    ->label('Emergency Type')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rl_ral')
                    ->label('RL/RAL')
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
