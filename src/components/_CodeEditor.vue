<template>
    <!--
        `disabled` is CodeMirror's read-only mode: the code still highlights, still
        scrolls and can still be selected and copied, which is the whole point of the
        screen when nothing here can be saved. Autofocus goes with it - putting a cursor
        in a box that will not accept typing is a small lie.
    -->
    <codemirror
        v-if="appReady"
        :class="'fsnip_code fsnip_code_'+langType"
        v-model="code"
        :placeholder="placeholder"
        :autofocus="canEdit"
        :disabled="!canEdit"
        :indent-with-tab="true"
        :tab-size="4"
        :extensions="extensions"
    />

    <!--
        How to get the keyboard back out of the editor.

        Tab indents rather than moving on - which is what you want while writing code, and
        is also the thing that leaves a keyboard user stuck in the box with no visible way
        out. CodeMirror's escape hatch is Escape and then Tab, and WCAG's no-keyboard-trap
        rule is only satisfied if the user is actually told what it is. So it is written
        down, next to the editor, and pointed at by aria-describedby so it is read out on
        the way in rather than only found by someone already trapped.
    -->
    <p id="fsnip_editor_help" class="fsnip_editor_help">
        {{ $t('Tab inserts an indent. To move on to the next control, press Escape and then Tab.') }}
    </p>

    <div v-if="errorHooks && errorHooks.length" class="fsnip_error_hooks">
        <p>{{$t('It seems like you are using some hooks that may not work correctly, because this code will run after the mentioned hooks:')}}</p>
        <ul>
            <li v-for="hook in errorHooks" :key="hook">{{ hook }}</li>
        </ul>
        <p v-if="errorHooks.indexOf('init') > -1">{{$t('* As you have conditional logics enabled the code will run on init hook.')}}</p>
    </div>

</template>

<script type="text/babel">
import {Codemirror} from 'vue-codemirror'
import {php} from '@codemirror/lang-php'
import {css} from '@codemirror/lang-css'
import { EditorView } from "@codemirror/view";
import {javascript} from '@codemirror/lang-javascript'
import {oneDark} from '@codemirror/theme-one-dark'

export default {
    name: 'CodeEditor',
    props: {
        modelValue: {
            type: String,
            default: ''
        },
        langType: {
            type: String,
            default: 'PHP'
        },
        conditions: {
            type: Object,
            default: () => {
            }
        }
    },
    computed: {
        placeholder() {
            if (this.langType == 'js') {
                return this.$t('Write your JavaScript code here');
            }
            if (this.langType == 'css') {
                return this.$t('Write your CSS code here');
            }
            return this.$t('Write your code here...');
        }
    },
    components: {
        Codemirror
    },
    data() {
        return {
            code: this.modelValue,
            extensions: this.buildExtensions(),
            appReady: false,
            timer: null,
            errorHooks: []
        }
    },
    watch: {
        code() {
            this.$emit('update:modelValue', this.code)
        },
        langType() {
            this.appReady = false;

            /*
             * Rebuilt, not just remounted. The extension list was assembled once in data()
             * and never touched again, so switching the snippet type tore the editor down
             * and put it back up with the previous language still in it - which now also
             * meant it would announce itself as a PHP editor while holding CSS.
             */
            this.extensions = this.buildExtensions();

            this.$nextTick(() => {
                this.appReady = true;
            });

            if (this.langType == 'PHP') {
                this.checkPhpError();
            }

            this.maybeStartTimer();
        }
    },
    methods: {
        /*
         * The editor's name. CodeMirror renders a contenteditable div rather than a
         * <textarea>, so it inherits no name from the form item's label - without this it
         * is announced as an unlabelled edit box, and which of the languages it is
         * expecting is exactly what the user needs to be told.
         *
         * A method rather than a computed because buildExtensions() is called from data(),
         * and computed properties are not set up until after data() has run.
         */
        editorLabel() {
            if (this.langType == 'js') {
                return this.$t('JavaScript code editor');
            }

            if (this.langType == 'css') {
                return this.$t('CSS code editor');
            }

            return this.$t('PHP code editor');
        },
        /*
         * The editor's extensions, including the ones that give it a name.
         *
         * `contentAttributes` and not an attribute in the template: CodeMirror's editable
         * surface is a contenteditable <div> nested inside the component's wrapper, and an
         * attribute written on <codemirror> lands on the wrapper - which is not the element
         * with the textbox role, so a name put there is never read. This facet writes onto
         * the content element itself.
         */
        buildExtensions() {
            let lang;

            if (this.langType == 'css') {
                lang = css();
            } else if (this.langType == 'js') {
                lang = javascript();
            } else {
                lang = php({
                    plain: this.langType === 'PHP'
                });
            }

            return [
                lang,
                oneDark,
                EditorView.lineWrapping,
                EditorView.contentAttributes.of({
                    'aria-label': this.editorLabel(),
                    'aria-describedby': 'fsnip_editor_help'
                })
            ];
        },
        checkPhpError() {
            this.errorHooks = [];

            if (this.langType != 'PHP') {
                return;
            }

            if (!this.code) {
                return;
            }

            let hooks = ['plugins_loaded', 'mu_plugin_loaded', 'setup_theme'];

            if (this.conditions && this.conditions.status == 'yes') {
                hooks.push('after_setup_theme');
                hooks.push('init');
            }

            this.errorHooks = this.findHooksInCode(this.code, hooks);
        },
        findHooksInCode(code, hooks) {
            // Create a regex pattern with dynamic tokens, excluding the quotes from the capturing group
            const tokensPattern = hooks.map(hook => `['"]${hook}['"]`).join('|');
            const regexPattern = `add_action\\s*\\(\\s*['"](${hooks.join('|')})['"]\\s*,`;

            // Create a regex from the pattern
            const regex = new RegExp(regexPattern, 'g');
            let matches;
            const foundHooks = [];

            // Find all matches and extract the hook names
            while ((matches = regex.exec(code)) !== null) {
                // The first element in the capturing group will be the hook name without quotes
                if (matches[1]) {
                    foundHooks.push(matches[1]);
                }
            }

            return foundHooks;
        },
        maybeStartTimer() {
            if (this.langType == 'PHP') {
                if (!this.timer) {
                    this.timer = setInterval(() => {
                        this.checkPhpError();
                    }, 5000);
                }
                return;
            }

            if (this.timer) {
                clearInterval(this.timer);
            }
        }
    },
    mounted() {
        this.maybeStartTimer();

        if (this.appVars?.enable_line_wrap === 'yes') {
            this.extensions.push(EditorView.lineWrapping);
        }
        this.appReady = true;
    },
    beforeUnmount() {
        if (this.timer) {
            clearInterval(this.timer);
        }
    }
}

</script>
