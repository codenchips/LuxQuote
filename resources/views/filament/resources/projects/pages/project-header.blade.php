<header class="fi-header fi-header-has-breadcrumbs">
    <div class="flex w-full items-start justify-between gap-4">

        {{-- Breadcrumbs + heading block (left side) --}}
        <div class="min-w-0">
            @if ($breadcrumbs)
                <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
            @endif

            @if (filled($heading))
                <h1 class="fi-header-heading">
                    {{ $heading }}
                </h1>
            @endif

            @if (filled($subheading))
                <p class="fi-header-subheading">
                    {{ $subheading }}
                </p>
            @endif
        </div>

        {{-- Right side: project sub-links (same row as breadcrumbs) + page actions --}}
        <div class="flex flex-col items-end gap-3 shrink-0">

            {{-- Sub-page text links --}}
            @if(isset($subLinks) && count($subLinks))
                <nav class="flex items-center gap-5 text-sm">
                    @foreach($subLinks as $link)
                        <a
                            href="{{ $link['url'] }}"
                            @class([
                                'transition-colors duration-150 whitespace-nowrap',
                                'text-gray-400 hover:text-gray-200 dark:text-gray-400 dark:hover:text-gray-200' => !$link['active'],
                                'text-white font-semibold underline underline-offset-4 decoration-primary-500' => $link['active'],
                            ])
                        >{{ $link['label'] }}</a>
                    @endforeach
                </nav>
            @endif

            {{-- Header actions (Details, Revisions, Areas, Schedule PDF) --}}
            @if ($actions)
                <div class="fi-header-actions-ctn">
                    <x-filament::actions
                        :actions="$actions"
                        :alignment="$actionsAlignment"
                    />
                </div>
            @endif

        </div>
    </div>
</header>
