<script setup>
import { computed, ref } from "vue";
import { useLocationsStore } from "@/stores";
import ConfirmAction from "@/components/globals/ConfirmAction.vue";
import LocationForm from "./LocationForm.vue";

/**
 * One location in the admin tree, rendered recursively (the template refers
 * to `LocationNode` — itself — for children).
 *
 * Delete is two-step in the genre-row mold: the first confirm goes without
 * `force`, so the server decides what's in the way and reports the
 * authoritative count. Shelved copies escalate to an acknowledged re-send
 * with `force` (which unshelves them); child locations are a hard stop — the
 * tree is deleted leaf-first, explicitly, never through a parent.
 */
const props = defineProps({
    location: {
        type: Object,
        required: true,
    },
});

const LocationsStore = useLocationsStore();

// null | 'edit' | 'move' | 'add'
const mode = ref(null);
const movingTo = ref(null);
const confirmingDelete = ref(false);
const deleteAcknowledged = ref(false);
const serverVersionsCount = ref(0);
const error = ref(null);
const conflict = ref(null);
const busy = ref(false);

const children = computed(() =>
    LocationsStore.childrenOf(props.location.location_id),
);

const subtreeCount = computed(() =>
    LocationsStore.subtreeIdsOf(props.location.location_id).reduce(
        (total, id) =>
            total +
            (LocationsStore.allLocations.find((l) => l.location_id === id)
                ?.versions_count ?? 0),
        0,
    ),
);

// A move target is anywhere outside this location's own subtree — moving
// under a descendant is the one way to knot the tree, and the server rejects
// it too (422 location_cycle); filtering here just keeps the option list
// honest.
const eligibleParents = computed(() => {
    const excluded = LocationsStore.subtreeIdsOf(props.location.location_id);

    return LocationsStore.allLocations
        .filter((candidate) => !excluded.includes(candidate.location_id))
        .map((candidate) => ({
            location_id: candidate.location_id,
            label: LocationsStore.pathLabel(candidate),
        }))
        .sort((a, b) => a.label.localeCompare(b.label));
});

function readError(e) {
    return (
        e.response?.data?.reason ??
        e.response?.data?.message ??
        "Something went wrong."
    );
}

function openMode(next) {
    mode.value = next;
    error.value = null;
    conflict.value = null;
    confirmingDelete.value = false;
    movingTo.value = props.location.parent_id ?? null;
}

function closePanels() {
    mode.value = null;
    error.value = null;
    conflict.value = null;
}

async function run(request) {
    busy.value = true;
    error.value = null;
    conflict.value = null;

    try {
        await request();
        closePanels();
    } catch (e) {
        if (e.response?.data?.reason_code === "location_code_taken") {
            conflict.value = e.response.data.conflict;
        } else {
            error.value = readError(e);
        }
    } finally {
        busy.value = false;
    }
}

const submitEdit = (attributes) =>
    run(() => LocationsStore.updateLocation(props.location.slug, attributes));

const submitMove = () =>
    run(() =>
        LocationsStore.updateLocation(props.location.slug, {
            parent_id: movingTo.value,
        }),
    );

const submitAddChild = (attributes) =>
    run(() =>
        LocationsStore.createLocation({
            ...attributes,
            parent_id: props.location.location_id,
        }),
    );

function startDelete() {
    closePanels();
    deleteAcknowledged.value = false;
    confirmingDelete.value = true;
}

async function confirmDelete() {
    busy.value = true;
    error.value = null;

    try {
        await LocationsStore.deleteLocation(props.location.slug, {
            force: deleteAcknowledged.value,
        });
        confirmingDelete.value = false;
    } catch (e) {
        if (e.response?.data?.reason_code === "location_in_use") {
            serverVersionsCount.value = e.response.data.versions_count;
            deleteAcknowledged.value = true;
        } else {
            // location_has_children lands here on purpose: it is not
            // forceable, so it reads as a plain refusal with the fix in it.
            confirmingDelete.value = false;
            error.value = readError(e);
        }
    } finally {
        busy.value = false;
    }
}

