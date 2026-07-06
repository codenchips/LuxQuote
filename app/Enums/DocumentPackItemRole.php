<?php

namespace App\Enums;

enum DocumentPackItemRole: string
{
    case Cover = 'cover';
    case Legal = 'legal';
    case CustomPdf = 'custom_pdf';
    case StandardLegalPage = 'standard_legal_page';
    case Quote = 'quote';
    case UnpricedSchedule = 'unpriced_schedule';

    public function label(): string
    {
        return match ($this) {
            self::Cover => 'Cover',
            self::Legal => 'Legal',
            self::CustomPdf => 'Custom PDF',
            self::StandardLegalPage => 'Standard Legal Page',
            self::Quote => 'Quote',
            self::UnpricedSchedule => 'Schedule',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Cover => 'An external PDF used as a cover or introductory document.',
            self::Legal => 'An external PDF containing terms, conditions, or legal content.',
            self::CustomPdf => 'An uploaded PDF included exactly where it appears in the pack.',
            self::StandardLegalPage => 'The standard Tamlite legal page supplied by the application.',
            self::Quote => 'The priced quote generated for the revision selected at output time.',
            self::UnpricedSchedule => 'The schedule generated for the revision selected at output time.',
        };
    }

    public function source(): DocumentPackItemSource
    {
        return match ($this) {
            self::Cover, self::Legal, self::CustomPdf => DocumentPackItemSource::Uploaded,
            self::StandardLegalPage => DocumentPackItemSource::Template,
            self::Quote, self::UnpricedSchedule => DocumentPackItemSource::Generated,
        };
    }

    public function selectableInBuilder(): bool
    {
        return ! in_array($this, [self::Cover, self::Legal], true);
    }
}
