<header class="fi-header sticky top-16 z-10 border-b border-gray-200 bg-white/95 py-4 backdrop-blur dark:border-white/10 dark:bg-gray-950/95">
    <div class="flex w-full items-center justify-between gap-4">

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

            {{-- Sub-page text links: topbar on desktop, compact fallback here on smaller screens. --}}
            @if(isset($subLinks) && count($subLinks))
                @include('filament.resources.projects.pages.project-sub-navigation', [
                    'links' => $subLinks,
                    'class' => 'lg:hidden',
                ])
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
