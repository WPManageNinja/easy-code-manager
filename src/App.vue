<template>
    <div class="fsnip_app">
        <div class="fsnip_main-menu-items">
            <div class="menu_logo_holder">
                <h3 style="margin: 10px 0; display: flex;align-items: center;">
                    <router-link to="/">
                        <img :src="appVars.asset_url+'images/logo.png'" alt="FluentSnippets" />
                    </router-link>
                </h3>
            </div>
            <div class="fsnip_handheld"><span class="dashicons dashicons-menu-alt3"></span></div>
            <ul class="fsnip_menu">
                <li v-for="item in menuItems" :key="item.route" class="fsnip_menu_item">
                    <router-link :to="{ name: item.route }" :class="'fsnip_menu_' + item.route" class="fsnip_menu_primary">
                        {{item.title}}
                    </router-link>
                </li>
            </ul>
        </div>
        <fsnip-promo :config="appVars.safeModes" />
        <div v-show="hasServerError">
            <el-button @click="hideErrors()" v-if="hasServerError">Hide Errors</el-button>
            <div :class="{fluent_snip_server_error : hasServerError}" id="fsnip_shadow_wrapper">
                <div id="fluent_snip_500_error"></div>
            </div>
        </div>
        <div class="ff_app_body">
            <router-view></router-view>
        </div>
    </div>
</template>

<script type="text/babel">
import FsnipPromo from './components/FsnipSafeModesWarning.vue';
import eventBus from "./Bits/event-bus";
export default {
    name: 'FluentAuthApp',
    components: {
        FsnipPromo
    },
    data() {
        return {
            menuItems: [
                {
                    route: 'dashboard',
                    title: this.$t('Snippets')
                },
                {
                    route: 'settings',
                    title: this.$t('Settings')
                },
                {
                    route: 'about',
                    title: this.$t('About')
                }
            ],
            hasServerError: false
        }
    },
    methods: {
        initShadowDomIframe(error) {
            if(!error) {
                this.hideErrors();
                return false;
            }

            let host = document.getElementById("fluent_snip_500_error");

            // Remove the existing host element
            if (host) {
                host.parentNode.removeChild(host);
            }

            // Create a new host element
            host = document.createElement('div');
            host.id = "fluent_snip_500_error";
            document.getElementById('fsnip_shadow_wrapper').appendChild(host); // Append it where it needs to be in the DOM

            // Attach a new shadow DOM and add content
            const shadow = host.attachShadow({mode: "closed"});
            const div = document.createElement("div");
            div.classList.add("fsnip_500_error_wrap");
            div.innerHTML = error;
            shadow.appendChild(div);
            this.hasServerError = true;

            // Scroll to top
            window.scrollTo(0, 0);
        },
        hideErrors() {
            let host = document.getElementById("fluent_snip_500_error");

            console.log(host);

            // Remove the existing host element
            if (host) {
                host.parentNode.removeChild(host);
            }
            this.hasServerError = false;
        }
    },
    created() {
        jQuery('.update-nag,.notice, #wpbody-content > .updated, #wpbody-content > .error').remove();
    },
    mounted() {
        jQuery('.fsnip_handheld span').on('click', function () {
            jQuery('ul.fsnip_menu').toggle('show');
        });

        this.$eventBus.on("server_error", (error) => {
            console.log(error);
            this.initShadowDomIframe(error);
        });
    }
}
</script>
