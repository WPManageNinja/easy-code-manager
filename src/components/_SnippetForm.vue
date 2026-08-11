<template>
    <el-form label-position="top" :model="snippet.meta">
        <el-row :gutter="20">
            <el-col :xs="24" :sm="15" :md="16" :lg="18">
                <slot name="code_editor_before"></slot>
                <slot name="code_editor">
                    <el-form-item :class="'fsnip_code_lang_'+snippet.meta.type" class="code_editor_wrap">
                        <template #label>
                            <span>{{$t('Code')}}</span>
                            <span class="fsn_label" :class="'fsn_'+snippet.meta.type">{{ getLangLabelName(snippet.meta.type) }}</span>
                        </template>
                        <code-editor
                            :langType="snippet.meta.type"
                            v-model="snippet.code"
                            :conditions="snippet.meta.condition"
                        />
                        <save-error-panel v-if="errorDetails" :details="errorDetails" />
                        <div v-else-if="errors.has('code')" class="code_error_block">
                            <p>{{ errors.get('code') }}</p>
                            <pre class="el-form-item__error_explained">{{ errors.get('code_explanation') }}</pre>
                        </div>
                    </el-form-item>
                </slot>

                <where-run :snippet="snippet" :is_new="is_new" />

                <advanced-conditions :snippet="snippet" />

            </el-col>
            <el-col :xs="24" :sm="9" :md="8" :lg="6">
                <el-form-item :label="$t('Snippet Name')">
                    <el-input :placeholder="$t('Your Snippet Name')" size="large" type="text" v-model="snippet.meta.name" />
                    <div class="el-form-item__error">{{ errors.get('name') }}</div>
                </el-form-item>
                <el-form-item :label="$t('Description')">
                    <el-input :placeholder="$t('Internal Description for this snippet')" :rows="3" type="textarea" v-model="snippet.meta.description" />
                </el-form-item>
                <el-form-item :label="$t('Snippet Group')">
                    <template #label>
                        <span>
                            {{$t('Snippet Group')}} <el-tooltip
                            class="box-item"
                            effect="dark"
                            :content="$t('Group your snippets to keep them organized and easy to find.')"
                            placement="top-start"
                        >
                            <el-button class="snip_field_help" text size="small" :icon="InfoField"></el-button>
                          </el-tooltip>
                        </span>
                    </template>
                    <select-plus :pop_placeholder="$t('Create new group')" :placeholder="$t('Select Snippet Group')" :options="appVars.groups" v-model="snippet.meta.group" />
                </el-form-item>
                <el-form-item :label="$t('Priority')">
                    <template #label>
                        <span>
                            {{$t('Priority')}} <el-tooltip
                            class="box-item"
                            effect="dark"
                            :content="$t('The lower the number, the earlier the snippet runs.')"
                            placement="top-start"
                        >
                            <el-button class="snip_field_help" text size="small" :icon="InfoField"></el-button>
                          </el-tooltip>
                        </span>
                    </template>
                    <el-input-number v-model="snippet.meta.priority" :min="1" />
                </el-form-item>
                <el-form-item class="snippet_tags_item">
                    <template #label>
                        <span>
                            {{$t('Tags')}} <el-tooltip
                            class="box-item"
                            effect="dark"
                            :content="$t('Makes your snippets easier to filter.')"
                            placement="top-start"
                        >
                            <el-button class="snip_field_help" text size="small" :icon="InfoField"></el-button>
                          </el-tooltip>
                        </span>
                    </template>
                    <tag-creator v-model="snippet.meta.tags" />
                </el-form-item>

                <template v-if="snippet.meta.type == 'css' || snippet.meta.type == 'js'">
                    <el-form-item  class="snippet_loading_method">
                        <template #label>
                        <span>
                            {{$t('Load as Stylesheet File')}} <el-tooltip
                            class="box-item"
                            effect="dark"
                            :content="$t('When enabled, this snippet is served as a separate stylesheet file.')"
                            placement="top-start"
                        >
                            <el-button class="snip_field_help" text size="small" :icon="InfoField"></el-button>
                          </el-tooltip>
                        </span>
                        </template>

                        <el-checkbox true-value="yes" false-value="no" v-model="snippet.meta.load_as_file">
                            {{$t('Enable Load as Stylesheet File')}}
                        </el-checkbox>
                    </el-form-item>

                    <el-form-item v-if="snippet.meta.type == 'css'" class="snippet_block_editor">
                        <template #label>
                            <span>
                                {{$t('Block Editor Styles?')}} <el-tooltip
                                class="box-item"
                                effect="dark"
                                :content="$t('When enabled, this snippet also loads in the block editor (Gutenberg).')"
                                placement="top-start"
                            >
                                <el-button class="snip_field_help" text size="small" :icon="InfoField"></el-button>
                              </el-tooltip>
                            </span>
                        </template>

                        <el-checkbox true-value="yes" false-value="no" v-model="snippet.meta.load_in_block_editor">
                            {{$t('Load this CSS in Block Editor (Gutenberg)')}}
                        </el-checkbox>

                    </el-form-item>

                </template>


            </el-col>
        </el-row>
    </el-form>
</template>

<script type="text/babel">
import TagCreator from './_TagCreator.vue'
import CodeEditor from './_CodeEditor.vue'
import {InfoFilled} from '@element-plus/icons-vue';
import {markRaw} from "vue";
import SelectPlus from './_SelectPlus';
import AdvancedConditions from './AdvancedConditions';
import WhereRun from './_WhereRun';
import SaveErrorPanel from './_SaveErrorPanel.vue';

export default {
    name: 'SnippetForm',
    components: {
        TagCreator,
        CodeEditor,
        SelectPlus,
        AdvancedConditions,
        WhereRun,
        SaveErrorPanel
    },
    data() {
        return {
            InfoField: markRaw(InfoFilled)
        }
    },
    computed: {
        // Every failure carries `error_details` now, whatever it was about — bad code, a
        // full disk, an expired token. The panel sits under the editor because that is
        // where the user is looking when they press save.
        errorDetails() {
            const details = this.errors.get('error_details');
            return (details && details.title) ? details : null;
        }
    },
    props: ['snippet', 'is_new', 'errors']
}
</script>
