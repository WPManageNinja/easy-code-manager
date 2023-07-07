<template>
    <el-form label-position="top" :model="snippet.meta">
        <el-row :gutter="20">
            <el-col :xs="24" :sm="15" :md="16" :lg="18">
                <slot name="code_editor">
                    <el-form-item :class="'fsnip_code_lang_'+snippet.meta.type" class="code_editor_wrap">
                        <template #label>
                            <span>Code</span>
                            <span class="fsn_label" :class="'fsn_'+snippet.meta.type">{{ getLangLabelName(snippet.meta.type) }}</span>
                        </template>
                        <code-editor
                            :langType="snippet.meta.type"
                            v-model="snippet.code"
                        />
                    </el-form-item>
                </slot>
                <el-form-item v-if="runTypeOptions" label="Where to run?">
                    <el-radio-group v-model="snippet.meta.run_at">
                        <el-radio v-for="(runType, runLabel) in runTypeOptions" :key="runLabel" :label="runLabel">{{ runType }}</el-radio>
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
                                <el-icon @click="copyShortCode()"><CopyDocument /></el-icon>
                            </span>
                        </div>
                    </div>
                </div>

                <advanced-conditions :snippet="snippet" />

            </el-col>
            <el-col :xs="24" :sm="9" :md="8" :lg="6">
                <el-form-item label="Snippet Name">
                    <el-input placeholder="Your Snippet Name" size="large" type="text" v-model="snippet.meta.name" />
                </el-form-item>
                <el-form-item label="Description">
                    <el-input placeholder="Internal Description for this snippet" :rows="3" type="textarea" v-model="snippet.meta.description" />
                </el-form-item>
                <el-form-item label="Snippet Group">
                    <template #label>
                        <span>
                            Snippet Group <el-tooltip
                            class="box-item"
                            effect="dark"
                            content="You may group your snippets for better organization and easy to find."
                            placement="top-start"
                        >
                            <el-button text size="small" :icon="InfoField" style="font-style: italic"></el-button>
                          </el-tooltip>
                        </span>
                    </template>
                    <select-plus pop_placeholder="Create new group" placeholder="Select Snippet Group" :options="appVars.groups" v-model="snippet.meta.group" />
                </el-form-item>
                <el-form-item label="Priority">
                    <template #label>
                        <span>
                            Priority <el-tooltip
                            class="box-item"
                            effect="dark"
                            content="The lower the number, the earlier to execute the snippet."
                            placement="top-start"
                        >
                            <el-button text size="small" :icon="InfoField" style="font-style: italic"></el-button>
                          </el-tooltip>
                        </span>
                    </template>
                    <el-input-number v-model="snippet.meta.priority" :min="1" />
                </el-form-item>
                <el-form-item class="snippet_tags_item" label="Tags">
                    <tag-creator v-model="snippet.meta.tags" />
                </el-form-item>
            </el-col>
        </el-row>

    </el-form>
</template>

<script type="text/babel">
import TagCreator from './_TagCreator.vue'
import CodeEditor from './_CodeEditor.vue'
import {InfoFilled, CopyDocument} from '@element-plus/icons-vue';
import {markRaw} from "vue";
import SelectPlus from './_SelectPlus.vue';
import AdvancedConditions from './_AdvancedConditions.vue';

export default {
    name: 'SnippetForm',
    components: {
        TagCreator,
        CodeEditor,
        SelectPlus,
        CopyDocument,
        AdvancedConditions
    },
    data() {
        return {
            InfoField: markRaw(InfoFilled)
        }
    },
    props: ['snippet', 'is_new'],
    computed: {
        runTypeOptions() {
            if (this.snippet.meta.type == 'PHP') {
                return {
                    'all': 'Everywhere',
                    'backend': 'Backend',
                    'frontend': 'Frontend'
                }
            }

            if(this.snippet.meta.type == 'php_content') {
                return {
                    'shortcode': '[/] Only display when inserted into a post or page',
                    'wp_head': '<> Display in site <head> section.',
                    'wp_footer': '<> Display at the end of the <body> section, in the footer.',
                }
            }

            if(this.snippet.meta.type == 'js') {
                return {
                    'wp_head': '<> Add Javascript on header',
                    'wp_footer': '<> Add Javascript on footer',
                }
            }

            return false;
        }
    },
    methods: {
        getFileName(file) {
            return file.replace('.php', '');
        },
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
