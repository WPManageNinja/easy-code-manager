<template>
    <div class="fsnip_safe">
        <div class="fsnip_warn" v-if="config.is_defined_disabled">
            <h3>Safe Mode is Enabled</h3>
            <p>Safe mode is enabled. This means that snippets are not executing. Safe mode can be disabled <b>by removing
                the following code</b> from your wp-config.php or where it is defined file:</p>
            <code style="padding: 10px;">define('FLUENT_SNIPPETS_SAFE_MODE', true);</code>
        </div>
        <div class="fsnip_warn" v-else-if="config.is_filtered_disabled">
            <h3>Safe Mode is Enabled</h3>
            <p>Looks like you enabled the safe mode via filter hook. This means that <b>snippets are not executing</b>. Filter hook used to enable safe Mode:</p>
            <code style="padding: 10px;">add_filter('fluent_snippets/run_snippets', '__return_false');</code>
        </div>
        <div class="fsnip_warn" v-else-if="config.is_forced_disabled">
            <h3>Safe Mode is Enabled</h3>
            <p>Looks like you enabled the safe mode via URL. This means that <b>snippets are not executing</b>. You may review your codes and once ready. Disable Safe mode again.</p>
            <el-button @click="disableSafeMode" :disabled="working" v-loading="working">Disable Safe Mode</el-button>
        </div>
    </div>
</template>

<script type="text/babel">
export default {
    name: 'SafeModesWarning',
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
