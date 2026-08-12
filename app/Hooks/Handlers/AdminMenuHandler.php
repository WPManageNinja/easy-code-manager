<?php

namespace FluentSnippets\App\Hooks\Handlers;

use FluentSnippets\App\Helpers\Arr;
use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Http\Controllers\SnippetsController;
use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Services\Permissions;
use FluentSnippets\App\Services\SnippetErrors;
use FluentSnippets\App\Services\TeamPlugins;
use FluentSnippets\App\Services\Trans;

class AdminMenuHandler
{
    /**
     * The class the dark theme hangs off. FluentCart's, deliberately - see
     * printThemeClass().
     */
    const DARK_CLASS = 'fluent_theme_dark';

    public function register()
    {
        add_action('admin_menu', array($this, 'addMenu'));
        add_action('admin_head', array($this, 'printThemeClass'));

        add_action('admin_init', [Helper::class, 'maybeUpdateStandAlone']);

        add_action('wp_ajax_fluent_snippets_export_snippets', [$this, 'exportSnippets']);
        add_action('wp_ajax_fluent_snippets_import_json', [$this, 'importSnippets']);

        add_action('wp_ajax_fluent_snippet_update', [$this, 'saveSnippet']);
        add_action('wp_ajax_fluent_snippet_create', [$this, 'createSnippet']);

    }

    /**
     * Applies the chosen theme before the page paints.
     *
     * The app itself could do this once Vue has booted, but by then the screen has already
     * been drawn light and the switch reads as a flash. This runs synchronously in <head>,
     * so the first frame is the right one.
     *
     * The Fluent Theme Mode specification puts the class on <body>, and <body> does not
     * exist yet at this point in the document - so the class goes on <html> first, where
     * it still resolves for a stylesheet before anything is painted, and is mirrored onto
     * <body> the moment the element is there. Both carry it from then on; the switch keeps
     * them in step (see Bits/ThemeSwitch.vue).
     *
     * `system` is cached as `system:dark` or `system:light`, which is what makes the fast
     * path above possible: the last resolution is read straight out of storage instead of
     * waiting on matchMedia. It is re-checked against the real preference immediately
     * afterwards and rewritten if the machine has changed its mind since.
     *
     * @return void
     */
    public function printThemeClass()
    {
        if (!isset($_GET['page']) || $_GET['page'] !== 'fluent-snippets') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        ?>
        <script>
            (function () {
                var KEY = 'fluent_theme_mode',
                    DARK = '<?php echo esc_js(self::DARK_CLASS); ?>',
                    stored = localStorage.getItem(KEY) || '',
                    isSystem = stored.indexOf('system') === 0 || stored === '',
                    dark = stored === 'dark' || stored === 'system:dark';

                // The cached resolution got the first frame right; this is the real answer.
                if (isSystem && window.matchMedia) {
                    dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    localStorage.setItem(KEY, dark ? 'system:dark' : 'system:light');
                }

                document.documentElement.classList.toggle(DARK, dark);

                // <body> is the class's home per the spec, and it is parsed after this.
                document.addEventListener('DOMContentLoaded', function () {
                    document.body.classList.toggle(DARK, dark);
                });
            })();
        </script>
        <?php
    }

    public function addMenu()
    {
        /*
         * Not install_plugins directly. On a site with DISALLOW_FILE_MODS set, core takes
         * that capability away from everybody, and this menu entry used to disappear with
         * it — snippets still running, no way to look at them, nothing to say why. The
         * screen it opens is read-only in that case; see Services/Permissions.
         */
        $permission = Permissions::viewCapability();

        add_menu_page(
            __('Fluent Snippets', 'easy-code-manager'),
            __('FluentSnippets', 'easy-code-manager'),
            $permission,
            'fluent-snippets',
            array($this, 'render'),
            $this->getMenuIcon(),
            120
        );
    }

