<template>
    <codemirror
        :class="'fsnip_code fsnip_code_'+langType"
        v-model="code"
        :placeholder="placeholder"
        :style="{ minHeight: '400px', maxHeight: '80vh', height: 'auto'}"
        :autofocus="true"
        :indent-with-tab="true"
        :tab-size="4"
        :extensions="extensions"
    />
</template>

<script type="text/babel">
import { Codemirror } from 'vue-codemirror'
import { php } from '@codemirror/lang-php'
import { css } from '@codemirror/lang-css'
import { javascript } from '@codemirror/lang-javascript'
import { oneDark } from '@codemirror/theme-one-dark'

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
        snippet: {
            type: Object,
            default: () => {}
        }
    },
    computed: {
        placeholder() {
            if(this.langType == 'js') {
                return 'Write your Javascript code here';
            }
            if(this.langType == 'css') {
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
        if(this.langType == 'css') {
            lang = css();
        } else if(this.langType == 'js') {
            lang = javascript();
        } else {
            lang = php({
                plain: this.langType === 'PHP'
            });
        }

        return {
            code: this.modelValue,
            extensions: [lang, oneDark]
        }
    },
    watch: {
        code() {
            this.$emit('update:modelValue', this.code)
        }
    }
}

</script>
