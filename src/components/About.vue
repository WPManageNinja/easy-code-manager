<template>
    <div class="fss_about_page">
        <!--
            The masthead. What the plugin is, and the one fact about it that most visitors
            to this screen are here to check: that it is free, that it stays free, and that
            there is no paid tier waiting behind a feature they are about to need.
        -->
        <div class="box fss_masthead">
            <div class="box_body">
                <brand-mark :size="56"/>

                <h1 class="fss_masthead_title"><span>fluent</span>Snippets</h1>

                <p class="fss_masthead_lead">
                    {{ $t('The code snippets plugin that writes to files instead of your database. Snippets load the way a small feature plugin loads - no query, no option lookup, no work on a page that does not use them.') }}
                </p>

                <p class="fss_masthead_free">
                    {{ $t('Free, and open source under the GPL. There is no pro version, no paid add-on and no upgrade prompt - every feature is in the copy you already have.') }}
                </p>

                <div class="fss_masthead_actions">
                    <el-button tag="a" type="primary" :href="links.github" target="_blank" rel="noopener">
                        {{ $t('View source on GitHub') }}
                    </el-button>
                    <el-button tag="a" :href="links.docs" target="_blank" rel="noopener">
                        {{ $t('Read the documentation') }}
                    </el-button>
                    <el-button tag="a" :href="links.review" target="_blank" rel="noopener">
                        {{ $t('Leave a review') }}
                    </el-button>
                </div>
            </div>
        </div>

        <el-row :gutter="20">
            <el-col :sm="24" :md="12">
                <!--
                    One plugin per row, so this column is the tall one and the three cards
                    opposite it come out to about the same height.
                -->
                <team-plugins/>

                <div class="box">
                    <div class="box_header">
                        <div class="box_head">
                            <h3>{{ $t('Contributors') }}</h3>
                        </div>
                    </div>
                    <div class="box_body">
                        <p>
                            {{ $t('FluentSnippets is built in the open by the people below.') }}
                            <a :href="links.github" target="_blank" rel="noopener">{{ $t('Pull requests are welcome.') }}</a>
                        </p>

                        <a class="fss_contributors_link" :href="links.contributors" target="_blank" rel="noopener">
                            <ul v-if="contributors.length" v-loading="contributorsLoading" class="fss_contributors">
                                <li v-for="contributor in contributors" :key="contributor.id">
                                    <img :src="contributor.avatar_url" :alt="contributor.login"
                                         :title="contributor.login" loading="lazy"/>
                                </li>
                            </ul>
                        </a>
                    </div>
                </div>
            </el-col>

            <el-col :sm="24" :md="12">
                <!--
                    Three things that are true of this plugin and not of most of the others,
                    written as what happens rather than as adjectives.
                -->
                <div class="box">
                    <div class="box_header">
                        <div class="box_head">
                            <h3>{{ $t('How it works') }}</h3>
                        </div>
                    </div>
                    <div class="box_body">
                        <ul class="fss_facts">
                            <li v-for="fact in facts" :key="fact.title">
                                <strong>{{ fact.title }}</strong>
                                <p>{{ fact.body }}</p>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="box">
                    <div class="box_header">
                        <div class="box_head">
                            <h3>{{ $t('Built with') }}</h3>
                        </div>
                    </div>
                    <div class="box_body">
                        <p>{{ $t('Other people\'s open source, without which this would be a much smaller plugin:') }}</p>
                        <ul class="fss_libraries">
                            <li v-for="library in libraries" :key="library.name">
                                <a :href="library.url" target="_blank" rel="noopener">{{ library.name }}</a>
                                <span>{{ library.role }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="box">
                    <div class="box_header">
                        <div class="box_head">
                            <h3>{{ $t('Getting help') }}</h3>
                        </div>
                    </div>
                    <div class="box_body">
                        <ul class="fss_links">
                            <li v-for="link in help" :key="link.href">
                                <a :href="link.href" target="_blank" rel="noopener">{{ link.label }}</a>
                                <span>{{ link.note }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </el-col>
        </el-row>
    </div>
</template>

<script type="text/babel">
import BrandMark from '../Bits/BrandMark.vue';
import TeamPlugins from './_TeamPlugins.vue';

const REPO = 'https://github.com/WPManageNinja/easy-code-manager';

export default {
    name: 'AboutFluentSnippets',
    components: {
        BrandMark,
        TeamPlugins
    },
    data() {
        return {
            links: {
                github: REPO,
                issues: REPO + '/issues',
                contributors: REPO + '/graphs/contributors',
                docs: 'https://fluentsnippets.com/docs',
                review: 'https://wordpress.org/support/plugin/easy-code-manager/reviews/#new-post',
                support: 'https://wordpress.org/support/plugin/easy-code-manager/'
            },
            contributors: [],
            contributorsLoading: false
        }
    },
    computed: {
        /*
         * Written as computed rather than as data so the strings go through $t on the
         * locale that is actually loaded, the same as every other string on this screen.
         */
        facts() {
            return [
                {
                    title: this.$t('Snippets are files'),
                    body: this.$t('Each one is written to wp-content/fluent-snippets and loaded from there. Nothing is fetched from the options table on the way in, so a site with two hundred snippets costs the same on a page that runs none of them as a site with two.')
                },
                {
                    title: this.$t('A fatal cannot lock you out'),
                    body: this.$t('If a snippet throws one, it is switched off on the spot and the screen tells you which snippet, which line and what was thrown. If you are already locked out, the Safe Mode URL on the Settings screen turns every snippet off without needing the admin.')
                },
                {
                    title: this.$t('It keeps running without the plugin'),
                    body: this.$t('Standalone Mode writes a must-use plugin that loads your snippets directly. Deactivate FluentSnippets, or delete it, and the code you wrote carries on running. What you built is yours, not held hostage by ours.')
                }
            ];
        },
        libraries() {
            return [
                {name: 'Vue 3', url: 'https://vuejs.org', role: this.$t('the admin screens')},
                {name: 'Vue Router', url: 'https://router.vuejs.org', role: this.$t('moving between them')},
                {name: 'Element Plus', url: 'https://element-plus.org', role: this.$t('the controls')},
                {name: 'CodeMirror', url: 'https://codemirror.net', role: this.$t('the code editor')},
                {name: 'Fuse.js', url: 'https://fusejs.io', role: this.$t('searching your snippets')},
                {name: 'Day.js', url: 'https://day.js.org', role: this.$t('dates and relative times')},
                {name: 'Lodash', url: 'https://lodash.com', role: this.$t('the odd jobs')}
            ];
        },
        help() {
            return [
                {
                    href: this.links.docs,
                    label: this.$t('Documentation'),
                    note: this.$t('how each feature works, with examples')
                },
                {
                    href: this.links.support,
                    label: this.$t('Support forum'),
                    note: this.$t('free support, answered in public')
                },
                {
                    href: this.links.issues,
                    label: this.$t('Report a bug'),
                    note: this.$t('on GitHub, where the fix will land')
                },
                {
                    href: this.links.review,
                    label: this.$t('Leave a review'),
                    note: this.$t('it is what keeps the plugin findable')
                }
            ];
        }
    },
    methods: {
        /*
         * GitHub's public API, called from the browser rather than proxied: it needs no
         * credentials, and a failure here should cost the page a row of avatars, nothing
         * more - so the catch is deliberately silent.
         */
        fetchContributors() {
            this.contributorsLoading = true;

            fetch(this.links.contributors.replace('https://github.com/', 'https://api.github.com/repos/').replace('/graphs/contributors', '/contributors'))
                .then(response => response.ok ? response.json() : [])
                .then(data => {
                    this.contributors = Array.isArray(data) ? data.slice(0, 20) : [];
                })
                .catch(() => {
                })
                .finally(() => {
                    this.contributorsLoading = false;
                });
        }
    },
    mounted() {
        this.fetchContributors();
    }
}
</script>
