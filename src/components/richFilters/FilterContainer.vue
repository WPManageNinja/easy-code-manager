<template>
    <div class="fc_rich_container">
        <div class="fc_rich_wrap">
            <div v-for="(rich_filter, filterIndex) in advanced_filters" :key="filterIndex">
                <div class="fc_rich_filter">
                    <rich-filters :filterOptions="filterOptions" :add_label="add_label" @maybeRemove="maybeRemoveGroup(filterIndex)" :items="rich_filter"/>
                </div>
                <div class="fc_cond_or">
                    <em>{{$t('OR')}}</em>
                </div>
            </div>
        </div>
        <div class="fc_cond_or">
            <em @click="addConditionGroup()"
                style="cursor: pointer; color: rgb(0, 119, 204); font-weight: bold;"><i
                class="el-icon-plus"></i> {{$t('OR')}}</em>
        </div>
    </div>
</template>

<script type="text/babel">
import RichFilters from './RichFilters';
import isArray from 'lodash/isArray';

export default {
    name: 'ConditionFilters',
    components: {
        RichFilters
    },
    props: {
        advanced_filters: {
            type: Array,
            default: function () {
                return [[]];
            }
        },
        add_label: {
            type: String,
            default: 'Add new filter to execute your snippet to specific conditions.'
        },
        filterOptions: {
            type: Array,
            default: function () {
                return [];
            }
        }
    },
    methods: {
        maybeRemoveGroup(index) {
            if (this.advanced_filters.length > 1) {
                this.advanced_filters.splice(index, 1);
            }
        },
        addConditionGroup() {
            this.advanced_filters.push([]);
        }
    },
    mounted() {
        if (!this.advanced_filters || !isArray(this.advanced_filters) || this.advanced_filters.length === 0) {
            this.advanced_filters = [[]];
        }
    }
}
</script>
