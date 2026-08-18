<?php
/*
Plugin Name: Artenium Tools
Plugin URI:  https://github.com/Artenium/artenium-wp-tools/
Description: Outils Wordpress artenium nécessaires au bon fonctionnement de votre site web.
Version:     1.0.2
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
        'title'  => 'Vide les caches Varnish, Redis et OPcache',
        'meta'   => [
            'class' => 'ab-item-disabled',
        ],
    ]);

}, 100);

add_action('admin_post_custom_varnish_purge', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    check_admin_referer('custom_varnish_purge_nonce');
    global $custom_varnish_host;
    $messages = [];

    // VARNISH
    $response = wp_remote_request('http://127.0.0.1/.*', [
        'method'  => 'PURGE',
        'headers' => [
            'Host'           => $custom_varnish_host,
            'X-Purge-Method' => 'regex',
        ],
        'timeout' => 5,
    ]);
    if (is_wp_error($response)) {
        $messages[] = '❌ Varnish : ERREUR — ' . $response->get_error_message();
    } else {
        $status = wp_remote_retrieve_response_code($response);
        if ($status === 200) {
            $messages[] = '✅ Varnish : OK — HTTP 200';
        } else {
            $messages[] = '❌ Varnish : ERREUR — HTTP ' . $status;
        }
    }

    // OPCACHE
    if (function_exists('opcache_reset')) {
        $opcache_result = opcache_reset();
        if ($opcache_result) {
            $messages[] = '✅ OPcache : OK — cache PHP vidé';
        } else {
            $messages[] = '❌ OPcache : ERREUR — opcache_reset() a retourné FALSE';
        }
    } else {
        $messages[] = '⚠️ OPcache : INDISPONIBLE — fonction opcache_reset() absente';
    }

    // REDIS
    if (function_exists('wp_cache_flush')) {
        $redis_result = wp_cache_flush();
        if ($redis_result) {
            $messages[] = '✅ Redis : OK — cache objet WordPress vidé';
        } else {
            $messages[] = '❌ Redis : ERREUR — wp_cache_flush() a retourné FALSE';
        }
    } else {
        $messages[] = '⚠️ Redis : INDISPONIBLE — wp_cache_flush() absente';
    }

    // MESSAGE FINAL
    $purge_id = wp_generate_password(12, false);
    set_transient(
        'artenium_purge_' . $purge_id,
        $messages,
        60
    );
    wp_safe_redirect(
        add_query_arg(
            'purge_done',
            $purge_id,
            wp_get_referer()
        )
    );
    exit;
});

add_action('admin_notices', function() {
    if (empty($_GET['purge_done'])) {
        return;
    }
    $purge_id = sanitize_text_field(wp_unslash($_GET['purge_done']));
    $messages = get_transient('artenium_purge_' . $purge_id);
    if ($messages === false) {
        return;
    }
    delete_transient('artenium_purge_' . $purge_id);
    echo '<div class="notice notice-info is-dismissible">';
    foreach ($messages as $message) {
        echo '<p>' . esc_html($message) . '</p>';
    }
    echo '</div>';
});



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
