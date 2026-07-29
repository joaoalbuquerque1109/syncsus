import js from "@eslint/js";
import globals from "globals";

export default [
    {
        ignores: ["public/build/**", "node_modules/**", "vendor/**"],
    },
    js.configs.recommended,
    {
        files: ["resources/js/**/*.js", "tests/Browser/**/*.js", "*.config.js"],
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",
            globals: {
                ...globals.browser,
                ...globals.node,
            },
        },
        rules: {
            "no-unused-vars": ["error", { argsIgnorePattern: "^_" }],
        },
    },
];
