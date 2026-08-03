<?php

namespace App\Filament\Resources\SpecialOrderCodes\Schemas;

use App\Models\Product;
use App\Models\SpecialOrderCode;
use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class SpecialOrderCodeForm
{
    private const MaxSpecialPrice = 9999999999.99;

    private const MaxUnsignedInteger = 4294967295;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Special Order Code')
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->rule(function (?Model $record): Closure {
                                return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                    if (self::specialCodeExists((string) $value, $record)) {
                                        $fail('This code already exists as a special order code.');
                                    }

                                    if (self::productCodeExists((string) $value)) {
                                        $fail('This code already exists in the product catalogue.');
                                    }
                                };
                            }),
                        TextInput::make('description')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Grid::make(3)
                            ->schema([
                                TextInput::make('price')
                                    ->label('Price')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(self::MaxSpecialPrice)
                                    ->default('0.00')
                                    ->placeholder('0.00')
                                    ->prefix('£')
                                    ->dehydrateStateUsing(fn (mixed $state): string => number_format((float) ($state ?? 0), 2, '.', ''))
                                    ->formatStateUsing(fn (mixed $state): string => number_format((float) ($state ?? 0), 2, '.', '')),
                                TextInput::make('qty')
                                    ->label('Qty')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(self::MaxUnsignedInteger)
                                    ->default(0)
                                    ->placeholder('0'),
                            ])
                            ->columnSpanFull(),
                        Grid::make(3)
                            ->schema([
                                Toggle::make('requires_approval')
                                    ->label('Requires Approval')
                                    ->default(true),
                                Toggle::make('show_on_schedules')
                                    ->label('Show on Schedules')
                                    ->default(true),
                                Toggle::make('show_on_quotes')
                                    ->label('Show on Quotes')
                                    ->default(true),
                            ])
                            ->columnSpanFull(),
                        Grid::make(2)
                            ->schema([
                                Placeholder::make('created_at_display')
                                    ->label('Created')
                                    ->content(fn (?Model $record): string => $record?->created_at?->format('M d Y H:i') ?? '-'),
                                Placeholder::make('updated_at_display')
                                    ->label('Modified')
                                    ->content(fn (?Model $record): string => $record?->updated_at?->format('M d Y H:i') ?? '-'),
                            ])
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    private static function specialCodeExists(string $code, ?Model $record): bool
    {
        $normalisedCode = SpecialOrderCode::normaliseCode($code);

        if ($normalisedCode === '') {
            return false;
        }

        return SpecialOrderCode::query()
            ->where('normalised_code', $normalisedCode)
            ->when(
                $record instanceof SpecialOrderCode,
                fn ($query): mixed => $query->whereKeyNot($record->getKey()),
            )
            ->exists();
    }

    private static function productCodeExists(string $code): bool
    {
        $normalisedCode = SpecialOrderCode::normaliseCode($code);

        if ($normalisedCode === '') {
            return false;
        }

        return Product::query()
            ->whereRaw("REPLACE(UPPER(TRIM(sku)), ' ', '') = ?", [$normalisedCode])
            ->exists();
    }
}
