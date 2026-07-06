<?php

namespace Tests\Feature;

use App\Filament\Support\BadgeStyle;
use Tests\TestCase;

class BadgeStyleTest extends TestCase
{
    public function test_salesforce_stage_badges_use_distinct_palettes(): void
    {
        $this->assertNotSame(
            BadgeStyle::filamentColor('Details'),
            BadgeStyle::filamentColor('Design'),
        );

        $this->assertNotSame(
            BadgeStyle::filamentColor('Design'),
            BadgeStyle::filamentColor('Quotation'),
        );
    }

    public function test_unknown_badge_labels_get_stable_unique_palettes(): void
    {
        $first = BadgeStyle::filamentColor('Discovery');
        $second = BadgeStyle::filamentColor('Discovery');
        $different = BadgeStyle::filamentColor('Negotiation');

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $different);
    }

    public function test_brand_badges_use_brand_classes(): void
    {
        $this->assertSame('lux-badge-tamlite', BadgeStyle::classes('Tamlite'));
        $this->assertSame('lux-badge-xcite', BadgeStyle::classes('xcite'));
    }
}
