<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    url: { type: String, default: null },
});

const emit = defineEmits(['confirm', 'close']);

const dialogEl = ref(null);

watch(
    () => props.url,
    (url) => {
        const dialog = dialogEl.value;

        if (!dialog) {
            return;
        }

        if (url && !dialog.open) {
            dialog.showModal();
        } else if (!url && dialog.open) {
            dialog.close();
        }
    },
    { flush: 'post' },
);

function onContinue() {
    if (!props.url) {
        return;
    }

    emit('confirm');
    dialogEl.value?.close();
}

function onDismiss() {
    dialogEl.value?.close();
}

function onClose() {
    emit('close');
}
</script>

<template>
    <dialog
        ref="dialogEl"
        aria-labelledby="visit-dialog-title"
        aria-describedby="visit-dialog-message"
        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-xl border border-gray-200 bg-white p-0 text-gray-900 shadow-xl backdrop:bg-gray-900/60"
        @close="onClose"
        @click.self="onDismiss"
    >
        <div class="p-5 sm:p-6">
            <h2 id="visit-dialog-title" class="text-lg font-semibold">
                Leave this site?
            </h2>

            <p id="visit-dialog-message" class="mt-2 text-sm leading-relaxed break-words text-gray-700">You are about to visit {{ url }}. Do you want to continue?</p>

            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    autofocus
                    class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-900 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
                    @click="onDismiss"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center justify-center rounded-md bg-gray-900 px-4 text-sm font-medium text-white hover:bg-gray-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
                    @click="onContinue"
                >
                    Continue
                </button>
            </div>
        </div>
    </dialog>
</template>
