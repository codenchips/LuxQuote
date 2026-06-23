<div class="flex flex-col items-center justify-center py-10 text-center gap-3 text-gray-400 dark:text-gray-500">
    <x-heroicon-o-clock class="w-10 h-10 text-gray-300 dark:text-gray-600" />
    <p class="text-sm">Revision management is coming soon.</p>
    <p class="text-xs">Current revision: <strong class="text-gray-600 dark:text-gray-300">{{ \App\Models\ProjectRevision::labelForNumber($project->revision) }}</strong></p>
</div>
