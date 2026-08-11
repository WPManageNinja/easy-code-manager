<template>
    <div v-if="condition" class="snip_condition_wrap">
        <el-collapse v-model="activeName">
            <el-collapse-item name="condition">
                <template #title>
                    <div class="snip_cond_title">
                        <h2>{{ $t('Advanced Conditional Logic') }}</h2>
                        <el-tooltip
                            placement="top-start"
                            class="box-item"
                            effect="dark"
                            :content="$t('Enable logic to add rules that limit where your snippet runs. Use multiple groups for different sets of rules.')"
                        >
                            <el-icon class="header-icon">
                                <InfoFilled/>
                            </el-icon>
                        </el-tooltip>
                    </div>
                </template>

                <!--
                    The words beside the toggle are loose text in the form item, not a
                    label bound to the control, so the toggle was announced with no name at
                    all. The name is the same sentence you can already see.
                -->
                <div class="snip_cond_toggle">
                    <el-form-item>
                        <el-switch v-model="condition.status"
                                   :aria-label="$t('Enable Conditional Logic')"
                                   active-value="yes" inactive-value="no"></el-switch>
                        {{ $t('Enable Conditional Logic') }}
                    </el-form-item>
                </div>

                <template v-if="condition.status == 'yes'">
                    <filter-container :filter-options="options" :advanced_filters="condition.items"/>
                </template>
            </el-collapse-item>
        </el-collapse>
    </div>
</template>

<script type="text/babel">
import FilterContainer from '@/components/richFilters/FilterContainer';
import {InfoFilled} from '@element-plus/icons-vue'
import {markRaw} from "vue";

export default {
    name: 'AdvancedConditions',
    props: ['snippet'],
    components: {
        FilterContainer,
        InfoFilled: markRaw(InfoFilled)
    },
    data() {
        return {
            activeName: 'condition',
            options: [],
            condition: null
        }
    },
    created() {
        let condition = this.snippet.meta.condition;
        if (!condition || !condition.status || !condition.items || condition.items.length == 0) {
            condition = {
                status: 'no',
                run_if: 'assertive',
                items: [[]]
            }
            this.snippet.meta.condition = condition;
        }
        this.condition = this.snippet.meta.condition;
        this.options = this.appVars.advanced_condition_options;
    }
}
</script>
