<template>
    <div class="fc_item_num_selector">
        <ajax-selector v-model="model[0]" :field="{
                        is_multiple: field.is_multiple,
                        option_key: field.primary_selector,
                        extended_key: field.extended_key || '',
                        size: 'small',
                        disabled: disabled,
                        placeholder: field.primary_placeholder,
                        cacheable: field.cacheable
                    }"/>
        <el-input :disabled="disabled" :placeholder="field.numeric_placeholder" size="small" type="number" :min="0"
                  v-model="model[1]"></el-input>
        <span v-if="field.input_help">
            <el-tooltip class="item" effect="dark" :content="field.input_help" placement="top-start">
                <i class="el-icon el-icon-info"></i>
            </el-tooltip>
        </span>
    </div>
</template>

<script type="text/babel">
import isArray from 'lodash/isArray';
import AjaxSelector from './Elements/_AjaxSelector';

export default {
    name: 'ItemTimesSelection',
    props: ['field', 'value', 'disabled'],
    components: {
        AjaxSelector
    },
    data() {
        return {
            model: this.value,
            loading: false,
            options: []
        }
    },
    watch: {
        model(value) {
            this.$emit('input', value);
        }
    },
    mounted() {
        if (!isArray(this.model)) {
            this.model = ['', 2];
        }
    }
}
</script>
