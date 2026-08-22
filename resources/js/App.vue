<script setup>
import { onMounted, ref } from 'vue';
import ClientSelect from './components/ClientSelect.vue';
import VisitConfirmDialog from './components/VisitConfirmDialog.vue';
import WebsiteList from './components/WebsiteList.vue';

const SEARCH_DEBOUNCE_MS = 300;

const clients = ref([]);
const clientsTotal = ref(0);
const clientSearch = ref('');
const clientsLoading = ref(false);
const clientsError = ref(null);

const selectedClientId = ref(null);
const websites = ref([]);
const websitesLoading = ref(false);
const websitesError = ref(null);

const dialogTarget = ref(null);

// Both lists guard against a slow reply overwriting a newer one: each request
// takes an id, and only the newest id is allowed to write to the refs.
let clientsRequestId = 0;
let clientsController = null;
let searchTimer = null;

let websitesRequestId = 0;
let websitesController = null;

async function fetchJson(url, signal) {
    let response;

    try {
        response = await fetch(url, { signal, headers: { Accept: 'application/json' } });
    } catch (cause) {
        if (cause.name === 'AbortError') {
            throw cause;
        }

        console.error(`[uptime] network failure requesting ${url}`, cause);
        throw new Error('We could not reach the server. Check your connection and try again.');
    }

    if (!response.ok) {
        console.error(`[uptime] HTTP ${response.status} ${response.statusText} from ${url}`);

        throw new Error(
            response.status === 404
                ? 'That client could not be found. It may have been removed \u2014 pick another one.'
                : `The server returned an error (${response.status}). Please try again.`,
        );
    }

    try {
        return await response.json();
    } catch (cause) {
        console.error(`[uptime] malformed JSON from ${url}`, cause);
        throw new Error('The server sent a response we could not read. Please try again.');
    }
}

async function loadClients() {
    const requestId = ++clientsRequestId;

    clientsController?.abort();
    const controller = new AbortController();
    clientsController = controller;

    clientsLoading.value = true;
    clientsError.value = null;

    const term = clientSearch.value.trim();
    const url = term === ''
        ? '/api/clients'
        : `/api/clients?search=${encodeURIComponent(term)}`;

    try {
        const { data, meta } = await fetchJson(url, controller.signal);

        if (requestId !== clientsRequestId) {
            return;
        }

        clients.value = data;
        clientsTotal.value = meta.total;
    } catch (error) {
        if (error.name === 'AbortError' || requestId !== clientsRequestId) {
            return;
        }

        clients.value = [];
        clientsTotal.value = 0;
        clientsError.value = error.message;
    } finally {
        if (requestId === clientsRequestId) {
            clientsLoading.value = false;
        }
    }
}

function onSearchClients(term) {
    clientSearch.value = term;

    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadClients, SEARCH_DEBOUNCE_MS);
}

function onRetryClients() {
    // Drop any pending debounce, or it fires a second request right after.
    clearTimeout(searchTimer);
    loadClients();
}

async function loadWebsites(clientId) {
    if (clientId === null) {
        return;
    }

    const requestId = ++websitesRequestId;

    websitesController?.abort();
    const controller = new AbortController();
    websitesController = controller;

    websitesLoading.value = true;
    websitesError.value = null;
    websites.value = [];

    try {
        const data = await fetchJson(
            `/api/clients/${encodeURIComponent(clientId)}/websites`,
            controller.signal,
        );

        if (requestId !== websitesRequestId) {
            return;
        }

        websites.value = data;
    } catch (error) {
        if (error.name === 'AbortError' || requestId !== websitesRequestId) {
            return;
        }

        websitesError.value = error.message;
    } finally {
        if (requestId === websitesRequestId) {
            websitesLoading.value = false;
        }
    }
}

function onSelectClient(clientId) {
    selectedClientId.value = clientId;
    loadWebsites(clientId);
}

function onRetryWebsites() {
    loadWebsites(selectedClientId.value);
}

function onVisit(url) {
    dialogTarget.value = url;
}

function onConfirmVisit() {
    window.open(dialogTarget.value, '_blank', 'noopener');
}

function onDialogClose() {
    dialogTarget.value = null;
}

onMounted(loadClients);
</script>

<template>
    <div class="mx-auto w-full max-w-2xl px-4 py-8 sm:px-6 sm:py-12">
        <header class="mb-8">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">
                Website uptime monitor
            </h1>
            <p class="mt-2 text-sm text-gray-600">
                Pick a client to see the websites being monitored for them.
            </p>
        </header>

        <ClientSelect
            :clients="clients"
            :total="clientsTotal"
            :model-value="selectedClientId"
            :loading="clientsLoading"
            :error="clientsError"
            @update:model-value="onSelectClient"
            @search="onSearchClients"
            @retry="onRetryClients"
        />

        <WebsiteList
            class="mt-8"
            :websites="websites"
            :loading="websitesLoading"
            :error="websitesError"
            :has-selection="selectedClientId !== null"
            @visit="onVisit"
            @retry="onRetryWebsites"
        />

        <VisitConfirmDialog
            :url="dialogTarget"
            @confirm="onConfirmVisit"
            @close="onDialogClose"
        />
    </div>
</template>
