@if(count($links))
    <nav class="flex items-center gap-5 text-sm font-medium {{ $class ?? '' }}">
        @foreach($links as $link)
            @if(! $loop->first)
                <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">|</span>
            @endif

            <a
                href="{{ $link['url'] }}"
                @class([
                    'inline-flex items-center gap-1.5 whitespace-nowrap transition-colors duration-150',
                    'text-gray-700 hover:text-amber-600 dark:text-white dark:hover:text-amber-300' => ! $link['active'],
                    'font-semibold text-amber-600 dark:text-amber-400' => $link['active'],
                ])
            >
                <x-dynamic-component :component="$link['icon']" class="h-4 w-4" />
                <span>{{ $link['label'] }}</span>
            </a>
        @endforeach
    </nav>
@endif
