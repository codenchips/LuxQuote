<div class="overflow-hidden">
    <table class="lux-dashboard-table w-full table-fixed text-sm">
        <colgroup>
            <col>
            <col style="width: 3.25rem;">
            <col style="width: 7rem;">
            <col style="width: 5.5rem;">
            <col style="width: 9.25rem;">
        </colgroup>
        <thead class="lux-table-column-head">
            <tr class="text-left text-xs font-bold uppercase text-slate-600 dark:text-slate-300">
                <th class="px-3 py-3">Project name</th>
                <th class="px-2 py-3 whitespace-nowrap">Rev</th>
                <th class="px-2 py-3 whitespace-nowrap">Status</th>
                <th class="px-2 py-3 whitespace-nowrap">Visibility</th>
                <th class="px-3 py-3 text-right whitespace-nowrap">Last accessed</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="min-w-0 px-3 py-3">
                        <a href="{{ $row['url'] }}" class="block truncate font-medium text-primary-600 hover:underline dark:text-primary-400" title="{{ $row['name'] }}">
                            {{ $row['name'] }}
                        </a>
                    </td>
                    <td class="px-2 py-3 whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $row['revision'] }}</td>
                    <td class="px-2 py-3 whitespace-nowrap">
                        <span class="lux-compact-badge {{ $row['statusClasses'] }}">{{ $row['status'] }}</span>
                    </td>
                    <td class="px-2 py-3 whitespace-nowrap">
                        <span class="lux-compact-badge {{ $row['visibilityClasses'] }}">{{ $row['visibility'] }}</span>
                    </td>
                    <td class="px-3 py-3 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $row['lastAccessed'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ $empty }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
