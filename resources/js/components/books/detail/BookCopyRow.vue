<script setup>
import { computed, ref } from "vue";
import {
    copyState,
    formatLabel,
    lengthLabel,
    loanLabel,
    locationLabel,
} from "@/utils/bookDetail";
import { monthYear } from "@/utils/libraryList";
import ShelfPicker from "@/components/locations/ShelfPicker.vue";

/**
 * One copy of a book: what it is, how long it is, where it is, and the
 * transitions it can make from here (shelve/move, lend/return,
 * discard/restore). A loan sits beside the shelf rather than replacing it —
 * the shelf is where a lent copy goes back to.
 *
 * This replaces the six-column `VersionTable`, whose columns were mostly
 * empty — "Audio Runtime" was blank on every physical book and "Nickname" on
 * almost everything. A copy states what it has and says nothing about what it
 * doesn't, the same rule the library row follows.
 */
const props = defineProps({
    version: {
        type: Object,
        required: true,
    },
    // How many times this book has been read on *this* copy. The read history
    // is the same set of rows seen from the other side; saying so here is what
    // connects the two sections.
    readCount: {
        type: Number,
        default: 0,
    },
});

const emit = defineEmits(["discard", "restore", "move", "lend", "return"]);

const isPickingShelf = ref(false);
const isConfirmingDiscard = ref(false);
const discardedAt = ref("");
const isLending = ref(false);
const loanedTo = ref("");
const loanedAt = ref("");

// One form at a time; the action links hide while one is open.
const isFormOpen = computed(
    () => isPickingShelf.value || isConfirmingDiscard.value || isLending.value,
);

const state = computed(() => copyState(props.version));
const isDiscarded = computed(() => state.value === "discarded");

const format = computed(() => formatLabel(props.version));
const length = computed(() => lengthLabel(props.version));
const shelf = computed(() => locationLabel(props.version));
const loan = computed(() => loanLabel(props.version));
const nickname = computed(() => props.version.nickname?.trim() || "");

// "read twice" beside the copy, not a number in a column — the sentence is
// what makes it obvious these are the same reads listed above.
const readLabel = computed(() => {
    const count = props.readCount;

    if (!count) return "";
    if (count === 1) return "read once";
    if (count === 2) return "read twice";

    return `read ${count} times`;
});

const discardLabel = computed(() => {
    // `discarded_at` is optional provenance; `is_discarded` is the state. A
    // copy got rid of before we tracked dates says so rather than showing a
    // blank that reads as an error.
    const when = monthYear(props.version.discarded_at);

    return when ? `Discarded ${when}` : "Discarded, date unknown";
});

const action = "underline hover:no-underline";

