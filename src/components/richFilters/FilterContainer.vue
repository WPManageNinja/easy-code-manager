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
        <!--
            "OR" adds another group of conditions. As an <em> with a click handler it could
            not be tabbed to or activated with a key, and was announced as emphasised text
            rather than as the control it is - so a keyboard user could build one group of
            conditions and never a second.
        -->
        <div class="fc_cond_or">
            <button type="button" class="fc_cond_add" @click="addConditionGroup()"
                    :aria-label="$t('Add another condition group')"><i
                class="el-icon-plus" aria-hidden="true"></i> {{$t('OR')}}</button>
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
            default: 'Add a new filter to run your snippet only under specific conditions.'
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
