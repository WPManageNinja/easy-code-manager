<template>
    <div class="fsnip_safe">
        <div class="fsnip_warn" v-if="config.is_defined_disabled">
            <h3>{{$t('Safe Mode is Enabled')}}</h3>
            <p>{{ $t('Safe Mode is enabled, which means your snippets are not executing. You can disable Safe Mode by removing the following code from your wp-config.php file (or wherever it is defined):') }}</p>
            <code style="padding: 10px;">define('FLUENT_SNIPPETS_SAFE_MODE', true);</code>
        </div>
        <div class="fsnip_warn" v-else-if="config.is_filtered_disabled">
            <h3>{{$t('Safe Mode is Enabled')}}</h3>
            <p>{{ $t('It looks like you enabled Safe Mode via a filter hook, which means your snippets are not executing. The filter hook used to enable Safe Mode is:') }}</p>
            <code style="padding: 10px;">add_filter('fluent_snippets/run_snippets', '__return_false');</code>
        </div>
        <div class="fsnip_warn" v-else-if="config.is_forced_disabled">
            <h3>{{$t('Safe Mode is Enabled')}}</h3>
            <p>{{ $t('It looks like you enabled Safe Mode via a URL, which means your snippets are not executing. Review your code, and once you are ready, disable Safe Mode again.') }}</p>
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
