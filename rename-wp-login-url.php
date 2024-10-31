<?php
/*
  Plugin Name: Rename WP Login URL
  Description: Protect your website by changing the login URL and preventing access to wp-login.php page and wp-admin directory while not logged-in
  Author: Evincedev
  Author URI: https://evincedev.com/
  Version: 1.0
  Text Domain: wordpress-login-url-rename
  License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

// don't load directly
if (!defined('ABSPATH')) {
    die('-1');
}

// Plugin constants
define('WP_LOGIN_URL_RENAME', '1.0');
define('WP_LOGIN_URL_RENAME_FOLDER', 'wordpress-login-url-rename');

define('WP_LOGIN_URL_RENAME_URL', plugin_dir_url(__FILE__));
define('WP_LOGIN_URL_RENAME_DIR', plugin_dir_path(__FILE__));
define('WP_LOGIN_URL_RENAME_BASENAME', plugin_basename(__FILE__));

require_once WP_LOGIN_URL_RENAME_DIR . 'autoload.php';

register_activation_hook(__FILE__, array('\WPLUR\WP_Login_Url_Rename\Plugin', 'activate'));

add_action('plugins_loaded', 'plugins_loaded_wp_login_url_rename_plugin');

function plugins_loaded_wp_login_url_rename_plugin() {
    \WPLUR\WP_Login_Url_Rename\Plugin::get_instance();
}
