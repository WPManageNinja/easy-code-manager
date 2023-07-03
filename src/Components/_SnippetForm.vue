<template>
    <el-form label-position="top" :model="snippet.meta">
        <el-form-item label="Snippet Name">
            <el-input placeholder="Your Snippet Name" size="large" type="text" v-model="snippet.meta.name" />
        </el-form-item>
        <slot name="code_editor">
            <el-form-item :class="'fsnip_code_lang_'+snippet.meta.type" class="code_editor_wrap">
                <template #label>
                    <span>Code <el-tag size="small" type="info">{{snippet.meta.type}}</el-tag></span>
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
        <el-form-item label="Description">
            <el-input placeholder="Internal Description for this snippet" type="textarea" v-model="snippet.meta.description" />
        </el-form-item>
        <el-row :gutter="30">
            <el-col :md="12" :sm="12" :xs="24">
                <el-form-item label="Tags">
                    <tag-creator v-model="snippet.meta.tags" />
                </el-form-item>
            </el-col>
            <el-col :md="12" :sm="12" :xs="24">
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
            </el-col>
        </el-row>
    </el-form>
</template>

<script type="text/babel">
import TagCreator from './_TagCreator.vue'
import CodeEditor from './_CodeEditor.vue'
import {InfoFilled} from '@element-plus/icons-vue';
import {markRaw} from "vue";

export default {
    name: 'SnippetForm',
    components: {
        TagCreator,
        CodeEditor
    },
    data() {
        return {
            InfoField: markRaw(InfoFilled)
        }
    },
    props: ['snippet'],
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
    }
}
</script>
