import Dashboard from './Components/Dashboard.vue';
import SnippetEditView from './Components/SnippetEditView.vue';
import CreateSnippet from './Components/CreateSnippet.vue';

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
    }
];
