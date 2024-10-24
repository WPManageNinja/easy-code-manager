<template>
    <div class="select_plus_wrap">
        <el-select v-model="dynamicAttributes" :fit-input-width="true" :multiple="true"
                   :placeholder="$t('Select Shortcode Attributes')" allow-create clearable
                   filterable @change="updated()">
            <el-option v-for="attribute in appVars.shortcode_attributes" :label="attribute"
                       :value="attribute"></el-option>
        </el-select>
        <el-popover :visible="createPop" :width="400" placement="left" trigger="click">
            <template #reference>
                <el-button @click="createPop = true">+</el-button>
            </template>
            <el-input
                v-model="inputValue"
                :placeholder="$t('Create a new attribute')"
            >
            </el-input>
            <el-button style="margin-top: 10px;" type="primary" @click="handleInputConfirm()">{{ $t('Add') }}
            </el-button>
        </el-popover>
    </div>
</template>

<script type="text/babel">
export default {
    name: 'ShortcodeAttributeCreator',
    props: ['modelValue'],
    $emits: ['update:modelValue'],
    data() {
        return {
            dynamicAttributes: [],
            inputVisible: false,
            inputValue: '',
            createPop: false
        }
    },
    methods: {
        handleInputConfirm() {
            let inputValue = this.inputValue;
            if (inputValue) {
                // check if the tag already in this.dynamicAttributes
                if (this.dynamicAttributes.indexOf(inputValue) === -1) {
                    this.dynamicAttributes.push(inputValue);
                }
            }
            this.appVars.shortcode_attributes.push(inputValue);
            this.createPop = false;
            this.inputValue = '';
            this.updated();
        },
        updated() {
            this.$emit('update:modelValue', this.dynamicAttributes.join(','));
        }
    },
    mounted() {
        if (this.modelValue) {
            this.dynamicAttributes = this.modelValue.split(',').map(tag => tag.trim());
        }
    }
}
</script>
