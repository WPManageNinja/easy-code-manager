<template>
    <div class="fsnip_tags">
        <el-tag
            v-for="tag in dynamicTags"
            :key="tag"
            closable
            :disable-transitions="false"
            @close="handleClose(tag)"
        >
            {{ tag }}
        </el-tag>
        <el-input
            v-if="inputVisible"
            ref="InputRef"
            v-model="inputValue"
            size="small"
            @keyup.enter="handleInputConfirm"
            @blur="handleInputConfirm"
        />
        <el-button v-else class="button-new-tag ml-1" size="small" @click="showInput">
            + New Tag
        </el-button>
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
            inputValue: ''
        }
    },
    methods: {
        handleClose(tag) {
            this.dynamicTags.splice(this.dynamicTags.indexOf(tag), 1);
            this.updated();
        },
        handleInputConfirm() {
            let inputValue = this.inputValue;
            if (inputValue) {
                // check if the tag already in this.dynamicTags
                if(this.dynamicTags.indexOf(inputValue) === -1) {
                    this.dynamicTags.push(inputValue);
                }
            }
            this.inputVisible = false;
            this.inputValue = '';
            this.updated();
        },
        showInput() {
            this.inputVisible = true;
            this.$nextTick(_ => {
                this.$refs.InputRef.focus();
            });
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
