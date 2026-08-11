/*
 * Strips this development site's furniture out of a screenshot before it is taken: the
 * admin bar, the admin menu, and the notices other plugins put at the top of every screen.
 *
 * What is left is the plugin's own interface, full width. The alternative - shooting the
 * whole admin - puts LearnDash, FluentCRM and a "first time using" pointer into a picture
 * that is meant to be showing FluentSnippets.
 */
(() => {
    const hide = sel => document.querySelectorAll(sel).forEach(n => n.remove());

    hide('#wpadminbar');
    hide('#adminmenumain');
    hide('.wp-pointer');
    hide('#wpfooter');
    hide('.notice, .update-nag, .updated, .error:not(.fsnip_error_panel)');

    const content = document.getElementById('wpcontent');
    if (content) {
        content.style.marginLeft = '0';
        content.style.paddingLeft = '0';
    }

    document.documentElement.style.paddingTop = '0';
    document.body.classList.remove('admin-bar');

    /* The app bar is pinned against the admin menu that has just been removed. */
    const bar = document.querySelector('.fsnip_app_bar');
    if (bar) {
        bar.style.left = '0';
        bar.style.top = '0';
    }

    const app = document.querySelector('.fsnip_app');
    if (app) {
        app.style.paddingTop = '56px';
    }

    return 'ready';
})()
