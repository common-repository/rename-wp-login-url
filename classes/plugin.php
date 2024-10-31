<?php

namespace WPLUR\WP_Login_Url_Rename;

class Plugin {

    use Singleton;

    private $wp_login_php;

    protected function init() {
        global $wp_version;

        if (version_compare($wp_version, '4.0-RC1-src', '<')) {
            add_action('admin_notices', array($this, 'wplur_admin_notices_incompatible'));
            add_action('network_admin_notices', array($this, 'wplur_admin_notices_incompatible'));

            return;
        }


        if (is_multisite() && !function_exists('is_plugin_active_for_network') || !function_exists('is_plugin_active')) {
            require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
        }

        if (is_plugin_active_for_network('rename-wp-login/rename-wp-login.php')) {
            deactivate_plugins(WP_LOGIN_URL_RENAME_BASENAME);
            add_action('network_admin_notices', array($this, 'wplur_admin_notices_plugin_conflict'));
            if (isset($_GET['activate'])) {
                unset($_GET['activate']);
            }

            return;
        }

        if (is_plugin_active('rename-wp-login/rename-wp-login.php')) {
            deactivate_plugins(WP_LOGIN_URL_RENAME_BASENAME);
            add_action('admin_notices', array($this, 'wplur_admin_notices_plugin_conflict'));
            if (isset($_GET['activate'])) {
                unset($_GET['activate']);
            }

            return;
        }

        if (is_multisite() && is_plugin_active_for_network(WP_LOGIN_URL_RENAME_BASENAME)) {
            add_action('wpmu_options', array($this, 'wplur_options'));
            add_action('update_wpmu_options', array($this, 'update_wplur_options'));

            add_filter('network_admin_plugin_action_links_' . WP_LOGIN_URL_RENAME_BASENAME, array(
                $this,
                'wplur_plugin_action_links'
            ));
        }

        if (is_multisite()) {
            add_action('wp_before_admin_bar_render', array($this, 'wplur_modify_mysites_menu'), 999);
        }

        add_action('admin_init', array($this, 'wplur_admin_init'));
        add_action('plugins_loaded', array($this, 'wplur_plugins_loaded'), 9999);
        add_action('admin_notices', array($this, 'wplur_admin_notices'));
        add_action('network_admin_notices', array($this, 'wplur_admin_notices'));
        add_action('wp_loaded', array($this, 'wplur_wp_loaded'));
        add_action('setup_theme', array($this, 'wplur_setup_theme'), 1);

        add_filter('plugin_action_links_' . WP_LOGIN_URL_RENAME_BASENAME, array($this, 'wplur_plugin_action_links'));
        add_filter('site_url', array($this, 'wplur_site_url'), 10, 4);
        add_filter('network_site_url', array($this, 'wplur_network_site_url'), 10, 3);
        add_filter('wp_redirect', array($this, 'wplur_redirect'), 10, 2);
        add_filter('site_option_welcome_email', array($this, 'wplur_welcome_email'));

        remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);
        add_action('admin_enqueue_scripts', array($this, 'wplur_admin_enqueue_scripts'));

        add_action('admin_menu', array($this, 'wplur_login_url_rename_menu_page'));

        add_action('template_redirect', array($this, 'wplur_redirect_export_data'));
        add_filter('login_url', array($this, 'wplur_login_url'), 10, 3);

        add_filter('user_request_action_email_content', array($this, 'wplur_user_request_action_email_content'), 999, 2);

