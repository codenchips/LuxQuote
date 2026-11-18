@php
    $links = \App\Filament\Resources\Projects\Support\ProjectSubNavigation::forCurrentRequest();

    if (empty($links)) {
        $links = \App\Filament\Support\SectionNavigation::forCurrentRequest();
    }
@endphp

@if(count($links))
    @include('filament.resources.projects.pages.project-sub-navigation', [
        'links' => $links,
        'class' => 'hidden lg:flex mr-4',
    ])
@endif
