<script setup>
import { computed } from 'vue';

const props = defineProps({
    clients: { type: Array, required: true },
    // Total matching the current search across all pages, not just the ones
    // rendered below -- that gap is what the status line has to explain.
    total: { type: Number, default: 0 },
    search: { type: String, default: '' },
    modelValue: { type: Number, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'search', 'retry']);

const isSearching = computed(() => props.search.trim() !== '');

const hasMoreThanShown = computed(() => props.total > props.clients.length);

const statusMessage = computed(() => {
    if (props.loading) {
        return 'Loading clients…';
    }

    if (props.error !== null) {
        return '';
    }

    if (props.clients.length === 0) {
        return isSearching.value
            ? `No clients match “${props.search.trim()}”.`
            : 'No clients are set up yet.';
    }

    if (hasMoreThanShown.value) {
        return `Showing ${props.clients.length} of ${props.total} clients — search to narrow the list.`;
    }

    return `${props.total} client${props.total === 1 ? '' : 's'}.`;
});

function onChange(event) {
    emit('update:modelValue', Number(event.target.value));
}

function onSearch(event) {
    emit('search', event.target.value);
}
</script>

<template>
    <section>
        <label for="client-search" class="block text-sm font-medium text-gray-900">
            Find a client
        </label>

        <input
            id="client-search"
            type="search"
            autocomplete="off"
            placeholder="Search by email…"
            class="mt-2 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 shadow-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 focus:outline-none"
            :value="search"
            @input="onSearch"
        >

        <label for="client-select" class="mt-4 block text-sm font-medium text-gray-900">
            Client
        </label>

        <select
            id="client-select"
            class="mt-2 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 shadow-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 focus:outline-none disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500"
            :value="modelValue === null ? '' : String(modelValue)"
            :disabled="loading || error !== null || clients.length === 0"
            :aria-busy="loading"
            aria-describedby="client-select-status"
            @change="onChange"
        >
            <option value="" disabled>Select a client…</option>
            <option v-for="client in clients" :key="client.id" :value="String(client.id)">
                {{ client.email }}
            </option>
        </select>

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
