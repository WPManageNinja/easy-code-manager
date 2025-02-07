// webpack.mix.js
let mix = require('laravel-mix');
const AutoImport = require('unplugin-auto-import/webpack').default;
const { ElementPlusResolver } = require('unplugin-vue-components/resolvers');
const VueComponents = require('unplugin-vue-components/webpack').default;
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

mix.js('src/app.js', 'dist').vue({ version: 3 })
    .copy('src/images', 'dist/images')
    .setPublicPath('dist');
