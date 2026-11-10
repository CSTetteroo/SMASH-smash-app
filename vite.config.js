import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

/*
 * SMASH type system (see DESIGN.md):
 *  - Inter            → body copy and UI (BUas 2026 uses Inter)
 *  - Source Serif 4   → page & section headings (stand-in for BUas' Signifier / FF Scala)
 *  - Barlow Condensed → scores, ratings, ranks, big numbers and the SMASH wordmark
 *
 * Every family is registered twice: the latin subset (preloaded where it matters for the first
 * paint) and the latin-ext subset (never preloaded). Member names are international (Ł, ş, ğ, ő …)
 * and the browser only downloads latin-ext when such a glyph is actually on the page.
 * Metric-optimised fallbacks need fontaine, which is not installed.
 */
const family = (name, { alias, preload = false, ...options }) => {
    const slug = alias ?? name.toLowerCase().replace(/\s+/g, '-');

    return [
        bunny(name, {
            ...options,
            alias: slug,
            variable: `--font-${slug}`,
            subsets: ['latin'],
            preload,
            optimizedFallbacks: false,
        }),
        bunny(name, {
            ...options,
            alias: `${slug}-ext`,
            variable: `--font-${slug}-ext`,
            subsets: ['latin-ext'],
            preload: false,
            optimizedFallbacks: false,
        }),
    ];
};

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            fonts: [
                ...family('Inter', {
                    weights: [400, 500, 600, 700],
                    preload: [{ weight: 400 }, { weight: 600 }],
                }),
                ...family('Barlow Condensed', {
                    weights: [600, 700, 800, 900],
                    preload: [{ weight: 800 }],
                }),
                ...family('Barlow Condensed', {
                    alias: 'barlow-condensed-italic',
                    weights: [800],
                    styles: ['italic'],
                }),
                ...family('Source Serif 4', {
                    weights: [400, 600],
                    preload: [{ weight: 400 }],
                }),
                ...family('Source Serif 4', {
                    alias: 'source-serif-4-italic',
                    weights: [400],
                    styles: ['italic'],
                }),
            ],
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
