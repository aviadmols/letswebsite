<?php
/**
 * Let's SEO — one-click import from Yoast SEO.
 *
 * Copies per-post and per-term fields and the site-wide settings that have
 * an equivalent here. Anything already filled in on this side is left
 * alone, so running it twice is harmless.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_SEO_Import {

	const ACTION = 'lets_seo_import_yoast';

	/** Yoast post meta → our field. */
	const POST_MAP = array(
		'_yoast_wpseo_title'                 => 'title',
		'_yoast_wpseo_metadesc'              => 'description',
		'_yoast_wpseo_focuskw'               => 'focus_keyword',
		'_yoast_wpseo_canonical'             => 'canonical',
		'_yoast_wpseo_meta-robots-noindex'   => 'noindex',
		'_yoast_wpseo_meta-robots-nofollow'  => 'nofollow',
		'_yoast_wpseo_opengraph-title'       => 'og_title',
		'_yoast_wpseo_opengraph-description' => 'og_description',
		'_yoast_wpseo_opengraph-image-id'    => 'og_image',
		'_yoast_wpseo_primary_category'      => 'primary_term',
		'_yoast_wpseo_primary_product_cat'   => 'primary_term',
	);

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * @return bool
	 */
	public static function has_yoast_data() {
		global $wpdb;

		return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_yoast\\_wpseo\\_%' LIMIT 1" )
			|| false !== get_option( 'wpseo_titles', false );
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( self::ACTION );

		$posts    = self::import_posts();
		$terms    = self::import_terms();
		$settings = self::import_settings();

		$message = sprintf( 'הייבוא הסתיים: %d עמודים, %d קטגוריות/תגיות, %d הגדרות אתר.', $posts, $terms, $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => Lets_SEO_Settings::PAGE,
					'lets_seo_imported' => rawurlencode( $message ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * @return int Posts that got at least one field.
	 */
	protected static function import_posts() {
		global $wpdb;

		$keys = array_keys( self::POST_MAP );
		$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ($in)", $keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$touched = array();

		foreach ( $rows as $row ) {
			$field = self::POST_MAP[ $row->meta_key ];
			$value = self::convert_value( $field, $row->meta_value );

			if ( '' === $value || 0 === $value || false === $value ) {
				continue;
			}

			$key = Lets_SEO::META_PREFIX . $field;

			if ( '' !== (string) get_post_meta( $row->post_id, $key, true ) ) {
				continue;
			}

			update_post_meta( $row->post_id, $key, $value );
			$touched[ $row->post_id ] = true;
		}

		return count( $touched );
	}

	/**
	 * Yoast keeps term fields in one option: wpseo_taxonomy_meta[taxonomy][term_id].
	 *
	 * @return int Terms that got at least one field.
	 */
	protected static function import_terms() {
		$all     = get_option( 'wpseo_taxonomy_meta', array() );
		$map     = array(
			'wpseo_title'   => 'title',
			'wpseo_desc'    => 'description',
			'wpseo_noindex' => 'noindex',
		);
		$touched = 0;

		foreach ( is_array( $all ) ? $all : array() as $terms ) {
			foreach ( is_array( $terms ) ? $terms : array() as $term_id => $values ) {
				$changed = false;

				foreach ( $map as $from => $to ) {
					if ( empty( $values[ $from ] ) ) {
						continue;
					}

					$value = 'noindex' === $to ? ( 'noindex' === $values[ $from ] ) : self::convert_vars( $values[ $from ] );
					$key   = Lets_SEO::META_PREFIX . $to;

					if ( $value && '' === (string) get_term_meta( $term_id, $key, true ) ) {
						update_term_meta( $term_id, $key, $value );
						$changed = true;
					}
				}

				$touched += $changed ? 1 : 0;
			}
		}

		return $touched;
	}

	/**
	 * @return int Settings filled in.
	 */
	protected static function import_settings() {
		$titles  = get_option( 'wpseo_titles', array() );
		$social  = get_option( 'wpseo_social', array() );
		$main    = get_option( 'wpseo', array() );
		$titles  = is_array( $titles ) ? $titles : array();
		$social  = is_array( $social ) ? $social : array();
		$main    = is_array( $main ) ? $main : array();
		$current = Lets_SEO::settings();
		$saved   = get_option( Lets_SEO::OPTION, array() );
		$saved   = is_array( $saved ) ? $saved : array();

		$separators = array(
			'sc-dash'   => '-',
			'sc-ndash'  => '–',
			'sc-mdash'  => '—',
			'sc-colon'  => ':',
			'sc-middot' => '·',
			'sc-bull'   => '•',
			'sc-star'   => '*',
			'sc-smstar' => '⋆',
			'sc-pipe'   => '|',
			'sc-tilde'  => '~',
			'sc-laquo'  => '«',
			'sc-raquo'  => '»',
			'sc-lt'     => '<',
			'sc-gt'     => '>',
		);

		$same_as = array();
		if ( ! empty( $social['facebook_site'] ) ) {
			$same_as[] = $social['facebook_site'];
		}
		if ( ! empty( $social['other_social_urls'] ) && is_array( $social['other_social_urls'] ) ) {
			$same_as = array_merge( $same_as, $social['other_social_urls'] );
		}

		$candidates = array(
			'separator'       => isset( $titles['separator'], $separators[ $titles['separator'] ] ) ? $separators[ $titles['separator'] ] : '',
			'site_name'       => isset( $titles['website_name'] ) ? $titles['website_name'] : '',
			'org_type'        => isset( $titles['company_or_person'] ) && 'person' === $titles['company_or_person'] ? 'Person' : '',
			'org_name'        => isset( $titles['company_name'] ) ? $titles['company_name'] : '',
			'org_logo'        => isset( $titles['company_logo_id'] ) ? (int) $titles['company_logo_id'] : 0,
			'title_single'    => isset( $titles['title-post'] ) ? self::convert_vars( $titles['title-post'] ) : '',
			'home_title'      => isset( $titles['title-home-wpseo'] ) ? self::convert_vars( $titles['title-home-wpseo'] ) : '',
			'home_description' => isset( $titles['metadesc-home-wpseo'] ) ? self::convert_vars( $titles['metadesc-home-wpseo'] ) : '',
			'default_image'   => isset( $social['og_default_image_id'] ) ? (int) $social['og_default_image_id'] : 0,
			'twitter_site'    => isset( $social['twitter_site'] ) ? $social['twitter_site'] : '',
			'org_same_as'     => implode( "\n", array_filter( array_map( 'trim', $same_as ) ) ),
			'verify_google'   => isset( $main['googleverify'] ) ? $main['googleverify'] : '',
			'verify_bing'     => isset( $main['msverify'] ) ? $main['msverify'] : '',
			'noindex_author'  => ! empty( $titles['noindex-author-wpseo'] ) || ! empty( $titles['disable-author'] ) ? 1 : 0,
		);

		$filled   = 0;
		$defaults = Lets_SEO::defaults();

		foreach ( $candidates as $key => $value ) {
			if ( '' === $value || 0 === $value ) {
				continue;
			}
			// Only where the value here is still the untouched default.
			if ( isset( $saved[ $key ] ) && $saved[ $key ] !== $defaults[ $key ] ) {
				continue;
			}
			$current[ $key ] = $value;
			++$filled;
		}

		if ( $filled ) {
			update_option( Lets_SEO::OPTION, $current );
		}

		return $filled;
	}

	/**
	 * @param string $field Our field.
	 * @param string $value Yoast value.
	 * @return mixed
	 */
	protected static function convert_value( $field, $value ) {
		switch ( $field ) {
			case 'noindex':
			case 'nofollow':
				return '1' === (string) $value;

			case 'og_image':
			case 'primary_term':
				return (int) $value;

			case 'title':
			case 'description':
			case 'og_title':
			case 'og_description':
				$value = self::convert_vars( $value );
				// Yoast's default post title template, spelled out, is the same as ours: store nothing.
				return '{title} {sep} {site}' === trim( str_replace( '{page}', '', $value ) ) ? '' : $value;

			default:
				return trim( (string) $value );
		}
	}

	/**
	 * Yoast %%variables%% → ours; the ones with no equivalent are dropped.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected static function convert_vars( $text ) {
		$text = strtr(
			(string) $text,
			array(
				'%%title%%'            => '{title}',
				'%%term_title%%'       => '{title}',
				'%%sitename%%'         => '{site}',
				'%%sep%%'              => '{sep}',
				'%%sitedesc%%'         => '{tagline}',
				'%%primary_category%%' => '{category}',
				'%%category%%'         => '{category}',
				'%%page%%'             => '{page}',
			)
		);

		$text = preg_replace( '/%%[a-z0-9_]+%%/i', '', $text );

		return trim( preg_replace( '/\s{2,}/u', ' ', $text ) );
	}
}
