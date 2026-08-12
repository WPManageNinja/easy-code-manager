<template>
    <!--
        Where the snippet runs.

        This was a <div> that opened a panel of <div>s, with click handlers on both. Nothing
        in it could be reached with a keyboard, nothing announced itself as a control, and
        nothing said which option was chosen except a coloured border - so choosing where a
        snippet runs, which is the second most important decision on this screen, could not
        be done at all without a mouse.

        It is now a disclosure button over a group of real radio inputs. The inputs are
        visually hidden rather than replaced, which is what buys arrow-key navigation,
        grouping, the checked state and the label association for free instead of
        reimplementing four ARIA patterns by hand.
    -->
    <div v-if="runTypeOptions" class="fsnin_run_wrap">
        <h2 id="fsnip_run_at_heading">{{$t('Where to Run?')}}</h2>
        <div class="fsnin_run_selector">
            <button type="button" @click="showSelector = !showSelector"
                    class="run_selected run_box" :class="{run_box_error: !selectedOption}"
                    :aria-expanded="showSelector ? 'true' : 'false'"
                    aria-controls="fsnip_run_at_options">
                <template v-if="selectedOption">
                    <span class="fsnip_sr_only">{{ $t('Where to Run?') }}: </span>
                    <span class="option_label">{{ selectedOption.label }}</span>
                    <span class="option_desc">{{ selectedOption.description }}</span>
                </template>
                <span v-else class="option_label">{{$t('Select Snippet Run Location')}}</span>
            </button>
            <slot></slot>
        </div>

        <div v-show="showSelector" id="fsnip_run_at_options" class="run_selector_options"
             role="radiogroup" aria-labelledby="fsnip_run_at_heading">
            <label v-for="(runType, runLabel) in runTypeOptions" :key="runLabel"
                   :class="{selector_option_selected: runLabel == snippet.meta.run_at}" class="selector_option">
                <input type="radio" class="fsnip_sr_only" name="fsnip_run_at" :value="runLabel"
                       :checked="runLabel == snippet.meta.run_at"
                       @change="selectRunAt(runLabel)"/>
                <span class="option_label">
                    {{ runType.label }}
                    <el-tag v-if="runLabel == snippet.meta.run_at" size="small">{{$t('selected')}}</el-tag>
                </span>
                <span class="option_desc">{{ runType.description }}</span>
            </label>
        </div>

        <div v-if="snippet.meta.run_at == 'shortcode'">
            <div v-if="is_new">
                <p>{{$t('You can view the shortcode after you save this snippet')}}</p>
            </div>
            <div class="fsnip_highlight" v-else>
                <p>{{$t('Use Shortcode to display the return or print content of this snippet:')}}</p>
                <div class="snip_shortcode">
                <span class="snip_code">
                    [fluent_snippet id="{{ getFileName(snippet.file_name) }}"]
                    <!-- A button. As a bare <el-icon> there was no way to copy this without a mouse. -->
                    <button type="button" class="snip_copy" :aria-label="$t('Copy shortcode')"
                            @click="copyShortCode()"><el-icon aria-hidden="true"><CopyDocument/></el-icon></button>
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
        /*
         * Picking an option no longer shuts the panel.
         *
         * A radio group is navigated with the arrow keys, and every arrow press fires a
         * change - so closing on change would collapse the panel on the first keystroke and
         * leave the user with whichever option they arrived at rather than the one they
         * were moving towards. The trigger closes it, which is what the trigger is for.
         */
        selectRunAt(runLabel) {
            this.snippet.meta.run_at = runLabel;
        },
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
