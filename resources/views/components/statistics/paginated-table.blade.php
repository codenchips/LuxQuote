@props(['count'])

@php
    $rowCount = (int) $count;
    $pageCount = max(1, (int) ceil($rowCount / 10));
@endphp

<div
    class="stats-paginated"
    x-data="{
        page: 1,
        perPage: 10,
        total: {{ $rowCount }},
        pages: {{ $pageCount }},
        visible(index) { return index >= (this.page - 1) * this.perPage && index < this.page * this.perPage },
    }"
>
    {{ $slot }}

    @if($rowCount > 10)
        <nav class="stats-pagination" aria-label="Table pagination">
            <span class="stats-muted">
                Showing <span x-text="((page - 1) * perPage) + 1">1</span>–<span x-text="Math.min(page * perPage, total)">{{ min(10, $rowCount) }}</span> of {{ number_format($rowCount) }}
            </span>
            <div class="stats-pagination-actions">
                <button type="button" class="stats-page-btn" x-on:click="page = 1" x-bind:disabled="page === 1" aria-label="First page">«</button>
                <button type="button" class="stats-page-btn" x-on:click="page--" x-bind:disabled="page === 1">Previous</button>
                <span class="stats-page-position">Page <span x-text="page">1</span> of {{ number_format($pageCount) }}</span>
                <button type="button" class="stats-page-btn" x-on:click="page++" x-bind:disabled="page === pages">Next</button>
                <button type="button" class="stats-page-btn" x-on:click="page = pages" x-bind:disabled="page === pages" aria-label="Last page">»</button>
            </div>
        </nav>
    @endif
</div>
