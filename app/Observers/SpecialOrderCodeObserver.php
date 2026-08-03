<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\SpecialOrderCode;

class SpecialOrderCodeObserver
{
    /** @var array<int, array<string, array{old: mixed, new: mixed}>> */
    private static array $pendingPayloads = [];

    public function created(SpecialOrderCode $specialOrderCode): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => null,
            'action_type' => 'special.created',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => null,
            'payload' => [
                'code' => $specialOrderCode->code,
                'description' => $specialOrderCode->description,
                'price' => $specialOrderCode->price,
                'qty' => $specialOrderCode->qty,
                'requires_approval' => $specialOrderCode->requires_approval,
                'show_on_schedules' => $specialOrderCode->show_on_schedules,
                'show_on_quotes' => $specialOrderCode->show_on_quotes,
            ],
        ]);
    }

    public function updating(SpecialOrderCode $specialOrderCode): void
    {
        $meaningful = array_diff_key($specialOrderCode->getDirty(), array_flip([
            'normalised_code',
            'created_at',
            'updated_at',
        ]));

        if ($meaningful === []) {
            return;
        }

        $payload = [
            'code' => $specialOrderCode->code,
            'changes' => [],
        ];

        foreach ($meaningful as $key => $newValue) {
            $payload['changes'][$key] = [
                'old' => $specialOrderCode->getOriginal($key),
                'new' => $newValue,
            ];
        }

        self::$pendingPayloads[$specialOrderCode->id] = $payload;
    }

    public function updated(SpecialOrderCode $specialOrderCode): void
    {
        $payload = self::$pendingPayloads[$specialOrderCode->id] ?? null;

        if ($payload === null) {
            return;
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => null,
            'action_type' => 'special.updated',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => null,
            'payload' => $payload,
        ]);

        unset(self::$pendingPayloads[$specialOrderCode->id]);
    }
}
