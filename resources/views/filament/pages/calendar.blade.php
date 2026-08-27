<x-filament-panels::page>
    <div class="grid gap-6 2xl:grid-cols-[minmax(0,1fr)_18rem]">
        <div class="min-w-0">
            @livewire(\App\Filament\Widgets\CalendarWidget::class, key('company-calendar'))
        </div>

        <aside
            id="calendar-date-navigator"
            class="h-fit rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
            x-data="{
                selected: new Date(),
                cursor: new Date(new Date().getFullYear(), new Date().getMonth(), 1),
                monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
                get days() {
                    const firstDay = new Date(this.cursor.getFullYear(), this.cursor.getMonth(), 1)
                    const mondayOffset = (firstDay.getDay() + 6) % 7
                    const start = new Date(firstDay)
                    start.setDate(firstDay.getDate() - mondayOffset)

                    return Array.from({ length: 42 }, (_, index) => {
                        const date = new Date(start)
                        date.setDate(start.getDate() + index)

                        return date
                    })
                },
                get years() {
                    const currentYear = new Date().getFullYear()

                    return Array.from({ length: 21 }, (_, index) => currentYear - 10 + index)
                },
                dateKey(date) {
                    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
                },
                isSelected(date) {
                    return this.dateKey(date) === this.dateKey(this.selected)
                },
                isToday(date) {
                    return this.dateKey(date) === this.dateKey(new Date())
                },
                jumpTo(date) {
                    this.selected = new Date(date)
                    this.cursor = new Date(date.getFullYear(), date.getMonth(), 1)
                    window.dispatchEvent(new CustomEvent('filament-fullcalendar--goto', {
                        detail: { date: this.dateKey(date) },
                    }))
                },
                moveMonth(offset) {
                    this.cursor = new Date(this.cursor.getFullYear(), this.cursor.getMonth() + offset, 1)
                    this.jumpTo(this.cursor)
                },
                changeMonth(month) {
                    this.cursor = new Date(this.cursor.getFullYear(), Number(month), 1)
                    this.jumpTo(this.cursor)
                },
                changeYear(year) {
                    this.cursor = new Date(Number(year), this.cursor.getMonth(), 1)
                    this.jumpTo(this.cursor)
                },
            }"
        >
            <div class="mb-4 flex items-center gap-2">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-600 text-white dark:bg-primary-500">
                    <x-heroicon-o-calendar-days class="size-5" />
                </div>
                <div>
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Jump to a date</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Choose any day, month, or year</p>
                </div>
            </div>

            <div class="mb-3 grid grid-cols-[auto_1fr_5.5rem_auto] items-center gap-1.5">
                <button
                    type="button"
                    class="flex size-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
                    title="Previous month"
                    aria-label="Previous month"
                    @click="moveMonth(-1)"
                >
                    <x-heroicon-m-chevron-left class="size-4" />
                </button>

                <select
                    class="min-w-0 rounded-lg border-0 bg-gray-50 px-2 py-1.5 text-sm font-medium text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                    :value="cursor.getMonth()"
                    @change="changeMonth($event.target.value)"
                    aria-label="Month"
                >
                    <template x-for="(month, index) in monthNames" :key="month">
                        <option :value="index" x-text="month"></option>
                    </template>
                </select>

                <select
                    class="rounded-lg border-0 bg-gray-50 px-2 py-1.5 text-sm font-medium text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                    :value="cursor.getFullYear()"
                    @change="changeYear($event.target.value)"
                    aria-label="Year"
                >
                    <template x-for="year in years" :key="year">
                        <option :value="year" x-text="year"></option>
                    </template>
                </select>

                <button
                    type="button"
                    class="flex size-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-950 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
                    title="Next month"
                    aria-label="Next month"
                    @click="moveMonth(1)"
                >
                    <x-heroicon-m-chevron-right class="size-4" />
                </button>
            </div>

            <div class="grid grid-cols-7 text-center text-[0.6875rem] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                <span class="py-1.5">Mon</span>
                <span class="py-1.5">Tue</span>
                <span class="py-1.5">Wed</span>
                <span class="py-1.5">Thu</span>
                <span class="py-1.5">Fri</span>
                <span class="py-1.5">Sat</span>
                <span class="py-1.5">Sun</span>
            </div>

            <div class="grid grid-cols-7 gap-y-1 text-center">
                <template x-for="day in days" :key="dateKey(day)">
                    <button
                        type="button"
                        class="mx-auto flex size-8 items-center justify-center rounded-full text-xs font-medium transition"
                        :class="{
                            'bg-primary-600 text-white shadow-sm dark:bg-primary-500': isSelected(day),
                            'text-gray-950 hover:bg-gray-100 dark:text-white dark:hover:bg-white/10': !isSelected(day) && day.getMonth() === cursor.getMonth(),
                            'text-gray-300 hover:bg-gray-50 dark:text-gray-600 dark:hover:bg-white/5': !isSelected(day) && day.getMonth() !== cursor.getMonth(),
                            'ring-1 ring-inset ring-primary-500': isToday(day) && !isSelected(day),
                        }"
                        :aria-label="day.toLocaleDateString('en-GB', { dateStyle: 'full' })"
                        @click="jumpTo(day)"
                        x-text="day.getDate()"
                    ></button>
                </template>
            </div>

            <button
                type="button"
                class="mt-4 w-full rounded-lg bg-gray-50 px-3 py-2 text-sm font-semibold text-primary-600 ring-1 ring-inset ring-gray-950/5 transition hover:bg-gray-100 dark:bg-white/5 dark:text-primary-400 dark:ring-white/10 dark:hover:bg-white/10"
                @click="jumpTo(new Date())"
            >
                Go to today
            </button>
        </aside>
    </div>
</x-filament-panels::page>
