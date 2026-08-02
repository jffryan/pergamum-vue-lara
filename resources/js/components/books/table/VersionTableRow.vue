<script setup>
import { ref } from "vue";
import { calculateRuntime } from "@/services/BookServices";

const props = defineProps({
    version: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(["discard", "restore"]);

const isConfirmingDiscard = ref(false);
const discardedAt = ref("");

const openDiscardForm = () => {
    discardedAt.value = "";
    isConfirmingDiscard.value = true;
};

const cancelDiscard = () => {
    isConfirmingDiscard.value = false;
};

// An empty date is the expected case for anything discarded before we started
// tracking it — send null rather than blocking on a date we can't recover.
const confirmDiscard = () => {
    emit("discard", {
        version_id: props.version.version_id,
        discarded_at: discardedAt.value || null,
    });
    isConfirmingDiscard.value = false;
};
</script>

<template>
    <div>
        <div class="grid grid-cols-12">
            <div class="col-span-3 p-2">
                {{ version.format.name }}
            </div>
            <div class="col-span-2 p-2">
                {{ version.page_count }}
            </div>
            <div v-if="version.audio_runtime" class="col-span-2 p-2">
                {{ calculateRuntime(version.audio_runtime) }}
            </div>
            <div v-else class="col-span-2 p-2"></div>
            <div class="col-span-3 p-2">
                {{ version.nickname }}
            </div>
            <div class="col-span-2 p-2">
                <div v-if="version.is_discarded">
                    <p class="font-bold">Discarded</p>
                    <p class="text-sm">
                        {{ version.discarded_at || "Date unknown" }}
                    </p>
                    <button
                        class="btn-inline"
                        @click.stop="emit('restore', version.version_id)"
                    >
                        Restore
                    </button>
                </div>
                <button
                    v-else-if="!isConfirmingDiscard"
                    class="btn-inline"
                    @click.stop="openDiscardForm"
                >
                    Discard
                </button>
            </div>
        </div>
        <div v-if="isConfirmingDiscard" class="p-2 border-t border-slate-400">
            <label
                :for="`discarded_at_${version.version_id}`"
                class="block mb-1 text-sm font-bold"
                >Discarded on (leave blank if unknown)</label
            >
            <input
                :id="`discarded_at_${version.version_id}`"
                type="date"
                v-model="discardedAt"
                class="bg-zinc-50 border border-gray-400 rounded px-2 py-1 mr-2 text-black"
            />
            <button class="btn-inline mr-2" @click.stop="confirmDiscard">
                Confirm
            </button>
            <button class="btn-inline" @click.stop="cancelDiscard">
                Cancel
            </button>
        </div>
    </div>
</template>
