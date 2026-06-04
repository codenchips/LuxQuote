<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginAsTestUserTest extends DuskTestCase
{
    public function test_user_can_access_dashboard_directly(): void
    {
        $this->browse(function (Browser $browser) {
            // 1. Grab the user directly from the database
            $user = User::where('email', 'test@tamlite.co.uk')->first();

            // 2. Log them in programmatically behind the scenes
            $browser->loginAs($user)
                    // 3. Jump straight to an authenticated route (e.g., your Filament dashboard)
                    ->visit('/') 
                    ->pause(2000)
                    ->assertSee('Dashboard'); // Confirm they are inside
        });
    }
}