const deleteImpact = computed(() => {
    if (deleteAcknowledged.value) {
        return `${serverVersionsCount.value} cop(ies) are shelved here. Deleting unshelves every one of them — the copies themselves are untouched.`;
    }

    if (children.value.length > 0) {
        return `This location contains ${children.value.length} other location(s); the server will refuse until they are moved or deleted.`;
    }

    return `${props.location.versions_count} cop(ies) are shelved directly here.`;
});

const deleteConfirmLabel = computed(() =>
    deleteAcknowledged.value
        ? `Yes, unshelve ${serverVersionsCount.value} cop(ies) and delete`
        : "Delete location",
);
</script>

<template>
    <div class="mt-1">
        <div class="flex flex-wrap items-center gap-2 py-1 border-b text-sm">
            <router-link
                :to="{
                    name: 'locations.show',
                    params: { slug: location.slug },
                }"
                class="font-semibold hover:underline"
            >
                {{ location.code }}
            </router-link>
            <span v-if="location.name" class="text-gray-600">{{
                location.name
            }}</span>
            <span
                class="inline-block px-1.5 rounded bg-slate-200 text-slate-700 text-xs uppercase tracking-wide"
            >
                {{ location.kind }}
            </span>
            <span class="text-gray-500">{{ subtreeCount }} copies</span>

            <span class="ml-auto flex gap-3">
                <button
                    type="button"
                    class="underline"
                    @click="mode === 'edit' ? closePanels() : openMode('edit')"
                >
                    Edit
                </button>
                <button
                    type="button"
                    class="underline"
                    @click="mode === 'move' ? closePanels() : openMode('move')"
                >
                    Move
                </button>
                <button
                    type="button"
                    class="underline"
                    @click="mode === 'add' ? closePanels() : openMode('add')"
                >
                    Add child
                </button>
                <button
                    type="button"
                    class="underline text-red-700"
                    :disabled="busy"
                    @click="startDelete"
                >
                    Delete
                </button>
            </span>
        </div>

        <div v-if="mode === 'edit'" class="p-2 bg-slate-100 rounded-b">
            <LocationForm
                :initial="location"
                submit-label="Save"
                :busy="busy"
                :id-prefix="`edit-${location.location_id}`"
                @submit="submitEdit"
                @cancel="closePanels"
            />
        </div>

        <div
            v-if="mode === 'move'"
            class="p-2 bg-slate-100 rounded-b flex items-center gap-2 text-sm"
        >
            <label :for="`move-${location.location_id}`">Move under</label>
            <select
                :id="`move-${location.location_id}`"
                v-model="movingTo"
                class="border px-2 py-1 bg-transparent"
            >
                <option :value="null">— top level —</option>
                <option
                    v-for="parent in eligibleParents"
                    :key="parent.location_id"
                    :value="parent.location_id"
                >
                    {{ parent.label }}
                </option>
            </select>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="busy"
                @click="submitMove"
            >
                Move
            </button>
            <button type="button" class="underline" @click="closePanels">
                Cancel
            </button>
        </div>

        <div v-if="mode === 'add'" class="p-2 bg-slate-100 rounded-b">
            <LocationForm
                submit-label="Add child"
                :busy="busy"
                :id-prefix="`add-${location.location_id}`"
                @submit="submitAddChild"
                @cancel="closePanels"
            />
        </div>

        <ConfirmAction
            v-if="confirmingDelete"
            :title="`Delete &quot;${location.name || location.code}&quot;?`"
            :impact="deleteImpact"
            :confirm-label="deleteConfirmLabel"
            :busy="busy"
            @confirm="confirmDelete"
            @cancel="confirmingDelete = false"
        />

        <p v-if="conflict" class="text-red-600 mt-1 text-sm">
            The code "{{ conflict.code }}" is already taken by
            {{ LocationsStore.pathLabel(conflict) }}.
        </p>
        <p v-if="error" class="text-red-600 mt-1 text-sm">{{ error }}</p>

        <!-- Nodes nest in the DOM, so a constant margin here is one level of
             indent — no depth arithmetic to get wrong. -->
        <div class="ml-6">
            <LocationNode
                v-for="child in children"
                :key="child.location_id"
                :location="child"
            />
        </div>
    </div>
</template>
