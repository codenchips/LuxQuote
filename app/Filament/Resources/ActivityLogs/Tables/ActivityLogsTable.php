<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Models\ActivityLog;
use App\Models\ActivityLogArchive;
use App\Models\ProjectRevision;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActivityLogsTable
{
    private const ACTION_DISPLAY_LIMIT = 160;

    public static function configure(Table $table, bool $archived = false): Table
    {
        $columns = [
            TextColumn::make('user.name')
                ->label('Who')
                ->placeholder(fn (ActivityLog|ActivityLogArchive $record): string => $record->user_email_snapshot)
                ->searchable()
                ->width('7rem')
                ->extraCellAttributes([
                    'class' => 'w-28 max-w-28 overflow-hidden whitespace-nowrap text-ellipsis',
                ]),

            TextColumn::make('project.reference_number')
                ->label('Reference')
                ->getStateUsing(fn (ActivityLog|ActivityLogArchive $record): string => self::referenceLabel($record))
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
                ->searchable(query: fn (Builder $query, string $search): Builder => self::searchActionPerformed($query, $search))
                ->width('100%')
                ->extraCellAttributes([
                    'class' => 'w-full min-w-[36rem] overflow-hidden whitespace-nowrap text-ellipsis',
                ])
                ->getStateUsing(function (ActivityLog|ActivityLogArchive $record): string {
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

                        'project.created' => 'Created the project <strong>'.e(self::projectName($record)).'</strong>',

                        'project.details_saved' => (function () use ($payload, $record): string {
                            $url = $payload['salesforce_pdf_url'] ?? null;
                            $filename = e((string) ($payload['salesforce_pdf_filename'] ?? 'schedule PDF'));
                            $projectName = e(self::projectName($record));
                            $prefix = "Changed project details for <strong>{$projectName}</strong>";

                            if (blank($url)) {
                                return $prefix;
                            }

                            $href = e((string) $url);

                            return "{$prefix} and uploaded <strong>{$filename}</strong> to Salesforce: <a href=\"{$href}\" target=\"_blank\" rel=\"noopener noreferrer\" class=\"text-primary-600 underline dark:text-primary-400\">View file</a>";
                        })(),

                        'project.updated' => (function () use ($payload, $record): string {
                            $projectName = e(self::projectName($record));

                            if (empty($payload)) {
                                return "Changed project details for <strong>{$projectName}</strong>";
                            }

                            return "Changed project details for <strong>{$projectName}</strong>: ".self::formatProjectChanges($payload);
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

                        'user.login' => (function () use ($payload): string {
                            $context = $payload['login_context']['display'] ?? null;

                            if (blank($context)) {
                                return 'Logged in';
                            }

                            return 'Logged in <strong>'.e((string) $context).'</strong>';
                        })(),

                        'product.added' => (function () use ($payload): string {
                            $qty = e((string) ($payload['qty'] ?? '1'));
                            $description = e((string) ($payload['description'] ?? ''));
                            $code = e((string) ($payload['code'] ?? ''));
                            $ref = isset($payload['ref']) ? ' | Ref: '.e((string) $payload['ref']) : '';
                            $price = isset($payload['unit_price']) ? ' | £'.e(number_format((float) $payload['unit_price'], 2)) : '';
                            $notes = isset($payload['notes']) && $payload['notes'] !== '' ? ' | '.e((string) $payload['notes']) : '';

                            $detail = trim("{$code}{$ref}{$price}{$notes}", ' |');
                            $label = $description !== '' ? "<strong>{$qty}x {$description}</strong>" : "<strong>{$qty}x</strong>";

                            return 'Added '.$label.($detail !== '' ? " ({$detail})" : '');
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
                                'cover_1' => 'Cover 1',
                                'cover_2' => 'Cover 2',
                                'cover_3' => 'Cover 3',
                                'notes' => 'Notes',
                                'type' => 'Line Type',
                                'status' => 'Status',
                            ];
                            $parts = [];
                            foreach ($changes as $field => $change) {
                                $label = $fieldNames[$field] ?? (string) str($field)->headline();

                                if ($field === 'unit_price' && self::isEmptyToZeroPriceChange($change)) {
                                    continue;
                                }

                                if (in_array($field, ['notes', 'validation_note'], true)) {
                                    $parts[] = self::formatSensitiveTextChange($label, $change);

                                    continue;
                                }

                                $humanizeValue = in_array($field, ['status', 'type'], true);
                                $old = e(self::formatChangedValue($change['old'] ?? null, $humanizeValue));
                                $new = e(self::formatChangedValue($change['new'] ?? null, $humanizeValue));
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
        ];

        if ($archived) {
            $columns[] = TextColumn::make('archived_at')
                ->label('Archived')
                ->dateTime('M d Y H:i')
                ->sortable()
                ->width('10rem')
                ->extraCellAttributes([
                    'class' => 'w-40 max-w-40 whitespace-nowrap',
                ]);
        }

        return $table
            ->columns($columns)
            ->filters([
                SelectFilter::make('action_type')
                    ->label('Action')
                    ->options(self::actionFilterOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([15, 25, 50])
            ->recordActions([])
            ->toolbarActions([]);
    }

    private static function searchActionPerformed(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        $normalisedSearch = str($search)->lower()->replace(['_', '.', '-'], ' ')->squish()->toString();
        $payloadSearch = str_replace(' ', '_', $search);
        $matchingActionTypes = collect(self::actionSearchLabels())
            ->filter(fn (string $label): bool => str_contains(
                str($label)->lower()->replace(['_', '.', '-'], ' ')->squish()->toString(),
                $normalisedSearch,
            ))
            ->keys()
            ->all();

        return $query->where(function (Builder $query) use ($search, $payloadSearch, $matchingActionTypes): void {
            $query
                ->where('action_type', 'like', "%{$search}%")
                ->orWhere('payload', 'like', "%{$search}%")
                ->orWhere('payload', 'like', "%{$payloadSearch}%")
                ->orWhere('project_name_snapshot', 'like', "%{$search}%")
                ->orWhere('user_email_snapshot', 'like', "%{$search}%");

            if ($matchingActionTypes !== []) {
                $query->orWhereIn('action_type', $matchingActionTypes);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private static function actionFilterOptions(): array
    {
        return [
            'area.created' => 'Area Created',
            'area.deleted' => 'Area Deleted',
            'project.created' => 'Project Created',
            'project.details_saved' => 'Project Details Saved',
            'project.updated' => 'Project Updated',
            'project.deleted' => 'Project Deleted',
            'revision.created' => 'Revision Created',
            'revision.approved' => 'Approved and locked',
            'revision.unapproved' => 'Unapproved and unlocked',
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
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function actionSearchLabels(): array
    {
        return self::actionFilterOptions() + [
            'revision.approved' => 'Approved and locked revision',
            'revision.unapproved' => 'Unapproved and unlocked revision',
        ];
    }

    private static function formatActionHtml(string $html, string $actionType): string
    {
        $copyText = self::plainActionText($html);
        $html = self::truncateActionHtml($html);
        $copyValue = e(json_encode($copyText, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR));

        $html = str_replace(
            ['<strong>', '</strong>'],
            ['<span class="font-semibold text-sky-600 dark:text-sky-300">', '</span>'],
            $html,
        );
        $html = self::styleActionLead($html, self::actionToneClass($actionType));
        $html = self::styleConnectorPhrases($html);

        return '<span title="Copy to clipboard" x-on:click.stop="navigator.clipboard.writeText('.$copyValue.')" class="block max-w-full cursor-pointer overflow-hidden text-ellipsis whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">'.$html.'</span>';
    }

    private static function truncateActionHtml(string $html): string
    {
        $plainText = self::plainActionText($html);

        if (mb_strlen($plainText) <= self::ACTION_DISPLAY_LIMIT) {
            return $html;
        }

        return e(mb_substr($plainText, 0, self::ACTION_DISPLAY_LIMIT - 3).'...');
    }

    private static function plainActionText(string $html): string
    {
        return html_entity_decode(trim(strip_tags($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function formatChangedValue(mixed $value, bool $humanize = false): string
    {
        if ($value === null || $value === '') {
            return 'empty';
        }

        $value = (string) $value;

        if (is_numeric($value)) {
            return $value;
        }

        if (! $humanize) {
            return $value;
        }

        return (string) str($value)->replace('_', ' ')->headline();
    }

    /**
     * @param  array<string, array{old?: mixed, new?: mixed}>  $changes
     */
    private static function formatProjectChanges(array $changes): string
    {
        $fieldNames = [
            'branch_name' => 'Branch Name',
            'contractor' => 'Contractor',
            'cover_1' => 'Cover 1',
            'cover_2' => 'Cover 2',
            'cover_3' => 'Cover 3',
            'cover_direction' => 'Cover Direction',
            'cover_percentage' => 'Cover Percentage',
            'created_by_email' => 'Created By Email',
            'customer_name' => 'Customer Name',
            'date' => 'Date',
            'department' => 'Department',
            'general_notes' => 'General Notes',
            'has_cover' => 'Has Cover',
            'internal_notes' => 'Internal Notes',
            'name' => 'Project Name',
            'owner_email' => 'Project Owner Email',
            'quote_notes' => 'Quote Notes',
            'reference_number' => 'Quote Reference',
            'revision' => 'Revision',
            'site_location' => 'Site Location',
            'status' => 'Status',
            'team_id' => 'Team',
            'value' => 'Value',
            'visibility' => 'Privacy Status',
        ];

        $parts = [];

        foreach ($changes as $key => $change) {
            $label = $fieldNames[$key] ?? (string) str($key)->headline();

            if (in_array($key, ['general_notes', 'internal_notes', 'quote_notes'], true)) {
                $parts[] = self::formatSensitiveTextChange($label, $change);

                continue;
            }

            $humanizeValue = in_array($key, ['cover_direction', 'status', 'visibility'], true);
            $old = e(self::formatChangedValue($change['old'] ?? null, $humanizeValue));
            $new = e(self::formatChangedValue($change['new'] ?? null, $humanizeValue));
            $parts[] = "Changed <strong>{$label}</strong> from <strong>{$old}</strong> to <strong>{$new}</strong>";
        }

        return implode('; ', $parts);
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private static function isEmptyToZeroPriceChange(array $change): bool
    {
        $oldValue = $change['old'] ?? null;
        $newValue = $change['new'] ?? null;

        return ($oldValue === null || $oldValue === '')
            && is_numeric($newValue)
            && (float) $newValue === 0.0;
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private static function formatSensitiveTextChange(string $label, array $change): string
    {
        $oldBlank = blank($change['old'] ?? null);
        $newBlank = blank($change['new'] ?? null);

        if ($oldBlank && ! $newBlank) {
            return "Added <strong>{$label}</strong>";
        }

        if (! $oldBlank && $newBlank) {
            return "Cleared <strong>{$label}</strong>";
        }

        return "Changed <strong>{$label}</strong>";
    }

    private static function referenceLabel(ActivityLog|ActivityLogArchive $record): string
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

    private static function projectName(ActivityLog|ActivityLogArchive $record): string
    {
        return (string) ($record->project?->name ?? $record->project_name_snapshot ?? 'Unknown project');
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