        add_filter('site_status_tests', array($this, 'wplur_site_status_tests'));
    }

    /*
     * Remove Loopback Requests
     */
    public function wplur_site_status_tests($tests) {
        unset($tests['async']['loopback_requests']);

        return $tests;
    }

    /*
     * Filter to change the text of the email sent when an account is created
     */
    public function wplur_user_request_action_email_content($email_text, $email_data) {
        $email_text = str_replace('###CONFIRM_URL###', esc_url_raw(str_replace($this->wplur_new_login_slug() . '/', 'wp-login.php', $email_data['confirm_url'])), $email_text);

        return $email_text;
    }

    /*
     * Function to remove trailing slash
     */
    private function wplur_use_trailing_slashes() {
        return ( '/' === esc_attr(substr(get_option('permalink_structure'), - 1, 1)) );
    }

    
    private function wplur_user_trailingslashit($string) {
        return esc_attr($this->wplur_use_trailing_slashes()) ? esc_attr(trailingslashit($string)) : esc_attr(untrailingslashit($string));
    }

    private function wplur_template_loader() {
        global $pagenow;
        $pagenow = 'index.php';
        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', true);
        }

        wp();
        require_once( ABSPATH . WPINC . '/template-loader.php' );
        die;
    }

    public function wplur_modify_mysites_menu() {
        global $wp_admin_bar;

        $all_toolbar_nodes = $wp_admin_bar->get_nodes();

        foreach ($all_toolbar_nodes as $node) {
            if (preg_match('/^blog-(\d+)(.*)/', $node->id, $matches)) {
                $blog_id = $matches[1];
                if ($login_slug = $this->wplur_new_login_slug($blog_id)) {
                    if (!$matches[2] || '-d' === $matches[2]) {
                        $args = $node;
                        $old_href = $args->href;
                        $args->href = preg_replace('/wp-admin\/$/', "$login_slug/", $old_href);
                        if ($old_href !== $args->href) {
                            $wp_admin_bar->add_node($args);
                        }
                    } elseif (strpos($node->href, '/wp-admin/') !== false) {
                        $wp_admin_bar->remove_node($node->id);
                    }
                }
            }
        }
    }

    private function wplur_new_login_slug($blog_id = '') {
        if ($blog_id) {
            if ($slug = esc_attr(get_blog_option($blog_id, 'wplur_page'))) {
                return $slug;
            }
        } else {
            if ($slug = esc_attr(get_option('wplur_page'))) {
                return $slug;
            } else if (( is_multisite() && is_plugin_active_for_network(WP_LOGIN_URL_RENAME_BASENAME) && ( $slug = esc_attr(get_site_option('wplur_page', 'login')) ))) {
                return $slug;
            } else if ($slug = 'login') {
                return $slug;
            }
        }
    }

    private function wplur_new_redirect_slug() {
        if ($slug = esc_attr(get_option('wplur_redirect_admin'))) {
            return $slug;
        } else if (( is_multisite() && is_plugin_active_for_network(WP_LOGIN_URL_RENAME_BASENAME) && ( $slug = get_site_option('wplur_redirect_admin', '404') ))) {
            return $slug;
        } else if ($slug = '404') {
            return $slug;
        }
    }

    public function wplur_new_login_url($scheme = null) {

        $url = apply_filters('wp_login_url_rename_home_url', home_url('/', $scheme));

        if (esc_attr(get_option('permalink_structure'))) {

            return esc_url($this->wplur_user_trailingslashit($url . $this->wplur_new_login_slug()));
        } else {

            return esc_url($url . '?' . $this->wplur_new_login_slug());
        }
    }

    public function wplur_new_redirect_url($scheme = null) {

        if (esc_attr(get_option('permalink_structure'))) {

            return esc_url($this->wplur_user_trailingslashit(home_url('/', $scheme) . $this->wplur_new_redirect_slug()));
        } else {

            return esc_url(home_url('/', $scheme) . '?' . $this->wplur_new_redirect_slug());
        }
    }

    public function wplur_admin_notices_incompatible() {

        echo wp_kses_post('<div class="error notice is-dismissible"><p>' . __('Please upgrade to the latest version of WordPress to activate', 'wordpress-login-url-rename') . ' <strong>' . __('WordPress Login URL Rename', 'wordpress-login-url-rename') . '</strong>.</p></div>');
    }

    public function wplur_admin_notices_plugin_conflict() {

        echo wp_kses_post('<div class="error notice is-dismissible"><p>' . __('WordPress Login URL Rename could not be activated because you already have Rename wp-login.php active. Please uninstall rename wp-login.php to use WordPress Login URL Rename', 'wordpress-login-url-rename') . '</p></div>');
    }

    /**
     * Plugin activation
     */
    public static function activate() {
        //add_option( 'wplur_redirect', '1' );

        do_action('wp_login_url_rename_activate');
    }

    public function wplur_options() {
        $out = '';

        $out .= '<h3>' . __('Rename WP Login URL ', 'wordpress-login-url-rename') . '</h3>';
        $out .= '<p>' . __('This option allows you to set a networkwide default, which can be overridden by individual sites. Simply go to to the site\'s permalink settings to change the url.', 'wordpress-login-url-rename') . '</p>';
        $out .= '<p>' . sprintf(__('Need help? Try the <a href="%1$s" target="_blank">support forum</a>. This plugin is kindly brought to you by <a href="%2$s" target="_blank">Evincedev</a>', 'wordpress-login-url-rename'), 'https://wordpress.org/plugins/rename-wp-login-url/', 'http://evincedev.com/') . '</p>';
        $out .= '<table class="form-table" id="wplur-settings">';
        $out .= '<tr valign="top">';
        $out .= '<th scope="row"><label for="wplur_page">' . __('Networkwide default', 'wordpress-login-url-rename') . '</label></th>';
        $out .= '<td><input id="wplur_page" type="text" name="wplur_page" value="' . esc_attr(get_site_option('wplur_page', 'login')) . '"></td>';
        $out .= '<th scope="row"><label for="wplur_redirect_admin">' . __('Redirection url default', 'wordpress-login-url-rename') . '</label></th>';
        $out .= '<td><input id="wplur_redirect_admin" type="text" name="wplur_redirect_admin" value="' . esc_attr(get_site_option('wplur_redirect_admin', '404')) . '"></td>';
        $out .= '</tr>';
        $out .= '</table>';

        echo $out;
    }

    /*
     * Function to update Rename WP Login URL Options
     */
    public function update_wplur_options() {
        if (!empty($_POST) && check_admin_referer('siteoptions')) {
            if (( $wplur_page = sanitize_title_with_dashes($_POST['wplur_page']) ) && strpos($wplur_page, 'wp-login') === false && !in_array($wplur_page, $this->wplur_forbidden_slugs())) {

                flush_rewrite_rules(true);
                update_site_option('wplur_page', $wplur_page);
            }
            if (( $wplur_redirect_admin = sanitize_title_with_dashes($_POST['wplur_redirect_admin']) ) && strpos($wplur_redirect_admin, '404') === false) {

                flush_rewrite_rules(true);
                update_site_option('wplur_redirect_admin', $wplur_redirect_admin);
            }
        }
    }

    
    /*
     * Admin Init Function
     */
    public function wplur_admin_init() {
        global $pagenow;

        add_settings_section(
                'wp-login-url-rename-section',
                'Rename WP Login URL',
                array($this, 'wplur_section_desc'),
                'wplur_settings'
        );

        add_settings_field(
                'wplur_page',
                '<label for="wplur_page">' . __('Login url', 'wordpress-login-url-rename') . '</label>',
                array($this, 'wplur_page_input'),
                'wplur_settings',
                'wp-login-url-rename-section'
        );

        add_settings_field(
                'wplur_redirect_admin',
                '<label for="wplur_redirect_admin">' . __('Redirection url', 'wordpress-login-url-rename') . '</label>',
                array($this, 'wplur_redirect_admin_input'),
                'wplur_settings',
                'wp-login-url-rename-section'
        );

        register_setting('wplur_settings', 'wplur_page', 'sanitize_title_with_dashes');
        register_setting('wplur_settings', 'wplur_redirect_admin', 'sanitize_title_with_dashes');

        if (esc_attr(get_option('wplur_redirect'))) {

            delete_option('wplur_redirect');

            if (is_multisite() && is_super_admin() && is_plugin_active_for_network(WP_LOGIN_URL_RENAME_BASENAME)) {

                $redirect = esc_url(network_admin_url('settings.php#wplur_settings'));
            } else {

                $redirect = esc_url(admin_url('options-general.php?page=wplur_settings'));
            }

            wp_safe_redirect($redirect);
            die();
        }
    }

    /*
     * Function to display data of Rename WP Login URL Plugin Settings Sections
     */
    public function wplur_section_desc() {

        $out = '';

        if (!is_multisite() || is_super_admin()) {

            $out .= '<div id="wplur_settings">';
            $out .= sprintf(__('Need help? Try the <a href="%1$s" target="_blank">support forum</a>. This plugin is kindly brought to you by <a href="%2$s" target="_blank">Evincedev</a>', 'wordpress-login-url-rename'), 'https://wordpress.org/plugins/rename-wp-login-url/', 'https://evincedev.com/') . ' (' . __('WordPress specialized hosting', 'wordpress-login-url-rename') . ')';
            $out .= '</div>';
        }

        if (is_multisite() && is_super_admin() && is_plugin_active_for_network(WP_LOGIN_URL_RENAME_BASENAME)) {

            $out .= '<p>' . sprintf(__('To set a networkwide default, go to <a href="%s">Network Settings</a>.', 'wordpress-login-url-rename'), network_admin_url('settings.php#wplur_settings')) . '</p>';
        }

        echo wp_kses_post($out);
    }

    
    public function wplur_page_input() {
        if (get_option('permalink_structure')) {

            echo '<code>' . esc_url(trailingslashit(home_url())) . '</code> <input id="wplur_page" type="text" name="wplur_page" value="' . esc_attr($this->wplur_new_login_slug()) . '">' . ( esc_attr($this->wplur_use_trailing_slashes()) ? ' <code>/</code>' : '' );
        } else {

            echo '<code>' . esc_url(trailingslashit(home_url())) . '?</code> <input id="wplur_page" type="text" name="wplur_page" value="' . esc_attr($this->wplur_new_login_slug()) . '">';
        }

        echo '<p class="description">' . __('Protect your website by changing the login URL and preventing access to the wp-login.php page and the wp-admin directory to non-connected people.', 'wordpress-login-url-rename') . '</p>';
    }

    public function wplur_redirect_admin_input() {
        if (get_option('permalink_structure')) {

            echo '<code>' . esc_url(trailingslashit(home_url())) . '</code> <input id="wplur_redirect_admin" type="text" name="wplur_redirect_admin" value="' . esc_attr($this->wplur_new_redirect_slug()) . '">' . ( esc_attr($this->wplur_use_trailing_slashes()) ? ' <code>/</code>' : '' );
        } else {

            echo '<code>' . esc_url(trailingslashit(home_url())) . '?</code> <input id="wplur_redirect_admin" type="text" name="wplur_redirect_admin" value="' . esc_attr($this->wplur_new_redirect_slug()) . '">';
        }

        echo '<p class="description">' . __('Redirect URL when someone tries to access the wp-login.php page and the wp-admin directory while not logged in.', 'wordpress-login-url-rename') . '</p>';
    }

    /*
     * Function to display wp-admin notices
     */
    public function wplur_admin_notices() {

        global $pagenow;

        $out = '';

        if (!is_network_admin() && $pagenow === 'options-general.php' && isset($_GET['settings-updated']) && !isset($_GET['page'])) {

            echo '<div class="updated notice is-dismissible"><p>' . sprintf(__('Your login page is now here: <strong><a href="%1$s">%2$s</a></strong>. Bookmark this page!', 'wordpress-login-url-rename'), esc_url($this->wplur_new_login_url()), esc_url($this->wplur_new_login_url())) . '</p></div>';
        }
    }

    /*
     * Function to display plugin settings link in the Plugins list table.
     */
    public function wplur_plugin_action_links($links) {

        if (is_network_admin() && is_plugin_active_for_network(WP_LOGIN_URL_RENAME_BASENAME)) {

            array_unshift($links, '<a href="' . esc_url(network_admin_url('settings.php#wplur_settings')) . '">' . __('Settings', 'wordpress-login-url-rename') . '</a>');
        } elseif (!is_network_admin()) {

            array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=wplur_settings')) . '">' . __('Settings', 'wordpress-login-url-rename') . '</a>');
        }

        return $links;
    }

    /*
     * Function to redirect
     */
    public function wplur_redirect_export_data() {
        if (!empty($_GET) && isset($_GET['action']) && 'confirmaction' === sanitize_text_field($_GET['action']) && isset($_GET['request_id']) && isset($_GET['confirm_key'])) {
            $request_id = (int) $_GET['request_id'];
            $key = sanitize_text_field(wp_unslash($_GET['confirm_key']));
            $result = wp_validate_user_request_key($request_id, $key);
            if (!is_wp_error($result)) {
                wplur_redirect(add_query_arg(array(
                    'action' => 'confirmaction',
                    'request_id' => sanitize_text_field($_GET['request_id']),
                    'confirm_key' => sanitize_text_field($_GET['confirm_key'])
                                ), $this->wplur_new_login_url()
                ));
                exit();
            }
        }
    }

    /*
     * Function will run when plugins loaded 
     */
    public function wplur_plugins_loaded() {

        global $pagenow;

        if (!is_multisite() && ( strpos(rawurldecode($_SERVER['REQUEST_URI']), 'wp-signup') !== false || strpos(rawurldecode($_SERVER['REQUEST_URI']), 'wp-activate') !== false ) && apply_filters('wp_login_url_rename_signup_enable', false) === false) {

            wp_die(__('This feature is not enabled.', 'wordpress-login-url-rename'));
        }

        $request = parse_url(rawurldecode($_SERVER['REQUEST_URI']));

        if (( strpos(rawurldecode($_SERVER['REQUEST_URI']), 'wp-login.php') !== false || ( isset($request['path']) && untrailingslashit($request['path']) === site_url('wp-login', 'relative') ) ) && !is_admin()) {

            $this->wp_login_php = true;

            $_SERVER['REQUEST_URI'] = $this->wplur_user_trailingslashit('/' . str_repeat('-/', 10));

            $pagenow = 'index.php';
        } elseif (( isset($request['path']) && untrailingslashit($request['path']) === home_url($this->wplur_new_login_slug(), 'relative') ) || (!get_option('permalink_structure') && isset($_GET[$this->wplur_new_login_slug()]) && empty($_GET[$this->wplur_new_login_slug()]) )) {

            $pagenow = 'wp-login.php';
        } elseif (( strpos(rawurldecode($_SERVER['REQUEST_URI']), 'wp-register.php') !== false || ( isset($request['path']) && untrailingslashit($request['path']) === site_url('wp-register', 'relative') ) ) && !is_admin()) {

            $this->wp_login_php = true;

            $_SERVER['REQUEST_URI'] = $this->wplur_user_trailingslashit('/' . str_repeat('-/', 10));

            $pagenow = 'index.php';
        }
    }

    public function wplur_setup_theme() {
        global $pagenow;

        if (!is_user_logged_in() && 'customize.php' === $pagenow) {
            wp_die(__('This has been disabled', 'wordpress-login-url-rename'), 403);
        }
    }

    public function wplur_wp_loaded() {

        global $pagenow;

        $request = parse_url(rawurldecode($_SERVER['REQUEST_URI']));

        if (!( isset($_GET['action']) && sanitize_text_field($_GET['action']) === 'postpass' && isset($_POST['post_password']) )) {

            if (is_admin() && !is_user_logged_in() && !defined('DOING_AJAX') && $pagenow !== 'admin-post.php' && $request['path'] !== '/wp-admin/options.php') {
                wp_safe_redirect($this->wplur_new_redirect_url());
                die();
            }

            if ($pagenow === 'wp-login.php' && $request['path'] !== $this->wplur_user_trailingslashit($request['path']) && get_option('permalink_structure')) {

                wp_safe_redirect($this->wplur_user_trailingslashit($this->wplur_new_login_url())
                        . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '' ));

                die;
            } elseif ($this->wp_login_php) {

                if (( $referer = wp_get_referer() ) && strpos($referer, 'wp-activate.php') !== false && ( $referer = parse_url($referer) ) && !empty($referer['query'])) {

                    parse_str($referer['query'], $referer);

                    @require_once WPINC . '/ms-functions.php';

                    if (!empty($referer['key']) && ( $result = wpmu_activate_signup($referer['key']) ) && is_wp_error($result) && ( $result->get_error_code() === 'already_active' || $result->get_error_code() === 'blog_taken' )) {

                        wp_safe_redirect($this->wplur_new_login_url()
                                . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '' ));

                        die;
                    }
                }

                $this->wplur_template_loader();
            } elseif ($pagenow === 'wp-login.php') {
                global $error, $interim_login, $action, $user_login;

                $redirect_to = admin_url();

                $requested_redirect_to = '';
                if (isset($_REQUEST['redirect_to'])) {
                    $requested_redirect_to = esc_url_raw($_REQUEST['redirect_to']);
                }

                if (is_user_logged_in()) {
                    $user = wp_get_current_user();
                    if (!isset($_REQUEST['action'])) {
                        $logged_in_redirect = apply_filters('whl_logged_in_redirect', $redirect_to, $requested_redirect_to, $user);
                        wp_safe_redirect($logged_in_redirect);
                        die();
                    }
                }

                @require_once ABSPATH . 'wp-login.php';

                die;
            }
        }
    }

    public function wplur_site_url($url, $path, $scheme, $blog_id) {
        return $this->wplur_filter_wp_login_php($url, $scheme);
    }

    public function wplur_network_site_url($url, $path, $scheme) {
        return $this->wplur_filter_wp_login_php($url, $scheme);
    }

    public function wplur_redirect($location, $status) {
        if (strpos($location, 'https://wordpress.com/wp-login.php') !== false) {
            return $location;
        }

        return $this->wplur_filter_wp_login_php($location);
    }

    public function wplur_filter_wp_login_php($url, $scheme = null) {

        if (strpos($url, 'wp-login.php?action=postpass') !== false) {
            return $url;
        }

        if (strpos($url, 'wp-login.php') !== false && strpos(wp_get_referer(), 'wp-login.php') === false) {

            if (is_ssl()) {

                $scheme = 'https';
            }

            $args = explode('?', $url);

            if (isset($args[1])) {

                parse_str($args[1], $args);

                if (isset($args['login'])) {
                    $args['login'] = rawurlencode($args['login']);
                }

                $url = add_query_arg($args, $this->wplur_new_login_url($scheme));
            } else {

                $url = $this->wplur_new_login_url($scheme);
            }
        }

        return $url;
    }

    public function wplur_welcome_email($value) {

        return $value = str_replace('wp-login.php', trailingslashit(get_site_option('wplur_page', 'login')), $value);
    }

    public function wplur_forbidden_slugs() {

        $wp = new \WP;

        return array_merge($wp->public_query_vars, $wp->private_query_vars);
    }

    /**
     * Load scripts
     */
    public function wplur_admin_enqueue_scripts($hook) {

        wp_enqueue_style('plugin-install');

        wp_enqueue_script('plugin-install');
        wp_enqueue_script('updates');
        add_thickbox();
        
        wp_enqueue_script('wplur-admin', plugins_url( 'assets/js/wplur-admin.js', dirname(__FILE__)));
    }

    /*
     * Function to create admin menu
     */
    public function wplur_login_url_rename_menu_page() {
        $title = __('Rename WP Login URL');

        add_options_page($title, $title, 'manage_options', 'wplur_settings', array(
            $this,
            'wplur_settings_page'
        ));
    }

    /*
     * Plugin Settings Page
     */
    public function wplur_settings_page() {
        ?>
        <form action="options.php" method="post">
        <?php
        settings_fields('wplur_settings');
        settings_fields('wplur_settings');

        do_settings_sections('wplur_settings');
        ?>
            <input name="submit" class="button button-primary" type="submit" value="<?php esc_attr_e('Save Changes'); ?>" />
        </form>
        <?php
    }

    /**
     *
     * Update url redirect : wp-admin/options.php
     *
     * @param $login_url
     * @param $redirect
     * @param $force_reauth
     *
     * @return string
     */
    public function wplur_login_url($login_url, $redirect, $force_reauth) {
        if (is_404()) {
            return '#';
        }

        if ($force_reauth === false) {
            return $login_url;
        }

        if (empty($redirect)) {
            return $login_url;
        }

        $redirect = explode('?', $redirect);

        if ($redirect[0] === admin_url('options.php')) {
            $login_url = admin_url();
        }

        return $login_url;
    }

}
