<?php

namespace App\Filament\Support;

use App\Filament\Pages\Calendar;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Salesforce;
use App\Filament\Pages\SalesforceCustomers;
use App\Filament\Pages\Statistics;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\PermissionGroups\PermissionGroupResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\ResourceFiles\ResourceFileResource;
use App\Filament\Resources\SpecialOrderCodes\SpecialOrderCodeResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;

use function Filament\Support\original_request;

class SectionNavigation
{
    /** @return array<string, array{label: string, icon: string, pages: array<array{string, class-string, string}>}> */
    private static function sections(): array
    {
        return [
            'salesforce' => ['label' => 'Salesforce', 'icon' => 'heroicon-o-cloud', 'pages' => [
                ['Customers', SalesforceCustomers::class, 'heroicon-o-building-office-2'],
                ['Projects', Salesforce::class, 'heroicon-o-folder'],
                ['Visits', Calendar::class, 'heroicon-o-calendar-days'],
            ]],
            'catalogue' => ['label' => 'Catalogue', 'icon' => 'heroicon-o-cpu-chip', 'pages' => [
                ['Products', ProductResource::class, 'heroicon-o-cpu-chip'],
                ['Specials', SpecialOrderCodeResource::class, 'heroicon-o-sparkles'],
            ]],
            'resources' => ['label' => 'Resources', 'icon' => 'heroicon-o-folder-open', 'pages' => [
                ['Resources', ResourceFileResource::class, 'heroicon-o-folder-open'],
            ]],
            'reporting' => ['label' => 'Reporting', 'icon' => 'heroicon-o-chart-bar', 'pages' => [
                ['Statistics', Statistics::class, 'heroicon-o-chart-bar'],
                ['History', ActivityLogResource::class, 'heroicon-o-clipboard-document-list'],
            ]],
            'administration' => ['label' => 'Administration', 'icon' => 'heroicon-o-cog-6-tooth', 'pages' => [
                ['Users', UserResource::class, 'heroicon-o-users'],
                ['Permission Groups', PermissionGroupResource::class, 'heroicon-o-shield-check'],
                ['Teams', TeamResource::class, 'heroicon-o-user-group'],
            ]],
        ];
    }

    /** @return array<NavigationItem> */
    public static function sidebarItems(): array
    {
        $items = Dashboard::getNavigationItems();
        if (ProjectResource::canViewAny()) {
            $items = [...$items, ...ProjectResource::getNavigationItems()];
        }

        foreach (self::sections() as $key => $section) {
            $links = self::links($key);
            if ($links === []) {
                continue;
            }
            $item = NavigationItem::make($section['label'])->icon($section['icon'])->url($links[0]['url'])
                ->isActiveWhen(fn (): bool => self::currentSection() === $key);
            if (count($section['pages']) > 1) {
                $item->extraAttributes([
                    'data-navigation-section' => $key,
                    'x-init' => "\$el.querySelector('a').setAttribute('aria-haspopup', 'true'); \$el.querySelector('a').setAttribute('aria-controls', 'lux-section-menu'); \$el.querySelector('a').setAttribute('aria-expanded', 'false')",
                    'x-on:mouseenter' => "\$dispatch('open-section-menu', { key: '{$key}', anchor: \$el })",
                    'x-on:mouseleave' => "\$dispatch('schedule-section-menu-close')",
                    'x-on:focusin' => "\$dispatch('open-section-menu', { key: '{$key}', anchor: \$el })",
                    'x-on:focusout' => "\$dispatch('schedule-section-menu-close')",
                    'x-on:keydown.arrow-right.prevent.stop' => "\$dispatch('open-section-menu', { key: '{$key}', anchor: \$el, focus: true })",
                    'x-on:click' => "if (window.matchMedia('(hover: none)').matches) { \$event.preventDefault(); \$dispatch('open-section-menu', { key: '{$key}', anchor: \$el, focus: true }) }",
                ]);
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return array<array{label: string, url: string, icon: string, active: bool}> */
    public static function links(string $section, ?string $currentUrl = null): array
    {
        $currentUrl ??= original_request()->url();
        $links = [];
        foreach (self::sections()[$section]['pages'] ?? [] as [$label, $page, $icon]) {
            $resource = is_subclass_of($page, Resource::class);
            if (! ($resource ? $page::canViewAny() : $page::canAccess())) {
                continue;
            }
            $url = $resource ? $page::getUrl('index') : $page::getUrl();
            $active = rtrim($currentUrl, '/') === rtrim($url, '/')
                || ($resource && str_starts_with($currentUrl, rtrim($url, '/').'/'));
            $links[] = compact('label', 'url', 'icon', 'active');
        }

        return $links;
    }

    public static function currentSection(): ?string
    {
        foreach (self::sections() as $key => $section) {
            foreach ($section['pages'] as [, $page]) {
                $pattern = is_subclass_of($page, Resource::class) ? $page::getRouteBaseName().'.*' : $page::getRouteName();
                if (original_request()->routeIs($pattern)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** @return array<array{label: string, url: string, icon: string, active: bool}> */
    public static function forCurrentRequest(): array
    {
        $section = self::currentSection();

        return $section === null || $section === 'resources' ? [] : self::links($section);
    }

    /** @return array<string, array{label: string, links: array<array{label: string, url: string, icon: string, active: bool}>}> */
    public static function popupMenus(): array
    {
        $menus = [];
        foreach (self::sections() as $key => $section) {
            $links = self::links($key);
            if (count($section['pages']) > 1 && $links !== []) {
                $menus[$key] = ['label' => $section['label'], 'links' => $links];
            }
        }

        return $menus;
    }
}
