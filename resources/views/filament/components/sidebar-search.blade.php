<div
    x-data="sidebarSearch()"
    x-show="$store.sidebar.isOpen"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 -translate-y-1"
    x-transition:enter-end="opacity-100 translate-y-0"
    class="mb-3 px-1.5"
>
    <div class="group relative flex items-center">
        {{-- Search Icon --}}
        <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-gray-400 transition-colors duration-200 group-focus-within:text-[#0aa0c2] dark:text-gray-500 dark:group-focus-within:text-[#38bdf8]">
            <svg class="h-4 w-4 transition-transform duration-200 group-focus-within:scale-110" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
        </div>

        {{-- Search Input --}}
        <input
            x-ref="searchInput"
            x-model="searchQuery"
            @input="filterMenu(searchQuery)"
            @keydown.window.prevent.slash="if (document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') { $refs.searchInput.focus(); }"
            @keydown.window.cmd.k.prevent="$refs.searchInput.focus()"
            @keydown.window.ctrl.k.prevent="$refs.searchInput.focus()"
            @keydown.escape="clearSearch()"
            type="text"
            placeholder="Quick search menu..."
            class="sidebar-search-box w-full rounded-xl border border-gray-200/90 bg-white/80 py-2 pe-14 ps-9 text-xs font-medium text-gray-800 placeholder-gray-400 backdrop-blur-md shadow-xs transition-all duration-200 hover:border-gray-300 hover:bg-white focus:border-[#0aa0c2] focus:bg-white focus:outline-none focus:ring-3 focus:ring-[#0aa0c2]/20 dark:border-gray-800/80 dark:bg-gray-900/60 dark:text-gray-100 dark:placeholder-gray-500 dark:hover:border-gray-700 dark:hover:bg-gray-900/90 dark:focus:border-[#38bdf8] dark:focus:bg-gray-900 dark:focus:ring-[#38bdf8]/20"
        />

        {{-- Right Action Area: Match Counter Badge / Clear Button / Keyboard Shortcut Badge --}}
        <div class="absolute inset-y-0 end-0 flex items-center pe-2 gap-1.5">
            {{-- Live Match Count Badge --}}
            <span
                x-show="searchQuery.trim().length > 0"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-90"
                x-transition:enter-end="opacity-100 scale-100"
                class="inline-flex items-center rounded-md bg-sky-50 px-1.5 py-0.5 text-[0.65rem] font-semibold text-sky-700 dark:bg-sky-950/60 dark:text-sky-300 border border-sky-200/60 dark:border-sky-800/50"
            >
                <span x-text="matchCount"></span>
            </span>

            {{-- Clear Button --}}
            <button
                x-show="searchQuery.length > 0"
                @click="clearSearch()"
                type="button"
                title="Clear search (Esc)"
                class="rounded-md p-1 text-gray-400 hover:bg-gray-200/70 hover:text-gray-700 transition-colors dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200"
            >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>

            {{-- Clean OS-Agnostic Shortcut Badge --}}
            <div x-show="searchQuery.length === 0" class="pointer-events-none hidden items-center gap-1 sm:flex">
                <kbd
                    x-text="shortcutLabel"
                    class="rounded-md border border-gray-200/90 bg-gray-100/90 px-1.5 py-0.5 font-mono text-[0.65rem] font-semibold text-gray-500 shadow-2xs dark:border-gray-700/80 dark:bg-gray-800/90 dark:text-gray-400"
                ></kbd>
            </div>
        </div>
    </div>
</div>

<script>
    function sidebarSearch() {
        return {
            searchQuery: '',
            matchCount: 0,
            shortcutLabel: navigator.platform.toUpperCase().indexOf('MAC') >= 0 ? '⌘K' : '/',
            filterMenu(query) {
                const q = query.toLowerCase().trim();
                const groups = document.querySelectorAll('.fi-sidebar-nav-groups > .fi-sidebar-group');
                let count = 0;

                groups.forEach(group => {
                    let groupHasVisibleItems = false;
                    const items = group.querySelectorAll('.fi-sidebar-item');

                    items.forEach(item => {
                        const labelEl = item.querySelector('.fi-sidebar-item-label');
                        const text = labelEl ? labelEl.textContent.toLowerCase() : item.textContent.toLowerCase();

                        if (!q || text.includes(q)) {
                            item.style.display = '';
                            groupHasVisibleItems = true;
                            if (q) count++;
                        } else {
                            item.style.display = 'none';
                        }
                    });

                    // Show or hide group container based on matches
                    if (!q || groupHasVisibleItems) {
                        group.style.display = '';

                        // Expand group items container if query is active
                        if (q) {
                            const itemsCtn = group.querySelector('.fi-sidebar-group-items');
                            if (itemsCtn) {
                                itemsCtn.style.display = 'block';
                            }
                        }
                    } else {
                        group.style.display = 'none';
                    }
                });

                this.matchCount = count;
            },
            clearSearch() {
                this.searchQuery = '';
                this.matchCount = 0;
                this.filterMenu('');
                if (this.$refs.searchInput) {
                    this.$refs.searchInput.blur();
                }
            }
        }
    }
</script>
