const t = (string) => (window.fluentSnippetAdmin.i18n || {})[string] || string;

const looksLikeHtml = (text) => typeof text === 'string' && /<\s*(html|body|br|p|div|h1|table)\b/i.test(text);

/**
 * Describe a request that never made it to a plugin response.
 *
 * Until this existed, `reject(xhr.responseJSON)` handed `undefined` to every caller
 * whenever the server answered with something that was not our JSON — which is exactly
 * what a firewall block, a PHP fatal, or a truncated POST looks like. The user saw
 * "Something went wrong!" for the three failures that need the most explaining.
 *
 * @param {object} xhr jQuery xhr object.
 * @returns {object} Same shape as a plugin 422: {message, data: {error_details}, html}.
 */
const describeTransportError = function (xhr) {
    const status = xhr ? xhr.status : 0;
    const body = xhr ? xhr.responseText : '';
    let details;

    if (!status) {
        details = {
            title: t('The request never reached your site'),
            reason: t('The browser could not complete the request. The connection dropped, the request was cancelled, or something between your browser and the site blocked it outright.'),
            fix: t('Check your internet connection and try again. If it keeps failing, an ad blocker, a VPN or a proxy in front of the site is a likely cause.')
        };
    } else if (status === 403 || status === 406 || status === 418 || status === 429) {
        details = {
            title: t('Your server blocked this request') + ' (HTTP ' + status + ')',
            reason: t('Your snippet is sent to the server as raw code, and a firewall rule decided that looked like an attack. ModSecurity, Wordfence, Cloudflare and several managed hosts do this by default — the request is stopped before FluentSnippets ever sees it.'),
            fix: t('Ask your host to allow requests to wp-admin/admin-ajax.php from your admin account, or to disable the ModSecurity rule that is firing. To confirm this is the cause, try saving a snippet containing a single harmless line — if that works, it is the content of your code that is being blocked, not the plugin.')
        };
    } else if (status === 413) {
        details = {
            title: t('The snippet is too large for your server to accept'),
            reason: t('The server rejected the request because of its size, so nothing was saved.'),
            fix: t('Ask your host to raise post_max_size, or split the snippet into two smaller ones.')
        };
    } else if (status >= 500) {
        details = {
            title: t('Your server returned an error') + ' (HTTP ' + status + ')',
            reason: t('PHP stopped while handling the save, so the snippet was not stored. The details are in your server error log, not in the browser.'),
            fix: t('Check your PHP error log, or ask your host for the last entry in it. If the error mentions memory, raising the PHP memory limit usually resolves it.')
        };
    } else {
        details = {
            title: t('The server sent back an unexpected response'),
            reason: t('FluentSnippets expected a normal response and received something else, so it cannot tell whether the snippet was saved.'),
            fix: t('Reload the page to see the current state of the snippet. If the response below mentions a PHP error, that is what needs fixing.')
        };
    }

    details.raw = looksLikeHtml(body) ? '' : (body || '').slice(0, 1200);
    details.message = details.title;

    return {
        message: details.title,
        status: status,
        // Kept separate from `raw` so the app can render a whole HTML error page in its
        // sandboxed viewer rather than dumping markup into the panel as text.
        html: looksLikeHtml(body) ? body : '',
        data: {
            error_details: details
        }
    };
};

const rejectWith = function (reject, xhr) {
    if (xhr && xhr.responseJSON) {
        reject(xhr.responseJSON);
        return;
    }

    reject(describeTransportError(xhr));
};

const request = function (method, route, data = {}) {
    const url = `${window.fluentSnippetAdmin.rest.url}/${route}`;

    const headers = {'X-WP-Nonce': window.fluentSnippetAdmin.rest.nonce};

    if (['PUT', 'PATCH', 'DELETE'].indexOf(method.toUpperCase()) !== -1) {
        headers['X-HTTP-Method-Override'] = method;
        method = 'POST';
    }

    data.query_timestamp = Date.now();

    return new Promise((resolve, reject) => {
        window.jQuery.ajax({
            url: url,
            type: method,
            data: data,
            headers: headers
        })
            .then(response => resolve(response))
            .fail(xhr => rejectWith(reject, xhr));
    });
}

export default {
    get(route, data = {}) {
        return request('GET', route, data);
    },
    post(route, data = {}) {
        return request('POST', route, data);
    },
    delete(route, data = {}) {
        return request('DELETE', route, data);
    },
    put(route, data = {}) {
        return request('PUT', route, data);
    },
    patch(route, data = {}) {
        return request('PATCH', route, data);
    },
    ajax(method, action, data = {}) {
        return new Promise((resolve, reject) => {
            data.query_timestamp = Date.now();
            data.action = action;
            data.__nonce = window.fluentSnippetAdmin.nonce;

            window.jQuery.ajax({
                url: window.fluentSnippetAdmin.ajax_url,
                type: method,
                data: data
            })
                .then(response => resolve(response))
                .fail(xhr => rejectWith(reject, xhr));
        });
    }
};

jQuery(document).ajaxSuccess((event, xhr, settings) => {
    const nonce = xhr.getResponseHeader('X-WP-Nonce');
    if (nonce) {
        window.fluentSnippetAdmin.rest_nonce = nonce;
    }
});
