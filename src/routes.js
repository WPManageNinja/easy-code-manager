import Dashboard from './components/Dashboard.vue';
import SnippetEditView from './components/SnippetEditView.vue';
import CreateSnippet from './components/CreateSnippet.vue';
import Settings from './components/ConfigSettings.vue';
import About from "./components/About.vue";

/*
 * `title` is the screen's name, and it is here rather than in each component because the
 * route announcer in App.vue reads it out on every navigation. This app is a single page,
 * so nothing reloads and a screen reader is told nothing at all when the view swaps -
 * `active` decides which nav item is lit, `title` says out loud what you just landed on.
 */
export var routes = [
    {
        path: '/',
        name: 'dashboard',
        component: Dashboard,
        meta: {
            active: 'dashboard',
            title: 'Snippets'
        }
    },
    {
        path: '/snippets/:snippet_name',
        name: 'edit_snippet',
        component: SnippetEditView,
        props: true,
        meta: {
            active: 'dashboard',
            title: 'Edit snippet'
        }
    },
    {
        path: '/create-new',
        name: 'create_snippet',
        component: CreateSnippet,
        meta: {
            active: 'dashboard',
            title: 'Create new snippet'
        }
    },
    {
        path: '/settings',
        name: 'settings',
        component: Settings,
        meta: {
            active: 'settings',
            title: 'Settings'
        }
    },
    {
        path: '/about',
        name: 'about',
        component: About,
        meta: {
            active: 'about',
            title: 'About'
        }
    }
];
