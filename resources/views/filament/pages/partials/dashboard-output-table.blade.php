<div class="overflow-x-auto">
    @php($showDocumentPackName = $showDocumentPackName ?? false)

    <table class="w-full table-fixed divide-y divide-gray-200 text-sm dark:divide-gray-800">
        <thead class="lux-table-column-head">
            <tr class="text-left text-xs font-bold uppercase text-slate-600 dark:text-slate-300">
                @if ($showDocumentPackName)
                    <th class="w-[28%] px-4 py-3">Document pack</th>
                    <th class="w-[30%] px-4 py-3">Project name</th>
                @else
                    <th class="w-[50%] px-4 py-3">Project name</th>
                @endif
                <th class="w-[12%] px-4 py-3">Rev</th>
                <th class="px-4 py-3 text-right {{ $showDocumentPackName ? 'w-[21%]' : 'w-[29%]' }}">Generated date/time</th>
                <th class="w-[9%] px-4 py-3 text-center">View</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($rows as $row)
                <tr class="odd:bg-white even:bg-gray-50/70 dark:odd:bg-gray-950 dark:even:bg-gray-900">
                    @if ($showDocumentPackName)
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                            <div class="truncate" title="{{ $row['documentPack'] ?? '' }}">{{ $row['documentPack'] ?? '' }}</div>
                        </td>
                    @endif
                    <td class="px-4 py-3">
                        <a href="{{ $row['projectUrl'] }}" class="block truncate font-medium text-primary-600 hover:underline dark:text-primary-400" title="{{ $row['project'] }}">
                            {{ $row['project'] }}
                        </a>
                    </td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['revision'] }}</td>
                    <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ $row['generatedAt'] }}</td>
                    <td class="px-4 py-3 text-center">
                        <a href="{{ $row['url'] }}" class="inline-flex align-middle text-primary-600 transition hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300" target="_blank" rel="noopener" aria-label="Open in new window">
                            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                            <span class="sr-only">Open in new window</span>
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showDocumentPackName ? 5 : 4 }}" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ $empty }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
