<template>
    <div v-if="runTypeOptions" class="fsnin_run_wrap">
        <h3>Where to Run?</h3>
        <div @click="showSelector = !showSelector" class="fsnin_run_selector">
            <div v-if="selectedOption" class="run_selected run_box">
                <p style="font-weight: bold;">{{ selectedOption.label }}</p>
                <p style="font-size: 80%;">{{ selectedOption.description }}</p>
            </div>
            <div style="border: 1px solid red !important;" v-else class="run_selected run_box">
                <p style="font-weight: bold;">Select Snippet Run Location</p>
            </div>
        </div>

        <div v-show="showSelector" class="run_selector_options">
            <div v-for="(runType, runLabel) in runTypeOptions" :key="runLabel" @click="snippet.meta.run_at = runLabel; showSelector = false" class="selector_option">
                <p class="option_label">
                    {{ runType.label }}
                    <el-tag v-if="runLabel == snippet.meta.run_at" size="small">selected</el-tag>
                </p>
                <p style="font-size: 80%;">{{ runType.description }}</p>
            </div>
        </div>

        <div v-if="snippet.meta.run_at == 'shortcode'">
            <div v-if="is_new">
                <p>You can view the shortcode after save this snippet</p>
            </div>
            <div class="fsnip_highlight" v-else>
                <p style="line-height: 1.9">Use Shortcode to display the return or print content of this snippet:</p>
                <div class="snip_shortcode">
                <span class="snip_code">
                    [fluent_snippet id="{{ getFileName(snippet.file_name) }}"]
                    <el-icon @click="copyShortCode()"><CopyDocument/></el-icon>
                </span>
                </div>
            </div>
        </div>
    </div>
</template>

<script type="text/babel">
import {CopyDocument} from '@element-plus/icons-vue';
import {markRaw} from "vue";

export default {
    name: 'WhereRun',
    props: ['snippet', 'is_new'],
    components: {
        CopyDocument: markRaw(CopyDocument)
    },
    data() {
        return {
            showSelector: false
        }
    },
    computed: {
        runTypeOptions() {
            if (this.snippet.meta.type == 'PHP') {
                return {
                    'all': {
                        'label': 'Run Everywhere',
                        'description': 'Snippet gets executed everywhere (both frontend and admin area)'
                    },
                    'backend': {
                        'label': 'Admin Only',
                        'description': 'Snippet gets executed only in admin area (/wp-admin/)'
                    },
                    'frontend': {
                        'label': 'Frontend Only',
                        'description': 'Snippet gets executed only in frontend area'
                    }
                }
            }

            if (this.snippet.meta.type == 'php_content') {
                return {
                    'shortcode': {
                        'label': 'Shortcode',
                        'description': 'Only display when inserted into a post or page using shortcode'
                    },
                    'wp_head': {
                        'label': 'Site Wide Header',
                        'description': 'Insert snippet between the head tags of your website (frontend).'
                    },
                    'wp_body_open': {
                        'label': 'Site Wide Body Open',
                        'description': 'Insert snippet after the opening body tag of your website (frontend).'
                    },
                    'wp_footer': {
                        'label': 'Site Wide Footer',
                        'description': 'Insert snippet before the closing body tag of your website on all pages (frontend).'
                    },
                }
            }

            if (this.snippet.meta.type == 'js') {
                return {
                    'wp_head': {
                        'label': 'Site Wide Header',
                        'description': 'Run Javascript between the head tags of your website on all pages (frontend).'
                    },
                    'wp_footer': {
                        'label': 'Site Wide Footer',
                        'description': 'Run Javascript before the closing body tag of your website on all pages (frontend).'
                    },
                }
            }

            if (this.snippet.meta.type == 'css') {
                return {
                    'wp_head': {
                        'label': 'Frontend',
                        'description': 'Add CSS on all pages (frontend).'
                    },
                    'admin_head': {
                        'label': 'Backend',
                        'description': 'Apply this snippet CSS to backend (/wp-admin/).'
                    },
                    'everywehere': {
                        'label': 'Both Backend and Frontend',
                        'description': 'Apply this snippet CSS to both backend and frontend.'
                    },
                }
            }

            return false;
        },
        selectedOption() {
            if (this.runTypeOptions) {
                return this.runTypeOptions[this.snippet.meta.run_at];
            }
            return null;
        }
    },
    methods: {
        copyShortCode() {
            const copyText = `[fluent_snippet id="${this.getFileName(this.snippet.file_name)}"]`;
            navigator.clipboard.writeText(copyText).then(() => {
                this.$notify.success({
                    message: 'Shortcode copied to clipboard',
                    type: 'success'
                });
            }, () => {
                this.$notify.error({
                    message: 'Failed to copy shortcode',
                    type: 'error'
                });
            });
        },
        getFileName(file) {
            return file.replace('.php', '');
        }
    }
}
</script>
