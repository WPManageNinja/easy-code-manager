<template>
    <tr>
        <td style="width: 190px; line-height: 110%;">
            {{ ucFirst(itemConfig.provider) }} <span class="fs_provider_separator">/</span>
            {{ itemConfig.label }}
            <span v-if="itemConfig.help">
                <el-tooltip class="item" effect="dark" placement="top-start">
                    <i class="el-icon el-icon-info"></i>
                    <span slot="content" v-html="itemConfig.help"></span>
                </el-tooltip>
            </span>
        </td>
        <td style="width: 190px" class="fc_filter_operator">
            <el-select :disabled="view_only" size="small" :placeholder="$t('Select Operator')"
                       @visible-change="maybeOperatorSelected"
                       v-model="item.operator">
                <el-option v-for="(optionLabel,option) in operatorOptions" :key="option" :value="option"
                           :label="optionLabel"></el-option>
            </el-select>
        </td>
        <td :class="'fnsip_filter_'+itemConfig.type" class="fc_filter_value">
            <template v-if="item.operator == 'is_null' || item.operator == 'not_null'">
                --
            </template>
            <template v-else>
                <el-input :disabled="view_only" size="small"
                          v-if="!itemConfig.type || itemConfig.type == 'text' || itemConfig.type == 'extended_text' || itemConfig.type == 'nullable_text'"
                          :placeholder="$t('Condition Value')"
                          type="text" v-model="item.value"/>
                <el-input :disabled="view_only" size="small" v-else-if="itemConfig.type == 'numeric'" type="number"
                          :placeholder="$t('Condition Value')"
                          :min="itemConfig.min"
                          v-model="item.value"/>
                <template v-if="itemConfig.type == 'selections'">
                    <template v-if="itemConfig.options">
                        <el-select :disabled="view_only" size="small" :multiple="itemConfig.is_multiple"
                                   :placeholder="$t('Select Option')"
                                   v-model="item.value">
                            <el-option v-for="(optionLabel,option) in itemConfig.options" :key="option" :value="option"
                                       :label="optionLabel"></el-option>
                        </el-select>
                    </template>
                    <template v-else-if="itemConfig.disable_values">
                        <p v-html="itemConfig.value_description"></p>
                    </template>
                    <pre v-else>{{ itemConfig }}</pre>
                </template>
                <template
                    v-else-if="itemConfig.type == 'single_assert_option' || itemConfig.type == 'straight_assert_option'">
                    <el-select size="small" :placeholder="$t('Select Option')" :disabled="view_only"
                               v-model="item.value">
                        <el-option v-for="(optionLabel,option) in itemConfig.options" :key="option" :value="option"
                                   :label="optionLabel"></el-option>
                    </el-select>
                </template>
                <template v-else-if="itemConfig.type == 'times_numeric'">
                    <item-times-selection :disabled="view_only" v-model="item.value" :field="itemConfig"/>
                </template>
                <template v-else-if="itemConfig.type == 'text_comma_in'">
                    <div class="fsnip_value_help" v-if="itemConfig.value_help" v-html="itemConfig.value_help"></div>
                    <el-input :disabled="view_only" size="small"
                              :placeholder="$t('Condition Value')"
                              type="text" v-model="item.value"/>
                </template>
                <template v-else-if="itemConfig.type == 'dates'">
                    <el-input :disabled="view_only" size="small"
                              v-if="item.operator == 'days_before' || item.operator == 'days_within'"
                              type="number" :placeholder="$t('Days')" v-model="item.value"/>
                    <el-date-picker v-else-if="item.operator" :type="itemConfig.date_type || 'date'"
                                    :disabled="view_only" :value-format="itemConfig.value_format || 'YYYY-MM-DD'"
                                    size="small"
                                    v-model="item.value"></el-date-picker>
                </template>
                <template v-else-if="itemConfig.type == 'time_range'">
                    <el-time-picker arrow-control is-range size="small" :value-format="itemConfig.value_format || 'HH:mm:ss'" v-model="item.value"></el-time-picker>
                </template>
            </template>
        </td>
        <td v-if="!view_only" style="width: 50px; text-align: right;">
            <el-button
                @click="removeItem()"
                size="small"
                type="danger">
                <el-icon>
                    <DeleteIcon/>
                </el-icon>
            </el-button>
        </td>
    </tr>
</template>

<script type="text/babel">
import isArray from 'lodash/isArray';
// import AjaxSelector from './Elements/_AjaxSelector';
// import TaxonomyTermsSelector from './Elements/_TaxonomyTermsSelector';
// import OptionSelector from './Elements/_OptionSelector';
// import ItemTimesSelection from './_ItemTimesSelection';
import {Delete} from '@element-plus/icons-vue'
import {markRaw} from "vue";

