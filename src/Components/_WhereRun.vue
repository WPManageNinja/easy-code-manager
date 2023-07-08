<template>
    <el-form-item v-if="runTypeOptions" label="Where to run?">
        <el-radio-group v-model="snippet.meta.run_at">
            <el-radio v-for="(runType, runLabel) in runTypeOptions" :key="runLabel" :label="runLabel">{{
                    runType.label
                }}
            </el-radio>
        </el-radio-group>
    </el-form-item>

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
</template>

<script type="text/babel">
export default {
    name: 'WhereRun',
    props: ['snippet', 'is_new'],
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

            return false;
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
        }
    }
}
</script>
