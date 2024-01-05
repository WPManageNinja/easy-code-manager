<template>
    <div class="box_wrapper">
        <div class="box dashboard_box">
            <div class="box_header" style="padding: 15px;font-size: 16px;">
                <div style="padding-top: 5px;" class="box_head">
                    <el-breadcrumb separator="/">
                        <el-breadcrumb-item :to="{ name: 'dashboard' }">{{ $t('Code Snippets') }}</el-breadcrumb-item>
                        <el-breadcrumb-item>
                            {{ $t('Create New') }}
                        </el-breadcrumb-item>
                    </el-breadcrumb>
                </div>
                <div v-if="snippet" style="display: flex;" class="box_actions">
                    <el-button @click="saveCode()" :disabled="saving" v-loading="saving" type="success">
                        {{ $t('Create Snippet') }}
                    </el-button>
                </div>
            </div>
            <div class="box_body">
                <snippet-form v-if="appLoaded" :errors="errors" :is_new="true" :snippet="snippet">
                    <template v-slot:code_editor_before>
                        <el-form-item label="Snippet Type">
                            <el-radio-group @change="snippetTypeChanged()" v-model="snippet.meta.type">
                                <el-radio v-for="(snippetType, type) in appVars.snippet_types" :key="snippetType.value"
                                          :label="snippetType.value">
                                    <span class="custom-tabs-label">
                                              {{ snippetType.label }}
                                        <span :class="'fsn_'+snippetType.value.toLowerCase()" class="fsn_label">
                                            {{ getLangLabelName(snippetType.value) }}
                                        </span>
                                    </span>
                                </el-radio>
                            </el-radio-group>
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
import Errors from '../Bits/Errors.js'

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
            appLoaded: true,
            saving: false,
            errors: new Errors()
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
                this.$notify.error('The code should not start with <?php');
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
                    if (typeof errors == 'string') {
                        this.$notify.error('Something went wrong. Please check the errors.');
                        this.$eventBus.emit("server_error", errors);
                        return;
                    }

                    if (errors && errors.data) {
                        this.errors.record(errors.data);
                    }

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
        snippetTypeChanged() {
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
        },
        $handle500Error(error) {
            console.log(error);
        }
    },
    created() {
        this.snippet.meta.type = Object.keys(this.appVars.snippet_types)[0];
    }
}
</script>
