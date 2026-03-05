<?php
/*
Plugin Name: Artenium Tools
Plugin URI:  https://github.com/Artenium/artenium-wp-tools/
Description: Outils Wordpress artenium.
Version:     1.0.0
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
// TITLE ANIMATION - Word reveal for .reveal-title class
//////////////////////////////////////////////////////////////////////////////////
function heading_word_animation() {
    ?>
    <style>
        .reveal-title .elementor-heading-title {
            visibility: hidden;
        }
        body.elementor-editor-active .reveal-title .elementor-heading-title {
            visibility: visible !important;
        }
        .reveal-title .elementor-heading-title word-anim {
            opacity: 0;
            transform: translateY(16px);
            display: inline-block;
            visibility: visible;
        }
        .reveal-title .elementor-heading-title.word-anim-active word-anim {
            animation: fadeUp 0.16s forwards;
        }
        @keyframes fadeUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        setTimeout(() => {
            function wrapTextNodes(node, delayRef) {
                let fragment = document.createDocumentFragment();
                node.childNodes.forEach(child => {
                    if (child.nodeType === Node.TEXT_NODE) {
                        let words = child.textContent.split(/\s+/);
                        words.forEach(word => {
                            if (word.trim().length > 0) {
                                let el = document.createElement("word-anim");
                                el.textContent = word;
                                el.style.animationDelay = (delayRef.value * 0.04) + "s";
                                delayRef.value++;
                                fragment.appendChild(el);
                                fragment.append(" ");
                            }
                        });
                    } else if (child.nodeType === Node.ELEMENT_NODE) {
                        let clone = child.cloneNode(false);
                        clone.appendChild(wrapTextNodes(child, delayRef));
                        fragment.appendChild(clone);
                    }
                });
                return fragment;
            }
            document.querySelectorAll(".reveal-title .elementor-heading-title").forEach(title => {
                if (!title.dataset.animated) {
                    let delayRef = { value: 0 };
                    let wrapped = wrapTextNodes(title, delayRef);
                    title.innerHTML = "";
                    title.appendChild(wrapped);
                    title.dataset.animated = "true";
                    title.style.visibility = "visible";
                }
            });
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add("word-anim-active");
                        observer.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.33
            });
            document.querySelectorAll(".reveal-title .elementor-heading-title").forEach(title => {
                observer.observe(title);
            });
		// Attente de 400ms avant d’exécuter le script.
		// Laisse par ex.GTranslate faire le travail de traduction AVANT application de l'effet.
        }, 400); 

    });
    </script>
    <?php
}
add_action('wp_head', 'heading_word_animation');



//////////////////////////////////////////////////////////////////////////////////
// TITLE ANIMATION - Word reveal for .reveal-title class
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
