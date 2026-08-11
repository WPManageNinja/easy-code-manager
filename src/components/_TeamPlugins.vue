<template>
    <div v-if="plugins.length" class="box fss_team">
        <div class="box_header">
            <div class="box_head">
                <h3>{{ $t('From the same team') }}</h3>
            </div>
        </div>
        <div class="box_body">
            <p class="fss_team_lead">
                {{ $t('FluentSnippets is built by WPManageNinja, who also make these. All of them are free on WordPress.org, and install from here without leaving the page.') }}
            </p>

            <ul class="fss_team_grid">
                <li v-for="plugin in plugins" :key="plugin.slug" class="fss_team_item">
                    <img v-if="plugin.icon && !plugin.iconFailed" class="fss_team_icon" :src="plugin.icon"
                         alt="" loading="lazy" @error="plugin.iconFailed = true"/>
                    <span v-else class="fss_team_icon fss_team_icon_fallback" aria-hidden="true">
                        {{ plugin.name.charAt(0) }}
                    </span>

                    <div class="fss_team_text">
                        <a class="fss_team_name" :href="plugin.url" target="_blank" rel="noopener">{{ plugin.name }}</a>
                        <p>{{ plugin.description }}</p>
                    </div>

                    <div class="fss_team_action">
                        <!--
                            Nothing left to offer, so nothing is offered - no "manage", no
                            link back to the plugins screen. The state is the message.
                        -->
                        <span v-if="plugin.state === 'active'" class="fss_team_active">
                            {{ $t('Active') }}
                        </span>

                        <el-button v-else-if="canRunFlow(plugin)" size="small" :loading="!!plugin.busy"
                                   :disabled="!!plugin.busy" @click="start(plugin)">
                            {{ busyLabel(plugin) || actionLabel(plugin) }}
                        </el-button>

                        <!--
                            The user cannot finish this from here - installed but they may
                            not activate, or not installed and they may not install. Hand
                            off to a screen where somebody can.
                        -->
                        <a v-else class="fss_team_fallback" :href="fallbackHref(plugin)" target="_blank" rel="noopener">
                            {{ plugin.state === 'installed' ? $t('Activate it on the Plugins screen') : $t('View on WordPress.org') }}
                        </a>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>

<script type="text/babel">
/**
 * The "from the same team" card on the About screen.
 *
 * Installing runs through this plugin's own REST routes rather than through core's
 * wp.updates, which is how the other Fluent plugins do it: one place that decides what
 * may be installed, one error shape the app already knows how to display, and no
 * dependency on markup from the Add Plugins screen that does not exist here.
 *
 * The server is the authority on state. Every response carries the plugin's state back
 * and the card is set from that, rather than from what the click was expected to do -
 * so an install that lands but fails to activate shows an Activate button, which is the
 * true state, without needing a reload to discover it.
 */
export default {
    name: 'TeamPlugins',
    data() {
        const config = this.appVars.team_plugins_config || {};

        return {
            canInstall: !!config.can_install,
            canActivate: !!config.can_activate,
            pluginsScreen: config.plugins_screen || '',
            /*
             * Copied rather than used in place: `busy` and `iconFailed` are this
             * component's own state, and appVars is shared with every other screen.
             */
            plugins: (this.appVars.team_plugins || []).map(plugin => {
                return Object.assign({}, plugin, {busy: '', iconFailed: false});
            })
        }
    },
    methods: {
        /**
         * Whether this user can finish the job from this card, which is what decides
         * between a button and a link out.
         */
        canRunFlow(plugin) {
            return plugin.state === 'installed' ? this.canActivate : this.canInstall;
        },

        actionLabel(plugin) {
            if (plugin.state === 'installed') {
                return this.$t('Activate');
            }

            /*
             * The label has to be the truth about what the click does. One click that
             * both installs and switches a plugin on is fine; one click labelled
             * "Install" that quietly switches it on as well is not.
             */
            return this.canActivate ? this.$t('Install & Activate') : this.$t('Install');
        },

        busyLabel(plugin) {
            if (plugin.busy === 'installing') {
                return this.$t('Installing');
            }

            return plugin.busy === 'activating' ? this.$t('Activating') : '';
        },

        start(plugin) {
            if (plugin.busy) {
                return;
            }

            if (plugin.state === 'installed') {
                this.activate(plugin);
                return;
            }

            this.install(plugin);
        },

        install(plugin) {
            plugin.busy = 'installing';

            this.$post('team-plugins/install', {slug: plugin.slug})
                .then(response => {
                    this.applyState(plugin, response.plugin);

                    /*
                     * A 200 that reports the plugin is still inactive means it installed
                     * and would not start - the message says why, and it is a warning
                     * rather than a success because the user asked for both halves.
                     */
                    this.$notify({
                        type: plugin.state === 'active' ? 'success' : 'warning',
                        title: plugin.state === 'active' ? this.$t('Done') : this.$t('Installed'),
                        message: response.message,
                        duration: plugin.state === 'active' ? 4500 : 8000
                    });
                })
                .catch(error => {
                    this.$handleError(error);
                })
                .finally(() => {
                    plugin.busy = '';
                });
        },

        activate(plugin) {
            plugin.busy = 'activating';

            this.$post('team-plugins/activate', {slug: plugin.slug})
                .then(response => {
                    this.applyState(plugin, response.plugin);

                    this.$notify({
                        type: 'success',
                        title: this.$t('Done'),
                        message: response.message
                    });
                })
                .catch(error => {
                    this.$handleError(error);
                })
                .finally(() => {
                    plugin.busy = '';
                });
        },

        /**
         * Take the server's word for where the plugin now stands.
         */
        applyState(plugin, state) {
            if (!state) {
                return;
            }

            plugin.state = state.state || plugin.state;
            plugin.file = state.file || plugin.file;
        },

        fallbackHref(plugin) {
            if (plugin.state === 'installed' && this.pluginsScreen) {
                return this.pluginsScreen;
            }

            return plugin.url;
        }
    }
}
</script>
