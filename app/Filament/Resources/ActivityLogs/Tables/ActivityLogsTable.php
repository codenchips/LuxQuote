<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Models\ActivityLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Who')
                    ->placeholder(fn (ActivityLog $record): string => $record->user_email_snapshot)
                    ->searchable(),

                TextColumn::make('project_name_snapshot')
                    ->label('Project')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('action_performed')
                    ->label('Action Performed')
                    ->html()
                    ->getStateUsing(function (ActivityLog $record): string {
                        $payload = $record->payload ?? [];

                        return match ($record->action_type) {
                            'project.created' => 'Created the project structure',

                            'project.updated' => (function () use ($payload): string {
                                if (empty($payload)) {
                                    return 'Updated project details';
                                }
                                $fieldNames = [
                                    'visibility' => 'Privacy Status',
                                    'reference_number' => 'Quote Reference',
                                    'customer_name' => 'Customer Name',
                                    'cover_percentage' => 'Cover Percentage',
                                    'branch_name' => 'Branch Name',
                                ];
                                $parts = [];
                                foreach ($payload as $key => $change) {
                                    $label = $fieldNames[$key] ?? (string) str($key)->headline();
                                    $old = e((string) ($change['old'] ?? 'empty'));
                                    $new = e((string) ($change['new'] ?? 'empty'));
                                    $parts[] = "Changed <strong>{$label}</strong> from <strong>{$old}</strong> to <strong>{$new}</strong>";
                                }

                                return 'Updated project details: '.implode('; ', $parts);
                            })(),

                            'project.deleted' => 'Permanently <strong>deleted</strong> the project',

                            'revision.created' => 'Created a new snapshot: <strong>Revision #'.e((string) ($payload['revision_number'] ?? '?')).'</strong>',

                            'product.added' => (function () use ($payload): string {
                                $qty = e((string) ($payload['qty'] ?? '1'));
                                $description = e((string) ($payload['description'] ?? ''));
                                $code = e((string) ($payload['code'] ?? ''));
                                $ref = isset($payload['ref']) ? ' | Ref: '.e((string) $payload['ref']) : '';
                                $price = isset($payload['unit_price']) ? ' | £'.e(number_format((float) $payload['unit_price'], 2)) : '';
                                $notes = isset($payload['notes']) && $payload['notes'] !== '' ? ' | '.e((string) $payload['notes']) : '';

                                $detail = trim("{$code}{$ref}{$price}{$notes}", ' |');
                                $label = $description !== '' ? "<strong>{$qty}x {$description}</strong>" : "<strong>{$qty}x</strong>";

                                return 'Added '.$label.($detail !== '' ? " ({$detail})" : '').' to the schedule';
                            })(),

                            'line.updated' => (function () use ($payload): string {
                                $code = e((string) ($payload['code'] ?? '?'));
                                $changes = $payload['changes'] ?? [];
                                if (empty($changes)) {
                                    return "Updated line <strong>{$code}</strong>";
                                }
                                $fieldNames = [
                                    'code' => 'SKU',
                                    'ref' => 'Reference',
                                    'description' => 'Description',
                                    'qty' => 'Quantity',
                                    'unit_price' => 'Unit Price',
                                    'notes' => 'Notes',
                                    'type' => 'Line Type',
                                    'status' => 'Status',
                                ];
                                $parts = [];
                                foreach ($changes as $field => $change) {
                                    $label = $fieldNames[$field] ?? (string) str($field)->headline();
                                    $old = e((string) ($change['old'] ?? 'empty'));
                                    $new = e((string) ($change['new'] ?? 'empty'));
                                    $parts[] = "Changed <strong>{$label}</strong> from <strong>{$old}</strong> to <strong>{$new}</strong>";
                                }

                                return "Updated line <strong>{$code}</strong>: ".implode('; ', $parts);
                            })(),

                            // Legacy action type — kept for backward compatibility with existing records
                            'line.qty_updated' => (function () use ($payload): string {
                                $code = e((string) ($payload['code'] ?? '?'));
                                $parts = [];
                                if (isset($payload['qty'])) {
                                    $old = e((string) $payload['qty']['old']);
                                    $new = e((string) $payload['qty']['new']);
                                    $parts[] = "Changed quantity for <strong>{$code}</strong> from {$old} to <strong>{$new}</strong>";
                                }
                                if (isset($payload['unit_price'])) {
                                    $old = e((string) $payload['unit_price']['old']);
                                    $new = e((string) $payload['unit_price']['new']);
                                    $parts[] = "Changed price for <strong>{$code}</strong> from {$old} to <strong>{$new}</strong>";
                                }

                                return implode('; ', $parts) ?: 'Updated line';
                            })(),

                            default => (string) str($record->action_type)->replace('.', ' ')->title(),
                        };
                    }),

                TextColumn::make('created_at')
                    ->label('Date & Time')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('action_type')
                    ->label('Action')
                    ->options([
                        'project.created' => 'Project Created',
                        'project.updated' => 'Project Updated',
                        'project.deleted' => 'Project Deleted',
                        'revision.created' => 'Revision Created',
                        'product.added' => 'Product Added',
                        'line.updated' => 'Line Updated',
                        'line.qty_updated' => 'Quantity / Price Updated (legacy)',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([15, 25, 50])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