export default {
    name: 'FilterItem',
    props: ['item', 'filterLabels', 'view_only'],
    components: {
        // OptionSelector,
        // AjaxSelector,
        // TaxonomyTermsSelector,
        // ItemTimesSelection,
        DeleteIcon: markRaw(Delete)
    },
    data() {
        return {}
    },
    computed: {
        operatorOptionsNative() {
            const type = this.itemConfig.type;

            if (type == 'extended_text') {
                return {
                    contains: this.$t('includes'),
                    not_contains: this.$t('does not includes'),
                    '=': this.$t('equal'),
                    '!=': this.$t('does not equal'),
                    startsWith: this.$t('starts with'),
                    endsWith: this.$t('ends with')
                }
            }

            if (!type || type == 'text') {
                return {
                    contains: this.$t('includes'),
                    not_contains: this.$t('does not includes'),
                    '=': this.$t('equal'),
                    '!=': this.$t('does not equal'),
                }
            }

            if (type == 'numeric' || type == 'times_numeric') {
                return {
                    '>': this.$t('Greater Than'),
                    '<': this.$t('Less Than'),
                    '=': this.$t('equal'),
                    '!=': this.$t('does not equal')
                }
            }

            if (type == 'selections') {
                if (this.itemConfig.custom_operators) {
                    return this.itemConfig.custom_operators;
                }

                if (this.itemConfig.option_key === 'countries') {
                    return {
                        in: this.$t('includes in'),
                        not_in: this.$t('not includes in'),
                        is_null: this.$t('Empty'),
                        not_null: this.$t('Not Empty')
                    }
                }

                if (this.itemConfig.is_multiple && !this.itemConfig.is_singular_value) {
                    return {
                        in: this.$t('includes'),
                        not_in: this.$t('Does not include (in any)'),
                        in_all: this.$t('includes all of'),
                        not_in_all: this.$t('includes none of (match all)')
                    };
                }

                if (this.itemConfig.is_only_in) {
                    return {
                        in: this.$t('includes in')
                    }
                }

                return {
                    in: this.$t('includes in'),
                    not_in: this.$t('not includes in')
                };
            }
            if (type == 'single_assert_option') {
                return {
                    '=': this.$t('equal')
                };
            }

            if (type == 'straight_assert_option') {
                return {
                    '=': this.$t('equal'),
                    '!=': this.$t('not equal')
                };
            }

            if (type == 'dates' || type == 'time_range') {
                if(this.itemConfig.is_range) {
                    if(!isArray(this.item.value)) {
                        this.value = [null, null];
                    }

                    return {
                        date_within: this.$t('within'),
                        date_not_within: this.$t('not within')
                    }
                }

                return {
                    before: this.$t('before'),
                    after: this.$t('after'),
                    date_equal: this.$t('in the date'),
                    days_before: this.$t('before days'),
                    days_within: this.$t('within days')
                }
            }

            if (type == 'nullable_text') {
                return {
                    '=': this.$t('equal'),
                    '!=': this.$t('does not equal'),
                    contains: this.$t('includes'),
                    not_contains: this.$t('does not includes'),
                    is_null: this.$t('Empty'),
                    not_null: this.$t('Not Empty')
                }
            }

            if(type == 'text_comma_in') {
                return {
                    in: this.$t('includes in'),
                    not_in: this.$t('not includes in')
                }
            }

            return {}
        },
        operatorOptions() {
            return this.operatorOptionsNative;
        },
        itemConfig() {
            const key = this.item.source.join('-');
            return this.filterLabels[key] || {}
        }
    },
    methods: {
        closingSource(status) {
            if (!status) {
                setTimeout(() => {
                    jQuery(this.$el).find('.fc_filter_operator .el-select').trigger('click');
                }, 300);
            }
        },
        maybeOperatorSelected(status) {
            if (!status && this.item.operator) {
                if (this.itemConfig.type == 'dates') {
                    this.item.value = '';
                }
                setTimeout(() => {
                    jQuery(this.$el).find('.fc_filter_value input').focus();
                }, 200);
            }
        },
        removeItem() {
            this.$emit('removeItem');
        }
    },
    mounted() {
        if (this.itemConfig.is_multiple && !isArray(this.item.value)) {
            this.item.value = [];
        }
        if (!this.item.operator) {
            const objectValues = Object.keys(this.operatorOptions);
            if (objectValues.length) {
                this.item.operator = objectValues[0];
                jQuery(this.$el).find('.fc_filter_operator .el-select').trigger('click');
            }
        } else {
            const itemOperator = this.item.operator;

            const objectValues = Object.keys(this.operatorOptions);

            if (objectValues.length && objectValues.indexOf(itemOperator) === -1) {
                this.item.operator = objectValues[0];
            }
        }
    }
}
</script>
