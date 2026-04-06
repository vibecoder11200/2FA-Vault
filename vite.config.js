import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import vueI18n from '@intlify/unplugin-vue-i18n/vite'
import AutoImport from 'unplugin-auto-import/vite'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import version from './vite.version'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

// Resolve 2FAuth-Components packages relative to this project's node_modules
// (installed via file: protocol in package.json)
const componentsRoot = path.resolve(__dirname, '../2FAuth-Components')

const ASSET_URL = process.env.ASSET_URL || ''

// Custom Rollup plugin to handle argon2-browser WASM file
function argon2WasmPlugin() {
    let wasmBase64 = null;

    return {
        name: 'argon2-wasm-fix',
        enforce: 'pre',
        buildStart() {
            const wasmPath = path.resolve(__dirname, 'node_modules/argon2-browser/dist/argon2.wasm');
            const wasmContent = fs.readFileSync(wasmPath);
            wasmBase64 = wasmContent.toString('base64');
        },
        resolveId(source) {
            if (source && (source.includes('argon2.wasm') || source.endsWith('.wasm'))) {
                return { id: 'argon2-wasm-virtual:' + source };
            }
            return null;
        },
        load(id) {
            if (id && id.startsWith('argon2-wasm-virtual:')) {
                return `export default "data:application/wasm;base64,${wasmBase64}"`;
            }
            return null;
        },
    };
}

export default defineConfig({
    base: `${ASSET_URL}`,
    plugins: [
        argon2WasmPlugin(),
        laravel([
            'resources/js/app.js',
        ]),
        vue({
            template: {
                transformAssetUrls: {
                    // The Vue plugin will re-write asset URLs, when referenced
                    // in Single File Components, to point to the Laravel web
                    // server. Setting this to `null` allows the Laravel plugin
                    // to instead re-write asset URLs to point to the Vite
                    // server instead.
                    base: null,

                    // The Vue plugin will parse absolute URLs and treat them
                    // as absolute paths to files on disk. Setting this to
                    // `false` will leave absolute URLs un-touched so they can
                    // reference assets in the public directory as expected.
                    includeAbsolute: false,
                },
            },
        }),
        vueI18n({
            include: 'resources/lang/*.json'
        }),
        AutoImport({
            // https://github.com/unplugin/unplugin-auto-import?tab=readme-ov-file#configuration
            include: [
                /\.[tj]sx?$/, // .ts, .tsx, .js, .jsx
                /\.vue$/,
                /\.vue\?vue/, // .vue
            ],
            imports: [
                'vue',
                'vue-router',
                'pinia',
                {
                    '@vueuse/core': [
                        'useStorage',
                        'useClipboard',
                        'useNavigatorLanguage'
                    ],
                    '@kyvg/vue3-notification': [
                        'useNotification'
                    ],
                },
            ],
            // resolvers: [
            //     ElementPlusResolver(),
            // ],
            dirs: [
                './resources/js/components/**',
                './resources/js/composables/**',
                './resources/js/layouts/**',
                './resources/js/router/**',
                './resources/js/services/**',
                './resources/js/stores/**',
            ],
            vueTemplate: true,
            vueDirectives: true,
            dts: './auto-imports.d.ts',
            viteOptimizeDeps: true,
            eslintrc: {
                enabled: true,
                filepath: './.eslintrc-auto-import.mjs',
                globalsPropValue: true, // 'readonly',
            },
        }),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
            '@2fauth/formcontrols': path.resolve(componentsRoot, 'packages/formcontrols'),
            '@2fauth/ui': path.resolve(componentsRoot, 'packages/ui'),
            '@2fauth/stores': path.resolve(componentsRoot, 'packages/stores'),
            '@2fauth/styles': path.resolve(componentsRoot, 'packages/styles'),
        },
        dedupe: [
            'pinia',
            '@kyvg/vue3-notification',
            'vue',
            'vue-router',
            'vue-i18n',
            '@vueuse/core',
            '@vueuse/components',
            'lucide-vue-next',
        ],
    },
    css: {
        preprocessorOptions: {
            scss: {
                silenceDeprecations: ['legacy-js-api'],
                api: 'modern-compiler',
                loadPaths: [
                    'node_modules',
                    path.resolve(componentsRoot, 'node_modules'),
                ],
            },
        },
    },
    build: {
        // sourcemap: true,
        commonjsOptions: {
            include: [/node_modules/],
        },
        rollupOptions: {
            output: {
                banner: '/*! 2FA-Vault version ' + version + ' - Copyright (c) 2025 */',
            },
        },
    },
    optimizeDeps: {
        exclude: ['argon2-browser'],
        esbuildOptions: {
            plugins: [{
                name: 'treat-wasm-as-asset',
                setup(build) {
                    build.onResolve({ filter: /\.wasm$/ }, args => ({
                        path: args.path,
                        namespace: 'wasm-asset',
                    }))
                    build.onLoad({ filter: /.*/, namespace: 'wasm-asset' }, async (args) => ({
                        contents: `export default new URL(${JSON.stringify(args.path)}, import.meta.url).href`,
                        loader: 'js',
                    }))
                },
            }],
        },
    },
    server: {
        port: 5173,
        strictPort: true,
        cors: true, // Configure CORS for the dev server. Pass an options object to fine tune the behavior or true to allow any origin
        // watch: {
        //     followSymlinks: false,
        // }
    }
});
