import Dashboard from './components/Dashboard.vue';
import SnippetEditView from './components/SnippetEditView.vue';
import CreateSnippet from './components/CreateSnippet.vue';
import Settings from './components/ConfigSettings.vue';

export var routes = [
    {
        path: '/',
        name: 'dashboard',
        component: Dashboard,
        meta: {
            active: 'dashboard'
        }
    },
    {
        path: '/snippets/:snippet_name',
        name: 'edit_snippet',
        component: SnippetEditView,
        props: true,
        meta: {
            active: 'dashboard'
        }
    },
    {
        path: '/create-new',
        name: 'create_snippet',
        component: CreateSnippet,
        meta: {
            active: 'dashboard'
        }
    },
    {
        path: '/settings',
        name: 'settings',
        component: Settings,
        meta: {
            active: 'settings'
        }
    }
];
