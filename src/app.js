import {createApp} from 'vue'
import {createRouter, createWebHashHistory} from 'vue-router';
import {routes} from './routes';
import Rest from './Bits/Rest.js';
import {ElNotification, ElLoading, ElMessageBox} from 'element-plus'
import Storage from '@/Bits/Storage';
import App from './App.vue';
require('./app.scss');

const dayjs = require('dayjs');
const relativeTime = require('dayjs/plugin/relativeTime');
require('dayjs/plugin/utc');
require('dayjs/plugin/localizedFormat');
dayjs.extend(require('dayjs/plugin/utc'));
dayjs.extend(require('dayjs/plugin/localizedFormat'));
dayjs.extend(relativeTime)

function convertToText(obj) {
    const string = [];
    if (typeof (obj) === 'object' && (obj.join === undefined)) {
        for (const prop in obj) {
            string.push(convertToText(obj[prop]));
        }
    } else if (typeof (obj) === 'object' && !(obj.join === undefined)) {
        for (const prop in obj) {
            string.push(convertToText(obj[prop]));
        }
    } else if (typeof (obj) === 'function') {

    } else if (typeof (obj) === 'string') {
        string.push(obj)
    }

    return string.join('<br />')
}

const app = createApp(App);
app.use(ElLoading);

app.config.globalProperties.appVars = window.fluentSnippetAdmin;

app.mixin({
    data() {
        return {
            Storage
        }
    },
    methods: {
        $get: Rest.get,
        $post: Rest.post,
        $put: Rest.put,
        $del: Rest.delete,
        changeTitle(title) {
            jQuery('head title').text(title + ' - Fluent Auth');
        },
        $handleError(response) {
            let errorMessage = '';
            if (typeof response === 'string') {
                errorMessage = response;
            } else if (response && response.message) {
                errorMessage = response.message;
            } else {
                errorMessage = convertToText(response);
            }
            if (!errorMessage) {
                errorMessage = 'Something is wrong!';
            }
            this.$notify({
                type: 'error',
                title: 'Error',
                message: errorMessage,
                dangerouslyUseHTMLString: true
            });
        },
        convertToText,
        $t(string) {
            return window.fluentSnippetAdmin.i18n[string] || string;
        },
        relativeTimeFromUtc(utcDateTime) {
            const localDateTime = dayjs.utc(utcDateTime).local();
            return localDateTime.fromNow();
        },
        getLangLabelName(lang) {
            switch (lang) {
                case 'php_content':
                    return 'PHP + HTML';
                default:
                    return lang.toUpperCase();
            }
        },
        $storeLocalData(key, value) {
            this.Storage.set(key, value);
        },
        $getLocalData(key, defaultValue = '') {
            return this.Storage.get(key, defaultValue);
        }
    }
});

app.config.globalProperties.$notify = ElNotification;
app.config.globalProperties.$confirm = ElMessageBox.confirm;

const router = createRouter({
    routes,
    history: createWebHashHistory()
});

window.fluentFrameworkApp = app.use(router).mount(
    '#fluent_snippets_app'
);
