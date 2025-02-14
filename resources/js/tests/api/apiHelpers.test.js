// eslint-disable-next-line
import { describe, it, expect, vi } from "vitest";
import axios from "axios";
import { makeRequest, buildUrl } from "@/api/apiHelpers";

// Mock axios
vi.mock("axios");

describe("buildUrl", () => {
    it("should return the correct URL when only entity is provided", () => {
        expect(buildUrl("users")).toBe("/api/users/");
    });

    it("should return the correct URL when entity and id are provided", () => {
        expect(buildUrl("users", 123)).toBe("/api/users/123");
    });

    it("should handle different entity names correctly", () => {
        expect(buildUrl("posts", 456)).toBe("/api/posts/456");
    });
});

describe("makeRequest", () => {
    it("should call axios with the correct config", async () => {
        axios.mockResolvedValue({ data: "response data" });

        const response = await makeRequest("GET", "/api/users", null, {
            search: "john",
        });

        expect(axios).toHaveBeenCalledWith({
            method: "GET",
            url: "/api/users",
            data: null,
            params: { search: "john" },
        });

        expect(response).toEqual({ data: "response data" });
    });

    it("should handle different HTTP methods", async () => {
        axios.mockResolvedValue({ data: "posted" });

        const response = await makeRequest("POST", "/api/users", {
            name: "John",
        });

        expect(axios).toHaveBeenCalledWith({
            method: "POST",
            url: "/api/users",
            data: { name: "John" },
            params: undefined,
        });

        expect(response).toEqual({ data: "posted" });
    });

    it("should work without data and params", async () => {
        axios.mockResolvedValue({ data: "no params" });

        const response = await makeRequest("DELETE", "/api/users/123");

        expect(axios).toHaveBeenCalledWith({
            method: "DELETE",
            url: "/api/users/123",
            data: undefined,
            params: undefined,
        });

        expect(response).toEqual({ data: "no params" });
    });
});
