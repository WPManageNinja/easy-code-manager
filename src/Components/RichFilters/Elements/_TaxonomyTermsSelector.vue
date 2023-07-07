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
        <el-option
            v-for="item in options"
            :key="item.id"
            :label="item.title"
            :value="item.id" />
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
            this.loading = true;
            this.$get('reports/taxonomy-terms', {
                search: query,
                values: this.model,
                taxonomy: this.field.taxonomy
            })
                .then(response => {
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
