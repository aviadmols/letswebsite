<?php
/**
 * Let's SEO — settings access and shared text helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_SEO {

	const OPTION = 'lets_seo_settings';

	/** Every per-post and per-term field is stored under this prefix. */
	const META_PREFIX = '_lets_seo_';

	public static function init() {
		Lets_SEO_Meta::init();
		Lets_SEO_Settings::init();
		Lets_SEO_Import::init();
		Lets_SEO_Share_Image::init();
		Lets_SEO_AI::init();
		Lets_SEO_Breadcrumbs::init();

		if ( self::output_enabled() ) {
			Lets_SEO_Head::init();
			Lets_SEO_Schema::init();
			Lets_SEO_Sitemap::init();
		}
	}

	/**
	 * Name of another SEO plugin that is printing its own tags, if any.
	 *
	 * @return string
	 */
	public static function competing_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'Yoast SEO';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'Rank Math';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'All in One SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'SEOPress';
		}
		return '';
	}

	/**
	 * Whether this module prints titles, meta tags, schema, sitemaps and robots.txt.
	 *
	 * @return bool
	 */
	public static function output_enabled() {
		return '' === self::competing_plugin();
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Titles.
			'site_name'            => '',
			'separator'            => '|',
			'home_title'           => '',
			'home_description'     => '',
			'title_single'         => '{title} {sep} {site}',
			'title_archive'        => '{title} {sep} {site}',

			// Who is behind the site (Organization / Person in the schema).
			'org_type'             => 'Organization',
			'org_name'             => '',
			'org_logo'             => 0,
			'org_description'      => '',
			'org_email'            => '',
			'org_phone'            => '',
			'org_same_as'          => '',

			// Sharing.
			'default_image'        => 0,
			'twitter_site'         => '',
			'share_enabled'        => 1,
			'share_style'          => 'photo',
			'share_bg'             => '#0f0f0f',
			'share_fg'             => '#ffffff',

			// Search engine verification.
			'verify_google'        => '',
			'verify_bing'          => '',
			'verify_facebook'      => '',

			// AI.
			'ai_search_bots'       => 1,
			'ai_training_bots'     => 1,
			'ai_markdown'          => 1,
			'llms_intro'           => '',

			// Indexing.
			'noindex_author'       => 0,
			'noindex_date'         => 1,
			'noindex_tag'          => 0,
			'redirect_attachments' => 1,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function settings() {
		static $settings = null;

		if ( null === $settings ) {
			$saved    = get_option( self::OPTION, array() );
			$settings = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}

		return $settings;
	}

	/**
	 * @param string $key Setting.
	 * @return mixed
	 */
	public static function get( $key ) {
		$settings = self::settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * @return string
	 */
	public static function site_name() {
		$name = trim( (string) self::get( 'site_name' ) );
		return '' !== $name ? $name : self::decode( get_bloginfo( 'name' ) );
	}

	/**
	 * @return string
	 */
	public static function org_name() {
		$name = trim( (string) self::get( 'org_name' ) );
		return '' !== $name ? $name : self::site_name();
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $key     Field name without prefix.
	 * @return mixed
	 */
	public static function post_meta( $post_id, $key ) {
		return get_post_meta( $post_id, self::META_PREFIX . $key, true );
	}

	/**
	 * @param int    $term_id Term ID.
	 * @param string $key     Field name without prefix.
	 * @return mixed
	 */
	public static function term_meta( $term_id, $key ) {
		return get_term_meta( $term_id, self::META_PREFIX . $key, true );
	}

	/**
	 * Fill {title} {site} {sep} {tagline} {category} {page} in a title template.
	 *
	 * @param string              $template Template.
	 * @param array<string,string> $vars    Values; site, sep and tagline are filled in when missing.
	 * @return string
	 */
	public static function replace_vars( $template, array $vars ) {
		$vars = array_merge(
			array(
				'site'     => self::site_name(),
				'sep'      => (string) self::get( 'separator' ),
				'tagline'  => self::decode( get_bloginfo( 'description' ) ),
				'title'    => '',
				'category' => '',
				'page'     => '',
			),
			$vars
		);

		$out = preg_replace_callback(
			'/\{(\w+)\}/',
			function ( $m ) use ( $vars ) {
				return array_key_exists( $m[1], $vars ) ? $vars[ $m[1] ] : $m[0];
			},
			(string) $template
		);

		// An empty variable must not leave a dangling separator behind ("Title |", "| Site").
		$sep = preg_quote( $vars['sep'], '/' );
		if ( '' !== $sep ) {
			$out = preg_replace( '/(\s*' . $sep . '\s*){2,}/u', ' ' . $vars['sep'] . ' ', $out );
			$out = preg_replace( '/^\s*' . $sep . '\s*|\s*' . $sep . '\s*$/u', '', $out );
		}

		return trim( preg_replace( '/\s{2,}/u', ' ', $out ) );
	}

	/**
	 * Plain text from HTML, shortcodes removed and whitespace collapsed.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function plain_text( $html ) {
		$html = strip_shortcodes( (string) $html );
		$html = preg_replace( '#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is', ' ', $html );
		$text = wp_strip_all_tags( $html );
		$text = self::decode( $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Cut text to a length on a word boundary.
	 *
	 * @param string $text   Text.
	 * @param int    $length Max characters.
	 * @return string
	 */
	public static function cut( $text, $length = 155 ) {
		$text = trim( (string) $text );

		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $length - 1 );
		$space = mb_strrpos( $cut, ' ' );

		if ( false !== $space && $space > $length * 0.6 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		// Not rtrim(): its character list is bytes, and the bytes of "–" also end Hebrew letters.
		return preg_replace( '/[\s,.;:\-–—]+$/u', '', $cut ) . '…';
	}

	/**
	 * Word count that also works for Hebrew (str_word_count does not).
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	public static function word_count( $text ) {
		$text = trim( (string) $text );
		return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
	}

	/**
	 * @param int $words Word count.
	 * @return int Minutes, at least 1.
	 */
	public static function reading_minutes( $words ) {
		return max( 1, (int) round( $words / 200 ) );
	}

	/**
	 * @param string $text Text with HTML entities.
	 * @return string
	 */
	public static function decode( $text ) {
		return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * The HTML a visitor actually sees for a post's body — Elementor layouts
	 * included, which are not in post_content.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function rendered_content( $post ) {
		static $cache = array();

		if ( isset( $cache[ $post->ID ] ) ) {
			return $cache[ $post->ID ];
		}

		// Rendering runs the_content filters, some of which render this post again.
		$cache[ $post->ID ] = '';

		$html = '';

		try {
			if ( self::built_with_elementor( $post->ID ) ) {
				$html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post->ID, false );
			}

			if ( '' === trim( (string) $html ) ) {
				$previous        = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
				$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				setup_postdata( $post );

				$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

				$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				if ( $previous instanceof WP_Post ) {
					setup_postdata( $previous );
				}
			}
		} catch ( Throwable $e ) {
			$html = $post->post_content;
		}

		$cache[ $post->ID ] = (string) $html;

		return $cache[ $post->ID ];
	}

	/**
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function built_with_elementor( $post_id ) {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->documents ) ) {
			return false;
		}

		$document = \Elementor\Plugin::$instance->documents->get( $post_id );

		return $document && $document->is_built_with_elementor();
	}

	/**
	 * Image details for an attachment, at the size best suited to sharing.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{url:string,width:int,height:int,alt:string,type:string}|null
	 */
	public static function image( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id < 1 ) {
			return null;
		}

		$src = wp_get_attachment_image_src( $attachment_id, 'full' );

		if ( ! $src ) {
			return null;
		}

		return array(
			'url'    => $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'alt'    => trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
			'type'   => (string) get_post_mime_type( $attachment_id ),
		);
	}

	/**
	 * Post types that get SEO fields: every public one except attachments.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'], $types['elementor_library'], $types['e-landing-page'] );
		return array_values( $types );
	}

	/**
	 * @return string[]
	 */
	public static function taxonomies() {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		unset( $taxonomies['post_format'] );
		return array_values( $taxonomies );
	}

	/**
	 * Whether a post should be kept out of search engines and the AI layer.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function post_is_hidden( $post ) {
		return 'publish' !== $post->post_status
			|| '' !== $post->post_password
			|| (bool) self::post_meta( $post->ID, 'noindex' );
	}
}
