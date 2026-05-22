<?php
/*
Plugin Name: Artenium Tools
Plugin URI:  https://github.com/Artenium/artenium-wp-tools/
Description: Outils Wordpress artenium nécessaires au bon fonctionnement de votre site web.
Version:     1.0.3
Author:      Équipe artenium
Author URI:  https://www.artenium.com
License:     GPL2
*/

if (!defined('ABSPATH')) exit;



//////////////////////////////////////////////////////////////////////////////////
// FONCTION DE PURGE CENTRALISÉE
//////////////////////////////////////////////////////////////////////////////////
 
function artenium_purge_all_caches() {
    global $custom_varnish_host;
    $response = wp_remote_request('http://127.0.0.1/.*', [
        'method'  => 'PURGE',
        'headers' => [
            'Host'           => $custom_varnish_host,
            'X-Purge-Method' => 'regex',
        ],
        'timeout' => 5,
    ]);
 
    if (function_exists('opcache_reset')) opcache_reset();
    if (function_exists('wp_cache_flush')) wp_cache_flush();
 
    return $response;
}
 
 
 
//////////////////////////////////////////////////////////////////////////////////
// ARTENIUM VARNISH / REDIS / OPCACHE PURGE — BOUTON MANUEL
//////////////////////////////////////////////////////////////////////////////////
 
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
        'title'  => 'Vide les caches Varnish, Redis et OPcache',
        'meta'   => [
            'class' => 'ab-item-disabled',
        ],
    ]);
 
}, 100);
 
add_action('admin_post_custom_varnish_purge', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('custom_varnish_purge_nonce');
 
    $response = artenium_purge_all_caches();
 
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
// PURGE AUTOMATIQUE LORS DE LA SAUVEGARDE
//////////////////////////////////////////////////////////////////////////////////

function artenium_maybe_purge_cache($post_id = 0) {
    static $already_purged = false;
    if ($already_purged) {
        return;
    }
    if ($post_id) {
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (wp_is_post_autosave($post_id)) {
            return;
        }
    }
    $already_purged = true;
    artenium_purge_all_caches();
}

// WORDPRESS
add_action('save_post', 'artenium_maybe_purge_cache', 10, 1);

// ELEMENTOR
add_action('elementor/editor/after_save', 'artenium_maybe_purge_cache', 10, 1);

// BRICKS
add_action('wp_ajax_bricks_save_post', function() {
    artenium_maybe_purge_cache();
}, 10);



//////////////////////////////////////////////////////////////////////////////////
// CACHER REDIS OBJECT CACHE
//////////////////////////////////////////////////////////////////////////////////

add_action('admin_bar_menu', function ($wp_admin_bar) {
    $wp_admin_bar->remove_node('redis-cache');
}, 999);
add_action('admin_menu', function () {
    remove_submenu_page('options-general.php', 'redis-cache');
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
// GITHUB UPDATE
//////////////////////////////////////////////////////////////////////////////////

add_filter('site_transient_update_plugins', function ($transient) {
    if (empty($transient->checked)) return $transient;

    $plugin_slug = 'artenium-wp-tools/artenium-wp-tools.php';
    $current_version = $transient->checked[$plugin_slug] ?? '0';

    // Cache 12h pour éviter de spammer l'API GitHub
    $cache_key = 'artenium_github_release';
    $data = get_transient($cache_key);

    if (!$data) {
        $response = wp_remote_get('https://api.github.com/repos/Artenium/artenium-wp-tools/releases/latest', [
            'headers' => ['User-Agent' => 'WordPress/' . get_bloginfo('version')],
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) return $transient;
        $data = json_decode(wp_remote_retrieve_body($response));
        if (empty($data->tag_name)) return $transient;
        set_transient($cache_key, $data, 12 * HOUR_IN_SECONDS);
    }

	$download_url = !empty($data->assets) ? $data->assets[0]->browser_download_url : $data->zipball_url;
    $remote_version = ltrim($data->tag_name, 'v');

    if (version_compare($remote_version, $current_version, '>')) {
        $transient->response[$plugin_slug] = (object) [
            'slug'			=> 'artenium-wp-tools',
            'plugin'      	=> $plugin_slug,
            'new_version' 	=> $remote_version,
            'package'		=> $download_url,
            'url'         	=> 'https://github.com/Artenium/artenium-wp-tools',
        ];
    }

    return $transient;
});
