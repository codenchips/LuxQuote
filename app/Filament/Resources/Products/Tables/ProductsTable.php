<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('site')
                    ->label('Site')
                    ->badge()
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
                TextColumn::make('type_name')
                    ->label('Type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
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
                SelectFilter::make('site')
                    ->label('Site')
                    ->options(fn (): array => Product::query()
                        ->distinct()
                        ->orderBy('site')
                        ->pluck('site', 'site')
                        ->filter()
                        ->toArray()
                    ),
                SelectFilter::make('type_name')
                    ->label('Type')
                    ->options(fn (): array => Product::query()
                        ->distinct()
                        ->orderBy('type_name')
                        ->pluck('type_name', 'type_name')
                        ->filter()
                        ->toArray()
                    ),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->paginated([25, 50, 100]);
    }
}
