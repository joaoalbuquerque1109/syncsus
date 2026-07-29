import { spawn, spawnSync } from "node:child_process";
import process from "node:process";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    "../..",
);
const publicDirectory = path.join(root, "public");
const port = process.env.E2E_PORT || "8010";
const baseURL = process.env.E2E_BASE_URL || `http://127.0.0.1:${port}`;
let server;
let resultCode;

async function waitUntilReady() {
    for (let attempt = 0; attempt < 60; attempt += 1) {
        try {
            const response = await fetch(`${baseURL}/health/live`);
            if (response.ok) return;
        } catch {
            // The process is still starting.
        }
        await new Promise((resolve) => setTimeout(resolve, 250));
    }
    throw new Error(`Servidor de teste nao iniciou em ${baseURL}.`);
}

function stopServer() {
    if (!server || server.killed) return;
    if (process.platform === "win32") {
        spawnSync("taskkill", ["/pid", String(server.pid), "/T", "/F"], {
            stdio: "ignore",
        });
    } else {
        server.kill("SIGTERM");
    }
}

try {
    if (!process.env.E2E_BASE_URL) {
        server = spawn(
            "php",
            [
                "-S",
                `127.0.0.1:${port}`,
                "../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php",
            ],
            { cwd: publicDirectory, stdio: "ignore" },
        );
        await waitUntilReady();
    }

    const playwrightCli = path.join(
        root,
        "node_modules",
        "@playwright",
        "test",
        "cli.js",
    );
    const result = spawnSync(process.execPath, [playwrightCli, "test"], {
        cwd: root,
        env: { ...process.env, E2E_BASE_URL: baseURL },
        stdio: "inherit",
    });
    resultCode = result.status ?? 1;
} finally {
    stopServer();
}

process.exit(resultCode ?? 1);
