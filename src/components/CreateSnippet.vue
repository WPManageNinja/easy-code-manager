<template>
    <div class="box_wrapper">
        <div class="box dashboard_box">
            <div class="box_header" style="padding: 15px;font-size: 16px;">
                <div style="padding-top: 5px;" class="box_head">
                    <el-breadcrumb separator="/">
                        <el-breadcrumb-item :to="{ name: 'dashboard' }">{{$t('Code Snippets')}}</el-breadcrumb-item>
                        <el-breadcrumb-item>
                            {{$t('Create New')}}
                        </el-breadcrumb-item>
                    </el-breadcrumb>
                </div>
                <div v-if="snippet" style="display: flex;" class="box_actions">
                    <el-button @click="saveCode()" :disabled="saving" v-loading="saving" type="success">
                        {{$t('Create Snippet')}}
                    </el-button>
                </div>
            </div>
            <div class="box_body">
                <snippet-form :is_new="true" :snippet="snippet">
                    <template v-slot:code_editor>
                        <el-form-item label="Code" :class="'fsnip_code_lang_'+snippet.meta.type"
                                      class="code_editor_wrap">
                            <el-tabs @tabChange="tabChanged()" v-model="snippet.meta.type" type="border-card">
                                <el-tab-pane v-if="appVars.snippet_types.PHP" name="PHP" label="Functions (PHP)">
                                    <template #label>
                                        <span class="custom-tabs-label">
                                             <span>Functions</span>
                                            <span class="fsn_label fsn_php">PHP</span>
                                        </span>
                                    </template>
                                    <code-editor
                                        v-if="snippet.meta.type == 'PHP'"
                                        :langType="snippet.meta.type"
                                        v-model="snippet.code"
                                    />
                                </el-tab-pane>
                                <el-tab-pane v-if="appVars.snippet_types.php_content" name="php_content">
                                    <template #label>
                                        <span class="custom-tabs-label">
                                             <span>Content</span>
                                            <span class="fsn_label fsn_mixed">HTML + PHP</span>
                                        </span>
                                    </template>
                                    <code-editor
                                        v-if="snippet.meta.type == 'php_content'"
                                        :langType="snippet.meta.type"
                                        v-model="snippet.code"
                                    />
                                </el-tab-pane>
                                <el-tab-pane v-if="appVars.snippet_types.css" name="css">
                                    <template #label>
                                        <span class="custom-tabs-label">
                                             <span>Styles</span>
                                            <span class="fsn_label fsn_css">CSS</span>
                                        </span>
                                    </template>
                                    <code-editor
                                        v-if="snippet.meta.type == 'css'"
                                        :langType="snippet.meta.type"
                                        v-model="snippet.code"
                                    />
                                </el-tab-pane>
                                <el-tab-pane v-if="appVars.snippet_types.js" name="js" label="Javascript">
                                    <template #label>
                                        <span class="custom-tabs-label">
                                             <span>Scripts</span>
                                            <span class="fsn_label fsn_js">JS</span>
                                        </span>
                                    </template>
                                    <code-editor
                                        v-if="snippet.meta.type == 'js'"
                                        :langType="snippet.meta.type"
                                        v-model="snippet.code"
                                    />
                                </el-tab-pane>
                            </el-tabs>
                        </el-form-item>
                    </template>
                </snippet-form>
            </div>
        </div>
    </div>
</template>

<script type="text/babel">
import SnippetForm from './_SnippetForm.vue'
import CodeEditor from './_CodeEditor.vue'

export default {
    name: 'CreateSnippet',
    components: {
        SnippetForm,
        CodeEditor
    },
    data() {
        return {
            loading: false,
            snippet: {
                code: '',
                meta: {
                    name: '',
                    type: 'PHP',
                    status: 'draft',
                    description: '',
                    run_at: 'all',
                    tags: '',
                    priority: 10
                }
            },
            saving: false
        }
    },
    methods: {
        saveCode() {
            // validate the code
            if (!this.snippet.code) {
                this.$notify.error(this.$t('Please enter some code to save'));
                return;
            }
            // check if snippet starts with <?php
            if (this.snippet.meta.type == 'PHP' && this.snippet.code.trim().startsWith('<?php')) {
                this.$notify.error('The code should not starts with <?php');
                return;
            }

            this.saving = true;
            this.$post('snippets/create', {
                meta: JSON.stringify({...this.snippet.meta, code: this.snippet.code})
            })
                .then(response => {
                    this.$notify.success(response.message);
                    this.$router.push({name: 'edit_snippet', params: {snippet_name: response.snippet}});
                })
                .catch((errors) => {
                    this.$handleError(errors);
                })
                .finally(() => {
                    this.saving = false;
                });
        },
        toggleStatus() {
            this.snippet.meta.status = (this.snippet.meta.status == 'published') ? 'draft' : 'published';
            this.saveCode();
        },
        tabChanged() {
            this.snippet.code = '';
            const type = this.snippet.meta.type;
            if (type == 'PHP') {
                this.snippet.meta.run_at = 'all';
            } else if (type == 'php_content') {
                this.snippet.meta.run_at = '';
            } else if (type == 'css') {
                this.snippet.meta.run_at = 'wp_head';
            } else if (type == 'js') {
                this.snippet.meta.run_at = 'wp_footer';
            }
        }
    },
    created() {
        this.snippet.meta.type = Object.keys(this.appVars.snippet_types)[0];
    }
}
</script>
