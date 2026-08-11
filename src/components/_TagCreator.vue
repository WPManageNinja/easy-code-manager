<template>
    <div class="select_plus_wrap">
        <el-select :fit-input-width="true" allow-create @change="updated()" :multiple="true" filterable clearable :placeholder="$t('Select Snippet Tags')" v-model="dynamicTags">
            <el-option v-for="tag in appVars.tags" :label="tag" :value="tag"></el-option>
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
                :placeholder="$t('Create new tag')"
                v-model="inputValue"
            >
            </el-input>
            <el-button class="snip_add_item" type="primary" @click="handleInputConfirm()">{{$t('Add')}}</el-button>
        </el-popover>
    </div>
</template>

<script type="text/babel">
export default {
    name: 'TagCreator',
    props: ['modelValue'],
    $emits: ['update:modelValue'],
    data() {
        return {
            dynamicTags: [],
            inputVisible: false,
            inputValue: '',
            createPop: false
        }
    },
    methods: {
        handleInputConfirm() {
            let inputValue = this.inputValue;
            if (inputValue) {
                // check if the tag already in this.dynamicTags
                if(this.dynamicTags.indexOf(inputValue) === -1) {
                    this.dynamicTags.push(inputValue);
                }
            }
            this.appVars.tags.push(inputValue);
            this.createPop = false;
            this.inputValue = '';
            this.updated();
        },
        updated() {
            this.$emit('update:modelValue', this.dynamicTags.join(','));
        }
    },
    mounted() {
        if(this.modelValue) {
            this.dynamicTags = this.modelValue.split(',').map(tag => tag.trim());
        }
    }
}
</script>
