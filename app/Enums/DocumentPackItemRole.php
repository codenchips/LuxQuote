<?php

namespace App\Enums;

enum DocumentPackItemRole: string
{
    case Cover = 'cover';
    case Legal = 'legal';
    case Quote = 'quote';
    case UnpricedSchedule = 'unpriced_schedule';

    public function label(): string
    {
        return match ($this) {
            self::Cover => 'Cover',
            self::Legal => 'Legal',
            self::Quote => 'Quote',
            self::UnpricedSchedule => 'Unpriced Schedule',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Cover => 'An external PDF used as a cover or introductory document.',
            self::Legal => 'An external PDF containing terms, conditions, or legal content.',
            self::Quote => 'The priced quote generated for the revision selected at output time.',
            self::UnpricedSchedule => 'The unpriced schedule generated for the revision selected at output time.',
        };
    }

    public function source(): DocumentPackItemSource
    {
        return match ($this) {
            self::Cover, self::Legal => DocumentPackItemSource::Uploaded,
            self::Quote, self::UnpricedSchedule => DocumentPackItemSource::Generated,
        };
    }
}
