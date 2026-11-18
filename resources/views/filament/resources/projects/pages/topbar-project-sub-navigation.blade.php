@php
    $links = \App\Filament\Resources\Projects\Support\ProjectSubNavigation::forCurrentRequest();

    if (empty($links) && request()->routeIs(\App\Filament\Pages\Salesforce::getRouteName(), \App\Filament\Pages\SalesforceCustomers::getRouteName())) {
        $links = \App\Filament\Pages\Salesforce::subNavigationLinks();
    }
@endphp

@if(count($links))
    @include('filament.resources.projects.pages.project-sub-navigation', [
        'links' => $links,
        'class' => 'hidden lg:flex mr-4',
    ])
@endif
