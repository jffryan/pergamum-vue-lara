<template>
    <div>
        <div class="grid grid-cols-12 bg-slate-900 text-slate-200 rounded-t-md">
            <div
                v-for="column in columns"
                :key="column.name"
                :class="[
                    'p-2 flex justify-between align-bottom',
                    `col-span-${column.span}`,
                ]"
            >
                {{ column.name }}
            </div>
        </div>
        <div v-for="(version, idx) in versions" :key="version.version_id">
            <VersionTableRow
                :version="version"
                :class="[
                    idx % 2 === 0 ? 'bg-slate-200' : 'bg-slate-300',
                    version.is_discarded ? 'opacity-60' : '',
                    ' text-black cursor-pointer hover:bg-slate-600 hover:text-white',
                ]"
                @discard="$emit('discard', $event)"
                @restore="$emit('restore', $event)"
                @move="$emit('move', $event)"
            />
        </div>
    </div>
</template>

<script>
import VersionTableRow from "@/components/books/table/VersionTableRow.vue";

export default {
    name: "VersionTable",
    props: {
        versions: {
            type: Array,
            required: true,
        },
    },
    components: {
        VersionTableRow,
    },
    emits: ["discard", "restore", "move"],
    data() {
        return {
            columns: [
                {
                    name: "Format",
                    span: 2,
                },
                {
                    name: "Page Count",
                    span: 2,
                },
                {
                    name: "Audio Runtime",
                    span: 2,
                    clickHandler: this.toggleSortByFormat,
                    ascending: "sortByFormat",
                    descending: "sortByFormatDesc",
                },
                {
                    name: "Nickname",
                    span: 2,
                    clickHandler: null,
                    ascending: null,
                    descending: null,
                },
                {
                    name: "Location",
                    span: 2,
                    clickHandler: null,
                    ascending: null,
                    descending: null,
                },
                {
                    name: "Status",
                    span: 2,
                    clickHandler: null,
                    ascending: null,
                    descending: null,
                },
            ],
        };
    },
};
</script>
