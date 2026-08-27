<?php

namespace Tests\Feature;

use App\Filament\Pages\Calendar;
use App\Models\PermissionGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCalendarPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_user_can_open_calendar_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(Calendar::getUrl())
            ->assertSuccessful()
            ->assertSee('calendar-date-navigator')
            ->assertSee('Month')
            ->assertSee('Week')
            ->assertSee('Day');

        $this->assertTrue($user->can('calendar.view'));
    }

    public function test_user_without_calendar_permission_cannot_open_calendar_page(): void
    {
        $group = PermissionGroup::query()->create([
            'name' => 'Restricted',
            'slug' => 'restricted',
            'description' => 'No calendar access.',
            'is_system' => false,
        ]);
        $user = User::factory()->create(['permission_group_id' => $group->id]);

        $this->actingAs($user)
            ->get(Calendar::getUrl())
            ->assertForbidden();

        $this->assertFalse($user->can('calendar.view'));
    }
}
