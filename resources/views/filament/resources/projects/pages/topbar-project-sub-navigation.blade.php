@php
    $links = \App\Filament\Resources\Projects\Support\ProjectSubNavigation::forCurrentRequest();
@endphp

@if(count($links))
    @include('filament.resources.projects.pages.project-sub-navigation', [
        'links' => $links,
        'class' => 'hidden lg:flex mr-4',
    ])
@endif
