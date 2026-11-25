<template>
    <div v-if="network || scope != null" class="fsnip_network_sites">
        <h3><label for="fsnip_site_mode">{{ $t('Network sites') }}</label></h3>
        <el-select id="fsnip_site_mode" :model-value="mode" @update:model-value="setMode" :disabled="!canEdit">
            <el-option v-if="mode === 'invalid'" value="invalid" disabled :label="$t('Invalid site selection')" />
            <el-option value="all" :label="$t('All sites')" />
            <el-option value="only" :label="$t('Only selected sites')" :disabled="!network" />
            <el-option value="except" :label="$t('All except selected sites')" :disabled="!network" />
        </el-select>
        <template v-if="mode !== 'all'">
            <el-select :model-value="ids" @update:model-value="setIds" multiple filterable remote
                       :aria-label="$t('Selected sites')" :placeholder="$t('Search by site address or ID')"
                       :disabled="!canEdit || !sameNetwork" :loading="loading" :remote-method="search"
                       @visible-change="open => open && search('')">
                <el-option v-for="site in choices" :key="site.id" :label="site.title" :value="site.id" />
            </el-select>
            <p v-if="!sameNetwork">{{ $t('This selection belongs to another network. Choose a new scope to use it here.') }}</p>
        </template>
    </div>
</template>

<script>
export default {
    props: ['snippet'],
    data() {
        return { options: [], labels: {}, loading: false, request: 0 };
    },
    computed: {
        network() { return Number(this.appVars.network_id); },
        scope() { return this.snippet.meta.network_sites; },
        mode() {
            if (this.scope == null) return 'all';
            return ['only', 'except'].includes(this.scope.mode) ? this.scope.mode : 'invalid';
        },
        sameNetwork() { return this.network && Number(this.scope?.network) === this.network; },
        ids() { return Array.isArray(this.scope?.sites) ? this.scope.sites.map(Number) : []; },
        choices() {
            const choices = new Map(this.options.map(site => [site.id, site]));
            for (const id of this.ids) {
                if (!choices.has(id)) {
                    choices.set(id, {id, title: this.labels[id] || '#' + id});
                }
            }
            return Array.from(choices.values());
        }
    },
    mounted() {
        if (this.sameNetwork && this.ids.length) this.loadSelectedLabels();
    },
    methods: {
        async loadSelectedLabels() {
            const ids = this.ids;
            try {
                for (let start = 0; start < ids.length; start += 25) {
                    const result = await this.$get('settings/options', {
                        rest_key: 'network_sites', values: ids.slice(start, start + 25)
                    });
                    for (const site of result.options) this.labels[site.id] = site.title;
                }
            } catch (error) {
                this.$handleError(error);
            }
        },
        setMode(mode) {
            this.snippet.meta.network_sites = mode === 'all' ? null : {
                mode, network: this.network, sites: this.sameNetwork ? this.ids : []
            };
        },
        setIds(sites) {
            for (const site of this.options) {
                if (sites.includes(site.id)) this.labels[site.id] = site.title;
            }
            this.snippet.meta.network_sites = {mode: this.mode, network: this.network, sites};
        },
        async search(query) {
            const request = ++this.request;
            this.loading = true;
            try {
                const result = await this.$get('settings/options', {rest_key: 'network_sites', search: query});
                if (request === this.request) {
                    this.options = result.options;
                    for (const site of result.options) {
                        if (this.ids.includes(site.id)) this.labels[site.id] = site.title;
                    }
                }
            } catch (error) {
                if (request === this.request) this.$handleError(error);
            } finally {
                if (request === this.request) this.loading = false;
            }
        }
    }
};
</script>
