<template>
    <div v-if="runTypeOptions" class="fsnin_run_wrap">
        <h3>{{$t('Where to Run?')}}</h3>
        <div @click="showSelector = !showSelector" class="fsnin_run_selector">
            <div v-if="selectedOption" class="run_selected run_box">
                <p style="font-weight: bold;">{{ selectedOption.label }}</p>
                <p style="font-size: 80%;">{{ selectedOption.description }}</p>
            </div>
            <div style="border: 1px solid red !important;" v-else class="run_selected run_box">
                <p style="font-weight: bold;">{{$t('Select Snippet Run Location')}}</p>
            </div>
        </div>

        <div v-show="showSelector" class="run_selector_options">
            <div v-for="(runType, runLabel) in runTypeOptions" :key="runLabel" @click="snippet.meta.run_at = runLabel; showSelector = false" class="selector_option">
                <p class="option_label">
                    {{ runType.label }}
                    <el-tag v-if="runLabel == snippet.meta.run_at" size="small">{{$t('selected')}}</el-tag>
                </p>
                <p style="font-size: 80%;">{{ runType.description }}</p>
            </div>
        </div>

        <div v-if="snippet.meta.run_at == 'shortcode'">
            <div v-if="is_new">
                <p>{{$t('You can view the shortcode after save this snippet')}}</p>
            </div>
            <div class="fsnip_highlight" v-else>
                <p style="line-height: 1.9">{{$t('Use Shortcode to display the return or print content of this snippet:')}}</p>
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
            const type = this.snippet.meta.type;
            if(this.appVars.snippet_types[type]) {
                return this.appVars.snippet_types[type].running_locations;
            }
            return null;
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
                    message: this.$t('Shortcode copied to clipboard'),
                    type: 'success'
                });
            }, () => {
                this.$notify.error({
                    message: this.$t('Failed to copy shortcode'),
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
