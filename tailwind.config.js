/** @type {import('tailwindcss').Config} */

/*
 * The tokens are copied from FluentCart by way of FluentAuth rather than approximated, so
 * a user moving between the Fluent plugins is looking at the same greys, the same spacing
 * steps and the same radii. If that palette changes there, it should be copied again
 * rather than drifted towards.
 */
const colors = require('./src/styles/tokens/color');
const spacing = require('./src/styles/tokens/spacing');
const borderRadius = require('./src/styles/tokens/borderRadius');
const fontSize = require('./src/styles/tokens/fontSize');

/*
 * The colours the app actually paints with, named for what they are for.
 *
 * Each one is a CSS variable rather than a value, because it has two values - see
 * src/styles/_theme.scss, where both themes are declared. That is the whole mechanism:
 * `@apply bg-surface` is correct in light and dark, `@apply bg-white` is correct in one
 * of them.
 *
 * The ramps above are still here and still used for anything that does not change between
 * themes - a language badge, a brand colour, a shadow. Reach for these first.
 */
const themed = {
    // Surfaces, from the page up: the page itself, a card on it, a well in the card.
    surface: 'var(--fsnip-surface)',
    'surface-sunk': 'var(--fsnip-surface-sunk)',
    'surface-raised': 'var(--fsnip-surface-raised)',

    // Rules and outlines.
    hairline: 'var(--fsnip-border)',
    'hairline-strong': 'var(--fsnip-border-strong)',

    // Text, from the loudest to the quietest.
    'ink-head': 'var(--fsnip-heading)',
    ink: 'var(--fsnip-text)',
    'ink-mid': 'var(--fsnip-text-mid)',
    'ink-light': 'var(--fsnip-text-light)',
    'ink-link': 'var(--fsnip-link)',

    // The brand colour, what goes on top of it, and a tint of it.
    accent: 'var(--fsnip-accent)',
    'accent-on': 'var(--fsnip-accent-contrast)',
    'accent-wash': 'var(--fsnip-accent-wash)',

    // Statuses: a band's fill and border, a chip's fill, and the text for all three.
    'danger-wash': 'var(--fsnip-danger-wash)',
    'danger-bg': 'var(--fsnip-danger-bg)',
    'danger-line': 'var(--fsnip-danger-line)',
    'danger-fg': 'var(--fsnip-danger-fg)',

    'caution-wash': 'var(--fsnip-warning-wash)',
    'caution-bg': 'var(--fsnip-warning-bg)',
    'caution-line': 'var(--fsnip-warning-line)',
    'caution-fg': 'var(--fsnip-warning-fg)',

    'ok-wash': 'var(--fsnip-success-wash)',
    'ok-bg': 'var(--fsnip-success-bg)',
    'ok-line': 'var(--fsnip-success-line)',
    'ok-fg': 'var(--fsnip-success-fg)',

    'quiet-bg': 'var(--fsnip-neutral-bg)',
    'quiet-fg': 'var(--fsnip-neutral-fg)'
};

module.exports = {
    darkMode: ['selector', '.fluent_theme_dark'],

    /*
     * Every utility is scoped under the app's own root element. This runs inside wp-admin
     * next to whatever else is installed, so utilities must not escape into the
     * surrounding page.
     */
    important: '#fluent_snippets_app',

    content: [
        './src/**/*.{vue,js}'
    ],

    corePlugins: {
        // WordPress supplies its own base styles; resetting them would break wp-admin.
        preflight: false
    },

    theme: {
        extend: {
            colors: {...colors, ...themed},
            borderRadius: borderRadius,
            borderWidth: {
                '0.5': '.5px'
            },
            screens: {
                '1xl': '1360px'
            }
        },
        fontFamily: {
            display: ['Inter'],
            body: ['Inter']
        },
        spacing: spacing,
        fontSize: fontSize
    },

    plugins: []
};
