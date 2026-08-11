<template>
    <div class="fsnip_app">
        <div class="fsnip_app_bar" :class="{'is-scrolled': scrolled}">
            <div class="fsnip_app_logo">
                <router-link :to="{name: 'dashboard'}" aria-label="FluentSnippets">
                    <brand-mark :size="30"/>
                    <span class="fsnip_wordmark"><span>fluent</span>Snippets</span>
                </router-link>
            </div>

            <button class="fsnip_app_bar_toggle" type="button" @click="navOpen = !navOpen"
                    :aria-label="$t('Menu')">
                <span class="dashicons dashicons-menu-alt3"></span>
            </button>

            <ul class="fsnip_app_nav" :class="{'is-open': navOpen}">
                <li v-for="item in menuItems" :key="item.route">
                    <router-link :to="{name: item.route}"
                                 :class="{'router-link-active': isActive(item)}">
                        {{ item.title }}
                    </router-link>
                </li>
            </ul>

            <div class="fsnip_app_bar_actions">
                <theme-switch/>
            </div>
        </div>

        <div class="fsnip_page">
            <div class="fsnip_page_inner">
                <fsnip-promo :config="appVars.safeModes"/>

                <!--
                    Said once, at the top, on every screen - rather than as a tooltip on
                    each control that is no longer there. Someone who opens this plugin and
                    finds the Create button missing needs the reason before they go looking
                    for it in Settings.
                -->
                <div v-if="readOnlyNotice" class="fsnip_read_only">
                    <span class="dashicons dashicons-lock"></span>
                    <div>
                        <strong>{{ readOnlyNotice.title }}</strong>
                        <p>{{ readOnlyNotice.reason }}</p>
                    </div>
                </div>

                <div v-show="hasServerError">
                    <el-button @click="hideErrors()" v-if="hasServerError">{{ $t('Hide Errors') }}</el-button>
                    <div :class="{fluent_snip_server_error : hasServerError}" id="fsnip_shadow_wrapper">
                        <div id="fluent_snip_500_error"></div>
                    </div>
                </div>

                <router-view></router-view>
            </div>
        </div>
    </div>
</template>

<script type="text/babel">
import FsnipPromo from './components/FsnipSafeModesWarning.vue';
import ThemeSwitch from './Bits/ThemeSwitch.vue';
import BrandMark from './Bits/BrandMark.vue';

export default {
    name: 'FluentSnippetsApp',
    components: {
        FsnipPromo,
        ThemeSwitch,
        BrandMark
    },
    data() {
        return {
            scrolled: false,
            navOpen: false,
            menuItems: [
                {
                    route: 'dashboard',
                    title: this.$t('Snippets')
                },
                {
                    route: 'settings',
                    title: this.$t('Settings')
                },
                {
                    route: 'about',
                    title: this.$t('About')
                }
            ],
            hasServerError: false
        }
    },
    computed: {
        // Built server-side, because the reason is a fact about wp-config.php that the
        // browser has no way of knowing. Null unless the screen really is read-only.
        readOnlyNotice() {
            return this.appVars.read_only_notice || null;
        }
    },
    methods: {
        /*
         * Which nav item is lit. `meta.active` is the route's own answer to "which section
         * am I in", so the editor and the create screen keep Snippets lit rather than
         * lighting nothing - which is what router-link-active alone would do.
         */
        isActive(item) {
            const active = this.$route.meta ? this.$route.meta.active : '';

            return active === item.route;
        },
        onScroll() {
            this.scrolled = window.scrollY > 10;
        },
        /**
         * Publishes the space wp-admin's own chrome occupies, as two CSS variables.
         *
         * The app bar is pinned to the viewport, which means it cannot inherit the page's
         * offsets the way an in-flow element does - it has to be told where the menu ends
         * and where the admin bar stops. Measuring beats hard-coding 160px and 32px:
         * collapsing the menu, the automatic fold on a narrow window and the off-canvas
         * menu on a phone all land on different widths, and an admin bar with enough items
         * on it to wrap onto a second line is taller than 32px - which is how the app bar
         * ends up underneath somebody's plugin menu.
         *
         * Below 783px wp-admin lets the admin bar scroll away with the page, so there is
         * nothing to sit under and the offset is zero.
         */
        measureShell() {
            const content = document.getElementById('wpcontent');
            const left = content ? content.getBoundingClientRect().left : 0;
            const bar = document.getElementById('wpadminbar');
            const pinned = bar && window.getComputedStyle(bar).position === 'fixed';
            const top = pinned ? bar.getBoundingClientRect().height : 0;

            const root = document.documentElement.style;

            root.setProperty('--fsnip-shell-left', left + 'px');
            root.setProperty('--fsnip-shell-top', top + 'px');
        },
        initShadowDomIframe(error) {
            if (!error) {
                this.hideErrors();
                return false;
            }

            let host = document.getElementById("fluent_snip_500_error");

            // Remove the existing host element
            if (host) {
                host.parentNode.removeChild(host);
            }

            // Create a new host element
            host = document.createElement('div');
            host.id = "fluent_snip_500_error";
            document.getElementById('fsnip_shadow_wrapper').appendChild(host); // Append it where it needs to be in the DOM

            // Attach a new shadow DOM and add content
            const shadow = host.attachShadow({mode: "closed"});
            const div = document.createElement("div");
            div.classList.add("fsnip_500_error_wrap");
            div.innerHTML = error;
            shadow.appendChild(div);
            this.hasServerError = true;

            // Scroll to top
            window.scrollTo(0, 0);
        },
        hideErrors() {
            let host = document.getElementById("fluent_snip_500_error");

            // Remove the existing host element
            if (host) {
                host.parentNode.removeChild(host);
            }
            this.hasServerError = false;
        }
    },
    watch: {
        $route() {
            this.navOpen = false;
        }
    },
    created() {
        jQuery('.update-nag,.notice, #wpbody-content > .updated, #wpbody-content > .error').remove();
    },
    mounted() {
        window.addEventListener('scroll', this.onScroll);
        this.onScroll();

        this.measureShell();

        /*
         * Folding the menu changes the width of #wpcontent, so watching its size catches
         * the fold, the automatic fold at narrow widths and an ordinary window resize
         * without listening for any of them by name.
         */
        const content = document.getElementById('wpcontent');
        const bar = document.getElementById('wpadminbar');

        if (content && window.ResizeObserver) {
            this.shellObserver = new ResizeObserver(this.measureShell);
            this.shellObserver.observe(content);

            /* The admin bar wraps to a second line on its own, without #wpcontent moving. */
            if (bar) {
                this.shellObserver.observe(bar);
            }
        } else {
            window.addEventListener('resize', this.measureShell);
        }

        this.$eventBus.on("server_error", (error) => {
            this.initShadowDomIframe(error);
        });
    },
    beforeUnmount() {
        window.removeEventListener('scroll', this.onScroll);
        window.removeEventListener('resize', this.measureShell);

        if (this.shellObserver) {
            this.shellObserver.disconnect();
        }
    }
}
</script>
