<header class="fi-header sticky top-16 z-10 border-b border-gray-200 bg-white/95 py-4 backdrop-blur dark:border-white/10 dark:bg-gray-950/95">
    <div class="flex w-full items-start justify-between gap-4">

        {{-- Heading block (left side) --}}
        <div class="min-w-0">
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

        {{-- Right side: project sub-links + page actions --}}
        <div class="flex flex-col items-end gap-6 shrink-0">

            {{-- Sub-page text links --}}
            @if(isset($subLinks) && count($subLinks))
                <nav class="flex items-center gap-6 text-base font-medium">
                    @foreach($subLinks as $link)
                        @if(! $loop->first)
                            <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">|</span>
                        @endif

                        <a
                            href="{{ $link['url'] }}"
                            @class([
                                'inline-flex items-center gap-1.5 transition-colors duration-150 whitespace-nowrap',
                                'text-gray-700 hover:text-amber-600 dark:text-white dark:hover:text-amber-300' => !$link['active'],
                                'font-semibold text-amber-600 dark:text-amber-400' => $link['active'],
                            ])
                        >
                            <x-dynamic-component :component="$link['icon']" class="h-4 w-4" />
                            <span>{{ $link['label'] }}</span>
                        </a>
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