const openDiscardForm = () => {
    discardedAt.value = "";
    isConfirmingDiscard.value = true;
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

// Local today, not `toISOString()` — that is UTC and turns into tomorrow on
// an evening in the Americas.
const today = () => {
    const now = new Date();
    const pad = (n) => String(n).padStart(2, "0");

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};

// Unlike a discard, a loan is almost always recorded as it happens, so the
// date starts at today. Both fields may be blanked.
const openLendForm = () => {
    loanedTo.value = "";
    loanedAt.value = today();
    isLending.value = true;
};

const confirmLend = () => {
    emit("lend", {
        version_id: props.version.version_id,
        loaned_to: loanedTo.value.trim() || null,
        loaned_at: loanedAt.value || null,
    });
    isLending.value = false;
};

const confirmMove = (payload) => {
    emit("move", payload);
    isPickingShelf.value = false;
};
</script>

<template>
    <li class="py-2.5">
        <div
            class="grid gap-x-6 gap-y-0.5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-baseline"
        >
            <div class="min-w-0">
                <p
                    class="mb-0 font-medium"
                    :class="isDiscarded ? 'text-zinc-500' : ''"
                >
                    {{ format
                    }}<span v-if="length" class="font-normal text-zinc-500">
                        · {{ length }}</span
                    >
                </p>
                <p
                    v-if="nickname || readLabel"
                    class="mb-0 text-sm text-zinc-500"
                >
                    <span v-if="nickname">“{{ nickname }}”</span
                    ><span v-if="nickname && readLabel"> · </span
                    ><span v-if="readLabel">{{ readLabel }}</span>
                </p>
            </div>

            <div class="text-sm sm:text-right">
                <p class="mb-0">
                    <span v-if="isDiscarded" class="text-zinc-500">{{
                        discardLabel
                    }}</span>
                    <router-link
                        v-else-if="version.location"
                        :to="{
                            name: 'locations.show',
                            params: { slug: version.location.slug },
                        }"
                        :class="action"
                        >{{ shelf }}</router-link
                    >
                    <span v-else class="text-zinc-400">Unshelved</span>
                </p>
                <!-- The shelf above stays: it is where the copy goes back. -->
                <p v-if="loan" class="mb-0 text-amber-700">{{ loan }}</p>
                <p v-if="!isFormOpen" class="mb-0 text-zinc-500">
                    <template v-if="isDiscarded">
                        <button
                            type="button"
                            :class="action"
                            @click="emit('restore', version.version_id)"
                        >
                            Restore
                        </button>
                    </template>
                    <template v-else>
                        <button
                            type="button"
                            :class="action"
                            @click="isPickingShelf = true"
                        >
                            {{ version.location ? "Move" : "Shelve" }}
                        </button>
                        ·
                        <button
                            v-if="version.is_on_loan"
                            type="button"
                            :class="action"
                            @click="emit('return', version.version_id)"
                        >
                            Returned
                        </button>
                        <button
                            v-else
                            type="button"
                            :class="action"
                            @click="openLendForm"
                        >
                            Lend
                        </button>
                        ·
                        <button
                            type="button"
                            :class="action"
                            @click="openDiscardForm"
                        >
                            Discard
                        </button>
                    </template>
                </p>
            </div>
        </div>

        <div v-if="isPickingShelf" class="mt-2">
            <ShelfPicker
                :version-id="version.version_id"
                :current-location-id="version.location_id"
                @move="confirmMove"
                @cancel="isPickingShelf = false"
            />
        </div>

        <div v-if="isLending" class="mt-2 text-sm">
            <label
                :for="`loaned_to_${version.version_id}`"
                class="mr-2 text-zinc-500"
                >Lent to</label
            >
            <input
                :id="`loaned_to_${version.version_id}`"
                v-model="loanedTo"
                type="text"
                maxlength="255"
                placeholder="optional"
                class="mr-2 rounded border border-zinc-400 bg-zinc-50 px-2 py-1"
                @keydown.enter="confirmLend"
            />
            <label
                :for="`loaned_at_${version.version_id}`"
                class="mr-2 text-zinc-500"
                >on</label
            >
            <input
                :id="`loaned_at_${version.version_id}`"
                v-model="loanedAt"
                type="date"
                class="mr-2 rounded border border-zinc-400 bg-zinc-50 px-2 py-1"
            />
            <button
                type="button"
                :class="['mr-2', action]"
                @click="confirmLend"
            >
                Confirm
            </button>
            <button
                type="button"
                :class="[action, 'text-zinc-500']"
                @click="isLending = false"
            >
                Cancel
            </button>
        </div>

        <div v-if="isConfirmingDiscard" class="mt-2 text-sm">
            <label
                :for="`discarded_at_${version.version_id}`"
                class="mr-2 text-zinc-500"
                >Discarded on (blank if unknown)</label
            >
            <input
                :id="`discarded_at_${version.version_id}`"
                v-model="discardedAt"
                type="date"
                class="mr-2 rounded border border-zinc-400 bg-zinc-50 px-2 py-1"
            />
            <button
                type="button"
                :class="['mr-2', action]"
                @click="confirmDiscard"
            >
                Confirm
            </button>
            <button
                type="button"
                :class="[action, 'text-zinc-500']"
                @click="isConfirmingDiscard = false"
            >
                Cancel
            </button>
        </div>
    </li>
</template>
