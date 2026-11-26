import workerUrl from './scss.worker.js?worker&url';

export default {
    beforeUnmount() {
        this.cancelScssSave?.();
    },
    methods: {
        async prepareSaveMeta() {
            const snippet = this.snippet;
            const meta = {...snippet.meta, code: snippet.code};
            if (meta.type !== 'scss') {
                return meta;
            }

            let worker;
            let timer;
            try {
                worker = new Worker(workerUrl);
                const result = await new Promise((resolve, reject) => {
                    this.cancelScssSave = () => resolve(null);
                    timer = setTimeout(() => reject(new Error(this.$t('SCSS compilation timed out.'))), 15000);
                    worker.onerror = () => reject(new Error(this.$t('The SCSS compiler could not load.')));
                    worker.onmessage = ({data}) => resolve(data);
                    worker.postMessage(meta.code);
                });
                if (!result) {
                    return null;
                }
                if (snippet !== this.snippet || meta.code !== snippet.code || snippet.meta.type !== 'scss') {
                    throw new Error(this.$t('The snippet changed during compilation. Save again.'));
                }
                if (result.message) {
                    const location = result.line ? result.line + ':' + result.column + ' ' : '';
                    throw new Error(location + result.message);
                }
                meta.compiled_css = result.css;
                return meta;
            } catch (error) {
                this.$notify.error(error.message);
                return null;
            } finally {
                clearTimeout(timer);
                worker?.terminate();
                this.cancelScssSave = null;
            }
        }
    }
};
