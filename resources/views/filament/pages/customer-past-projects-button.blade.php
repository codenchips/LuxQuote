<span title="{{ $getState() === null ? 'Past Projects — count unavailable' : 'Past Projects: '.number_format($getState()) }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5">
    <x-heroicon-o-clock class="h-4 w-4" />
    <span>{{ $getState() === null ? '—' : number_format($getState()) }}</span>
</span>
