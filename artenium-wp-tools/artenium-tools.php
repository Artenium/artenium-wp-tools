<?php
/*
Plugin Name: Artenium Tools
Plugin URI:  https://github.com/Artenium/artenium-wp-tools/
Description: Outils Wordpress artenium
Version:     1.0.1
Author:      Alan
Author URI:  https://www.artenium.com
License:     GPL2
*/

if (!defined('ABSPATH')) exit;

//////////////////////////////////////////////////////////////////////////////////
// ARTENIUM VARNISH / REDIS / OPCACHE PURGE
//////////////////////////////////////////////////////////////////////////////////

$custom_varnish_host = parse_url(get_site_url(), PHP_URL_HOST);

add_action('admin_bar_menu', function($wp_admin_bar) {
    if (!current_user_can('manage_options')) return;

    $url = wp_nonce_url(
        admin_url('admin-post.php?action=custom_varnish_purge'),
        'custom_varnish_purge_nonce'
    );

    $wp_admin_bar->add_node([
        'id'    => 'custom_varnish_purge',
        'title' => '❌ Vider les caches',
        'href'  => $url,
    ]);

    $wp_admin_bar->add_node([
        'id'     => 'custom_varnish_purge_desc',
        'parent' => 'custom_varnish_purge',
        'title'  => 'Vide les caches Varnish, Redis et OPCache',
        'meta'   => [
            'class' => 'ab-item-disabled',
        ],
    ]);

}, 100);

add_action('admin_post_custom_varnish_purge', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('custom_varnish_purge_nonce');

    global $custom_varnish_host;

    // PURGE VARNISH
    $response = wp_remote_request('http://127.0.0.1/.*', [
        'method'  => 'PURGE',
        'headers' => [
            'Host'           => $custom_varnish_host,
            'X-Purge-Method' => 'regex',
        ],
        'timeout' => 5,
    ]);

    // OPCACHE
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
	
    // REDIS
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }

    // Message
    if (is_wp_error($response)) {
        $msg = 'Purge Varnish + OPCache + Redis : Erreur (' . $response->get_error_message() . ')';
    } else {
        $msg = 'Purge Varnish + OPCache + Redis : OK (Varnish HTTP ' . wp_remote_retrieve_response_code($response) . ')';
    }

    wp_redirect(add_query_arg('purge_done_msg', urlencode($msg), wp_get_referer()));
    exit;
});

add_action('admin_notices', function() {
    if (isset($_GET['purge_done_msg'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($_GET['purge_done_msg']) . '</p></div>';
    }
});



//////////////////////////////////////////////////////////////////////////////////
// CACHER REDIS OBJECT CACHE
//////////////////////////////////////////////////////////////////////////////////

add_action('admin_bar_menu', function ($wp_admin_bar) {
    $wp_admin_bar->remove_node('redis-cache');
}, 999);



//////////////////////////////////////////////////////////////////////////////////
// SVG UPLOAD
//////////////////////////////////////////////////////////////////////////////////

add_filter('upload_mimes', function($mimes) {
    $mimes['svg'] = 'image/svg+xml';
    return $mimes;
});



//////////////////////////////////////////////////////////////////////////////////
// Activer les shortcodes SCF
//////////////////////////////////////////////////////////////////////////////////

add_action( 'acf/init', 'set_acf_settings' );
function set_acf_settings() {
    acf_update_setting( 'enable_shortcode', true );
}



//////////////////////////////////////////////////////////////////////////////////
// UPDATE VIA GITHUB (sans GitHub Updater)
//////////////////////////////////////////////////////////////////////////////////

add_filter('site_transient_update_plugins', function($transient) {
    if (empty($transient->checked)) return $transient;

    $plugin_slug = plugin_basename(__FILE__); // artenium-wp-tools/artenium-tools.php
    $current_version = $transient->checked[$plugin_slug] ?? '0';

    // GitHub API : récupérer la dernière release
    $github_api = 'https://api.github.com/repos/Artenium/artenium-wp-tools/releases/latest';

    $response = wp_remote_get($github_api, [
        'headers' => ['User-Agent' => 'WordPress/' . get_bloginfo('version')]
    ]);

    if (is_wp_error($response)) return $transient;

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);

    if (empty($data->tag_name)) return $transient;

    $remote_version = ltrim($data->tag_name, 'v'); // enlever le "v" devant tag
    $download_url   = $data->zipball_url;

    if (version_compare($remote_version, $current_version, '>')) {
        $transient->response[$plugin_slug] = (object)[
            'slug'        => $plugin_slug,
            'new_version' => $remote_version,
            'package'     => $download_url,
            'url'         => 'https://github.com/Artenium/artenium-wp-tools',
        ];
    }

    return $transient;
});
