<template>
    <div class="fsnip_settings box_wrapper">
        <!--
            Every setting is a title, a sentence saying what it actually does, and the
            control - rather than a bare checkbox with the explanation crammed into its
            label. The consequence of turning one off is part of the sentence: two of these
            change what happens when a snippet fatals, which is not a thing to discover
            afterwards.
        -->
        <div class="box box_narrow">
            <div class="box_header">
                <div class="box_head">
                    <!-- The page's <h1>. The cards below it are its sections, so they are h2. -->
                    <h1>{{ $t('Settings') }}</h1>
                </div>
                <div class="box_actions">
                    <el-button v-if="canEdit" @click="saveSettings()" v-loading="saving" :disabled="fetching || saving"
                               type="primary">
                        {{ $t('Save Settings') }}
                    </el-button>
                </div>
            </div>

            <div v-if="!fetching" class="box_body">
                <div class="fsnip_setting">
                    <div class="fsnip_setting_text">
                        <strong>{{ $t('Publish new snippets straight away') }}</strong>
                        <p>{{ $t('A snippet runs from the moment you save it. Turn this off and every new snippet is saved as a draft instead, so nothing runs until you say so.') }}</p>
                    </div>
                    <div class="fsnip_setting_control">
                        <!--
                            The setting's title is in a <strong> beside the toggle, not in a
                            <label> tied to it, so the toggle itself was announced as an
                            unnamed switch. Repeating the title as the name is what connects
                            the two.
                        -->
                        <el-switch :disabled="!canEdit" v-model="settings.auto_publish" active-value="yes" inactive-value="no"
                                   :aria-label="$t('Publish new snippets straight away')"/>
                    </div>
                </div>

                <div class="fsnip_setting">
                    <div class="fsnip_setting_text">
                        <strong>{{ $t('Deactivate a snippet that causes a fatal error') }}</strong>
                        <p>{{ $t('When a snippet throws a fatal error it is switched off on the spot, and the list tells you which one and why. This is what stops one bad snippet from taking the site down with it.') }}</p>

                        <div v-if="settings.auto_disable != 'yes'" class="fsnip_setting_warning" role="alert">
                            {{ $t('__SNIPPET_AUTO_DISABLE_INS__') }}
                        </div>
                    </div>
                    <div class="fsnip_setting_control">
                        <el-switch :disabled="!canEdit" v-model="settings.auto_disable" active-value="yes" inactive-value="no"
                                   :aria-label="$t('Deactivate a snippet that causes a fatal error')"/>
                    </div>
                </div>

                <div class="fsnip_setting">
                    <div class="fsnip_setting_text">
                        <strong>{{ $t('Wrap long lines in the editor') }}</strong>
                        <p>{{ $t('A line too long for the editor folds onto the next one instead of scrolling sideways.') }}</p>
                    </div>
                    <div class="fsnip_setting_control">
                        <el-switch :disabled="!canEdit" v-model="settings.enable_line_wrap" active-value="yes" inactive-value="no"
                                   :aria-label="$t('Wrap long lines in the editor')"/>
                    </div>
                </div>

                <div class="fsnip_setting is_pending">
                    <div class="fsnip_setting_text">
                        <strong>
                            {{ $t('Delete every snippet when the plugin is uninstalled') }}
                            <span class="fsnip_tag is_neutral">{{ $t('Coming soon') }}</span>
                        </strong>
                        <p>{{ $t('Uninstalling leaves your snippet files on disk, so reinstalling picks up where you left off. This will make removal complete instead.') }}</p>
                    </div>
                    <div class="fsnip_setting_control">
                        <el-switch :disabled="true" v-model="settings.remove_on_uninstall"
                                   active-value="yes" inactive-value="no"
                                   :aria-label="$t('Delete every snippet when the plugin is uninstalled')"/>
                    </div>
                </div>
            </div>
            <div v-else class="box_body">
                <el-skeleton :rows="5" animated/>
            </div>
        </div>

        <!--
            Safe Mode is the way back in when the admin itself will not load, so the URL is
            the first thing on the card rather than the last - by the time you need it you
            are reading this page on your phone from a copy you saved earlier.
        -->
        <div class="box box_narrow">
            <div class="box_header">
                <div class="box_head">
                    <h2>{{ $t('Safe Mode') }}</h2>
                </div>
            </div>

            <div v-if="!fetching" class="box_body">
                <p class="fsnip_lede">
                    {{ $t('Safe Mode turns every snippet off at once, without going through the admin. It is the way back in if a snippet ever locks you out of your own site - so save this URL somewhere you can reach when WordPress will not load.') }}
                </p>

                <label class="fsnip_field_label" for="fsnip_safe_url">{{ $t('Your Safe Mode URL') }}</label>
                <el-input id="fsnip_safe_url" size="large" v-model="secret_url"
                          :disabled="true">
                    <template #append>
                        <!--
                            Copy is the only thing in here. Element Plus lays the append
                            slot out as a single seam on the end of the field, so a second
                            button lands on top of the first rather than beside it - and
                            regenerating is not a peer of copying anyway. It is the rarer,
                            heavier action, so it gets its own row below with a line
                            saying what it does.
                        -->
                        <el-button @click="copyItem(secret_url)">{{ $t('Copy') }}</el-button>
                    </template>
                </el-input>

                <p class="fsnip_field_note">
                    {{ $t('Treat it like a password: anyone who opens it can switch your snippets off.') }}
                </p>

                <div v-if="canEdit" class="fsnip_field_action">
                    <el-button size="small" :loading="regenerating" :disabled="regenerating"
                               @click="regenerateSecretUrl()">
                        {{ $t('Regenerate') }}
                    </el-button>
                    <span>
                        {{ $t('Issues a new URL and stops the current one working. Do this if it has been somewhere it should not have been - a shared document, a support thread, a screenshot.') }}
                    </span>
                </div>

                <div class="fsnip_setting_aside">
                    <strong>{{ $t('Or turn it on from wp-config.php') }}</strong>
                    <p>{{ $t('If you would rather not rely on a URL, this does the same thing and cannot be triggered by anyone who has not got file access:') }}</p>
                    <code>define('FLUENT_SNIPPETS_SAFE_MODE', true);</code>
                </div>
            </div>
            <div v-else class="box_body">
                <el-skeleton :rows="2" animated/>
            </div>
        </div>

        <!--
            The one setting on this screen that changes what happens after the plugin is
            gone, so the current state is stated before the explanation rather than left to
            be read off the button.
        -->
        <div class="box box_narrow">
            <div class="box_header">
                <div class="box_head">
                    <h2>{{ $t('Standalone Mode') }}</h2>
                    <span class="fsnip_tag" :class="is_standalone ? 'is_success' : 'is_neutral'">
                        {{ is_standalone ? $t('On') : $t('Off') }}
                    </span>
                </div>
            </div>

            <div v-if="!fetching" class="box_body">
                <p class="fsnip_lede">
                    {{ $t('Standalone Mode writes your snippets into a must-use plugin, so they keep running whether or not FluentSnippets is installed. Deactivate this plugin, or delete it, and your code carries on working.') }}
                </p>

                <template v-if="is_standalone">
                    <div class="fsnip_setting_aside is_on">
                        <strong>{{ $t('Your snippets no longer depend on this plugin') }}</strong>
                        <p>{{ $t('They are loaded from mu-plugins. Uninstalling FluentSnippets will not stop them; turning this off below will hand them back to the plugin.') }}</p>
                    </div>

                    <el-button v-if="canEdit" v-loading="saving" :disabled="saving" @click="updateStandAloneMode('no')">
                        {{ $t('Turn off Standalone Mode') }}
                    </el-button>
                </template>
                <template v-else>
                    <el-button v-if="canEdit" v-loading="saving" :disabled="saving" type="primary"
                               @click="updateStandAloneMode('yes')">
                        {{ $t('Turn on Standalone Mode') }}
                    </el-button>
                </template>
            </div>
            <div v-else class="box_body">
                <el-skeleton :rows="2" animated/>
            </div>
        </div>
    </div>
