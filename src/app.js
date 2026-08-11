import {createApp} from 'vue'
import {createRouter, createWebHashHistory} from 'vue-router';
import {routes} from './routes';
import Rest from './Bits/Rest.js';
import {ElNotification, ElLoading, ElMessageBox} from 'element-plus'
import Storage from '@/Bits/Storage';
import App from './App.vue';
import eventBus from './Bits/event-bus';

require('./app.scss');

const dayjs = require('dayjs');
const relativeTime = require('dayjs/plugin/relativeTime');
require('dayjs/plugin/utc');
require('dayjs/plugin/localizedFormat');
dayjs.extend(require('dayjs/plugin/utc'));
dayjs.extend(require('dayjs/plugin/localizedFormat'));
dayjs.extend(relativeTime)

// The notification renders HTML, and error text now includes things PHP said verbatim —
// class names in angle brackets, snippets of the user's own markup. Those get escaped
// before they are pasted into the message.
function escapeHtml(text) {
    return String(text === null || text === undefined ? '' : text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

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
            Storage,
            is_rtl: false
        }
    },
    computed: {
        /*
         * Whether this screen may change anything, as one name every component can ask
         * rather than each of them reaching into appVars for the same key.
         *
         * False on a site with DISALLOW_FILE_MODS set: snippets are files, so WordPress
         * has been told nothing may write them. Hiding the controls is a courtesy — the
         * server refuses these operations regardless of what the app draws.
         */
        canEdit() {
            return !!window.fluentSnippetAdmin.can_edit;
        }
    },
    methods: {
        $get: Rest.get,
        $post: Rest.post,
        $put: Rest.put,
        $del: Rest.delete,
        $ajax: Rest.ajax,
        changeTitle(title) {
            jQuery('head title').text(title + ' - FluentSnippets');
        },
        $handleError(response) {
            const details = (response && response.data) ? response.data.error_details : null;

            let errorMessage = '';
            if (typeof response === 'string') {
                errorMessage = response;
            } else if (response && response.message) {
                errorMessage = response.message;
            } else {
                errorMessage = convertToText(response);
            }
            if (!errorMessage) {
                errorMessage = this.$t('Something went wrong!');
            }

            // The toast carries the headline and why it happened. The step-by-step fix
            // lives in the panel under the editor, where it can be read at leisure and
            // does not have to fit in a corner of the screen.
            if (details && details.reason) {
                errorMessage = '<strong>' + escapeHtml(details.title || errorMessage) + '</strong>'
                    + '<div style="margin-top:6px;font-weight:normal;">' + escapeHtml(details.reason) + '</div>';
            }

            this.$notify({
                type: 'error',
                title: this.$t('Error'),
                message: errorMessage,
                duration: details ? 10000 : 4500,
                dangerouslyUseHTMLString: true
            });
        },
        convertToText,
        /**
         * The translation of `string`, with any %s / %1s / %d placeholders filled in from
         * the remaining arguments.
         *
         * Placeholders are what let a sentence keep its word order in a language that puts
         * the value somewhere else, which concatenating the pieces at the call site does
         * not. Called with one argument it is exactly the lookup it always was.
         */
        $t(string) {
            string = window.fluentSnippetAdmin.i18n[string] || string;

            const args = Array.prototype.slice.call(arguments, 1);

            if (args.length === 0) {
                return string;
            }

            let argIndex = 0;

            return string.replace(/%(\d*)s|%d/g, (match, number) => {
                // A numbered placeholder names its argument; a bare one takes the next.
                if (number) {
                    const index = parseInt(number, 10) - 1;

                    return index < args.length ? args[index] : match;
                }

                return argIndex < args.length ? args[argIndex++] : match;
            });
        },
        relativeTimeFromUtc(utcDateTime) {
            if(!utcDateTime) {
                return '';
            }
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
        },
        ucFirst(string) {
            if (!string) {
                return '';
            }
            return string.charAt(0).toUpperCase() + string.slice(1);
        },
        exportSnippets(snippets) {
            let selected = snippets.map(snippet => {
                // replace .php from the end
                return snippet.replace(/\.php$/, '');
            });

            if (selected.length === 0) {
                this.$message.error(this.$t('Please select at least one snippet to export.'));
                return;
            }

            location.href = window.ajaxurl + '?' + jQuery.param({
                action: 'fluent_snippets_export_snippets',
                snippets: selected,
                _nonce: window.fluentSnippetAdmin.nonce
            });
        }
    }
});

app.config.globalProperties.$notify = ElNotification;
app.config.globalProperties.$confirm = ElMessageBox.confirm;
app.config.globalProperties.$prompt = ElMessageBox.prompt;

app.use(eventBus);

const router = createRouter({
    routes,
    history: createWebHashHistory()
});

window.fluentFrameworkApp = app.use(router).mount(
    '#fluent_snippets_app'
);
