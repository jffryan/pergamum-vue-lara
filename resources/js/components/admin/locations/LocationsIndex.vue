<script setup>
import { onMounted, ref } from "vue";
import { useLocationsStore } from "@/stores";
import LocationNode from "./LocationNode.vue";
import LocationForm from "./LocationForm.vue";

const LocationsStore = useLocationsStore();

const loading = ref(true);
const loadError = ref(null);
const addingRoot = ref(false);
const busy = ref(false);
const error = ref(null);
const conflict = ref(null);

onMounted(async () => {
    try {
        // Forced: every count on this screen is a decision input, and the
        // cached tree may predate moves made elsewhere.
        await LocationsStore.fetchAllLocations({ force: true });
    } catch (e) {
        loadError.value = "Unable to load locations at this time.";
    } finally {
        loading.value = false;
    }
});

async function submitAddRoot(attributes) {
    busy.value = true;
    error.value = null;
    conflict.value = null;

    try {
        await LocationsStore.createLocation(attributes);
        addingRoot.value = false;
    } catch (e) {
        if (e.response?.data?.reason_code === "location_code_taken") {
            conflict.value = e.response.data.conflict;
        } else {
            error.value =
                e.response?.data?.reason ??
                e.response?.data?.message ??
                "An error occurred.";
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section>
        <h2 class="text-xl mb-1">Locations</h2>
        <p class="text-sm text-gray-600 mb-4">
            Rooms hold bookcases, bookcases hold shelves — but the depth is
            yours to choose. Copies are shelved from each book's page; deleting
            a location never touches the copies themselves.
        </p>

        <p v-if="loading">Loading locations...</p>
        <p v-else-if="loadError" class="text-red-600">{{ loadError }}</p>

        <template v-else>
            <p v-if="!LocationsStore.roots.length" class="text-gray-500">
                No locations yet — add a room to start.
            </p>

            <LocationNode
                v-for="root in LocationsStore.roots"
                :key="root.location_id"
                :location="root"
            />

            <div class="mt-4">
                <button
                    v-if="!addingRoot"
                    type="button"
                    class="btn btn-primary"
                    @click="addingRoot = true"
                >
                    Add top-level location
                </button>
                <LocationForm
                    v-else
                    :initial="{
                        code: '',
                        name: '',
                        kind: 'room',
                        ordinal: null,
                    }"
                    submit-label="Add location"
                    :busy="busy"
                    id-prefix="add-root"
                    @submit="submitAddRoot"
                    @cancel="addingRoot = false"
                />
                <p v-if="conflict" class="text-red-600 mt-1 text-sm">
                    The code "{{ conflict.code }}" is already taken by
                    {{ LocationsStore.pathLabel(conflict) }}.
                </p>
                <p v-if="error" class="text-red-600 mt-1 text-sm">
                    {{ error }}
                </p>
            </div>
        </template>
    </section>
</template>
