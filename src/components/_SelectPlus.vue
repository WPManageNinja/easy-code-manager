<template>
    <div class="select_plus_wrap">
        <el-select @change="$emit('update:modelValue', selected)" v-model="selected" clearable allow-create filterable :placeholder="placeholder">
            <el-option
                v-for="item in options"
                :key="item"
                :label="item"
                :value="item">
            </el-option>
        </el-select>
        <!--
            v-model:visible, not :visible - the same trap as the sort popover on the
            dashboard. Bound one way the popover is fully controlled, so Element Plus
            will not close it on an outside click or a second press of "+", and the only
            thing that ever set it back to false was Add. With v-model the trigger opens
            and closes it, which is why the button no longer sets the flag by hand.
        -->
        <el-popover v-model:visible="createPop" placement="left" :width="400" trigger="click">
            <template #reference>
                <el-button>+</el-button>
            </template>
            <el-input
                :placeholder="pop_placeholder"
                v-model="new_group"
            >
            </el-input>
            <el-button class="snip_add_item" type="primary" @click="addItem()">{{ $t('Add') }}</el-button>
        </el-popover>
    </div>
</template>

<script type="text/babel">
export default {
    name: 'SelectPlus',
    props: ['options', 'modelValue', 'placeholder', 'pop_placeholder'],
    emits: ['update:modelValue', 'itemCreated'],
    data() {
        return {
            selected: this.modelValue,
            appReady: false,
            new_group: '',
            createPop: false
        }
    },
    methods: {
        addItem() {
            this.options.push(this.new_group);
            this.selected = this.new_group;
            this.$emit('update:modelValue', this.new_group);
            this.$emit('itemCreated', this.new_group);
            this.new_group = '';
            this.createPop = false;
        }
    },
    mounted() {
        this.appReady = true;
    }
}
</script>
