<div
    x-data="luxQuoteSectionMenu(@js(\App\Filament\Support\SectionNavigation::popupMenus()))"
    x-on:open-section-menu.window="show($event.detail)"
    x-on:schedule-section-menu-close.window="scheduleClose()"
    x-on:keydown.escape.window="close(true)"
    x-on:resize.window="close()"
    x-on:scroll.window="close()"
    x-on:livewire:navigating.window="close()"
    x-effect="document.body.classList.toggle('section-menu-open', open)"
>
    <nav
        id="lux-section-menu"
        role="menu"
        x-cloak
        x-show="open"
        x-ref="menu"
        class="lux-section-menu"
        x-bind:style="`left: ${left}px; top: ${top}px`"
        x-bind:aria-label="menu?.label ?? 'Section pages'"
        x-on:mouseenter="cancelClose()"
        x-on:mouseleave="scheduleClose()"
        x-on:focusin="cancelClose()"
        x-on:focusout="scheduleClose()"
        x-on:click.outside="if (!anchor?.contains($event.target)) close()"
        x-on:keydown.arrow-down.prevent="moveFocus(1)"
        x-on:keydown.arrow-up.prevent="moveFocus(-1)"
    >
        <div class="lux-section-menu-heading" x-text="menu?.label"></div>
        <template x-for="link in menu?.links ?? []" :key="link.url">
            <a role="menuitem" :href="link.url" :aria-current="link.active ? 'page' : null" :class="{ 'lux-section-menu-active': link.active }" x-on:click="close()">
                <span x-text="link.label"></span>
            </a>
        </template>
    </nav>
</div>
