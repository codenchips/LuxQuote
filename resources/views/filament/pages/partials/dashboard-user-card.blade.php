<div class="flex min-h-[14rem] flex-col justify-between p-5">
    <div class="flex items-center gap-4">
        @if ($user['avatarUrl'])
            <img
                src="{{ $user['avatarUrl'] }}"
                alt="{{ $user['name'] }} avatar"
                class="h-16 w-16 rounded-full border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800"
            />
        @else
            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-primary-100 text-lg font-semibold text-primary-700 dark:bg-primary-400/10 dark:text-primary-300">
                {{ mb_substr($user['name'], 0, 1) }}
            </div>
        @endif

        <div class="min-w-0">
            <p class="truncate text-base font-semibold text-gray-950 dark:text-white">{{ $user['name'] }}</p>
            <p class="truncate text-sm text-gray-500 dark:text-gray-400">{{ $user['email'] }}</p>
        </div>
    </div>

    <div class="mt-6 flex items-end justify-between border-t border-slate-200 pt-5 dark:border-slate-800">
        <div>
            <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">Projects created</p>
            <p class="mt-1 text-2xl font-semibold text-slate-950 dark:text-white">{{ number_format($user['projectCount']) }}</p>
        </div>

        @if ($user['profileUrl'])
            <a href="{{ $user['profileUrl'] }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                Edit profile
            </a>
        @else
            <span class="text-sm text-gray-500 dark:text-gray-400">Profile unavailable</span>
        @endif
    </div>
</div>
