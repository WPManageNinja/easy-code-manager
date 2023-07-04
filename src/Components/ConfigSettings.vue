<template>
    <div class="dashboard box_wrapper">
        <div class="box dashboard_box box_narrow">
            <div class="box_header" style="padding: 15px; font-size: 16px;">
                <div style="padding-top: 5px;" class="box_head">
                    Settings
                </div>
                <div class="box_actions">
                    <el-button @click="saveSettings()" v-loading="saving" :disabled="fetching || saving" type="success">
                        Save Settings
                    </el-button>
                </div>
            </div>
            <div v-if="!fetching" style="padding: 15px;" class="box_body">
                <h3>General Settings</h3>
                <el-form v-model="settings" label-position="top">
                    <el-form-item>
                        <el-checkbox true-label="yes" false-label="no" v-model="settings.auto_publish">Activate Snippets
                            as default action. If disabled then it will be saved as "Draft"
                        </el-checkbox>
                    </el-form-item>
                    <el-form-item>
                        <el-checkbox true-label="yes" false-label="no" v-model="settings.auto_disable">Stop executing
                            scripts on fatal error from scripts.
                        </el-checkbox>
                    </el-form-item>
                    <el-form-item>
                        <el-checkbox true-label="yes" false-label="no" v-model="settings.remove_on_uninstall">Remove all
                            data including <b>All Scripts</b> completely on plugin delete
                        </el-checkbox>
                    </el-form-item>
                </el-form>
            </div>
            <div v-else class="box_body">
                <el-skeleton :rows="5" animated></el-skeleton>
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
            fetching: true,
            saving: false
        }
    },
    methods: {
        getSettings() {
            this.fetching = true;
            this.$get('settings')
                .then(response => {
                    this.settings = response.settings;
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
