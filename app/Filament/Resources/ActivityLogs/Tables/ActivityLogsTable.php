<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Models\ActivityLog;
use App\Models\ProjectRevision;
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
                    ->searchable()
                    ->width('7rem')
                    ->extraCellAttributes([
                        'class' => 'w-28 max-w-28 overflow-hidden whitespace-nowrap text-ellipsis',
                    ]),

                TextColumn::make('project.reference_number')
                    ->label('Reference')
                    ->getStateUsing(fn (ActivityLog $record): string => self::referenceLabel($record))
                    ->searchable()
                    ->sortable()
                    ->width('10rem')
                    ->extraCellAttributes([
                        'class' => 'w-40 max-w-40 overflow-hidden whitespace-nowrap text-ellipsis',
                    ]),

                TextColumn::make('revision_number')
                    ->label('Rev')
                    ->formatStateUsing(fn (?int $state): ?string => $state !== null ? ProjectRevision::labelForNumber($state) : null)
                    ->placeholder('—')
                    ->sortable()
                    ->width('4.5rem')
                    ->extraCellAttributes([
                        'class' => 'w-[4.5rem] max-w-[4.5rem] whitespace-nowrap',
                    ]),

                TextColumn::make('action_performed')
                    ->label('Action Performed')
                    ->html()
                    ->width('100%')
                    ->extraCellAttributes([
                        'class' => 'w-full min-w-[36rem] overflow-hidden whitespace-nowrap text-ellipsis',
                    ])
                    ->getStateUsing(function (ActivityLog $record): string {
                        $payload = $record->payload ?? [];

                        $html = match ($record->action_type) {
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
                                    $old = e(self::formatChangedValue($change['old'] ?? null));
                                    $new = e(self::formatChangedValue($change['new'] ?? null));
                                    $parts[] = "Changed <strong>{$label}</strong> from <strong>{$old}</strong> to <strong>{$new}</strong>";
                                }

                                return 'Updated project details: '.implode('; ', $parts);
                            })(),

                            'project.deleted' => 'Permanently <strong>deleted</strong> the project',

                            'revision.created' => 'Created a new snapshot: <strong>Revision #'.e((string) ($payload['revision_number'] ?? '?')).'</strong>',

                            'revision.approved' => 'Approved and locked <strong>'.e((string) ($payload['revision_label'] ?? 'revision')).'</strong>',
                            'revision.unapproved' => 'Unapproved and unlocked <strong>'.e((string) ($payload['revision_label'] ?? 'revision')).'</strong>',

                            'quote_approval.requested' => 'Requested quote approval for <strong>'.e((string) ($payload['revision_label'] ?? 'revision')).'</strong>',

                            'validation.issue_approved' => (function () use ($payload): string {
                                $lines = $payload['lines'] ?? [];
                                $items = implode(', ', array_map(function (array $line): string {
                                    $code = e((string) ($line['code'] ?? ''));
                                    $desc = e((string) ($line['description'] ?? ''));

                                    return $code !== '' && $desc !== '' ? "{$code} ({$desc})" : ($code ?: $desc ?: '—');
                                }, $lines));

                                return 'Approved validation warning'.($items !== '' ? " for <strong>{$items}</strong>" : '');
                            })(),

                            'validation.issue_approval_undone' => (function () use ($payload): string {
                                $lines = $payload['lines'] ?? [];
                                $items = implode(', ', array_map(function (array $line): string {
                                    $code = e((string) ($line['code'] ?? ''));
                                    $desc = e((string) ($line['description'] ?? ''));

                                    return $code !== '' && $desc !== '' ? "{$code} ({$desc})" : ($code ?: $desc ?: '—');
                                }, $lines));

                                return 'Undid validation approval'.($items !== '' ? " for <strong>{$items}</strong>" : '');
                            })(),

                            'validation.issue_matched' => (function () use ($payload): string {
                                $lines = $payload['lines'] ?? [];
                                $items = implode(', ', array_map(function (array $line): string {
                                    $code = e((string) ($line['code'] ?? ''));
                                    $desc = e((string) ($line['description'] ?? ''));

                                    return $code !== '' && $desc !== '' ? "{$code} ({$desc})" : ($code ?: $desc ?: '—');
                                }, $lines));
                                $price = isset($payload['matched_price']) ? ' to <strong>£'.e(number_format((float) $payload['matched_price'], 2)).'</strong>' : '';

                                return 'Matched and approved quote price'.$price.($items !== '' ? " for <strong>{$items}</strong>" : '');
                            })(),

                            'validation.issue_flagged' => (function () use ($payload): string {
                                $lines = $payload['lines'] ?? [];
                                $items = implode(', ', array_map(function (array $line): string {
                                    $code = e((string) ($line['code'] ?? ''));
                                    $desc = e((string) ($line['description'] ?? ''));

                                    return $code !== '' && $desc !== '' ? "{$code} ({$desc})" : ($code ?: $desc ?: '—');
                                }, $lines));

                                return 'Flagged validation issue'.($items !== '' ? " for <strong>{$items}</strong>" : '');
                            })(),

                            'schedule_pdf.generated' => 'Generated schedule PDF <strong>'.e((string) ($payload['filename'] ?? '')).'</strong>',

                            'quote_pdf.generated' => 'Generated quote PDF <strong>'.e((string) ($payload['filename'] ?? '')).'</strong>',

                            'document_pack.saved' => 'Saved document pack <strong>'.e((string) ($payload['document_pack_name'] ?? '')).'</strong>',

                            'document_pack.generated' => 'Generated document pack <strong>'.e((string) ($payload['document_pack_name'] ?? '')).'</strong> as <strong>'.e((string) ($payload['filename'] ?? '')).'</strong>',

                            'document_pack.deleted' => 'Deleted document pack <strong>'.e((string) ($payload['document_pack_name'] ?? '')).'</strong>',

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
                                    $old = e(self::formatChangedValue($change['old'] ?? null));
                                    $new = e(self::formatChangedValue($change['new'] ?? null));
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

                        return self::formatActionHtml($html, $record->action_type);
                    }),

                TextColumn::make('created_at')
                    ->label('Date & Time')
                    ->dateTime('M d Y H:i')
                    ->sortable()
                    ->width('10rem')
                    ->extraCellAttributes([
                        'class' => 'w-40 max-w-40 whitespace-nowrap',
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
                        'revision.approved' => 'Revision Approved',
                        'revision.unapproved' => 'Revision Unapproved',
                        'quote_approval.requested' => 'Quote Approval Requested',
                        'validation.issue_approved' => 'Validation Issue Approved',
                        'validation.issue_approval_undone' => 'Validation Issue Approval Undone',
                        'validation.issue_matched' => 'Validation Issue Matched',
                        'validation.issue_flagged' => 'Validation Issue Flagged',
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

    private static function formatActionHtml(string $html, string $actionType): string
    {
        $html = str_replace(
            ['<strong>', '</strong>'],
            ['<span class="font-semibold text-sky-600 dark:text-sky-300">', '</span>'],
            $html,
        );
        $html = self::styleActionLead($html, self::actionToneClass($actionType));
        $html = self::styleConnectorPhrases($html);

        return '<span class="block overflow-hidden text-ellipsis whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">'.$html.'</span>';
    }

    private static function formatChangedValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'empty';
        }

        $value = (string) $value;

        if (is_numeric($value)) {
            return $value;
        }

        return (string) str($value)
            ->replace('_', ' ')
            ->headline();
    }

    private static function referenceLabel(ActivityLog $record): string
    {
        $reference = $record->project?->reference_number;

        if (filled($reference)) {
            return (string) $reference;
        }

        $projectName = $record->project?->name ?? $record->project_name_snapshot;

        if (filled($projectName)) {
            return self::shortProjectName((string) $projectName);
        }

        return 'No project';
    }

    private static function shortProjectName(string $projectName): string
    {
        return mb_strlen($projectName) > 12
            ? mb_substr($projectName, 0, 12).'...'
            : $projectName;
    }

    private static function styleActionLead(string $html, string $class): string
    {
        $firstDataSegment = strpos($html, '<span class="font-semibold');
        $lead = $firstDataSegment === false ? $html : substr($html, 0, $firstDataSegment);

        if ($lead === '') {
            return $html;
        }

        $remaining = $firstDataSegment === false ? '' : substr($html, $firstDataSegment);

        if (preg_match('/^(.*?)(\s(?:for|to|as|from|with)\s)$/', $lead, $matches) === 1) {
            return self::actionSpan($matches[1], $class)
                .self::connectorSpan($matches[2])
                .$remaining;
        }

        return self::actionSpan($lead, $class).$remaining;
    }

    private static function styleConnectorPhrases(string $html): string
    {
        $connectors = [
            ' to the schedule',
            ' to Salesforce',
            ' as ',
            ' from ',
            ' to ',
            ' — ',
        ];

        foreach ($connectors as $connector) {
            $html = str_replace($connector, self::connectorSpan($connector), $html);
        }

        return $html;
    }

    private static function actionSpan(string $text, string $class): string
    {
        return '<span class="font-semibold '.$class.'">'.$text.'</span>';
    }

    private static function connectorSpan(string $text): string
    {
        return '<span class="text-gray-500 dark:text-gray-400">'.$text.'</span>';
    }

    private static function actionToneClass(string $actionType): string
    {
        if (str_contains($actionType, 'deleted')
            || str_contains($actionType, 'unapproved')
            || str_contains($actionType, 'undone')
            || str_contains($actionType, 'flagged')) {
            return 'text-rose-600 dark:text-rose-400';
        }

        if (str_contains($actionType, 'approved')
            || str_contains($actionType, 'created')
            || str_contains($actionType, 'generated')
            || str_contains($actionType, 'uploaded')
            || str_contains($actionType, 'saved')
            || str_contains($actionType, 'requested')
            || str_contains($actionType, 'matched')
            || $actionType === 'product.added'
            || $actionType === 'user.login') {
            return 'text-emerald-600 dark:text-emerald-400';
        }

        return 'text-gray-800 dark:text-gray-100';
    }
}
