<?php

namespace FluentSnippets\App\Services;

class Router
{
    private $namespace = '';

    public function __construct($namespace)
    {
        $this->namespace = $namespace;
    }

    public function route($method, $endpoint, $callback, $permissions = [])
    {
        $endpoint = str_replace('{id}', '(?P<id>[\d]+)', $endpoint);

        register_rest_route($this->namespace, $endpoint, array(
            'methods'  => $method,
            'callback' => function($request) use ($callback) {
                /*
                 * Swallow anything the callback prints — a snippet echoing during
                 * validation, a stray warning — so it cannot corrupt the JSON response.
                 *
                 * This used to be a bare ob_get_clean() after the call, with nothing
                 * opening a buffer first. That is harmless when no buffer is active, but
                 * when ANOTHER plugin had one open it silently destroyed their buffered
                 * output. Now the buffer is opened here and torn down only to the depth
                 * it was at on entry, so buffers we did not open are never touched.
                 */
                $bufferLevel = ob_get_level();

                ob_start();

                try {
                    $result = call_user_func($callback, $request);
                } finally {
                    while (ob_get_level() > $bufferLevel) {
                        ob_end_clean();
                    }
                }

                if(is_wp_error($result)) {
                    return new \WP_REST_Response([
                        'code' => $result->get_error_code(),
                        'message' => $result->get_error_message(),
                        'data' => $result->get_error_data()
                    ], 422);
                }

                return rest_ensure_response( $result );
            },
            'permission_callback' => function($request) use ($permissions) {
                if(is_array($permissions)) {
                    // Fail closed: a route registered without any capability is denied
                    // rather than served publicly.
                    foreach ($permissions as $permission) {
                        if(current_user_can($permission)) {
                            return true;
                        }
                    }
                    return false;
                }

                return call_user_func($permissions, $request);
            }
        ));

        return $this;
    }

    public function get($endpoint, $callback, $permissions = [])
    {
        $this->route(\WP_REST_Server::READABLE, $endpoint, $callback, $permissions);
        return $this;
    }

    public function post($endpoint, $callback, $permissions = [])
    {
        $this->route(\WP_REST_Server::CREATABLE, $endpoint, $callback, $permissions);
        return $this;
    }
}