</template>

<script type="text/babel">
export default {
    name: 'ConfigSettings',
    data() {
        return {
            settings: {
                auto_disable: 'yes',
                auto_publish: 'yes',
                enable_line_wrap: 'no',
                remove_on_uninstall: 'no',
            },
            secret_url: '',
            fetching: true,
            saving: false,
            is_standalone: false,
            regenerating: false
        }
    },
    methods: {
        getSettings() {
            this.fetching = true;
            this.$get('settings')
                .then(response => {
                    this.settings = response.settings;
                    this.secret_url = response.secret_url;
                    this.is_standalone = response.is_standalone;
                    this.appVars.is_standalone = response.is_standalone;
                    if (!this.settings.enable_line_wrap) {
                        this.settings.enable_line_wrap = 'no';
                    }
                    this.appVars.enable_line_wrap = this.settings.enable_line_wrap;
                })
                .catch((errors) => {
                    this.$handleError(errors);
                })
                .finally(() => {
                    this.fetching = false;
                });
        },
        saveSettings() {
            this.saving = true;
            this.$post('settings', {
                settings: this.settings
            })
                .then(response => {
                    this.$notify.success(response.message);
                    this.appVars.enable_line_wrap = this.settings.enable_line_wrap;
                })
                .catch((errors) => {
                    this.$handleError(errors);
                })
                .finally(() => {
                    this.saving = false;
                });
        },
        /**
         * Issue a new Safe Mode URL, after making sure the user knows what it costs.
         *
         * The confirm is not ceremony. This URL is meant to be saved somewhere reachable
         * when WordPress will not load — a password manager, a phone note, a runbook —
         * and every one of those copies stops working the moment this is pressed. Someone
         * who regenerates it and then closes the tab without saving the new one has
         * thrown away their way back into a locked-out site.
         */
        regenerateSecretUrl() {
            this.$confirm(
                this.$t('The current Safe Mode URL will stop working immediately. Anywhere you have saved it - a password manager, a runbook, your phone - will need the new one.'),
                this.$t('Generate a new Safe Mode URL?'),
                {
                    confirmButtonText: this.$t('Regenerate'),
                    cancelButtonText: this.$t('Cancel'),
                    type: 'warning'
                }
            ).then(() => {
                this.regenerating = true;

                this.$post('settings/regenerate-secret')
                    .then(response => {
                        this.secret_url = response.secret_url;
                        this.$notify.success(response.message);
                    })
                    .catch((errors) => {
                        this.$handleError(errors);
                    })
                    .finally(() => {
                        this.regenerating = false;
                    });
            }).catch(() => {
                // Dismissed the dialog. $confirm rejects on cancel; nothing to report.
            });
        },
        copyItem(copyText) {
            navigator.clipboard.writeText(copyText).then(() => {
                this.$notify.success({
                    message: this.$t('The secure Safe Mode URL has been copied to your clipboard.'),
                    type: 'success'
                });
            }, () => {
                this.$notify.error({
                    message: this.$t('Failed to copy the URL.'),
                    type: 'error'
                });
            });
        },
        updateStandAloneMode(enabled) {
            this.saving = true;
            this.$post('settings/standalone', {
                enable: enabled
            })
                .then(response => {
                    this.$notify.success(response.message);
                    this.getSettings();
                })
                .catch((errors) => {
                    this.$handleError(errors);
                })
                .finally(() => {
                    this.saving = false;
                });
        }
    },
    mounted() {
        this.getSettings();
    }
}
</script>
