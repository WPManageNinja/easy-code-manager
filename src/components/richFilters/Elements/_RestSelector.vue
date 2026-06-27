<template>
    <el-select
        v-model="model"
        :multiple="field.is_multiple"
        filterable
        remote
        reserve-keyword
        :disabled="field.disabled"
        :size="field.size"
        :placeholder="field.placeholder || $t('Please enter a keyword')"
        :remote-method="fetchOptions"
        :loading="loading">

        <template v-if="field.is_grouped">
            <el-option-group
                v-for="group in options"
                :key="group.label"
                :label="group.label"
            >
                <el-option
                    v-for="item in group.options"
                    :key="item.id"
                    :label="item.title"
                    :value="item.id">
                    <template v-if="field.show_id">
                        {{ item.title || item.id }} <span style="float: right; font-size: 70%;">{{ item.id }}</span>
                    </template>
                </el-option>
            </el-option-group>
        </template>
        <template v-else>
            <el-option
                v-for="item in options"
                :key="item.id"
                :label="item.title"
                :value="item.id"/>
        </template>
    </el-select>
</template>

<script type="text/babel">
import isEmpty from 'lodash/isEmpty';

export default {
    name: 'TaxonomySelector',
    props: ['field', 'modelValue'],
    $emits: ['update:modelValue'],
    data() {
        return {
            model: this.modelValue,
            loading: false,
            options: []
        }
    },
    watch: {
        model(value) {
            this.$emit('update:modelValue', value);
        }
    },
    methods: {
        fetchOptions(query) {
            if (window['fsnip_cache_options_' + this.field.rest_key]) {
                this.options = window['fsnip_cache_options_' + this.field.rest_key];
                return;
            }

            if (!query && !isEmpty(this.options)) {
                return;
            }

            this.loading = true;
            this.$get('settings/options', {
                search: query,
                values: this.modelValue,
                rest_key: this.field.rest_key
            })
                .then(response => {
                    if (response.is_cachable) {
                        window['fsnip_cache_options_' + this.field.rest_key] = response.options;
                    }
                    this.options = response.options;
                })
                .catch((errors) => {
                    this.handleError(errors)
                })
                .finally(() => {
                    this.loading = false;
                })
        }
    },
    mounted() {
        if (this.field.is_multiple && !Array.isArray(this.modelValue)) {
            this.model = [];
        }

        this.fetchOptions('');
    }
}
</script>
