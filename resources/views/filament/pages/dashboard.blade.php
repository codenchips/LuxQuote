<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-2">
        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-gray-900">
            <div class="lux-table-title-bar flex items-center justify-between border-b px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-950 dark:text-slate-100">Recent Projects</h2>
                <x-heroicon-o-clock class="h-5 w-5 text-slate-200" />
            </div>

            @include('filament.pages.partials.dashboard-project-table', [
                'rows' => $this->recentProjects(),
                'empty' => 'No recent project activity.',
            ])
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-gray-900">
            <div class="lux-table-title-bar flex items-center justify-between border-b px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-950 dark:text-slate-100">Your Projects</h2>
                <x-heroicon-o-folder class="h-5 w-5 text-slate-200" />
            </div>

            @include('filament.pages.partials.dashboard-project-table', [
                'rows' => $this->yourProjects(),
                'empty' => 'No projects created yet.',
            ])
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-gray-900">
            <div class="lux-table-title-bar flex items-center justify-between border-b px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-950 dark:text-slate-100">Recent Schedules</h2>
                <x-heroicon-o-document-arrow-down class="h-5 w-5 text-slate-200" />
            </div>

            @include('filament.pages.partials.dashboard-output-table', [
                'rows' => $this->recentSchedules(),
                'empty' => 'No recent schedules.',
            ])
        </section>

        @if ($this->canViewQuotesPanel())
            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-gray-900">
                <div class="lux-table-title-bar flex items-center justify-between border-b px-4 py-3">
                    <h2 class="text-sm font-semibold text-slate-950 dark:text-slate-100">Recent Quotes</h2>
                    <x-heroicon-o-document-text class="h-5 w-5 text-slate-200" />
                </div>

                @include('filament.pages.partials.dashboard-output-table', [
                    'rows' => $this->recentQuotes(),
                    'empty' => 'No recent quotes.',
                ])
            </section>
        @endif

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-gray-900">
            <div class="lux-table-title-bar flex items-center justify-between border-b px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-950 dark:text-slate-100">Recent Document Packs</h2>
                <x-heroicon-o-document-duplicate class="h-5 w-5 text-slate-200" />
            </div>

            @include('filament.pages.partials.dashboard-output-table', [
                'rows' => $this->recentDocumentPacks(),
                'empty' => 'No recent document packs.',
                'showDocumentPackName' => true,
            ])
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-gray-900">
            <div class="lux-table-title-bar flex items-center justify-between border-b px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-950 dark:text-slate-100">User</h2>
                <x-heroicon-o-user-circle class="h-5 w-5 text-slate-200" />
            </div>

            @include('filament.pages.partials.dashboard-user-card', [
                'user' => $this->userSummary(),
            ])
        </section>
    </div>
</x-filament-panels::page>