    public function exportSnippets()
    {
        // Export reads and downloads; it changes nothing on the site, so it stays
        // available on a read-only screen — where it is arguably the most useful thing
        // on offer, since editing has to happen somewhere else.
        if (!Permissions::canView()) {
            wp_send_json([
                'status'  => false,
                'message' => __('You do not have permission to perform this action.', 'easy-code-manager')
            ], 422);
        }

        $nonce = Arr::get($_REQUEST, '_nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'fluent-snippets')) {
            wp_send_json([
                'status'  => false,
                'message' => __('Invalid nonce.', 'easy-code-manager')
            ], 422);
        }

        $snippetDir = Helper::getStorageDir();

        $selectedSnippets = Arr::get($_REQUEST, 'snippets', []);

        // array_map() fatals on a scalar, and `snippets=foo` is a single request
        // parameter away (M11).
        if (!is_array($selectedSnippets)) {
            $selectedSnippets = [];
        }

        $selectedSnippets = array_map(function ($snippet) use ($snippetDir) {
            // add .php
            return $snippetDir . '/' . $snippet . '.php';
        }, $selectedSnippets);

        if (empty($selectedSnippets)) {
            wp_send_json([
                'status'  => false,
                'message' => __('No snippets selected.', 'easy-code-manager')
            ], 422);
        }

        // get the file paths and store them in an array
        $files = glob($snippetDir . '/*.php');

        $selectedSnippets = array_intersect($selectedSnippets, $files);

        if (empty($selectedSnippets)) {
            wp_send_json([
                'status'  => false,
                'message' => __('No snippets selected.', 'easy-code-manager')
            ], 422);
        }

        $formattedSnippets = [];

        $snippetModel = new Snippet();
        foreach ($selectedSnippets as $snippetFile) {
            if (!file_exists($snippetFile)) {
                continue;
            }
            $fileContent = file_get_contents($snippetFile);
            [$docBlockArray, $code] = $snippetModel->parseBlock($fileContent);

            if (empty($docBlockArray) || empty($code)) {
                continue;
            }

            $base64Code = base64_encode($code);
            $formattedSnippets[] = [
                'code'      => $base64Code,
                'code_hash' => md5($code),
                'info'      => $docBlockArray,
            ];
        }

        $exportData = [
            'file_type'      => 'fluent_code_snippets',
            'version'        => FLUENT_SNIPPETS_PLUGIN_VERSION,
            'snippets'       => $formattedSnippets,
            'snippets_count' => count($formattedSnippets),
        ];

        $fileName = 'fluent-snippets-' . count($formattedSnippets) . '-' . date('Y-m-d-H-i') . '.json';

        // export as JSON
        header('Content-disposition: attachment; filename=' . $fileName);
        header('Content-type: application/json');
        echo json_encode($exportData); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit();
    }

    public function importSnippets()
    {
        /*
         * Importing authors code: every snippet in the file is written to disk, and a PHP
         * one is executed on the spot by Helper::validateCode() -> PhpValidator, which
         * eval()s it to report runtime errors. So this needs the same capability pair as
         * every other authoring path (saveSnippet, createSnippet, and the REST routes via
         * SnippetsController::denyUnlessCanAuthorSnippets). It previously required only
         * install_plugins, which meant a site using DISALLOW_UNFILTERED_HTML — where every
         * other way of adding code is closed — could still have code introduced here.
         */
        if (!wp_is_file_mod_allowed('capability_update_core')) {
            wp_send_json([
                'status'  => false,
                'message' => __('Snippets cannot be imported on this site: wp-config.php has DISALLOW_FILE_MODS set, and importing writes a file per snippet into wp-content.', 'easy-code-manager')
            ], 422);
        }

        if (!current_user_can('unfiltered_html') || !current_user_can('install_plugins')) {
            wp_send_json([
                'status'  => false,
                'message' => __('You do not have permission to perform this action. Required Permission: unfiltered_html and install_plugins', 'easy-code-manager')
            ], 422);
        }

        $nonce = Arr::get($_REQUEST, '_nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'fluent-snippets')) {
            wp_send_json([
                'status'  => false,
                'message' => __('Invalid nonce.', 'easy-code-manager')
            ], 422);
        }

        // Guarded (M11): with none of this, a missing or failed upload produced a chain of
        // PHP warnings and then the generic "invalid file format" message, which tells the
        // user nothing about what actually went wrong.
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            wp_send_json([
                'status'  => false,
                'message' => __('No file was uploaded.', 'easy-code-manager')
            ], 422);
        }

        $jsonFile = $_FILES['file'];

        if (!isset($jsonFile['error']) || $jsonFile['error'] !== UPLOAD_ERR_OK) {
            wp_send_json([
                'status'  => false,
                'message' => __('The file could not be uploaded. Please try again.', 'easy-code-manager')
            ], 422);
        }

        // PHP populates $_FILES itself, so tmp_name cannot be pointed at an arbitrary
        // file by the request — this is a sanity check, not a traversal defence.
        if (empty($jsonFile['tmp_name']) || !is_uploaded_file($jsonFile['tmp_name'])) {
            wp_send_json([
                'status'  => false,
                'message' => __('The uploaded file could not be read.', 'easy-code-manager')
            ], 422);
        }

        // get file contents
        $fileContent = file_get_contents($jsonFile['tmp_name']);

        $fileData = json_decode($fileContent, true);

        if (!$fileData || empty($fileData['file_type']) || $fileData['file_type'] != 'fluent_code_snippets' || empty($fileData['snippets'])) {
            wp_send_json([
                'status'  => false,
                'message' => __('Invalid file format. Please upload a JSON file that is exported from Fluent Snippets', 'easy-code-manager')
            ], 422);
        }

        $snippets = Arr::get($fileData, 'snippets', []);

        $createdSnippts = [];

        $existingSnippets = (new Snippet)->get();

        $existingCodeHashes = [];

        foreach ($existingSnippets as $existingSnippet) {
            $existingCodeHashes[] = md5($existingSnippet['code']);
        }

