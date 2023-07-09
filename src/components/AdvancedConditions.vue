<template>
    <div v-if="condition" class="snip_condition_wrap">
        <el-collapse v-model="activeName">
            <el-collapse-item name="condition">
                <template #title>
                    <h3 style="margin-right: 10px;">{{$t('Advanced Conditional Logic')}}</h3>
                    <el-tooltip placement="top-start" class="box-item" effect="dark" content="Enable logic to add rules and limit where your snippet will be executed. Use multiple groups for different sets of rules.">
                        <el-icon class="header-icon">
                            <el-icon><InfoFilled /></el-icon>
                        </el-icon>
                    </el-tooltip>
                </template>

                <div style="padding: 15px 15px 0;">
                    <el-form-item>
                        <el-switch style="margin-right: 10px;" v-model="condition.status" active-color="#13ce66" active-value="yes" inactive-value="no"></el-switch>
                        {{$t('Enable Conditional Logic')}}
                    </el-form-item>
                </div>

                <template v-if="condition.status == 'yes'">
                    <filter-container :filter-options="options" :advanced_filters="condition.items" />
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
        if(!condition || !condition.status || !condition.items || condition.items.length == 0) {
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
