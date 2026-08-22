<script setup>
defineProps({
    websites: { type: Array, required: true },
    loading: { type: Boolean, default: false },
    error: { type: String, default: null },
    hasSelection: { type: Boolean, default: false },
});

const emit = defineEmits(['visit', 'retry']);

</script>

<template>
    <section aria-labelledby="websites-heading" :aria-busy="loading">
        <h2 id="websites-heading" class="text-lg font-semibold text-gray-900">
            Websites
        </h2>

        <p v-if="loading" role="status" class="mt-2 text-sm text-gray-600">
            Loading websites…
        </p>

        <div v-else-if="error" role="alert" class="mt-2 rounded-md border border-red-300 bg-red-50 p-3">
            <p class="text-sm text-red-800">{{ error }}</p>
            <button
                type="button"
                class="mt-3 inline-flex min-h-11 items-center justify-center rounded-md border border-red-300 bg-white px-4 text-sm font-medium text-red-800 hover:bg-red-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                @click="emit('retry')"
            >
                Try again
            </button>
        </div>

        <p v-else-if="!hasSelection" class="mt-2 text-sm text-gray-600">
            Select a client to see their websites.
        </p>

        <p v-else-if="websites.length === 0" class="mt-2 text-sm text-gray-600">
            This client has no websites being monitored.
        </p>

        <ul v-else class="mt-3 list-disc space-y-2 pl-5 marker:text-gray-400">
            <li v-for="website in websites" :key="website.id">
                <a
                    :href="website.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="break-all text-blue-700 underline underline-offset-2 hover:text-blue-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
                    @click.prevent="emit('visit', website.url)"
                >{{ website.url }}</a>
            </li>
        </ul>
    </section>
</template>
