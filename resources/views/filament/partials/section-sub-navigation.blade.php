@php
    $links = \App\Filament\Support\SectionNavigation::forCurrentRequest();
    $hasHeaderLinks = request()->routeIs(\App\Filament\Pages\Salesforce::getRouteName(), \App\Filament\Pages\SalesforceCustomers::getRouteName());
@endphp
@if(count($links) && ! $hasHeaderLinks)
    <div class="lg:hidden overflow-x-auto">
        @include('filament.resources.projects.pages.project-sub-navigation', ['links' => $links])
    </div>
@endif
