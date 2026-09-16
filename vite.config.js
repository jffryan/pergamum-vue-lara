import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";
import { fileURLToPath, URL } from "node:url";
import { readFileSync } from "node:fs";
import parseChangelogVersion from "./resources/js/utils/changelogVersion";

const port = Number(process.env.VITE_PORT || 5173);

// Vitest loads this same config, and laravel-vite-plugin hard-refuses to
// initialise when it sees CI env vars ("You should not run the Vite HMR server
// in CI environments"). The tests never touch the manifest or the dev server,
// so drop the plugin for test runs rather than bypassing the guard — the guard
// is right about `npm run dev`, it just can't tell a test run apart from one.
const isTest = Boolean(process.env.VITEST);

// The version the SPA shows is the newest `## [x.y.z]` entry in CHANGELOG.md,
// read here at build time — a release is recorded once, in the changelog, and
// the home page follows. A changelog with no entry yet builds as "dev".
const appVersion =
    parseChangelogVersion(
        readFileSync(
            fileURLToPath(new URL("./CHANGELOG.md", import.meta.url)),
            "utf8",
        ),
    ) ?? "dev";

export default defineConfig({
    define: {
        __APP_VERSION__: JSON.stringify(appVersion),
    },
    // `@/…` resolved before this was written down; declaring it keeps that
    // true on purpose rather than by accident.
    resolve: {
        alias: {
            "@": fileURLToPath(new URL("./resources/js", import.meta.url)),
        },
    },
    plugins: [
        vue(),
        ...(isTest
            ? []
            : [
                  laravel({
                      input: ["resources/css/app.css", "resources/js/app.js"],
                      refresh: true,
                  }),
              ]),
    ],
    server: {
        host: true,
        port,
        strictPort: true,
        hmr: {
            host: "localhost",
            port: port,
        },
    },
});
