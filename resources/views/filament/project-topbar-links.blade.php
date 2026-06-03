@php
    use App\Enums\UserRole;
    use App\Filament\Resources\Projects\Pages\OutputProject;
    use App\Filament\Resources\Projects\Pages\PricingProject;
    use App\Filament\Resources\Projects\Pages\ValidationProject;
    use App\Filament\Resources\Projects\Pages\ViewProject;

    $user = auth()->user();

    // Only show on project record pages and only to admins.
    $routeName  = request()->route()?->getName() ?? '';
    $isOnProject = str_starts_with($routeName, 'filament.admin.resources.projects.')
        && ! in_array($routeName, ['filament.admin.resources.projects.index', 'filament.admin.resources.projects.create']);
    $isAdmin    = $user?->role === UserRole::Admin;
@endphp

@if($isOnProject && $isAdmin)
    @php
        $recordId   = request()->route('record');
        $currentUrl = request()->url();

        $links = [
            ['label' => 'Edit', 'url' => ValidationProject::getUrl(['record' => $recordId])],
            ['label' => 'Validation', 'url' => ValidationProject::getUrl(['record' => $recordId])],
            ['label' => 'Pricing',    'url' => PricingProject::getUrl(['record' => $recordId])],
            ['label' => 'Output',     'url' => OutputProject::getUrl(['record' => $recordId])],
        ];
    @endphp

    <nav class="flex items-center gap-5 pr-4 text-sm">
        @foreach($links as $link)
            <a
                href="{{ $link['url'] }}"
                @class([
                    'transition-colors duration-150',
                    'text-gray-400 hover:text-white dark:text-gray-400 dark:hover:text-white' => ! str_starts_with($currentUrl, $link['url']),
                    'text-white font-semibold border-b border-primary-500 pb-px'              =>   str_starts_with($currentUrl, $link['url']),
                ])
            >{{ $link['label'] }}</a>
        @endforeach
    </nav>
@endif
