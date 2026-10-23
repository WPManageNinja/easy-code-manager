// webpack.mix.js
let mix = require('laravel-mix');
const AutoImport = require('unplugin-auto-import/webpack').default;
const { ElementPlusResolver } = require('unplugin-vue-components/resolvers');
const VueComponents = require('unplugin-vue-components/webpack');
var path = require('path');

mix.webpackConfig({
    module: {
        rules: [{
            test: /\.mjs$/,
            resolve: { fullySpecified: false },
            include: /node_modules/,
            type: "javascript/auto"
        }]
    },
    plugins: [
        AutoImport({
            resolvers: [ElementPlusResolver()],
        }),
        VueComponents({
            resolvers: [ElementPlusResolver()],
            directives: false
        }),
    ],
    resolve: {
        extensions: ['.js', '.vue', '.json'],
        alias: {
            '@': path.resolve(__dirname, 'src')
        }
    }
});

// Tailwind is a PostCSS plugin, so it has to be handed to Mix rather than imported from
// the stylesheet. Its own config (tailwind.config.js) scopes every utility it emits to
// #fluent_snippets_app so nothing escapes into the rest of wp-admin.
mix.options({
    postCss: [
        require('tailwindcss'),
        require('autoprefixer')
    ]
});

// runtimeOnly: every template is an SFC compiled at build time. Without it Mix aliases the
// full build, and after the 2026-10 dependency update its template compiler was no longer
// shaken out (about 80 KB).
mix.js('src/app.js', 'dist').vue({ version: 3, runtimeOnly: true })
    .copy('src/images', 'dist/images')
    .setPublicPath('dist');
