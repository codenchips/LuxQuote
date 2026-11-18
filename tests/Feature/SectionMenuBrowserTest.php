<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class SectionMenuBrowserTest extends TestCase
{
    public function test_hover_opens_the_permitted_section_within_the_viewport(): void
    {
        $this->runBrowserCheck('verifyHover');
    }

    public function test_keyboard_navigation_and_escape_restore_focus(): void
    {
        $this->runBrowserCheck('verifyKeyboard');
    }

    public function test_hovering_the_popup_cancels_close_and_leaving_closes_it(): void
    {
        $this->runBrowserCheck('verifyHoverClose');
    }

    public function test_blade_popup_hover_keyboard_and_click_behaviour_in_chrome(): void
    {
        $this->runBrowserCheck('verifyBrowser');
    }

    private function runBrowserCheck(string $check): void
    {
        $process = new Process([
            'node', '--input-type=module', '--eval',
            'import { '.$check.' } from "./tests/Browser/Components/section-menu-checks.mjs"; await '.$check.'();',
        ], base_path());
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }
}
