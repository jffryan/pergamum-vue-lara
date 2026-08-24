<script setup>
import { computed, onMounted, ref } from "vue";
import { useLocationsStore } from "@/stores";

/**
 * Pick a place for one copy. Options are the tree's leaves — anything not
 * further subdivided is shelvable — labelled with their ancestor path so
 * 'O1S5' reads as 'Office / O1 / O1S5'. Emits; the owning view calls the
 * API, mirroring how discard flows through the version table.
 */
const props = defineProps({
    versionId: {
        type: Number,
        required: true,
    },
    currentLocationId: {
        type: Number,
        default: null,
    },
});

const emit = defineEmits(["move", "cancel"]);

const LocationsStore = useLocationsStore();
const selectedId = ref(props.currentLocationId);

const options = computed(() =>
    LocationsStore.leaves
        .map((location) => ({
            location_id: location.location_id,
            label: LocationsStore.pathLabel(location),
        }))
        .sort((a, b) => a.label.localeCompare(b.label)),
);

onMounted(() => {
    LocationsStore.fetchAllLocations().catch((error) => {
        console.error("Error fetching locations:", error);
    });
});
</script>

<template>
    <div class="flex items-center gap-2">
        <select
            v-model="selectedId"
            class="bg-zinc-50 border border-gray-400 rounded px-2 py-1 text-black text-sm"
        >
            <option :value="null">— Unshelved —</option>
            <option
                v-for="option in options"
                :key="option.location_id"
                :value="option.location_id"
            >
                {{ option.label }}
            </option>
        </select>
        <button
            class="inline-block text-xs px-2 py-0.5 rounded border border-slate-500 bg-white text-slate-900 hover:bg-slate-900 hover:text-white transition-colors"
            @click.stop="
                emit('move', {
                    version_id: versionId,
                    location_id: selectedId,
                })
            "
        >
            Confirm
        </button>
        <button
            class="inline-block text-xs px-2 py-0.5 rounded border border-slate-500 bg-white text-slate-900 hover:bg-slate-900 hover:text-white transition-colors"
            @click.stop="emit('cancel')"
        >
            Cancel
        </button>
    </div>
</template>
