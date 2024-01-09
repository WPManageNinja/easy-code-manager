<template>
    <div class="fss_support">
        <el-row :gutter="20">
            <el-col :sm="24" :md="12">
                <div class="fss_about">
                    <div class="fss_header">About</div>
                    <div class="fss_content">
                        <p>
                            <a href="https://fluentsnippets.com" target="_blank" rel="noopener">FluentSnippets</a> {{ $t('is The High - Performance Code Snippets Plugin for WordPress.It is built for speed and security.All code snippets are stored in the file system and load just like a regular feature plugin.No database query, it’s secure and native.') }}
                        </p>
                        <div>
                            <p>{{ $t('FluentSMTP is built using the following open -sorce libraries and software') }}</p>
                            <ul style="list-style: disc;margin-left: 30px;">
                                <li>VueJS</li>
                                <li>Vue Router</li>
                                <li>codemirror</li>
                                <li>dayjs</li>
                                <li>fuse.js</li>
                                <li>lodash</li>
                                <li>element-plus</li>
                            </ul>
                            <p>
                                {{ $t('If you find an issue or have a suggestion please ')}}<a target="_blank" rel="nofollow" href="https://github.com/WPManageNinja/easy-code-manager/issues">{{ $t('open an issue on GitHub')
                                }}</a>.
                                <br/>{{ $t('If you are a developer and would like to contribute to the project, Please ')}}<a
                                target="_blank" rel="nofollow"
                                href="https://github.com/WPManageNinja/easy-code-manager/">{{
                                    $t('contribute on GitHub')
                                }}</a>.
                            </p>
                            <p>{{ $t('Please ') }}<a target="_blank" rel="noopener" href="http://fluentsnippets.com/docs">{{
                                    $t('read the documentation here')
                                }}</a></p>
                        </div>
                    </div>
                </div>
                <div class="fss_about">
                    <div class="fss_header">{{ $t('Contributors') }}</div>
                    <div class="fss_content">
                        <p>{{ $t('FluentSnippets is powered by it\'s users like you. Feel free to contribute on Github. Thanks to all of our contributors.') }}</p>

                        <a target="_blank"
                           href="https://github.com/WPManageNinja/easy-code-manager/graphs/contributors">

                            <ul v-if="contributors.length > 0" v-loading="contributorsLoading"
                                style="list-style: none; display: flex; flex-direction: row; flex-wrap: wrap; ">
                                <li v-for="contributor in contributors" :key="contributor.id" class="">
                                    <p :title="contributor.login">
                                        <img :src="contributor.avatar_url" :alt="contributor.login"
                                             style="width: 60px; height: 60px; border-radius: 50%;"/>
                                    </p>
                                </li>
                            </ul>
                        </a>
                    </div>
                </div>
            </el-col>
            <el-col :sm="24" :md="12">
                <div v-if="plugin || installed_info">
                    <div v-loading="installing" :element-loading-text="$t('Installing... Please wait')" class="fss_about">
                        <div class="fss_header">{{ $t('Recommended Plugin') }}</div>
                        <div class="fss_content">
                            <div v-if="installed_info" class="install_success">
                                <h3>{{ installed_message }}</h3>
                                <a class="el-button el-button--success installed_dashboard_url"
                                   :href="installed_info.admin_url">{{ installed_info.title }}</a>
                            </div>
                            <div v-else class="fss_plugin_block">
                                <div class="fss_plugin_title">
                                    <h3>{{ plugin.title }}</h3>
                                    <p>{{ plugin.subtitle }}</p>
                                </div>
                                <div class="fss_plugin_body">
                                    <div v-html="plugin.description"></div>
                                    <div class="fss_install_btn">
                                        <el-button v-if="!appVars.disable_installation"
                                                   @click="installPlugin(plugin.slug)"
                                                   :class="plugin.btn_class" type="success">{{ plugin.btn_text }}
                                        </el-button>
                                        <a v-else :href="plugin.plugin_url" target="_blank" rel="noopener"
                                           class="el-button el-button--success fss_ninjatables_btn">
                                            <span>{{ $t('View') }} {{ plugin.title }}</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="fss_about">
                    <div class="fss_header">{{ $t('Community') }}</div>
                    <div class="fss_content">
                        <p>{{ $t('FluentSnippets is powered by community.We listen to our community users and build products that add values to businesses and save time.') }}</p>
                        <p>{{ $t('Join our communities and participate in great conversations.') }}</p>
                        <ul style="list-style: disc;margin-left: 30px;">
                            <li>
                                <a target="_blank" rel="nofollow" href="https://www.facebook.com/groups/fluentforms">{{ $t('Join FluentForms Facebook Community') }}</a>
                            </li>
                            <li>
                                <a target="_blank" rel="nofollow" href="https://www.facebook.com/groups/fluentcrm">{{ $t('Join FluentCRM Facebook Community') }}</a>
                            </li>
                            <li>
                                <a target="_blank" rel="nofollow"
                                   href="https://wordpress.org/support/plugin/easy-code-manager/reviews/?filter=5">{{ $t('Write a review(really appreciate 😊)') }}</a>
                            </li>
                            <li>
                                <a target="_blank" rel="noopener" href="http://fluentsnippets.com/docs">{{ $t('Read the documentation') }}</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </el-col>
        </el-row>
    </div>
