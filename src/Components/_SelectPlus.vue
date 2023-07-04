<template>
    <div class="select_plus_wrap">
        <el-select v-model="selected" clearable allow-create filterable :placeholder="placeholder">
            <el-option
                v-for="item in options"
                :key="item"
                :label="item"
                :value="item">
            </el-option>
        </el-select>
        <el-popover :visible="createPop" placement="left" :width="400" trigger="click">
            <template #reference>
                <el-button @click="createPop = true">+</el-button>
            </template>
            <el-input
                :placeholder="pop_placeholder"
                v-model="new_group"
            >
            </el-input>
            <el-button style="margin-top: 10px;" type="primary" @click="addItem()">Add</el-button>
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
