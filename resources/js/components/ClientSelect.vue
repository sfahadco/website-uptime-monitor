<script setup>
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    clients: { type: Array, required: true },
    // Total matching the current query across all pages, not just the options
    // rendered below -- that gap is what the status line has to explain.
    total: { type: Number, default: 0 },
    modelValue: { type: Number, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'search', 'retry']);

// The typed text lives here rather than in the parent: it doubles as the
// display value for the current selection, which is not something the parent's
// search term should be responsible for.
const query = ref('');
const open = ref(false);
const activeIndex = ref(-1);

const root = ref(null);
const list = ref(null);

const isSearching = computed(() => query.value.trim() !== '');

const statusMessage = computed(() => {
    if (props.loading) {
        return 'Loading clients…';
    }

    if (props.error !== null) {
        return '';
    }

    if (props.clients.length === 0) {
        return isSearching.value
            ? `No clients match “${query.value.trim()}”.`
            : 'No clients are set up yet.';
    }

    if (props.total > props.clients.length) {
        return `Showing ${props.clients.length} of ${props.total} clients — keep typing to narrow.`;
    }

    return `${props.total} client${props.total === 1 ? '' : 's'}.`;
});

function onInput(event) {
    query.value = event.target.value;
    open.value = true;
    activeIndex.value = -1;
    emit('search', query.value);
}

function openList() {
    if (open.value) {
        return;
    }

    open.value = true;
    activeIndex.value = props.clients.findIndex((client) => client.id === props.modelValue);
}

function close() {
    open.value = false;
    activeIndex.value = -1;
}

function select(client) {
    // Showing the chosen email in the input is what makes this read as a
    // select rather than a search box that happens to have results.
    query.value = client.email;
    close();
    emit('update:modelValue', client.id);
}

function move(step) {
    if (!open.value) {
        openList();

        return;
    }

    const count = props.clients.length;

    if (count === 0) {
        return;
    }

    activeIndex.value = (activeIndex.value + step + count) % count;

    nextTick(() => {
        list.value?.children[activeIndex.value]?.scrollIntoView({ block: 'nearest' });
    });
}

function onEnter() {
    if (open.value && activeIndex.value >= 0) {
        select(props.clients[activeIndex.value]);
    }
}

// focusout rather than blur: clicking an option moves focus inside the
// component, and that must not count as leaving it.
function onFocusOut(event) {
    if (!root.value?.contains(event.relatedTarget)) {
        close();
    }
}
</script>

<template>
    <section>
        <label for="client-combobox" class="block text-sm font-medium text-gray-900">
            Client
        </label>

        <div ref="root" class="relative mt-2" @focusout="onFocusOut">
            <input
                id="client-combobox"
                type="text"
                role="combobox"
                autocomplete="off"
                aria-autocomplete="list"
                aria-controls="client-listbox"
                aria-describedby="client-select-status"
                placeholder="Search or select a client…"
                class="block w-full rounded-md border border-gray-300 bg-white py-2 pr-10 pl-3 text-base text-gray-900 shadow-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 focus:outline-none"
                :value="query"
                :aria-expanded="open"
                :aria-activedescendant="activeIndex >= 0 ? `client-option-${activeIndex}` : null"
                :aria-busy="loading"
                @input="onInput"
                @focus="openList"
                @click="openList"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="onEnter"
                @keydown.esc.prevent="close"
                @keydown.tab="close"
            >

            <button
                type="button"
                tabindex="-1"
                aria-label="Toggle client list"
                class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-500 hover:text-gray-900"
                @click="open ? close() : openList()"
            >
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>

            <ul
                v-show="open"
                id="client-listbox"
                ref="list"
                role="listbox"
                class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg"
            >
                <li
                    v-for="(client, index) in clients"
                    :id="`client-option-${index}`"
                    :key="client.id"
                    role="option"
                    class="cursor-pointer px-3 py-2 text-base text-gray-900"
                    :class="{
                        'bg-gray-100': index === activeIndex,
                        'font-medium': client.id === modelValue,
                    }"
                    :aria-selected="client.id === modelValue"
                    @mousedown.prevent
                    @click="select(client)"
                    @mousemove="activeIndex = index"
                >
                    {{ client.email }}
                </li>

                <li v-if="clients.length === 0" class="px-3 py-2 text-base text-gray-600">
                    {{ loading ? 'Loading…' : 'No matching clients.' }}
                </li>
            </ul>
        </div>

        <p id="client-select-status" role="status" class="mt-2 min-h-5 text-sm text-gray-600">
            {{ statusMessage }}
        </p>

        <div v-if="error" role="alert" class="mt-2 rounded-md border border-red-300 bg-red-50 p-3">
            <p class="text-sm text-red-800">{{ error }}</p>
            <button
                type="button"
                class="mt-2 rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-800 hover:bg-red-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                @click="emit('retry')"
            >
                Retry
            </button>
        </div>
    </section>
</template>
