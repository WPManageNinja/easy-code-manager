<template>
    <div class="fsnip_safe">
        <div class="fsnip_warn" v-if="config.is_defined_disabled">
            <h3>{{$t('Safe Mode is Enabled')}}</h3>
            <p>{{ $t('Safe_Mode_Constant_Notice') }}</p>
            <code>define('FLUENT_SNIPPETS_SAFE_MODE', true);</code>
        </div>
        <div class="fsnip_warn" v-else-if="config.is_filtered_disabled">
            <h3>{{$t('Safe Mode is Enabled')}}</h3>
            <p>{{ $t('Safe_Mode_Filter_Notice') }}</p>
            <code>add_filter('fluent_snippets/run_snippets', '__return_false');</code>
        </div>
        <div class="fsnip_warn" v-else-if="config.is_forced_disabled">
            <h3>{{$t('Safe Mode is Enabled')}}</h3>
            <p>{{ $t('Safe_Mode_Url_Notice') }}</p>
            <el-button @click="disableSafeMode" :disabled="working" v-loading="working">{{$t('Disable Safe Mode')}}</el-button>
        </div>
    </div>
</template>

<script type="text/babel">
export default {
    name: 'FsnipSafeModesWarning',
    props: ['config'],
    data() {
        return {
            working: false
        }
    },
    methods: {
        disableSafeMode() {
            this.working = true;
            this.$post('settings/disable-safe-mode')
                .then(response => {
                    this.$notify.success(response.message);
                    this.config.is_forced_disabled = false;
                })
                .catch((errors) => {
                    this.$handleError(errors);
                })
                .finally(() => {
                    this.working = false;
                });
        }
    },
}
</script>
