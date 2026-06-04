<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ExampleTest extends DuskTestCase
{
    public function test_basic_example(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                    ->pause(3000) // ⏱️ Pause for 3 seconds right after loading the page
                    
                    ->assertSee('LuxQuote')
                    
                    ->pause(2000); // ⏱️ Pause for another 2 seconds so you can see the final state
        });
    }
}