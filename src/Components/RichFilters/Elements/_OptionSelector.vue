<template>
    <div class="fc_options_selector" :class="(field.creatable) ? 'fc_option_creatable' : ''">

        <el-select :disabled="field.disabled" :size="field.size" v-loading="!element_ready" v-model="model"
                   value-key="id"
                   :multiple="field.is_multiple"
                   :placeholder="field.placeholder"
                   clearable
                   filterable>

            <el-option v-if="element_ready"
                       v-for="option in options[field.option_key]"
                       :key="option.id"
                       :value="Number.isInteger(option.id) ? String(option.id) : option.id"
                       :label="option.title">
                <span v-html="option.title"></span>
            </el-option>
        </el-select>

        <el-popover
            v-if="field.creatable && !field.disabled"
            placement="right"
            :width="400"
            trigger="click"
        >
            <div>
                <el-input :placeholder="$t('Provide Name')" v-model="new_item">
                    <template slot="append">
                        <el-button @click="createNewItem()" type="success">{{ $t('Add') }}</el-button>
                    </template>
                </el-input>
            </div>
            <template #reference>
                <el-button slot="reference" class="fc_with_select" type="info">+</el-button>
            </template>
        </el-popover>
    </div>
</template>

<script type="text/babel">
export default {
    name: 'OptionSelector',
    props: ['value', 'field'],
    data() {
        return {
            options: {},
            model: this.value && Array.isArray(this.value) ? this.value.map(String) : this.value,
            element_ready: false,
            new_item: '',
            creating: false
        }
    },
    watch: {
        model(value) {
            this.$emit('input', value);
        }
    },
    methods: {
        getOptions() {
            this.app_ready = false;
            const query = {
                fields: 'editable_statuses,' + this.field.option_key
            };
            this.$get('reports/options', query).then(response => {
                window.fc_options_cache = response.options;
                this.options = response.options;
                this.element_ready = true;
                this.$emit('element_ready');
            })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {

                });
        },
        createNewItem() {
            this.creating = true;
            if (!this.new_item.length) {
                this.$notify.error('Provide name Field is required');
                return false;
            }
            this.$post(this.field.option_key + '/bulk', {
                items: [
                    {
                        title: this.new_item
                    }
                ]
            })
                .then(response => {
                    this.$notify.success(response.message);
                    this.getOptions();

                    if (response.ids && response.ids[0]) {
                        if (this.field.is_multiple) {
                            this.model.push(String(response.ids[0]));
                        } else {
                            this.model = String(response.ids[0]);
                        }
                    }

                    this.new_item = '';

                    if (this.field.option_key == 'tags') {
                        this.$bus.$emit('renew_options', 'tag');
                    } else if (this.field.option_key == 'lists') {
                        this.$bus.$emit('renew_options', 'list');
                    }
                })
                .catch((errros) => {
                    this.handleError(errros);
                })
                .finally(() => {
                    this.creating = false;
                });
        }
    },
    mounted() {
        if (this.field.is_multiple && typeof this.value !== 'object') {
            this.$set(this, 'model', []);
        }

        if (this.field.option_key == 'tags') {
            this.options[this.field.option_key] = this.appVars.available_tags;
            this.element_ready = true;
            this.$emit('element_ready');
        } else if (this.field.option_key == 'lists') {
            this.options[this.field.option_key] = this.appVars.available_lists;
            this.element_ready = true;
            this.$emit('element_ready');
        } else if (window.fc_options_cache && window.fc_options_cache[this.field.option_key]) {
            this.options = window.fc_options_cache;
            this.element_ready = true;
            this.$emit('element_ready');
        } else {
            this.getOptions();
        }
    }
}
</script>

<style lang="scss">
.fc_option_creatable {
    display: block;
    width: 100%;
    border-radius: 4px;

    .el-select {
        width: 100%;
        float: left;

        input {
            margin: 0;
        }
    }

    .fc_with_select {
        float: left;
        position: absolute;
        right: 0;
    }
}
</style>
