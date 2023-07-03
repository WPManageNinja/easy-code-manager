<template>
    <div class="fsnip_app">
        <div class="fsnip_main-menu-items">
            <div class="menu_logo_holder">
                <h3 style="margin: 10px 0; display: flex;align-items: center;">Fluent Snippets</h3>
            </div>
            <div class="fsnip_handheld"><span class="dashicons dashicons-menu-alt3"></span></div>
            <ul class="fsnip_menu">
                <li v-for="item in menuItems" :key="item.route" class="fsnip_menu_item">
                    <router-link :to="{ name: item.route }" class="fsnip_menu_primary">
                        {{item.title}}
                    </router-link>
                </li>
            </ul>
        </div>

        <div class="ff_app_body">
            <router-view></router-view>
        </div>
    </div>
</template>

<script type="text/babel">
export default {
    name: 'FluentAuthApp',
    data() {
        return {
            menuItems: [
                {
                    route: 'dashboard',
                    title: this.$t('Snippets')
                }
            ]
        }
    },
    created() {
        jQuery('.update-nag,.notice, #wpbody-content > .updated, #wpbody-content > .error').remove();
    },
    mounted() {
        jQuery('.fsnip_handheld span').on('click', function () {
            jQuery('ul.fsnip_menu').toggle('show');
        });
    }
}
</script>
