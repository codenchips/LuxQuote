<div class="overflow-hidden">
    @php($showDocumentPackName = $showDocumentPackName ?? false)

    <table class="lux-dashboard-table w-full table-fixed text-sm">
        <colgroup>
            @if ($showDocumentPackName)
                <col style="width: 30%;">
                <col>
            @else
                <col>
            @endif
            <col style="width: 3.25rem;">
            <col style="width: 10.5rem;">
            <col style="width: 3.5rem;">
        </colgroup>
        <thead class="lux-table-column-head">
            <tr class="text-left text-xs font-bold uppercase text-slate-600 dark:text-slate-300">
                @if ($showDocumentPackName)
                    <th class="px-3 py-3 whitespace-nowrap">Document pack</th>
                    <th class="px-3 py-3 whitespace-nowrap">Project name</th>
                @else
                    <th class="px-3 py-3 whitespace-nowrap">Project name</th>
                @endif
                <th class="px-2 py-3 whitespace-nowrap">Rev</th>
                <th class="px-3 py-3 text-right whitespace-nowrap">Generated date/time</th>
                <th class="px-2 py-3 text-center whitespace-nowrap">View</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @if ($showDocumentPackName)
                        <td class="min-w-0 px-3 py-3 font-medium text-gray-900 dark:text-gray-100">
                            <div class="truncate" title="{{ $row['documentPack'] ?? '' }}">{{ $row['documentPack'] ?? '' }}</div>
                        </td>
                    @endif
                    <td class="min-w-0 px-3 py-3">
                        <a href="{{ $row['projectUrl'] }}" class="block truncate font-medium text-primary-600 hover:underline dark:text-primary-400" title="{{ $row['project'] }}">
                            {{ $row['project'] }}
                        </a>
                    </td>
                    <td class="px-2 py-3 whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $row['revision'] }}</td>
                    <td class="px-3 py-3 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $row['generatedAt'] }}</td>
                    <td class="px-2 py-3 text-center whitespace-nowrap">
                        <a
                            href="{{ $row['url'] }}"
                            class="inline-flex align-middle text-primary-600 transition hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300"
                            target="_blank"
                            rel="noopener"
                            aria-label="Open in new window"
                            data-pdf-generation
                            data-pdf-title="Generating PDF"
                            data-pdf-message="Your PDF is being generated. This can take a while."
                        >
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
