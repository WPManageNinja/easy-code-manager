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
                        <el-checkbox true-label="yes" false-label="no" v-model="settings.auto_publish">
                            Activate Snippets as default action. If disabled then it will be saved as "Draft"
                        </el-checkbox>
                    </el-form-item>
                    <el-form-item>
                        <el-checkbox true-label="yes" false-label="no" v-model="settings.auto_disable">
                            Automatically Disable Script on fatal error
                        </el-checkbox>
                        <div style="color: red;" v-if="settings.auto_disable != 'yes'">
                            We highly recommend to enable this option. If disabled, then your site may go down if there has any error on the scripts.
                        </div>
                    </el-form-item>
                    <el-form-item>
                        <el-checkbox
                                true-label="yes"
                                false-label="no"
                                v-model="settings.enable_line_wrap"
                        >
                            {{ $t("Enable Editor Line Wrap") }}
                        </el-checkbox>
                    </el-form-item>
                    <el-form-item>
                        <el-checkbox :disabled="true" true-label="yes" false-label="no" v-model="settings.remove_on_uninstall">
                            Remove all data including <b>All Scripts</b> completely on plugin delete (coming soon)
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
                    FluentSnippets always try to deactivate any script if fatal error encountered. There are still
                    situations when you might get locked due to any fatal error from your snippets.
                </p>
                <p>
                    This doesn't happen often, but if it does, or if you just want to turn off all code snippets for a
                    while, you can use a thing called safe mode.
                </p>
                <p><b>To use safe mode</b>, use the following URL and once you visit the URL, FluentSnippets will
                    disable all scripts temporarily.</p>
                <b>Safe Mode URL</b>
                <el-input style="margin-top: 5px;" size="large" v-model="secret_url" :disabled="true">
                    <template #append>
                        <el-button @click="copyItem(secret_url)">Copy</el-button>
                    </template>
                </el-input>

                <h3>{{$t('Enable Safe Mode Programmatically:')}}</h3>
                <p>If you want to enable safe mode programmatically, you can add the following code to your
                    wp-config.php file</p>
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
                <p>FluentSnippet does not force you to keep installing this plugin all the time. You can disable or
                    uninstall this plugin and still keep running your snippets as a stand-alone mode.</p>
                <template v-if="is_standalone">
                    <p style="font-weight: bold;">Standalone Mode is currently activated. Even if you uninstall or
                        delete this plugin, Your scripts will still run.</p>
                    <el-button v-loading="saving" :disabled="saving" size="small" @click="updateStandAloneMode('no')">
                        {{$t('Disable Standalone Mode')}}
                    </el-button>
                </template>
                <template v-else>
                    <p>When using standalone mode your scripts will be executed from mu-plugins file.</p>
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
                    message: 'Secure SafeMode URL has been copied to clipboard',
                    type: 'success'
                });
            }, () => {
                this.$notify.error({
                    message: 'Failed to copy shortcode',
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
