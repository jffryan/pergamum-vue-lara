<script setup>
import { computed, onMounted, ref } from "vue";
import { useLocationsStore } from "@/stores";

/**
 * Pick a place for one copy. Options are the tree's leaves — anything not
 * further subdivided is shelvable — labelled with their ancestor path so
 * 'O1S5' reads as 'Office / O1 / O1S5'. Emits; the owning view calls the
 * API, mirroring how discard flows through the book page's copy row.
 *
 * `BookCopyRow` is the only consumer, which is why the controls are styled as
 * that page's quiet text links rather than as standalone buttons.
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
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <select
            v-model="selectedId"
            class="rounded border border-zinc-400 bg-zinc-50 px-2 py-1 text-black"
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
            type="button"
            class="underline hover:no-underline"
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
            type="button"
            class="text-zinc-500 underline hover:no-underline"
            @click.stop="emit('cancel')"
        >
            Cancel
        </button>
    </div>
</template>
