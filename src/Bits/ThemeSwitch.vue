<script type="text/babel">
/*
 * Light, dark, or whatever the machine is set to.
 *
 * This implements the Fluent Theme Mode specification, which is shared by every plugin in
 * the ecosystem: the key is `fluent_theme_mode`, the class is `fluent_theme_dark`, and it
 * goes on <body>. That is the whole point of the spec - somebody who has chosen dark in
 * FluentCart has chosen it here too, and neither plugin has to know the other is
 * installed. Switching here moves an open tab of any of them.
 *
 * `system` is stored resolved, as `system:dark` or `system:light`, so the script that runs
 * before paint (see AdminMenuHandler::printThemeClass) can apply the right theme to the
 * first frame instead of waiting for matchMedia and then repainting.
 */
const KEY = 'fluent_theme_mode';
const DARK_CLASS = 'fluent_theme_dark';
const MODES = ['light', 'dark', 'system'];

const icon = paths =>
    `<svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18">${paths}</svg>`;

const icons = {
    light: icon('<path d="M10 14.5A4.5 4.5 0 1 1 14.5 10 4.5 4.5 0 0 1 10 14.5Zm0-1.5a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm-.75-11.25h1.5V4h-1.5V1.75ZM9.25 16h1.5v2.25h-1.5V16ZM3.64 4.7l1.06-1.06 1.59 1.59-1.06 1.06L3.64 4.7Zm10.07 10.07 1.06-1.06 1.59 1.59-1.06 1.06-1.59-1.59Zm1.59-11.13 1.06 1.06-1.59 1.59-1.06-1.06 1.59-1.59ZM5.23 13.71l1.06 1.06-1.59 1.59-1.06-1.06 1.59-1.59ZM18.25 9.25v1.5H16v-1.5h2.25ZM4 9.25v1.5H1.75v-1.5H4Z"/>'),
    dark: icon('<path d="M8.5 6.25a6.24 6.24 0 0 0 9 5.68V10a7.5 7.5 0 1 1-7.5-7.5h.08A6.23 6.23 0 0 0 8.5 6.25ZM4 10a6 6 0 0 0 11.3 2.82 7.5 7.5 0 0 1-8.12-8.12A6 6 0 0 0 4 10Z"/>'),
    system: icon('<path d="M6.875 4.375V6.25H4.375V7.5h2.5v1.875h1.25v-5h-1.25Zm2.5 3.125h6.25V6.25h-6.25V7.5Zm3.75 3.125V12.5h2.5v1.25h-2.5v1.875h-1.25v-5h1.25Zm-2.5 3.125H4.375V12.5h6.25v1.25Z"/>')
};

export default {
    name: 'ThemeSwitch',
    data() {
        return {
            icons,
            mode: 'system',
            /* What `system` currently resolves to, so the trigger shows the real state. */
            resolved: 'light',
            channel: null,
            media: null
        }
    },
    computed: {
        options() {
            return [
                {value: 'light', label: this.$t('Light')},
                {value: 'dark', label: this.$t('Dark')},
                {value: 'system', label: this.$t('System')}
            ];
        },
        /* The trigger wears the mode you picked, not the one it resolved to. */
        triggerIcon() {
            return icons[this.mode] || icons.system;
        },
        label() {
            const option = this.options.find(item => item.value === this.mode);

            return this.$t('Theme: %s', option ? option.label : this.$t('System'));
        }
    },
    methods: {
        /* `light`, `dark` or `system` - the mode, with any cached resolution dropped. */
        read() {
            const stored = localStorage.getItem(KEY) || '';
            const mode = stored.split(':')[0];

            return MODES.indexOf(mode) === -1 ? 'system' : mode;
        },
        systemPrefers() {
            return this.media && this.media.matches ? 'dark' : 'light';
        },
        /*
         * The one place the theme is actually applied. `store` is off when the change came
         * in from another tab, so a broadcast cannot bounce back and forth.
         *
         * The class goes on <body>, which is where the spec puts it. It is also left on
         * <html>, where the pre-paint script had to put it because <body> did not exist
         * yet: the two are kept in step rather than one being handed off to the other, so
         * a stylesheet written against either one is right.
         */
        apply(mode, store = true) {
            this.mode = MODES.indexOf(mode) === -1 ? 'system' : mode;
            this.resolved = this.mode === 'system' ? this.systemPrefers() : this.mode;

            const isDark = this.resolved === 'dark';

            document.body.classList.toggle(DARK_CLASS, isDark);
            document.documentElement.classList.toggle(DARK_CLASS, isDark);

            if (!store) {
                return;
            }

            localStorage.setItem(KEY, this.mode === 'system' ? 'system:' + this.resolved : this.mode);

            if (this.channel) {
                this.channel.postMessage({mode: this.mode});
            }
        },
        select(mode) {
            this.apply(mode);
        }
    },
    created() {
        this.media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

        if (typeof BroadcastChannel !== 'undefined') {
            this.channel = new BroadcastChannel('fluent_theme_changed:' + window.location.origin);
            this.channel.onmessage = event => {
                if (event.data && event.data.mode) {
                    this.apply(event.data.mode, false);
                }
            };
        }

        /* A tab opened before this one may have been switched since - trust storage, not the DOM. */
        this.apply(this.read(), false);

        this.onSystemChange = () => {
            if (this.mode === 'system') {
                this.apply('system');
            }
        };

        if (this.media && this.media.addEventListener) {
            this.media.addEventListener('change', this.onSystemChange);
        }

        /* Browsers without BroadcastChannel, and any other plugin that only writes the key. */
        this.onStorage = event => {
            if (event.key === KEY) {
                this.apply(this.read(), false);
            }
        };

        window.addEventListener('storage', this.onStorage);
    },
    beforeUnmount() {
        if (this.channel) {
            this.channel.close();
        }

        if (this.media && this.media.removeEventListener) {
            this.media.removeEventListener('change', this.onSystemChange);
        }

        window.removeEventListener('storage', this.onStorage);
    }
}
</script>

<template>
    <el-dropdown trigger="click" @command="select" popper-class="fsnip_theme_menu">
        <button type="button" class="fsnip_theme_trigger" :title="label" :aria-label="label"
                v-html="triggerIcon"></button>

        <template #dropdown>
            <el-dropdown-menu>
                <el-dropdown-item v-for="option in options" :key="option.value"
                                  :command="option.value"
                                  :class="{is_current: mode === option.value}">
                    <span class="fsnip_theme_item">
                        <span class="fsnip_theme_item_icon" v-html="icons[option.value]"></span>
                        <span>{{ option.label }}</span>
                    </span>
                </el-dropdown-item>
            </el-dropdown-menu>
        </template>
    </el-dropdown>
</template>
