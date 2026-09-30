<script setup>
import { computed, onMounted } from "vue";
import { useConfigStore, useLocationsStore } from "@/stores";
import { expectsAudioRuntime, expectsPageCount } from "@/utils/formats";

/**
 * The fields for one copy being created: format, the length that format
 * carries, shelf, nickname. Used by the new-book page and the add-a-copy
 * page, so a copy is entered the same way through either door; the rules
 * and payload are `utils/copyForm.js`.
 *
 * Controlled: `copy` comes in, a whole new object goes out on every edit
 * (`v-model:copy`), and the owning page decides when to validate.
 */
const props = defineProps({
    copy: {
        type: Object,
        required: true,
    },
    // `validateCopy`'s `{ field: message }`.
    errors: {
        type: Object,
        default: () => ({}),
    },
    // Keeps label/input ids unique if two of these ever share a page.
    idPrefix: {
        type: String,
        default: "copy",
    },
});

const emit = defineEmits(["update:copy"]);

const ConfigStore = useConfigStore();
const LocationsStore = useLocationsStore();

const formats = computed(() => ConfigStore.books.formats);
// The same path-labelled leaves `ShelfPicker` offers on the book page.
const shelves = computed(() => LocationsStore.shelfOptions);

const showsPageCount = computed(() =>
    expectsPageCount(formats.value, props.copy.format_id),
);
const showsAudioRuntime = computed(() =>
    expectsAudioRuntime(formats.value, props.copy.format_id),
);

onMounted(() => {
    ConfigStore.checkForFormats();
    LocationsStore.fetchAllLocations().catch((error) => {
        console.error("Error fetching locations:", error);
    });
});

const set = (field, value) =>
    emit("update:copy", { ...props.copy, [field]: value });

// Length inputs are text fields (for the shared underline style); strip
// anything that isn't a digit as it's typed, and write the stripped value
// back so the box never shows what the model doesn't hold.
const setDigits = (field, event) => {
    const digits = event.target.value.replace(/[^0-9]/g, "");
    const input = event.target;
    input.value = digits;
    set(field, digits);
};

const id = (name) => `${props.idPrefix}-${name}`;

const label = "mb-1 block text-sm text-zinc-500";
const fieldError = "mb-0 mt-1 text-sm text-red-600";
const select =
    "w-full rounded-md border border-zinc-300 bg-white px-2 py-2 focus:border-zinc-600 focus:outline-none";
</script>

<template>
    <div>
        <div class="mb-5 grid gap-x-6 gap-y-5 sm:grid-cols-2">
            <div>
                <label :for="id('format')" :class="label">Format</label>
                <select
                    :id="id('format')"
                    :value="copy.format_id ?? ''"
                    :class="[select, 'capitalize']"
                    @change="set('format_id', Number($event.target.value))"
                >
                    <option value="" disabled>Choose…</option>
                    <option
                        v-for="format in formats"
                        :key="format.format_id"
                        :value="format.format_id"
                    >
                        {{ format.name }}
                    </option>
                </select>
                <p v-if="errors.format_id" :class="fieldError">
                    {{ errors.format_id }}
                </p>
            </div>

            <div v-if="showsPageCount">
                <label :for="id('pages')" :class="label">Pages</label>
                <input
                    :id="id('pages')"
                    :value="copy.page_count"
                    type="text"
                    inputmode="numeric"
                    @input="setDigits('page_count', $event)"
                />
                <p v-if="errors.page_count" :class="fieldError">
                    {{ errors.page_count }}
                </p>
            </div>

            <div v-if="showsAudioRuntime">
                <label :for="id('runtime')" :class="label"
                    >Runtime (minutes)</label
                >
                <input
                    :id="id('runtime')"
                    :value="copy.audio_runtime"
                    type="text"
                    inputmode="numeric"
                    @input="setDigits('audio_runtime', $event)"
                />
                <p v-if="errors.audio_runtime" :class="fieldError">
                    {{ errors.audio_runtime }}
                </p>
            </div>
        </div>

        <div class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
            <div>
                <label :for="id('shelf')" :class="label">Shelf</label>
                <!-- Option values reach the DOM as strings: map the ""
                     "Unshelved" sentinel back to null, anything else to its id.
                     The format select above uses the same sentinel. -->
                <select
                    :id="id('shelf')"
                    :value="copy.location_id ?? ''"
                    :class="select"
                    @change="
                        set(
                            'location_id',
                            $event.target.value === ''
                                ? null
                                : Number($event.target.value),
                        )
                    "
                >
                    <option value="">— Unshelved —</option>
                    <option
                        v-for="shelf in shelves"
                        :key="shelf.location_id"
                        :value="shelf.location_id"
                    >
                        {{ shelf.label }}
                    </option>
                </select>
            </div>

            <div>
                <label :for="id('nickname')" :class="label"
                    >Nickname (optional)</label
                >
                <input
                    :id="id('nickname')"
                    :value="copy.nickname"
                    type="text"
                    placeholder="e.g. signed, first edition"
                    @input="set('nickname', $event.target.value)"
                />
            </div>
        </div>
    </div>
</template>
