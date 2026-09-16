import { describe, it, expect } from "vitest";
import parseChangelogVersion from "@/utils/changelogVersion";

describe("parseChangelogVersion", () => {
    it("returns the version of the first release heading", () => {
        const text = [
            "# Changelog",
            "",
            "All notable changes.",
            "",
            "## [0.1.18] - 2026-09-15",
            "",
            "- Something.",
            "",
            "## [0.1.17] - 2026-08-22",
        ].join("\n");

        expect(parseChangelogVersion(text)).toBe("0.1.18");
    });

    it("ignores headings that are not release entries", () => {
        const text =
            "# Changelog\n\n## Unreleased\n\n## [1.2.3] - 2026-01-01\n";

        expect(parseChangelogVersion(text)).toBe("1.2.3");
    });

    it("does not match a bracketed version mid-line", () => {
        const text = "see [0.9.9] for details\n\n## [2.0.0] - 2026-01-01\n";

        expect(parseChangelogVersion(text)).toBe("2.0.0");
    });

    it("returns null when there is no release heading", () => {
        expect(parseChangelogVersion("# Changelog\n")).toBeNull();
        expect(parseChangelogVersion("")).toBeNull();
        expect(parseChangelogVersion(undefined)).toBeNull();
    });
});