        foreach ($snippets as $snippet) {
            if (empty($snippet['code'])) {
                continue;
            }

            $name = Arr::get($snippet, 'info.name', '');
            if (!$name) {
                continue;
            }

            $code = base64_decode($snippet['code']);
            $codeHash = md5($code);

            if ($codeHash != $snippet['code_hash']) {
                $createdSnippts[] = [
                    'name'       => $name,
                    'is_success' => 'no',
                    'reason'     => 'Invalid code hash',
                ];
                continue;
            }

            if (in_array($codeHash, $existingCodeHashes)) {
                $createdSnippts[] = [
                    'name'       => $name,
                    'is_success' => 'no',
                    'reason'     => __('Snippet already exists', 'easy-code-manager')
                ];
                continue;
            }

            $meta = Arr::get($snippet, 'info', []);

            $metaValidated = SnippetsController::validateMeta($meta);

            if (is_wp_error($metaValidated)) {
                $createdSnippts[] = [
                    'name'       => $name,
                    'is_success' => 'no',
                    'reason'     => $metaValidated->get_error_message()
                ];
                continue;
            }
            $meta['status'] = 'draft';

            // Check if the php snippet $code is valid or not by validating it
            if ($meta['type'] == 'php_content') {
                $code = apply_filters('fluent_snippets/sanitize_mixed_content', $code, $meta);
                if (is_wp_error($code)) {
                    $createdSnippts[] = [
                        'name'       => $name,
                        'is_success' => 'no',
                        'reason'     => $metaValidated->get_error_message()
                    ];
                    continue;
                }
            }

            $validated = Helper::validateCode($meta['type'], $code);

            if (is_wp_error($validated)) {
                $createdSnippts[] = [
                    'name'       => $name,
                    'is_success' => 'no',
                    'reason'     => $validated->get_error_message()
                ];
                continue;
            }

            $snippetModel = new Snippet();
            $createdSnippet = $snippetModel->createSnippet($code, $meta);

            if (is_wp_error($createdSnippet)) {
                $createdSnippts[] = [
                    'name'       => $name,
                    'is_success' => 'no',
                    'reason'     => $createdSnippet->get_error_message()
                ];
                continue;
            }

            $createdSnippts[] = [
                'name'       => $name,
                'is_success' => 'yes',
                'status'     => 'draft',
                'reason'     => 'Imported',
                'file_name'  => $createdSnippet
            ];
        }

        Helper::cacheSnippetIndex();

