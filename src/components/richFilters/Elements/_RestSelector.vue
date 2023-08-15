<template>
    <el-select
        v-model="model"
        :multiple="field.is_multiple"
        filterable
        remote
        reserve-keyword
        :disabled="field.disabled"
        :size="field.size"
        :placeholder="field.placeholder || 'Please enter a keyword'"
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
                    :value="item.id" />
            </el-option-group>
        </template>
        <template v-else>
            <el-option
                v-for="item in group.options"
                :key="item.id"
                :label="item.title"
                :value="item.id" />
        </template>
    </el-select>
</template>

<script type="text/babel">
export default {
    name: 'TaxonomySelector',
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
            if(window['fsnip_cache_options_'+this.field.rest_key]) {
                this.options = window['fsnip_cache_options_'+this.field.rest_key];
                return;
            }

            this.loading = true;
            this.$get('settings/options', {
                search: query,
                values: this.model,
                rest_key: this.field.rest_key
            })
                .then(response => {
                    if(response.is_cachable) {
                        window['fsnip_cache_options_'+this.field.rest_key] = response.options;
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
        this.fetchOptions('');
    }
}
</script>
