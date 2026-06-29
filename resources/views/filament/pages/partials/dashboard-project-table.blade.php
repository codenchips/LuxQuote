<div class="overflow-x-auto">
    <table class="lux-dashboard-table w-full table-fixed text-sm">
        <thead class="lux-table-column-head">
            <tr class="text-left text-xs font-bold uppercase text-slate-600 dark:text-slate-300">
                <th class="w-[42%] px-4 py-3">Project name</th>
                <th class="w-[12%] px-4 py-3">Rev</th>
                <th class="w-[14%] px-4 py-3">Status</th>
                <th class="w-[14%] px-4 py-3">Visibility</th>
                <th class="w-[18%] px-4 py-3 text-right">Last accessed</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ $row['url'] }}" class="block truncate font-medium text-primary-600 hover:underline dark:text-primary-400" title="{{ $row['name'] }}">
                            {{ $row['name'] }}
                        </a>
                    </td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['revision'] }}</td>
                    <td class="px-4 py-3">
                        <span @class([
                            'inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
                            'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-300 dark:ring-gray-400/20' => $row['statusColor'] === 'gray',
                            'bg-sky-50 text-sky-700 ring-sky-700/10 dark:bg-sky-400/10 dark:text-sky-300 dark:ring-sky-400/20' => $row['statusColor'] === 'info',
                            'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20' => $row['statusColor'] === 'success',
                            'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-400/10 dark:text-rose-300 dark:ring-rose-400/20' => $row['statusColor'] === 'danger',
                        ])>{{ $row['status'] }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span @class([
                            'inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
                            'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20' => $row['visibilityColor'] === 'success',
                            'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20' => $row['visibilityColor'] === 'warning',
                            'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-300 dark:ring-gray-400/20' => $row['visibilityColor'] === 'gray',
                        ])>{{ $row['visibility'] }}</span>
                    </td>
                    <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ $row['lastAccessed'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ $empty }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
