<template>
    <el-select
        v-model="model"
        :multiple="field.is_multiple"
        filterable
        :remote="!field.cacheable"
        :clearable="field.clearable"
        :disabled="field.disabled"
        reserve-keyword
        :size="field.size"
        :placeholder="field.placeholder || $t('Please enter a keyword')"
        :remote-method="fetchOptions"
        v-loading="loading">
        <el-option
            v-for="item in options"
            :key="item.id"
            :label="item.title"
            :value="item.id">
        </el-option>
    </el-select>
</template>

<script type="text/babel">
export default {
    name: 'AjaxSelector',
    props: ['field', 'value'],
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
    methods: {
        fetchOptions(query) {
            let optionKey = this.field.option_key;
            if (this.field.extended_key) {
                optionKey += '_' + this.field.extended_key;
            }

            let cacheKey = '';

            if (this.field.cacheable) {
                cacheKey += '_fcrm_ajax_cache_' + optionKey;
                if (window[cacheKey]) {
                    this.options = window[cacheKey];
                    return;
                }
            } else if (this.field.experimental_cache) {
                cacheKey += '_fcrm_ajax_cache_' + optionKey + '_' + query + ' ' + JSON.stringify(this.model);
                if (this.field.sub_option_key) {
                    cacheKey += '_' + JSON.stringify(this.field.sub_option_key);
                }
                if (window[cacheKey]) {
                    this.options = window[cacheKey];
                    return;
                }
            }

            if (this.doing_ajax) {
                return false;
            }

            this.loading = true;

            const args = {
                search: query,
                values: this.model,
                option_key: optionKey
            };

            if (this.field.sub_option_key) {
                args.sub_option_key = this.field.sub_option_key;
            }

            this.$get('reports/ajax-options', args)
                .then(response => {
                    this.options = response.options;
                    if (cacheKey && response.options) {
                        window[cacheKey] = response.options;
                    }
                })
                .catch((errors) => {
                    this.handleError(errors)
                })
                .finally(() => {
                    this.loading = false;
                });
        }
    },
    mounted() {
        this.fetchOptions('');
    }
}
</script>