        wp_send_json([
            'snippets' => $createdSnippts
        ]);
    }

    public function render()
    {
        $currentUser = wp_get_current_user();

        [$tags, $groups] = (new Snippet())->getAllSnippetTagsGroups();

        add_action('wp_print_scripts', function () {

            $isSkip = apply_filters('fluent_snippets/skip_no_conflict', false);

            if ($isSkip) {
                return;
            }

            global $wp_scripts;
            if (!$wp_scripts) {
                return;
            }

            $approvedSlugs = apply_filters('fluent_snippets_asset_listed_slugs', [
                '\/gutenberg\/'
            ]);

            $approvedSlugs[] = 'easy-code-manager';

            $approvedSlugs = array_unique($approvedSlugs);

            $approvedSlugs = implode('|', $approvedSlugs);

            $pluginUrl = plugins_url();

            $pluginUrl = str_replace(['http:', 'https:'], '', $pluginUrl);

            foreach ($wp_scripts->queue as $script) {
                if (empty($wp_scripts->registered[$script]) || empty($wp_scripts->registered[$script]->src)) {
                    continue;
                }

                $src = $wp_scripts->registered[$script]->src;
                $isMatched = (strpos($src, $pluginUrl) !== false) && !preg_match('/' . $approvedSlugs . '/', $src);
                if (!$isMatched) {
                    continue;
                }
                wp_dequeue_script($wp_scripts->registered[$script]->handle);
            }
        });

        wp_enqueue_script('fluent_snippets_app', FLUENT_SNIPPETS_PLUGIN_URL . 'dist/app.js', ['jquery'], FLUENT_SNIPPETS_PLUGIN_VERSION, true);

        $indexConfig = Helper::getIndexedConfig();

        if (!$indexConfig) {
            $indexConfig = Helper::getIndexedConfig();
        }

        add_filter('admin_footer_text', function ($content) use ($indexConfig) {
            $ext = '';
            if (defined('FLUENT_SNIPPETS_RUNNING_MU')) {
                $ext = '<b>Standalone (MU Mode) is active</b> ';
            }

            return 'Thank you for using <a rel="noopener"  target="_blank" href="https://fluentsnippets.com">Fluent Snippets</a>. <a rel="noopener"  target="_blank" style="text-decoration: none;" href="https://wordpress.org/support/plugin/easy-code-manager/reviews/?filter=5">Write a review ⭐️⭐️⭐️⭐️⭐️</a> ' . $ext;
        });

        wp_localize_script('fluent_snippets_app', 'fluentSnippetAdmin', [
            'slug'                       => 'fluent-snippets',
            'nonce'                      => wp_create_nonce('fluent-snippets'),
            'ajax_url'                   => admin_url('admin-ajax.php'),
            'rest'                       => [
                'base_url'  => esc_url_raw(rest_url()),
                'url'       => rest_url('fluent-snippets'),
                'nonce'     => wp_create_nonce('wp_rest'),
                'namespace' => 'fluent-snippets',
                'version'   => '1'
            ],
            'asset_url'                  => FLUENT_SNIPPETS_PLUGIN_URL . 'dist/',
            'me'                         => [
                'id'        => $currentUser->ID,
                'full_name' => trim($currentUser->first_name . ' ' . $currentUser->last_name),
                'email'     => $currentUser->user_email
            ],
            'i18n'                       => Trans::getStrings(),
            'tags'                       => $tags,
            'groups'                     => $groups,
            'safeModes'                  => $this->getSafeModeInfo(),
            'is_standalone'              => defined('FLUENT_SNIPPETS_RUNNING_MU'),
            'advanced_condition_options' => $this->getAdvancedConditionOptions(),
            'snippet_types'              => $this->getSnippetTypes(),
            'team_plugins'               => TeamPlugins::get(),
            'team_plugins_config'        => TeamPlugins::config(),
            /*
             * The app hides or disables everything that writes when this is false. That is
             * a courtesy to the user, not the enforcement — every route and handler checks
             * for itself, and has to, because nothing stops someone calling them directly.
             */
            'can_edit'                   => Permissions::canEdit(),
            'read_only_notice'           => Permissions::isReadOnly() ? Permissions::readOnlyNotice() : null,
            /*
             * Localised as 'has_line_wrap' until 2026-08-11, but _CodeEditor.vue reads
             * appVars.enable_line_wrap — so the editor never saw this value on page load.
             * Turning line wrap on, reloading, and finding it off again was the visible
             * symptom; it only ever took effect after visiting the Settings screen, which
             * sets the key client-side. Renamed to what the editor actually reads (M3).
             */
            'enable_line_wrap'           => Arr::get($indexConfig, 'meta.enable_line_wrap', 'no'),
        ]);

        echo '<div id="fluent_snippets_app"><h3 class="fsnip_booting">' . __('Loading Snippets..', 'easy-code-manager') . '</h3></div>';
    }

    public function getSafeModeInfo()
    {
        $config = Helper::getIndexedConfig();

        return [
            'is_defined_disabled'  => defined('FLUENT_SNIPPETS_SAFE_MODE') && FLUENT_SNIPPETS_SAFE_MODE,
            'is_filtered_disabled' => !apply_filters('fluent_snippets/run_snippets', true),
            'is_forced_disabled'   => Arr::get($config, 'meta.force_disabled') == 'yes'
        ];
    }

    public function getSnippetTypes()
    {
        return apply_filters('fluent_snippets/snippet_types', [
            'PHP'         => [
                'label'             => __('Functions', 'easy-code-manager'),
                'value'             => 'PHP',
                'inline_tag'        => 'PHP',
                'running_locations' => [
                    'all'      => [
                        'label'       => __('Run Everywhere', 'easy-code-manager'),
                        'description' => __('Snippet gets executed everywhere (both frontend and admin area)', 'easy-code-manager')
                    ],
                    'backend'  => [
                        'label'       => __('Admin Only', 'easy-code-manager'),
                        'description' => __('Snippet gets executed only in admin area (/wp-admin/)', 'easy-code-manager')
                    ],
                    'frontend' => [
                        'label'       => __('Frontend Only', 'easy-code-manager'),
                        'description' => __('Snippet gets executed only in frontend area', 'easy-code-manager')
                    ]
                ]
            ],
            'php_content' => [
                'label'             => __('Content', 'easy-code-manager'),
                'value'             => 'php_content',
                'inline_tag'        => 'PHP + HTML',
                'running_locations' => [
                    'shortcode'      => [
                        'label'       => __('Shortcode', 'easy-code-manager'),
                        'description' => __('Only display when inserted into a post or page using shortcode', 'easy-code-manager')
                    ],
                    'wp_head'        => [
                        'label'       => __('Site Wide Header', 'easy-code-manager'),
                        'description' => __('Insert snippet between the head tags of your website (frontend).', 'easy-code-manager')
                    ],
                    'wp_body_open'   => [
                        'label'       => __('Site Wide Body Open', 'easy-code-manager'),
                        'description' => __('Insert snippet after the opening body tag of your website (frontend).', 'easy-code-manager')
                    ],
                    'wp_footer'      => [
                        'label'       => __('Site Wide Footer', 'easy-code-manager'),
                        'description' => __('Insert snippet before the closing body tag of your website on all pages (frontend).', 'easy-code-manager')
                    ],
                    'before_content' => [
                        'label'       => __('Before Content', 'easy-code-manager'),
                        'description' => __('Insert snippet at the beginning of single post/page content.', 'easy-code-manager')
                    ],
                    'after_content'  => [
                        'label'       => __('After Content', 'easy-code-manager'),
                        'description' => __('Insert snippet at the end of single post/page content.', 'easy-code-manager')
                    ]
                ]
            ],
            'css'         => [
                'label'             => __('Styles', 'easy-code-manager'),
                'value'             => 'css',
                'inline_tag'        => 'CSS',
                'running_locations' => [
                    'wp_head'    => [
                        'label'       => __('Frontend', 'easy-code-manager'),
                        'description' => __('Add CSS on all pages (frontend).', 'easy-code-manager')
                    ],
                    'admin_head' => [
                        'label'       => __('Backend', 'easy-code-manager'),
                        'description' => __('Apply this snippet CSS to backend (/wp-admin/).', 'easy-code-manager')
                    ],
                    'everywhere' => [
                        'label'       => __('Both Backend and Frontend', 'easy-code-manager'),
                        'description' => __('Apply this snippet CSS to both backend and frontend.', 'easy-code-manager')
                    ]
                ]
            ],
            'js'          => [
                'label'             => __('Scripts', 'easy-code-manager'),
                'value'             => 'js',
                'inline_tag'        => 'JS',
                'running_locations' => [
                    'wp_head'      => [
                        'label'       => __('Site Wide Header', 'easy-code-manager'),
                        'description' => __('Run Javascript between the head tags of your website on all pages (frontend).', 'easy-code-manager')
                    ],
                    'wp_footer'    => [
                        'label'       => __('Site Wide Footer', 'easy-code-manager'),
                        'description' => __('Run Javascript before the closing body tag of your website on all pages (frontend).', 'easy-code-manager')
                    ],
                    'admin_head'   => [
                        'label'       => __('Admin Area Header', 'easy-code-manager'),
                        'description' => __('Run Javascript in admin area (/wp-admin/).', 'easy-code-manager')
                    ],
                    'admin_footer' => [
                        'label'       => __('Admin Area Footer', 'easy-code-manager'),
                        'description' => __('Run Javascript in admin area (/wp-admin/) before the closing body tag.', 'easy-code-manager')
                    ]
                ]
            ],
        ]);
    }

    private function getAdvancedConditionOptions()
    {
        $postTypes = get_post_types(array('public' => true), 'objects');

        $formattedPostTypes = array();
        foreach ($postTypes as $postType) {
            $formattedPostTypes[$postType->name] = $postType->label;
        }

        $taxonomies = get_taxonomies(['public' => true], 'objects');
        $formattedTaxonomies = array();
        foreach ($taxonomies as $taxonomy) {
            if ('post_format' === $taxonomy->name) {
                continue;
            }
            $formattedTaxonomies[$taxonomy->name] = $taxonomy->labels->singular_name;
        }

        $options = [
            'user' => [
                'label'    => __('User', 'easy-code-manager'),
                'value'    => 'user',
                'children' => [
                    [
                        'label'       => __('Logged-in', 'easy-code-manager'),
                        'value'       => 'authenticated',
                        'type'        => 'single_assert_option',
                        'is_multiple' => false,
                        'options'     => [
                            'yes' => 'True',
                            'no'  => 'False'
                        ]
                    ],
                    [
                        'label'             => __('User Role', 'easy-code-manager'),
                        'value'             => 'role',
                        'is_multiple'       => true,
                        'is_singular_value' => true,
                        'type'              => 'selections',
                        'options'           => Helper::getUserRoles()
                    ]
                ],
            ],
            'page' => [
                'label'    => __('Page', 'easy-code-manager'),
                'value'    => 'page',
                'children' => [
                    [
                        'label'             => __('Type of page', 'easy-code-manager'),
                        'value'             => 'page_type',
                        'type'              => 'selections',
                        'is_multiple'       => true,
                        'is_singular_value' => true,
                        'options'           => array(
                            'is_front_page' => __('Homepage', 'easy-code-manager'),
                            'is_archive'    => __('Archive', 'easy-code-manager'),
                            'is_singular'   => __('Single Post/Page/CPT', 'easy-code-manager'),
                            'is_search'     => __('Search page', 'easy-code-manager'),
                            'is_404'        => __('404 page', 'easy-code-manager'),
                            'is_author'     => __('Author page', 'easy-code-manager')
                        )
                    ],
                    [
                        'label'       => __('Post Type', 'easy-code-manager'),
                        'value'       => 'post_type',
                        'type'        => 'selections',
                        'is_multiple' => true,
                        'options'     => $formattedPostTypes
                    ],
                    [
                        'label'       => __('Taxonomy Page', 'easy-code-manager'),
                        'value'       => 'taxonomy_page',
                        'type'        => 'selections',
                        'is_multiple' => true,
                        'options'     => $formattedTaxonomies
                    ],
                    [
                        'label'       => __('Taxonomy Term Page', 'easy-code-manager'),
                        'value'       => 'taxonomy_term_page',
                        'type'        => 'rest_selections',
                        'is_multiple' => true,
                        'rest_key'    => 'tax_term_groups',
                        'is_grouped'  => true
                    ],
                    [
                        'label' => __('URL', 'easy-code-manager'),
                        'value' => 'url',
                        'type'  => 'text'
                    ],
                    [
                        'label'       => __('Single Post/Page/CPT', 'easy-code-manager'),
                        'value'       => 'page_ids',
                        'type'        => 'rest_selections',
                        'is_multiple' => true,
                        'rest_key'    => 'post_cpt_groups',
                        'is_grouped'  => true,
                        'show_id'     => true
                    ]
                ]
            ],
            'date' => [
                'label'    => __('Date', 'easy-code-manager'),
                'value'    => 'date',
                'is_pro'   => true,
                'children' => [
                    [
                        'label'        => __('Date Range', 'easy-code-manager'),
                        'value'        => 'date_range',
                        'type'         => 'dates',
                        'date_type'    => 'datetimerange',
                        'is_range'     => true,
                        'value_format' => 'YYYY-MM-DD HH:mm'
                    ],
                    [
                        'label'        => __('Time Range', 'easy-code-manager'),
                        'value'        => 'time_range',
                        'type'         => 'time_range',
                        'is_range'     => true,
                        'value_format' => 'HH:mm:ss'
                    ],
                    [
                        'label'             => __('Day of the week', 'easy-code-manager'),
                        'value'             => 'day_of_week',
                        'type'              => 'selections',
                        'options'           => [
                            'mon' => __('Monday', 'easy-code-manager'),
                            'tue' => __('Tuesday', 'easy-code-manager'),
                            'wed' => __('Wednesday', 'easy-code-manager'),
                            'thu' => __('Thursday', 'easy-code-manager'),
                            'fri' => __('Friday', 'easy-code-manager'),
                            'sat' => __('Saturday', 'easy-code-manager'),
                            'sun' => __('Sunday', 'easy-code-manager'),
                        ],
                        'is_multiple'       => true,
                        'is_singular_value' => true,
                    ]
                ]
            ]
        ];

        if (defined('FLUENTCRM')) {
            $options['fluentcrm'] = [
                'label'    => __('FluentCRM', 'easy-code-manager'),
                'value'    => 'fluentcrm',
                'children' => [
                    [
                        'label'       => __('Is a CRM Contact?', 'easy-code-manager'),
                        'value'       => 'exists',
                        'type'        => 'single_assert_option',
                        'is_multiple' => false,
                        'options'     => [
                            'yes' => 'True',
                            'no'  => 'False'
                        ]
                    ],
                    [
                        'label'       => __('Contact Tags', 'easy-code-manager'),
                        'value'       => 'tags_ids',
                        'type'        => 'rest_selections',
                        'is_multiple' => true,
                        'rest_key'    => 'fluentcrm_tags',
                        'is_grouped'  => false,
                        'show_id'     => false
                    ],
                    [
                        'label'       => __('Contact Lists', 'easy-code-manager'),
                        'value'       => 'tags_list_ids',
                        'type'        => 'rest_selections',
                        'is_multiple' => true,
                        'rest_key'    => 'fluentcrm_lists',
                        'is_grouped'  => false,
                        'show_id'     => false
                    ]
                ],
            ];
        }

        return array_values($options);

    }

    /**
     * The sidebar menu icon.
     *
     * Same shield and </> as the official plugin icon on WordPress.org and as Bits/BrandMark.vue
     * in the app — the paths are lifted from there verbatim, so the listing, the admin bar and
     * this entry are all the one shape. If the mark changes, it changes on the icon first and is
     * copied here; don't redraw it.
     *
     * Two things are deliberate and easy to undo by accident. The shield and the glyph are one
     * path with fill-rule="evenodd", so the glyph is a hole rather than a second colour: the
     * sidebar shows through it, which is what keeps the mark reading as itself when the admin
     * colour scheme changes underneath it. And the fill is plain white — core dims the icon to
     * 60% and takes it back to full on hover and on the current screen, so anything pre-dimmed
     * here (the old artwork drew the shield at 21% opacity) gets dimmed twice and turns to mush
     * at 20px.
     *
     * The viewBox is the shield's exact bounding box, no padding, which is what FluentAuth does
     * too. Core sizes these with `background-size: 20px auto`, so the viewBox width — not the
     * shield — is what lands on 20px: any air left inside it shrinks the mark by that much. An
     * earlier pass here padded the box to about 84% and the shield came out 16.9px against
     * FluentAuth's 20px, visibly the smaller of the two in the same sidebar. Sitting a little
     * larger than the dashicons is the correct trade; the icons this one is read against are
     * the other Fluent plugins, not core's.
     */
    private function getMenuIcon()
    {
        $shield = 'M86.021 23.2424C100.351 26.9727 105.4 28.4285 105.4 28.4285C105.4 28.4285 106.174 43.2132 99.7139 69.9622C94.4735 91.4208 75.1461 103.369 64.1414 110.171L63.9121 110.313L63.6344 110.141C52.6207 103.333 33.298 91.3894 28.1103 69.9622C21.6051 43.2132 22.3329 28.4285 22.3329 28.4285C22.3329 28.4285 27.337 26.9272 41.6668 23.2424C56.861 19.3302 63.8667 17.9199 63.8667 17.9199C63.8667 17.9199 70.8723 19.3302 86.021 23.2424Z';

        $glyph = 'M45.5288 57.7249C45.1648 58.0889 44.9829 58.5893 44.9829 59.0897C44.9829 59.5901 45.1648 60.0905 45.5288 60.4544L52.2615 67.1872C52.4435 67.3691 52.6709 67.5056 52.8984 67.5966C53.1258 67.6876 53.3988 67.7331 53.6262 67.7331C53.8992 67.7331 54.1267 67.6876 54.3541 67.5966C54.5816 67.5056 54.809 67.3691 54.991 67.1872C55.173 67.0052 55.3094 66.7777 55.4004 66.5503C55.4914 66.3228 55.5369 66.0499 55.5369 65.8224C55.5369 65.5495 55.4914 65.322 55.4004 65.0946C55.3094 64.8671 55.173 64.6396 54.991 64.4577L49.623 59.0897L55.0365 53.6762C55.2185 53.4942 55.3549 53.2668 55.4459 53.0393C55.5369 52.8119 55.5824 52.5389 55.5824 52.3115C55.5824 52.0385 55.5369 51.811 55.4459 51.5836C55.3549 51.3561 55.2185 51.1287 55.0365 50.9467C54.8545 50.7647 54.6271 50.6283 54.3996 50.5373C54.1721 50.4463 53.8992 50.4008 53.6717 50.4008C53.3988 50.4008 53.1713 50.4463 52.9439 50.5373C52.7164 50.6283 52.489 50.7647 52.307 50.9467L45.5288 57.7249ZM82.0129 60.4544C82.3769 60.0905 82.5588 59.5901 82.5588 59.0897C82.5588 58.5893 82.3769 58.0889 82.0129 57.7249L75.2802 50.9922C75.0982 50.8102 74.8708 50.6738 74.6433 50.5828C74.4159 50.4918 74.1429 50.4463 73.9155 50.4463C73.6425 50.4463 73.415 50.4918 73.1876 50.5828C72.9601 50.6738 72.7327 50.8102 72.5507 50.9922C72.3687 51.1742 72.2323 51.4016 72.1413 51.6291C72.0503 51.8565 72.0048 52.1295 72.0048 52.3569C72.0048 52.6299 72.0503 52.8573 72.1413 53.0848C72.2323 53.3123 72.3687 53.5397 72.5507 53.7217L77.9642 59.1352L72.5507 64.5487C72.3687 64.7306 72.2323 64.9581 72.1413 65.1855C72.0503 65.413 72.0048 65.686 72.0048 65.9134C72.0048 66.1864 72.0503 66.4138 72.1413 66.6413C72.2323 66.8687 72.3687 67.0962 72.5507 67.2782C72.7327 67.4601 72.9601 67.5966 73.1876 67.6876C73.415 67.7786 73.688 67.8241 73.9155 67.8241C74.1884 67.8241 74.4159 67.7786 74.6433 67.6876C74.8708 67.5966 75.0982 67.4601 75.2802 67.2782L82.0129 60.4544ZM59.8586 72.7371C60.0405 72.7826 60.2225 72.8281 60.4045 72.8281C60.8139 72.8281 61.2233 72.6916 61.5418 72.4187C61.8602 72.1457 62.1332 71.8273 62.2241 71.4179L69.0024 47.8078C69.0934 47.5803 69.0934 47.3074 69.0934 47.0344C69.0934 46.7615 69.0024 46.534 68.8659 46.3066C68.7294 46.0791 68.5929 45.8971 68.3655 45.7152C68.1835 45.5332 67.9561 45.4422 67.6831 45.3513C67.4557 45.2603 67.1827 45.2603 66.9098 45.3058C66.6368 45.3513 66.4094 45.4422 66.1819 45.5332C65.9544 45.6697 65.7725 45.8517 65.5905 46.0336C65.454 46.2611 65.3176 46.4885 65.2721 46.716L58.5393 70.3716C58.4029 70.872 58.4483 71.3724 58.7213 71.8273C58.9488 72.2822 59.4037 72.6007 59.8586 72.7371Z';

        $svg = '<svg viewBox="22.3086 17.9199 83.1204 92.3931" xmlns="http://www.w3.org/2000/svg">'
            . '<path fill="#ffffff" fill-rule="evenodd" d="' . $shield . ' ' . $glyph . '"/>'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Capability and nonce gate for the two editor endpoints.
     *
     * Both failures are dead ends for the user unless they are told what to do about
     * them, and the expired-nonce one is worse than it looks: reloading the page is the
     * fix, and reloading loses whatever they just typed. That warning belongs in the
     * message, not in a support reply afterwards.
     *
     * Sends a 422 and exits when the request cannot proceed.
     *
     * @return void
     */
    private function guardAuthoringRequest()
    {
        /*
         * DISALLOW_FILE_MODS is checked first because it is not a permissions problem and
         * saying so saves the user going through their roles looking for one. It takes
         * install_plugins away from every account on the site, so without this branch the
         * message below would send an administrator hunting for a capability nobody has.
         */
        if (!wp_is_file_mod_allowed('capability_update_core')) {
            $this->sendError(SnippetErrors::make('file_mods_disabled', [
                'title'  => __('Snippets cannot be changed on this site', 'easy-code-manager'),
                'reason' => __('wp-config.php has DISALLOW_FILE_MODS set. Every snippet is a file in wp-content, and that constant tells WordPress nothing may write there, so this is a deliberate setting on this site rather than anything wrong with your account. Snippets that are already active keep running.', 'easy-code-manager'),
                'fix'    => __('Copy your code somewhere safe before leaving this page. To make the change, either remove that line from wp-config.php, or put the snippet file on the server the same way the rest of wp-content gets there, via a deployment or version control.', 'easy-code-manager'),
            ]));
        }

        if (!current_user_can('unfiltered_html') || !current_user_can('install_plugins')) {
            $this->sendError(SnippetErrors::make('permission_denied', [
                'title'  => __('You are not allowed to save snippets on this site', 'easy-code-manager'),
                'reason' => __('Saving a snippet means putting executable code on the site, so it needs both the install_plugins and unfiltered_html capabilities. Your account is missing at least one of them.', 'easy-code-manager'),
                'fix'    => __('On a multisite, only Super Admins have unfiltered_html by default. Otherwise the usual causes are DISALLOW_UNFILTERED_HTML set in wp-config.php, or a security or role-editor plugin that has removed the capability from your role.', 'easy-code-manager'),
            ]));
        }

        $nonce = Arr::get($_REQUEST, '__nonce');

        if (!wp_verify_nonce($nonce, 'fluent-snippets')) {
            $this->sendError(SnippetErrors::make('invalid_nonce', [
                'title'  => __('Your security token has expired', 'easy-code-manager'),
                'reason' => __('WordPress security tokens are only valid for a limited time. This page has been open for too long, or you have signed in again somewhere else since it loaded.', 'easy-code-manager'),
                'fix'    => __('Copy your code somewhere safe first, then reload this page and paste it back. Reloading is what issues a fresh token. If this keeps happening, a caching layer is caching wp-admin pages and needs to be told not to.', 'easy-code-manager'),
            ]));
        }
    }

    /**
     * @param \WP_Error $error
     * @return void
     */
    private function sendError($error)
    {
        wp_send_json([
            'message' => $error->get_error_message(),
            'data'    => $error->get_error_data()
        ], 422);
    }

    /**
     * Decode the `meta` payload the editor posts, or explain why it could not be read.
     *
     * A truncated or missing payload used to fatal on `$meta['code']` one line later, so
     * a size limit on the server surfaced as a blank 500 with no clue attached. Large
     * snippets are exactly what trips `post_max_size`, a ModSecurity body limit, or a
     * `max_input_vars` cap, so this is a real path and it deserves a real message.
     *
     * @return array Decoded meta including the `code` key. Exits on failure.
     */
    private function readPostedMeta()
    {
        $meta = json_decode(wp_unslash(Arr::get($_REQUEST, 'meta', '')), true);

        if (is_array($meta) && isset($meta['code'])) {
            return $meta;
        }

        $this->sendError(SnippetErrors::make('invalid_payload', [
            'title'  => __('The snippet did not arrive in one piece', 'easy-code-manager'),
            'reason' => __('The editor sent your snippet, but the server received something incomplete, so nothing was saved and the previous version is still in place.', 'easy-code-manager'),
            'fix'    => __('This is almost always a size limit on the request. Ask your host to raise post_max_size and max_input_vars, or to relax the firewall rule (often ModSecurity) that is truncating requests to wp-admin. Saving a much shorter snippet is a quick way to confirm that is what is happening.', 'easy-code-manager'),
        ]));
    }

    public function saveSnippet()
    {
        $this->guardAuthoringRequest();

        $fileName = sanitize_file_name(Arr::get($_REQUEST, 'fluent_saving_snippet_name'));
        $meta = $this->readPostedMeta();
        $code = $meta['code'];
        unset($meta['code']);

        $data = [
            'meta'       => $meta,
            'code'       => $code,
            'file_name'  => $fileName,
            'reactivate' => Arr::get($_REQUEST, 'reactivate', '')
        ];

        $snippet = Helper::updateSnippet($data);

        if (is_wp_error($snippet)) {
            wp_send_json([
                'message' => $snippet->get_error_message(),
                'data'    => $snippet->get_error_data()
            ], 422);
        }

        wp_send_json([
            'message' => __('Snippet saved successfully.', 'easy-code-manager'),
            'snippet' => $snippet
        ], 200);
    }

    public function createSnippet()
    {
        $this->guardAuthoringRequest();

        $meta = $this->readPostedMeta();
        $code = $meta['code'];
        unset($meta['code']);

        $data = [
            'meta' => $meta,
            'code' => $code
        ];

        $snippet = Helper::createSnippet($data);

        if (is_wp_error($snippet)) {
            wp_send_json([
                'message' => $snippet->get_error_message(),
                'data'    => $snippet->get_error_data()
            ], 422);
        }

        wp_send_json([
            'message' => __('Snippet created successfully.', 'easy-code-manager'),
            'snippet' => $snippet
        ], 200);
    }


}
