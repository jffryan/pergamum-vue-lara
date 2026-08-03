<script>
/**
 * The frame every statistics widget sits in: grid placement, an optional
 * heading, the loading / unavailable states, and the footnote.
 *
 * The footnote is the shell's job rather than each widget's. `StatisticsGrid`
 * already holds the response's `meta`, so it can caveat a widget from data —
 * no widget knows it is being asterisked, and a metric that stops being an
 * estimate silently stops being footnoted.
 */

// Tailwind needs whole class names in the source, so spans are a lookup rather
// than an interpolation.
const SPANS = {
    3: "col-span-6 sm:col-span-3",
    4: "col-span-12 sm:col-span-4",
    6: "col-span-12 sm:col-span-6",
    8: "col-span-12 sm:col-span-8",
    12: "col-span-12",
};

const TONES = {
    accent: "bg-slate-600",
    muted: "bg-zinc-700",
};

export default {
    name: "WidgetShell",
    props: {
        span: {
            type: Number,
            default: 4,
        },
        tall: {
            type: Boolean,
            default: false,
        },
        tone: {
            type: String,
            default: "muted",
        },
        heading: {
            type: String,
            default: null,
        },
        footnote: {
            type: String,
            default: null,
        },
        unavailable: {
            type: Boolean,
            default: false,
        },
    },
    computed: {
        classes() {
            return [
                SPANS[this.span] ?? SPANS[4],
                TONES[this.tone] ?? TONES.muted,
                this.tall ? "sm:row-span-2" : "",
                "p-3 sm:p-4",
            ];
        },
    },
};
</script>

<template>
    <div :class="classes">
        <h3 v-if="heading" class="font-semibold mb-3">{{ heading }}</h3>

        <p v-if="unavailable" class="text-sm text-zinc-300">
            Unavailable right now.
        </p>
        <slot v-else />

        <p v-if="footnote && !unavailable" class="mt-3 text-xs text-zinc-300">
            {{ footnote }}
        </p>
    </div>
</template>
