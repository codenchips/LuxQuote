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

                TextColumn::make('project.reference_number')
                    ->label('Reference')
                    ->placeholder('No project')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('revision_number')
                    ->label('Rev')
                    ->formatStateUsing(fn (?int $state): ?string => $state ? 'R'.$state : null)
                    ->placeholder('—')
                    ->sortable()
                    ->width('4rem'),

                TextColumn::make('action_performed')
                    ->label('Action Performed')
                    ->html()
                    ->wrap()
                    ->width('136ch')
                    ->extraCellAttributes([
                        'class' => 'max-w-[136ch] whitespace-normal break-words',
                    ])
                    ->getStateUsing(function (ActivityLog $record): string {
                        $payload = $record->payload ?? [];

                        return match ($record->action_type) {
                            'area.created' => 'Created area <strong>'.e((string) ($payload['area'] ?? '?')).'</strong>',

                            'area.deleted' => (function () use ($payload): string {
                                $name = e((string) ($payload['area'] ?? '?'));
                                $lines = $payload['lines'] ?? [];
                                if (empty($lines)) {
                                    return "Deleted area <strong>{$name}</strong> (no items)";
                                }
                                $count = count($lines);
                                $items = implode(', ', array_map(function (array $line): string {
                                    $code = e((string) ($line['code'] ?? ''));
                                    $desc = e((string) ($line['description'] ?? ''));

                                    return $code !== '' && $desc !== '' ? "{$code} ({$desc})" : ($code ?: $desc ?: '—');
                                }, $lines));

                                return "Deleted area <strong>{$name}</strong> — {$count} item".($count !== 1 ? 's' : '')." removed: {$items}";
                            })(),

                            'project.created' => 'Created the project structure',

                            'project.details_saved' => (function () use ($payload): string {
                                $url = $payload['salesforce_pdf_url'] ?? null;
                                $filename = e((string) ($payload['salesforce_pdf_filename'] ?? 'schedule PDF'));

                                if (blank($url)) {
                                    return 'Saved project details';
                                }

                                $href = e((string) $url);

                                return "Saved project details and uploaded <strong>{$filename}</strong> to Salesforce: <a href=\"{$href}\" target=\"_blank\" rel=\"noopener noreferrer\" class=\"text-primary-600 underline dark:text-primary-400\">View file</a>";
                            })(),

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

                            'schedule_pdf.generated' => 'Generated schedule PDF <strong>'.e((string) ($payload['filename'] ?? '')).'</strong>',

                            'quote_pdf.generated' => 'Generated quote PDF <strong>'.e((string) ($payload['filename'] ?? '')).'</strong>',

                            'salesforce_pdf.uploaded' => 'Uploaded '.e((string) ($payload['document_label'] ?? 'PDF')).' to Salesforce <strong>'.e((string) ($payload['filename'] ?? '')).'</strong>',

                            'user.login' => 'Logged in',

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
                    ->sortable()
                    ->width('11rem')
                    ->extraCellAttributes([
                        'class' => 'whitespace-nowrap',
                    ]),
            ])
            ->filters([
                SelectFilter::make('action_type')
                    ->label('Action')
                    ->options([
                        'area.created' => 'Area Created',
                        'area.deleted' => 'Area Deleted',
                        'project.created' => 'Project Created',
                        'project.details_saved' => 'Project Details Saved',
                        'project.updated' => 'Project Updated',
                        'project.deleted' => 'Project Deleted',
                        'revision.created' => 'Revision Created',
                        'schedule_pdf.generated' => 'Schedule PDF Generated',
                        'quote_pdf.generated' => 'Quote PDF Generated',
                        'salesforce_pdf.uploaded' => 'Salesforce PDF Uploaded',
                        'user.login' => 'User Login',
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
