<template>
    <div class="fc_rich_filters">
        <table v-if="items.length && !working" style="width: 100%;" class="fc_table">
            <tbody>
            <filter-item v-for="(item,itemKey) in items" :view_only="view_only" @removeItem="removeItem(itemKey)" :key="itemKey"
                         :filterLabels="filterLabels" :item="item"/>
            </tbody>
        </table>

        <div v-if="items.length == 0" class="fc_filter_intro fc_pad_around_5">
            <el-popover
                :placement="isRTL ? 'left' : 'right'"
                width="450"
                class="fc_contact_filter_pop"
                :visible="addVisible">
                <el-cascader-panel @change="maybeSelected"
                                   style="width: 100%"
                                   :options="filterOptions"
                                   v-model="new_item"/>
                <template #reference>
                    <el-button :icon="PlusIcon" @click="addVisible = !addVisible" size="small">
                        {{$t('Add')}}
                    </el-button>
                </template>
            </el-popover>
            {{add_label}}
            <el-button style="float: right;" @click="$emit('maybeRemove')" size="small" type="danger">
                <el-icon><delete-icon /></el-icon>
            </el-button>
        </div>

        <div v-else-if="!view_only" class="fc_filter_intro fc_pad_around_5">
            <el-popover
                :placement="isRTL ? 'left' : 'right'"
                width="450"
                :visible="addVisible">
                <el-cascader-panel @change="maybeSelected"
                                   style="width: 100%"
                                   :options="filterOptions"
                                   v-model="new_item"/>
                <template #reference>
                    <el-button :icon="PlusIcon" @click="addVisible = !addVisible" size="small">
                        {{$t('And')}}
                    </el-button>
                </template>
            </el-popover>
        </div>
    </div>
</template>
<script type="text/babel">
import FilterItem from './FilterItem';
import each from 'lodash/each';
import {Plus, Delete} from '@element-plus/icons-vue'
import {markRaw} from "vue";

export default {
    name: 'RichFilters',
    components: {
        'delete-icon': markRaw(Delete),
        'filter-item': FilterItem
    },
    props: {
        items: {
            type: Array,
            default: () => []
        },
        add_label: {
            type: String,
            default() {
                return 'Add new filter to narrow down your contacts based on different properties';
            }
        },
        filterOptions: {
            type: Array,
            default() {
                return this.appVars.advanced_filter_options;
            }
        },
        view_only: {
            type: Boolean,
            default() {
                return false;
            }
        }
    },
    data() {
        return {
            addVisible: false,
            new_item: [],
            working: false,
            isRTL: false,
            PlusIcon: markRaw(Plus)
        }
    },
    computed: {
        filterLabels() {
            const options = {};
            each(this.filterOptions, (option) => {
                each(option.children, (item) => {
                    options[option.value + '-' + item.value] = {
                        provider: option.value,
                        ...item
                    }
                });
            });
            return options
        }
    },
    methods: {
        maybeSelected() {
            if (this.new_item.length == 2) {
                let operator = '';

                if (this.new_item[0] == 'subscriber' && this.new_item[1] != 'country') {
                    operator = 'contains';
                }

                this.items.push({
                    source: [...this.new_item],
                    operator: operator,
                    value: ''
                });
                this.addVisible = false;
                this.new_item = [];
            }
        },
        removeItem(index) {
            this.working = true;
            this.$nextTick(() => {
                this.items.splice(index, 1);
                if (!this.items.length) {
                    this.$emit('maybeRemove');
                }
                this.working = false;
            });
        }
    }
}
</script>