</template>

<script type="text/babel">
import sample from 'lodash/sample';

export default {
    name: 'FluentMailSupport',
    data() {
        return {
            plugins: {
                fluentsmtp: {
                    slug: 'fluent-smtp',
                    title: 'Fluent SMTP',
                    subtitle: this.$t('WP Mail SMTP, Amazon SES, SendGrid, MailGun and Any SMTP Connector Plugin'),
                    description: `<p><a href="https://wordpress.org/plugins/fluent-smtp" target="_blank" rel="nofollow">FluentSMTP</a> ${ this.$t(' plugin fixes your email delivery issue by connecting WordPress Mail with your email service providers. These integrations are native, so it will send the emails super-fast. It\'s free and will be always free.')}</p>`,
                    btn_text: this.$t('Install Fluent SMTP (Free)'),
                    btn_class: '',
                    plugin_url: 'https://wordpress.org/plugins/fluent-smtp'
                },
                fluentform: {
                    slug: 'fluentform',
                    title: 'Fluent Forms',
                    subtitle: this.$t('Fastest Contact Form Builder Plugin for WordPress'),
                    description: `<p><a href="https://wordpress.org/plugins/fluentform" target="_blank" rel="nofollow">Fluent Forms</a> ${ this.$t(' is the ultimate user-friendly, fast, customizable drag-and-drop WordPress Contact Form Plugin that offers you all the premium features, plus many more completely unique additional features.') }</p>`,
                    btn_text: this.$t('Install Fluent Forms (Free)'),
                    btn_class: '',
                    plugin_url: 'https://wordpress.org/plugins/fluentform'
                },
                fluent_crm: {
                    slug: 'fluent-crm',
                    title: 'FluentCRM',
                    subtitle: this.$t('Email Marketing Automation and CRM Plugin for WordPress'),
                    description: `<p><a href="https://wordpress.org/plugins/fluent-crm/" target="_blank" rel="nofollow">FluentCRM</a> ${ this.$t('is the best and complete feature-rich Email Marketing & CRM solution. It is also the simplest and fastest CRM and Marketing Plugin on WordPress. Manage your customer relationships, build your email lists, send email campaigns, build funnels, and make more profit and increase your conversion rates. (Yes, It’s Free!)') }</p>`,
                    btn_text: this.$t('Install FluentCRM (Free)'),
                    btn_class: 'fss_fluentcrm_btn',
                    plugin_url: 'https://wordpress.org/plugins/fluent-crm/'
                },
                ninja_tables: {
                    slug: 'ninja-tables',
                    title: 'Ninja Tables',
                    subtitle: this.$t('Best WP DataTables Plugin for WordPress'),
                    description: `<p>${ this.$t('Looking for a WordPress table plugin for your website? Then you’re in the right place.') }</p><p>${ this.$t('Meet ') }<a href="https://wordpress.org/plugins/ninja-tables/" target="_blank" rel="nofollow">Ninja Tables</a>${this.$t(', the best WP table plugin that comes with all the solutions to the problems you face while creating tables on your posts/pages.')}</p>`,
                    btn_text: this.$t('Install Ninja Tables (Free)'),
                    btn_class: 'fss_ninjatables_btn',
                    plugin_url: 'https://wordpress.org/plugins/ninja-tables/'
                }
            },
            installing: false,
            installed_info: false,
            installed_message: '',
            contributors: [],
            contributorsLoading: false
        }
    },
    computed: {
        plugin() {
            if (this.appVars.disable_recommendation) {
                return false;
            }
            const recommended = [];

            if (!this.appVars.has_fluentsmtp) {
                recommended.push(this.plugins.has_fluentsmtp)
            }

            if (!this.appVars.has_fluentform) {
                recommended.push(this.plugins.fluentform)
            }
            if (!this.appVars.has_ninja_tables) {
                recommended.push(this.plugins.ninja_tables)
            }
            if (!this.appVars.has_fluentcrm) {
                recommended.push(this.plugins.fluent_crm)
            }
            if (!recommended.length) {
                return false;
            }
            return sample(recommended);
        }
    },
    methods: {
        installPlugin(slug) {
            this.installing = true;
            this.$post('install_plugin', {
                plugin_slug: slug
            })
                .then(response => {
                    this.installed_info = response.info;
                    this.installed_message = response.message;
                })
                .catch((error) => {
                    this.$handleError(error);
                })
                .finally(() => {
                    this.installing = false;
                });
        },
        async fetchContributors() {
            this.contributorsLoading = true;
            try {
                await fetch('https://api.github.com/repos/WPManageNinja/easy-code-manager/contributors')
                    .then(response => response.json())
                    .then(data => {
                        this.contributors = data.slice(0, 20);
                        this.contributorsLoading = false;
                    })
            } catch (e) {
                this.contributorsLoading = false;
            }
        }
    },
    mounted() {
        this.fetchContributors();
    }
}
</script>
