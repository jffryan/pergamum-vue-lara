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

// The row itself is clickable, so status actions need to read as buttons rather
// than as the status value.
const actionButtonClass =
    "inline-block text-xs px-2 py-0.5 rounded border border-slate-500 bg-white text-slate-900 hover:bg-slate-900 hover:text-white transition-colors";

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
                    <span
                        class="inline-block px-2 py-0.5 rounded text-xs font-bold uppercase tracking-wide bg-slate-800 text-slate-100"
                    >
                        Discarded
                    </span>
                    <p class="mb-1 text-sm">
                        {{ version.discarded_at || "Date unknown" }}
                    </p>
                    <button
                        :class="actionButtonClass"
                        @click.stop="emit('restore', version.version_id)"
                    >
                        Restore
                    </button>
                </div>
                <div v-else>
                    <span
                        class="inline-block px-2 py-0.5 rounded text-xs font-bold uppercase tracking-wide bg-emerald-100 text-emerald-800"
                    >
                        Active
                    </span>
                    <button
                        v-if="!isConfirmingDiscard"
                        :class="['mt-1 block', actionButtonClass]"
                        @click.stop="openDiscardForm"
                    >
                        Discard
                    </button>
                </div>
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
            <button
                :class="['mr-2', actionButtonClass]"
                @click.stop="confirmDiscard"
            >
                Confirm
            </button>
            <button :class="actionButtonClass" @click.stop="cancelDiscard">
                Cancel
            </button>
        </div>
    </div>
</template>
