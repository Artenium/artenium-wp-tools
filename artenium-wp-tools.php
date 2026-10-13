<?php
/*
Plugin Name: Artenium Tools
Plugin URI:  https://github.com/Artenium/artenium-wp-tools/
Description: Outils Wordpress artenium nécessaires au bon fonctionnement de votre site web.
Version:     1.0.4
Author:      Alan
Author URI:  https://www.artenium.com
License:     GPL2
*/

if (!defined('ABSPATH')) exit;



//////////////////////////////////////////////////////////////////////////////////
// ARTENIUM WEBP CONVERTER / IMAGE RENAMER
//////////////////////////////////////////////////////////////////////////////////

final class Auto_WebP_Converter {

	const OPT_QUALITY = 'awc_quality';
	const OPT_KEEP    = 'awc_keep_if_larger';

	/** Nom d'origine du fichier envoyé (avant tout nettoyage par WordPress). */
	private static string $original_name = '';

	/** Textes à injecter dans le média, indexés par nom de fichier final. */
	private static array $labels = [];

	public static function init(): void {
		// Capture du nom d'origine (upload classique, REST, sideload).
		add_filter( 'wp_handle_upload_prefilter', [ __CLASS__, 'remember_name' ] );
		add_filter( 'wp_handle_sideload_prefilter', [ __CLASS__, 'remember_name' ] );

		// Renommage + conversion.
		add_filter( 'wp_handle_upload', [ __CLASS__, 'process' ], 10, 2 );

		// Légende + description (avant insertion), texte alternatif (après insertion).
		add_filter( 'wp_insert_attachment_data', [ __CLASS__, 'fill_texts' ], 10, 4 );
		add_action( 'add_attachment', [ __CLASS__, 'set_alt' ] );

		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_notices', [ __CLASS__, 'maybe_notice' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ __CLASS__, 'action_links' ] );
	}

	/* ------------------------------ Utilitaires ------------------------------ */

	private static function quality(): int {
		return max( 1, min( 100, (int) get_option( self::OPT_QUALITY, 72 ) ) );
	}

