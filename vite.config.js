import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";

const port = Number(process.env.VITE_PORT || 5173)

export default defineConfig({
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
