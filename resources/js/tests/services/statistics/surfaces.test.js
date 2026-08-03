import { describe, it, expect } from "vitest";
import surfaces from "@/services/statistics/surfaces";
import { widgetKeys } from "@/services/statistics/widgetRegistry";
import { footnoteFor } from "@/services/statistics/footnotes";
import {
    formatValue,
    formatDuration,
    formatCompact,
} from "@/services/statistics/formatters";

/**
 * The metric keys the backend registry serves, mirrored from
 * `config/statistics.php`. A typo in a surface config would otherwise ship as
 * a quietly missing card — or, since unknown keys are a 422, as a whole
 * surface that fails to load.
 */
const BACKEND_METRICS = [
    "totalBooks",
    "totalBooksRead",
    "percentageOfBooksRead",
    "totalReads",
    "readsByYear",
    "uniqueBooksReadByYear",
    "pagesReadByYear",
    "audioRuntimeByYear",
    "estimatedTotalPagesByYear",
    "averageRating",
    "ratingDistribution",
    "newestBooks",
    "totalItems",
    "completedCount",
    "completedPercent",
    "totalPages",
    "genreBreakdown",
];

const allEntries = () =>
    Object.entries(surfaces).flatMap(([key, surface]) =>
        surface.widgets.map((entry) => ({ surface: key, entry })),
    );

describe("statistics surfaces", () => {
    it("name only registered widgets", () => {
        allEntries().forEach(({ surface, entry }) => {
            expect(widgetKeys(), `${surface}.${entry.id}`).toContain(
                entry.widget,
            );
        });
    });

    it("name only metrics the backend serves", () => {
        allEntries().forEach(({ surface, entry }) => {
            Object.values(entry.metrics ?? {}).forEach((metric) => {
                expect(BACKEND_METRICS, `${surface}.${entry.id}`).toContain(
                    metric,
                );
            });
        });
    });

    it("give every widget a unique id within its surface", () => {
        Object.entries(surfaces).forEach(([key, surface]) => {
            const ids = surface.widgets.map((entry) => entry.id);
            expect(new Set(ids).size, key).toBe(ids.length);
        });
    });

    it("declare a scope type", () => {
        Object.values(surfaces).forEach((surface) => {
            expect(surface.scope?.type).toBeTruthy();
        });
    });
});

describe("footnotes", () => {
    const meta = {
        catalogWide: ["totalBooks"],
        shelfScoped: [],
        estimated: {
            estimatedTotalPagesByYear: {
                pagesPerAudioMinute: 0.5,
                from: ["pagesReadByYear", "audioRuntimeByYear"],
                converted: "audioRuntimeByYear",
            },
        },
        failed: [],
    };

    it("names the estimated portion when the converted series is loaded", () => {
        const note = footnoteFor(
            { metrics: { series: "estimatedTotalPagesByYear" } },
            meta,
            { audioRuntimeByYear: [{ year: 2024, total: 600 }] },
        );

        expect(note).toBe("Includes ~300 pages estimated from 10h of audio.");
    });

    it("falls back to the rate when the inputs were not requested", () => {
        const note = footnoteFor(
            { metrics: { series: "estimatedTotalPagesByYear" } },
            meta,
            {},
        );

        expect(note).toBe("Audio counts as pages at ~30 pages an hour.");
    });

    it("caveats catalogue-wide metrics", () => {
        const note = footnoteFor(
            { metrics: { value: "totalBooks" } },
            meta,
            {},
        );

        expect(note).toBe("Counts the whole catalogue, not only your books.");
    });

    it("says nothing about a plain user-scoped metric", () => {
        expect(
            footnoteFor({ metrics: { value: "totalReads" } }, meta, {}),
        ).toBeNull();
    });

    it("lets a surface override or suppress the note", () => {
        const entry = { metrics: { value: "totalBooks" } };

        expect(footnoteFor({ ...entry, footnote: "Mine." }, meta, {})).toBe(
            "Mine.",
        );
        expect(footnoteFor({ ...entry, footnote: false }, meta, {})).toBeNull();
    });
});

describe("formatters", () => {
    it("renders an em dash for missing values", () => {
        expect(formatValue(null)).toBe("—");
        expect(formatValue(undefined, "percent")).toBe("—");
    });

    it("keeps zero as a real number", () => {
        expect(formatValue(0)).toBe("0");
    });

    it("formats minutes as hours and minutes", () => {
        expect(formatDuration(3500)).toBe("58h 20m");
        expect(formatDuration(120)).toBe("2h");
        expect(formatDuration(45)).toBe("45m");
    });

    it("compacts thousands", () => {
        expect(formatCompact(999)).toBe("999");
        expect(formatCompact(12300)).toBe("12.3k");
        expect(formatCompact(2000)).toBe("2k");
    });
});
