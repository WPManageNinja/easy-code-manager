<template>
    <div class="select_plus_wrap">
        <el-select :disabled="disabled" @change="$emit('update:modelValue', selected)" v-model="selected" clearable allow-create filterable
                   :aria-label="placeholder" :placeholder="placeholder">
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
                <!-- "+" is not a name. What it adds is. -->
                <el-button :disabled="disabled || createDisabled || saving" :aria-label="$t('Create new group')">+</el-button>
            </template>
            <el-input
                :aria-label="pop_placeholder"
                :placeholder="pop_placeholder"
                v-model="new_group"
            >
            </el-input>
            <el-button :loading="saving" class="snip_add_item" type="primary" @click="addItem()">{{ $t('Add') }}</el-button>
        </el-popover>
    </div>
</template>

<script type="text/babel">
export default {
    name: 'SelectPlus',
    props: ['options', 'modelValue', 'placeholder', 'pop_placeholder', 'createItem', 'disabled', 'createDisabled'],
    emits: ['update:modelValue', 'itemCreated'],
    data() {
        return {
            selected: this.modelValue,
            appReady: false,
            new_group: '',
            createPop: false,
            saving: false
        }
    },
    methods: {
        async addItem() {
            if (this.disabled || this.createDisabled || this.saving) return;
            const name = this.new_group.trim();
            this.saving = true;
            try {
                if (this.createItem) await this.createItem(name);
                if (!this.options.includes(name)) this.options.push(name);
                this.selected = name;
                this.$emit('update:modelValue', name);
                this.$emit('itemCreated', name);
                this.new_group = '';
                this.createPop = false;
            } catch (error) {
                this.$handleError(error);
            } finally {
                this.saving = false;
            }
        }
    },
    mounted() {
        this.appReady = true;
    }
}
</script>
