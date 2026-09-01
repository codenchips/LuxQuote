@php
    $shouldCollapseSidebar = filament()->auth()->check()
        && ! session()->has('luxquote.sidebar-default-collapsed');

    if ($shouldCollapseSidebar) {
        session()->put('luxquote.sidebar-default-collapsed', true);
    }
@endphp

@if ($shouldCollapseSidebar)
    <script>
        localStorage.setItem('isOpen', 'false')
        localStorage.setItem('isOpenDesktop', 'false')
    </script>
@endif
