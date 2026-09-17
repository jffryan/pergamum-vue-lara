<script setup>
/**
 * One read: when, what you thought of it, and which copy you read it on.
 *
 * The copy is the part the old page dropped. `read_instances.version_id` has
 * always been in the payload, but the read-history panel rendered the
 * *format* name under the heading "Version" — so two paperbacks looked
 * identical, and a read on a copy since given away looked like a read of the
 * one still on the shelf. The row now names the copy and says when it is gone.
 */
defineProps({
    // A row from `readsFor()` — already joined to its copy and carrying the
    // gap since the previous read.
    read: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <li
        class="grid gap-x-6 gap-y-0.5 py-2.5 sm:grid-cols-[9rem_5rem_minmax(0,1fr)] sm:items-baseline"
    >
        <div class="tabular-nums">
            <span v-if="read.dateLabel">{{ read.dateLabel }}</span>
            <span v-else class="text-zinc-400">Date unknown</span>
        </div>

        <div class="text-sm tabular-nums">
            <span v-if="read.rating != null">★ {{ read.rating }}</span>
            <span v-else class="text-zinc-400">Unrated</span>
        </div>

        <div class="min-w-0 text-sm text-zinc-500">
            <span v-if="read.copyLabel">{{ read.copyLabel }}</span
            ><span v-if="read.copyIsDiscarded" class="text-zinc-400">
                (no longer owned)</span
            ><span v-if="read.copyLabel && read.gap"> · </span
            ><span v-if="read.gap">{{ read.gap }}</span>
        </div>
    </li>
</template>
