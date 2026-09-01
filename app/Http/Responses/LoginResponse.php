<?php

namespace App\Http\Responses;

use App\Enums\LandingPage;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse implements LoginResponseContract
{
    public function toResponse(mixed $request): RedirectResponse|Redirector
    {
        return redirect()->intended($this->defaultDestination());
    }

    private function defaultDestination(): string
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return LandingPage::Dashboard->url();
        }

        $landingPage = $user->permissionGroup?->default_landing_page ?? LandingPage::Dashboard;

        if (! $landingPage->isAccessibleTo($user)) {
            return LandingPage::Dashboard->url();
        }

        return $landingPage->url();
    }
}
