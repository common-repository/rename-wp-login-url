<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * @package   WordPress Login URL Rename
 * @license   GPL-3.0+
 */
// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

if (is_multisite()) {

    $blogs = $wpdb->get_results("SELECT blog_id FROM {$wpdb->blogs}", ARRAY_A);
    delete_site_option('wplur_page');
    delete_site_option('wplur_redirect_admin');

    flush_rewrite_rules();

    if ($blogs) {

        foreach ($blogs as $blog) {
            switch_to_blog($blog['blog_id']);
            delete_option('wplur_page');
            delete_option('wplur_redirect_admin');

            flush_rewrite_rules();

            //info: optimize table
            $GLOBALS['wpdb']->query("OPTIMIZE TABLE `" . $GLOBALS['wpdb']->prefix . "options`");
            restore_current_blog();
        }
    }
} else {
    delete_option('wplur_page');
    delete_option('wplur_redirect_admin');

    flush_rewrite_rules();

    //info: optimize table
    $GLOBALS['wpdb']->query("OPTIMIZE TABLE `" . $GLOBALS['wpdb']->prefix . "options`");
}