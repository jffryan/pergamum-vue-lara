<template>
    <div
        class="relative"
        @focusin="focused = true"
        @focusout="focused = false"
    >
        <div
            class="bg-white flex flex-wrap gap-1.5 border-b border-zinc-400 p-2 cursor-text min-h-[2.5rem]"
            @click="focusInput"
        >
            <span
                v-for="(genre, idx) in modelValue"
                :key="idx"
                class="inline-flex items-center gap-1 bg-zinc-200 text-zinc-700 text-sm px-2.5 py-0.5 rounded-full capitalize"
            >
                {{ genre.name }}
                <button
                    type="button"
                    @click.stop="removeGenre(idx)"
                    class="text-zinc-500 hover:text-zinc-800 leading-none"
                >×</button>
            </span>
            <input
                ref="input"
                v-model="inputText"
                type="text"
                class="flex-1 min-w-[8rem] bg-transparent outline-none text-sm py-0.5 capitalize"
                placeholder="Type a genre..."
                @keydown.enter.prevent="handleEnter"
                @keydown.down.prevent="moveDown"
                @keydown.up.prevent="moveUp"
                @keydown.backspace="handleBackspace"
                @blur="commitInput"
            />
        </div>
        <ul
            v-if="focused && suggestions.length"
            class="absolute top-full z-10 w-full bg-white border border-zinc-200 rounded-b shadow-sm"
        >
            <li
                v-for="(genre, idx) in suggestions"
                :key="genre.genre_id"
                @mousedown.prevent="selectSuggestion(genre)"
                :class="[
                    'px-3 py-1.5 text-sm text-zinc-500 cursor-pointer capitalize',
                    idx === activeIndex ? 'bg-zinc-200' : 'hover:bg-zinc-100',
                ]"
            >
                {{ genre.name }}
            </li>
        </ul>
    </div>
</template>

<script>
import { useGenreStore } from "@/stores";
import { getAllGenres } from "@/api/GenresController";

export default {
    name: "GenreTagInput",
    props: {
        modelValue: {
            type: Array,
            default: () => [],
        },
    },
    emits: ["update:modelValue"],
    setup() {
        const GenreStore = useGenreStore();
        return { GenreStore };
    },
    data() {
        return {
            inputText: "",
            focused: false,
            activeIndex: -1,
        };
    },
    computed: {
        suggestions() {
            const query = this.inputText.trim();
            if (query.length < 3) return [];
            const regex = new RegExp(query, "i");
            return this.GenreStore.allGenres
                .filter((g) => regex.test(g.name))
                .slice(0, 3);
        },
    },
    watch: {
        inputText(val) {
            this.activeIndex = -1;
            if (!val.includes(",")) return;
            const parts = val.split(",");
            const toCommit = parts.slice(0, -1);
            toCommit.forEach((part) => {
                const name = part.trim().toLowerCase();
                if (name) this.addGenre(name);
            });
            this.inputText = parts[parts.length - 1].trimStart();
        },
        suggestions(val) {
            if (this.activeIndex >= val.length) {
                this.activeIndex = val.length - 1;
            }
        },
    },
    async mounted() {
        if (!this.GenreStore.allGenres.length) {
            const res = await getAllGenres();
            this.GenreStore.setAllGenres(res.data);
        }
    },
    methods: {
        addGenre(name) {
            const normalized = name.toLowerCase();
            this.$emit("update:modelValue", [
                ...this.modelValue,
                { name: normalized, genre_id: null },
            ]);
        },
        selectSuggestion(genre) {
            this.$emit("update:modelValue", [
                ...this.modelValue,
                { name: genre.name, genre_id: genre.genre_id },
            ]);
            this.inputText = "";
            this.activeIndex = -1;
        },
        removeGenre(idx) {
            const updated = [...this.modelValue];
            updated.splice(idx, 1);
            this.$emit("update:modelValue", updated);
        },
        handleEnter() {
            if (this.activeIndex >= 0) {
                this.selectSuggestion(this.suggestions[this.activeIndex]);
            } else {
                this.commitInput();
            }
        },
        moveDown() {
            if (!this.suggestions.length) return;
            if (this.activeIndex < this.suggestions.length - 1) {
                this.activeIndex++;
            }
        },
        moveUp() {
            if (this.activeIndex > 0) {
                this.activeIndex--;
            } else {
                this.activeIndex = -1;
            }
        },
        commitInput() {
            const name = this.inputText.trim().toLowerCase();
            if (name) {
                this.addGenre(name);
            }
            this.inputText = "";
        },
        handleBackspace() {
            if (this.inputText === "" && this.modelValue.length > 0) {
                this.removeGenre(this.modelValue.length - 1);
            }
        },
        focusInput() {
            this.$refs.input.focus();
        },
    },
};
</script>