	private static function supported(): bool {
		return wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] );
	}

	/** "Mon Image_été (2)" => "mon-image-ete-2" */
	private static function slugify( string $name ): string {
		$s = strtolower( remove_accents( $name ) );
		$s = trim( preg_replace( '/[^a-z0-9]+/', '-', $s ), '-' );
		return '' !== $s ? $s : 'image';
	}

	/** "Mon_Image-été 2" => "Mon Image été 2" */
	private static function make_label( string $original ): string {
		$name  = pathinfo( $original, PATHINFO_FILENAME );
		$label = trim( preg_replace( '/[\s_-]+/', ' ', $name ) );
		return sanitize_text_field( (string) apply_filters( 'awc_label', $label, $original ) );
	}

	private static function url_with_basename( string $url, string $basename ): string {
		return trailingslashit( dirname( $url ) ) . $basename;
	}

	/* ------------------------------ Pipeline upload ------------------------------ */

	public static function remember_name( $file ) {
		self::$original_name = ( is_array( $file ) && isset( $file['name'] ) ) ? (string) $file['name'] : '';
		return $file;
	}

	/**
	 * @param array  $upload  [ 'file', 'url', 'type' ] ou [ 'error' ]
	 * @param string $context 'upload' ou 'sideload'
	 */
	public static function process( $upload, $context = 'upload' ) {
		$original            = self::$original_name;
		self::$original_name = '';

		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}
		if ( 0 !== strpos( $upload['type'], 'image/' ) ) {
			return $upload;
		}
		if ( '' === $original ) {
			$original = wp_basename( $upload['file'] );
		}

		$label = self::make_label( $original );

		if ( apply_filters( 'awc_rename', true, $upload, $context ) ) {
			$upload = self::rename_safe( $upload, $original );
		}

		$upload = self::to_webp( $upload, $context );

		if ( '' !== $label && apply_filters( 'awc_fill_texts', true, $upload, $context ) ) {
			self::$labels[ wp_basename( $upload['file'] ) ] = $label;
		}

		return $upload;
	}

	/** Renomme le fichier : minuscules, tirets, sans accents ni espaces. */
	private static function rename_safe( array $upload, string $original ): array {
		$info = pathinfo( $upload['file'] );
		$ext  = strtolower( $info['extension'] ?? '' );
		$slug = self::slugify( pathinfo( $original, PATHINFO_FILENAME ) );

		$target = '' !== $ext ? $slug . '.' . $ext : $slug;
		if ( $target === $info['basename'] ) {
			return $upload; // Déjà propre.
		}

		$target   = wp_unique_filename( $info['dirname'], $target );
		$new_path = trailingslashit( $info['dirname'] ) . $target;

		if ( ! @rename( $upload['file'], $new_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $upload;
		}

		$upload['file'] = $new_path;
		$upload['url']  = self::url_with_basename( $upload['url'], $target );
		return $upload;
	}

	/** Convertit JPG/PNG en WebP et remplace le fichier. */
	private static function to_webp( array $upload, $context ): array {
		if ( ! in_array( $upload['type'], [ 'image/jpeg', 'image/png' ], true ) ) {
			return $upload;
		}
		if ( ! apply_filters( 'awc_should_convert', true, $upload, $context ) || ! self::supported() ) {
			return $upload;
		}

		$source = $upload['file'];
		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return $upload;
		}

		// Orientation EXIF appliquée avant conversion (les métadonnées sont perdues en WebP).
		if ( 'image/jpeg' === $upload['type'] && method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}

		$editor->set_quality( (int) apply_filters( 'awc_quality', self::quality(), $upload ) );

		$info = pathinfo( $source );
		$name = wp_unique_filename( $info['dirname'], $info['filename'] . '.webp' );
		$dest = trailingslashit( $info['dirname'] ) . $name;

		$saved = $editor->save( $dest, 'image/webp' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return $upload; // Échec : on garde l'original.
		}

		// Garde l'original si le WebP est plus lourd.
		if ( get_option( self::OPT_KEEP, true ) && filesize( $saved['path'] ) >= filesize( $source ) ) {
			wp_delete_file( $saved['path'] );
			return $upload;
		}

		wp_delete_file( $source );

		$upload['file'] = $saved['path'];
		$upload['url']  = self::url_with_basename( $upload['url'], wp_basename( $saved['path'] ) );
		$upload['type'] = 'image/webp';
		return $upload;
	}

	/* ------------------------------ Légende, description, alt ------------------------------ */

	/** Filtre wp_insert_attachment_data : les données reçues sont "slashées". */
	public static function fill_texts( $data, $postarr = [], $unsanitized = [], $update = false ) {
		if ( $update || ! empty( $postarr['ID'] ) || empty( $data['guid'] ) ) {
			return $data;
		}
		$key = wp_basename( $data['guid'] );
		if ( ! isset( self::$labels[ $key ] ) ) {
			return $data;
		}
		$label                = wp_slash( self::$labels[ $key ] );
		$data['post_excerpt'] = $label; // Légende.
		$data['post_content'] = $label; // Description.
		return $data;
	}

	public static function set_alt( $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$key = wp_basename( $post->guid );
		if ( ! isset( self::$labels[ $key ] ) ) {
			return;
		}
		update_post_meta( $post_id, '_wp_attachment_image_alt', wp_slash( self::$labels[ $key ] ) );
		unset( self::$labels[ $key ] );
	}

	/* ------------------------------ Réglages ------------------------------ */

	public static function register_settings(): void {
		register_setting( 'media', self::OPT_QUALITY, [
			'type'              => 'integer',
			'sanitize_callback' => static fn( $v ) => max( 1, min( 100, (int) $v ) ),
			'default'           => 82,
		] );
		register_setting( 'media', self::OPT_KEEP, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );

		add_settings_section( 'awc_section', __( 'Conversion WebP', 'auto-webp-converter' ), '__return_false', 'media' );

		add_settings_field( self::OPT_QUALITY, __( 'Qualité WebP', 'auto-webp-converter' ), static function () {
			printf(
				'<input type="number" min="1" max="100" step="1" name="%1$s" value="%2$d" class="small-text"> <span class="description">%3$s</span>',
				esc_attr( self::OPT_QUALITY ),
				self::quality(),
				esc_html__( '1 à 100 (80-85 est un bon compromis).', 'auto-webp-converter' )
			);
		}, 'media', 'awc_section' );

		add_settings_field( self::OPT_KEEP, __( 'Original conservé', 'auto-webp-converter' ), static function () {
			printf(
				'<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>',
				esc_attr( self::OPT_KEEP ),
				checked( (bool) get_option( self::OPT_KEEP, true ), true, false ),
				esc_html__( 'Garder le JPG/PNG d\'origine si le WebP obtenu est plus lourd.', 'auto-webp-converter' )
			);
		}, 'media', 'awc_section' );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-media.php' ) ) . '">' . esc_html__( 'Réglages', 'auto-webp-converter' ) . '</a>' );
		return $links;
	}

	public static function maybe_notice(): void {
		if ( self::supported() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'plugins', 'options-media', 'upload' ], true ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Convertisseur WebP artenium :</strong> '
			. esc_html__( 'ce serveur ne peut pas écrire de WebP (GD sans support WebP ou Imagick absent). Aucune conversion n\'est effectuée.', 'auto-webp-converter' )
			. '</p></div>';
	}
}

Auto_WebP_Converter::init();



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
