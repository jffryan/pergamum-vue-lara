import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";
import { fileURLToPath, URL } from "node:url";

const port = Number(process.env.VITE_PORT || 5173)

export default defineConfig({
    // `@/…` resolved before this was written down; declaring it keeps that
    // true on purpose rather than by accident.
    resolve: {
        alias: {
            "@": fileURLToPath(new URL("./resources/js", import.meta.url)),
        },
    },
    plugins: [
        vue(),
        laravel({
            input: ["resources/css/app.css", "resources/js/app.js"],
            refresh: true,
        }),
    ],
    server: {
        host: true,
        port,
        strictPort: true,
        hmr: {
            host: 'localhost',
            port: port,
        },
    },
});
