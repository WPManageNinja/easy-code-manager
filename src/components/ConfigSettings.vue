<template>
    <div class="dashboard box_wrapper">
        <div class="box dashboard_box box_narrow">
            <div class="box_header" style="padding: 15px; font-size: 16px;">
                <div style="padding-top: 5px;" class="box_head">
                    {{$t('Settings')}}
                </div>
                <div class="box_actions">
                    <el-button @click="saveSettings()" v-loading="saving" :disabled="fetching || saving" type="success">
                        {{$t('Save Settings')}}
                    </el-button>
                </div>
            </div>
            <div v-if="!fetching" style="padding: 15px;" class="box_body">
                <h3>{{$t('General Settings')}}</h3>
                <el-form v-model="settings" label-position="top">
                    <el-form-item>
                        <el-checkbox true-value="yes" false-value="no" v-model="settings.auto_publish">
                            {{$t('Activate Snippets as default action. If disabled then it will be saved as Draft')}}
                        </el-checkbox>
                    </el-form-item>
                    <el-form-item>
                        <el-checkbox true-value="yes" false-value="no" v-model="settings.auto_disable">
                            {{$t('Automatically Disable Script on fatal error')}}
                        </el-checkbox>
                        <div style="color: red;" v-if="settings.auto_disable != 'yes'">
                            {{$t('__SNIPPET_AUTO_DISABLE_INS__')}}
                        </div>
                    </el-form-item>
                    <el-form-item>
                        <el-checkbox
                                true-value="yes"
                                false-value="no"
                                v-model="settings.enable_line_wrap"
                        >
                            {{ $t("Enable Editor Line Wrap") }}
                        </el-checkbox>
                    </el-form-item>
                    <el-form-item>
                        <el-checkbox :disabled="true" true-value="yes" false-value="no" v-model="settings.remove_on_uninstall">
                            {{ $t('Remove all data, including all scripts, when the plugin is deleted (coming soon)') }}
                        </el-checkbox>
                    </el-form-item>
                </el-form>
            </div>
            <div v-else class="box_body">
                <el-skeleton :rows="5" animated></el-skeleton>
            </div>
        </div>
        <div class="box dashboard_box box_narrow">
            <div class="box_header" style="padding: 15px; font-size: 16px;">
                <div style="padding-top: 5px;" class="box_head">
                    {{$t('Safe Mode')}}
                </div>
            </div>
            <div v-if="!fetching" style="padding: 15px 15px 25px;" class="box_body">
                <p>
                    {{ $t('FluentSnippets always tries to deactivate a script when a fatal error is encountered. Even so, there are rare situations where you could get locked out due to a fatal error in one of your snippets.') }}
                </p>
                <p>
                    {{ $t('This does not happen often, but if it does — or if you simply want to turn off all code snippets for a while — you can use Safe Mode.') }}
                </p>
                <p>{{ $t('To use Safe Mode, open the following URL. As soon as you visit it, FluentSnippets will temporarily disable all scripts.') }}</p>
                <b>{{ $t('Safe Mode URL') }}</b>
                <el-input style="margin-top: 5px;" size="large" v-model="secret_url" :disabled="true">
                    <template #append>
                        <el-button @click="copyItem(secret_url)">{{ $t('Copy') }}</el-button>
                    </template>
                </el-input>

                <h3>{{$t('Enable Safe Mode Programmatically:')}}</h3>
                <p>{{ $t('If you want to enable Safe Mode programmatically, you can add the following code to your wp-config.php file:') }}</p>
                <code style="padding: 10px;">define('FLUENT_SNIPPETS_SAFE_MODE', true);</code>
            </div>
            <div v-else class="box_body">
                <el-skeleton :rows="2" animated></el-skeleton>
            </div>
        </div>

        <div class="box dashboard_box box_narrow">
            <div class="box_header" style="padding: 15px; font-size: 16px;">
                <div style="padding-top: 5px;" class="box_head">
                    {{$t('Standalone Mode (Must use mode)')}}
                    <el-tag v-if="is_standalone" type="success">{{$t('Enabled')}}</el-tag>
                </div>
            </div>
            <div v-if="!fetching" style="padding: 15px 15px 25px;" class="box_body">
                <p>{{ $t('FluentSnippets does not force you to keep this plugin installed all the time. You can disable or uninstall the plugin and still keep your snippets running in standalone mode.') }}</p>
                <template v-if="is_standalone">
                    <p style="font-weight: bold;">{{ $t('Standalone Mode is currently activated. Even if you uninstall or delete this plugin, your scripts will still run.') }}</p>
                    <el-button v-loading="saving" :disabled="saving" size="small" @click="updateStandAloneMode('no')">
                        {{$t('Disable Standalone Mode')}}
                    </el-button>
                </template>
                <template v-else>
                    <p>{{ $t('When using Standalone Mode, your scripts are executed from the mu-plugins file.') }}</p>
                    <el-button v-loading="saving" :disabled="saving" type="primary"
                               @click="updateStandAloneMode('yes')">
                        {{$t('Enable Standalone Mode')}}
                    </el-button>
                </template>
            </div>
            <div v-else class="box_body">
                <el-skeleton :rows="2" animated></el-skeleton>
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
                remove_on_uninstall: 'no',
            },
            secret_url: '',
            fetching: true,
            saving: false,
            is_standalone: false
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
                    if (!this.settings.enable_line_wrap){
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
