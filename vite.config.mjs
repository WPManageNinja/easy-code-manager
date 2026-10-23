import {defineConfig} from 'vite';
import vue from '@vitejs/plugin-vue';
import AutoImport from 'unplugin-auto-import/vite';
import Components from 'unplugin-vue-components/vite';
import {ElementPlusResolver} from 'unplugin-vue-components/resolvers';
import autoprefixer from 'autoprefixer';
import path from 'path';

/*
 * One classic script and one stylesheet (plus the index.php guard below), nothing else.
 *
 * AdminMenuHandler enqueues dist/app.js as an ordinary <script> that depends on jQuery and
 * reads window.fluentSnippetAdmin from wp_localize_script, and dist/app.css next to it.
 * Library mode with the IIFE format is what produces exactly that: no ES module, no
 * chunks to resolve at runtime, no manifest for PHP to read.
 *
 *     npm run build   production, minified
 *     npm run dev     rebuilds on every save; reload wp-admin to see it
 */

/*
 * The "silence is golden" index.php every other folder in the plugin has, so the server
 * never lists dist/. Written by the build because emptyOutDir clears dist/ each time.
 */
const silenceIndex = {
    name: 'fluent-snippets-silence-index',
    generateBundle() {
        this.emitFile({type: 'asset', fileName: 'index.php', source: '<?php\n// Silence is golden.\n'});
    }
};

export default defineConfig(({mode}) => {
    const production = mode === 'production';

    return {
        plugins: [
            vue(),
            AutoImport({
                resolvers: [ElementPlusResolver()]
            }),
            Components({
                resolvers: [ElementPlusResolver()],
                directives: false,
                dts: false
            }),
            silenceIndex
        ],

        resolve: {
            alias: {
                '@': path.resolve(import.meta.dirname, 'src')
            }
        },

        // Library mode leaves process.env.NODE_ENV in the output for the consumer to
        // replace. There is no consumer after this, and Vue and Element Plus both branch
        // on it, so it is set here.
        define: {
            'process.env.NODE_ENV': JSON.stringify(production ? 'production' : 'development')
        },

        css: {
            postcss: {
                plugins: [autoprefixer]
            }
        },

        // Nothing is served from public/: the only output is the bundle.
        publicDir: false,

        build: {
            outDir: 'dist',
            emptyOutDir: true,
            minify: production,
            sourcemap: !production,
            lib: {
                entry: path.resolve(import.meta.dirname, 'src/app.js'),
                formats: ['iife'],
                name: 'FluentSnippetsApp',
                fileName: () => 'app.js',
                cssFileName: 'app'
            }
        }
    };
});
