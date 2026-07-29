import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import tailwindcss from "@tailwindcss/vite";
import { existsSync, unlinkSync } from "node:fs";
import { resolve } from "node:path";

export default defineConfig({
    plugins: [
        {
            name: "remove-stale-laravel-hot-file",
            apply: "build",
            buildStart() {
                const hotFile = resolve("public/hot");

                if (existsSync(hotFile)) {
                    unlinkSync(hotFile);
                }
            },
        },
        laravel({
            input: ["resources/css/app.css", "resources/js/app.js"],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: "127.0.0.1",
        hmr: {
            host: "127.0.0.1",
        },
        watch: {
            ignored: ["**/storage/framework/views/**"],
        },
    },
});
