import { describe, it, expect, vi } from "vitest";
import {
    addVersionToBookService,
    calculateRuntime,
    fetchBookData,
    formatDateRead,
    splitAndNormalizeGenres,
} from "@/services/BookServices";
import { getOneBookFromSlug } from "@/api/BookController";
import { createVersion } from "@/api/VersionController";

// Mock API calls
vi.mock("@/api/BookController", () => ({
    getOneBookFromSlug: vi.fn(),
}));

vi.mock("@/api/VersionController", () => ({
    createVersion: vi.fn(),
}));

describe("BookServices", () => {
    // ----------------------------
    //  addVersionToBookService
    // ----------------------------
    describe("addVersionToBookService", () => {
        it("should throw an error if bookId is invalid", async () => {
            await expect(addVersionToBookService(null, {})).rejects.toThrow(
                "Invalid book ID",
            );
        });

        it("should call createVersion with correct parameters", async () => {
            createVersion.mockResolvedValue({ success: true });

            const version = { format: "Hardcover", page_count: 300 };
            const response = await addVersionToBookService(1, version);

            expect(createVersion).toHaveBeenCalledWith({
                book_id: 1,
                ...version,
            });
            expect(response).toEqual({ success: true });
        });

        it("should throw an error if the API call fails", async () => {
            createVersion.mockRejectedValue(new Error("API Error"));

            await expect(
                addVersionToBookService(1, { format: "Hardcover" }),
            ).rejects.toThrow("Failed to add version: API Error");
        });
    });

    // ----------------------------
    //  calculateRuntime
    // ----------------------------
    describe("calculateRuntime", () => {
        it("should convert minutes into hours and minutes correctly", () => {
            expect(calculateRuntime(130)).toBe("2h 10m");
            expect(calculateRuntime(45)).toBe("0h 45m");
        });

        it("should handle zero input correctly", () => {
            expect(calculateRuntime(0)).toBe("0h 0m");
        });

        it("should return '0h 0m' for invalid inputs", () => {
            expect(calculateRuntime(-5)).toBe("0h 0m");
            expect(calculateRuntime(null)).toBe("0h 0m");
            expect(calculateRuntime(undefined)).toBe("0h 0m");
            expect(calculateRuntime("string")).toBe("0h 0m");
        });
    });

    // ----------------------------
    //  fetchBookData
    // ----------------------------
    describe("fetchBookData", () => {
        it("should throw an error if slug is invalid", async () => {
            await expect(fetchBookData("")).rejects.toThrow(
                "Invalid book slug",
            );
        });

        it("should return book data if API call is successful", async () => {
            getOneBookFromSlug.mockResolvedValue({
                status: 200,
                data: { title: "Book Title" },
            });

            const data = await fetchBookData("book-slug");
            expect(data).toEqual({ title: "Book Title" });
        });

        it("should throw an error if the API call fails", async () => {
            getOneBookFromSlug.mockRejectedValue(new Error("API Error"));

            await expect(fetchBookData("book-slug")).rejects.toThrow(
                "API Error",
            );
        });

        it("should throw an error if response is invalid", async () => {
            getOneBookFromSlug.mockResolvedValue({ status: 500, data: null });

            await expect(fetchBookData("book-slug")).rejects.toThrow(
                "Failed to fetch book data: Invalid server response",
            );
        });
    });

    // ----------------------------
    //  formatDateRead
    // ----------------------------
    describe("formatDateRead", () => {
        it("should format YYYY-MM-DD into MM/DD/YY", () => {
            expect(formatDateRead("2023-07-15")).toBe("07/15/23");
            expect(formatDateRead("1999-01-01")).toBe("01/01/99");
        });

        it("should return 'Invalid Date' for malformed inputs", () => {
            expect(formatDateRead("")).toBe("Invalid Date");
            expect(formatDateRead(null)).toBe("Invalid Date");
            expect(formatDateRead("07/15/2023")).toBe("Invalid Date");
            expect(formatDateRead("random string")).toBe("Invalid Date");
        });
    });

    // ----------------------------
    //  splitAndNormalizeGenres
    // ----------------------------
    describe("splitAndNormalizeGenres", () => {
        it("should split and normalize genres correctly", () => {
            expect(splitAndNormalizeGenres("Fantasy, Horror, Sci-Fi")).toEqual([
                "fantasy",
                "horror",
                "sci-fi",
            ]);
            expect(splitAndNormalizeGenres(" Action , Adventure ,  ")).toEqual([
                "action",
                "adventure",
            ]);
        });

        it("should return an empty array if input is null or invalid", () => {
            expect(splitAndNormalizeGenres("")).toEqual([]);
            expect(splitAndNormalizeGenres(null)).toEqual([]);
            expect(splitAndNormalizeGenres([])).toEqual([]);
        });

        it("should handle extra spaces and casing correctly", () => {
            expect(
                splitAndNormalizeGenres("  Mystery , thriller , COMEDY  "),
            ).toEqual(["mystery", "thriller", "comedy"]);
        });
    });
});
