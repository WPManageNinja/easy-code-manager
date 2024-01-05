<template>
    <codemirror
        v-if="appReady"
        :class="'fsnip_code fsnip_code_'+langType"
        v-model="code"
        :placeholder="placeholder"
        :style="{ minHeight: '400px', maxHeight: '80vh', height: 'auto'}"
        :autofocus="true"
        :indent-with-tab="true"
        :tab-size="4"
        :extensions="extensions"
    />

    <div v-if="errorHooks && errorHooks.length" class="fsnip_error_hooks">
        <p>It seems like you're using some hooks that may not work correctly, because this code will run after the
            mentioned hooks:</p>
        <ul>
            <li v-for="hook in errorHooks" :key="hook">{{ hook }}</li>
        </ul>
        <p v-if="errorHooks.indexOf('init') > -1">* As you have conditional logics enabled the code will run on init
            hook.</p>
    </div>

</template>

<script type="text/babel">
import {Codemirror} from 'vue-codemirror'
import {php} from '@codemirror/lang-php'
import {css} from '@codemirror/lang-css'
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
                return 'Write your Javascript code here';
            }
            if (this.langType == 'css') {
                return 'Write your CSS here';
            }
            return 'Code goes here...';
        }
    },
    components: {
        Codemirror
    },
    data() {
        let lang = null;
        if (this.langType == 'css') {
            lang = css();
        } else if (this.langType == 'js') {
            lang = javascript();
        } else {
            lang = php({
                plain: this.langType === 'PHP'
            });
        }

        return {
            code: this.modelValue,
            extensions: [lang, oneDark],
            appReady: true,
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
    },
    beforeUnmount() {
        if (this.timer) {
            clearInterval(this.timer);
        }
    }
}

</script>
