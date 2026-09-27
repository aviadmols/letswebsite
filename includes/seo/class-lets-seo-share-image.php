<?php
/**
 * Let's SEO — generated share images (1200×630, what Facebook, WhatsApp,
 * LinkedIn, X and Slack show when a link is shared).
 *
 * Two styles:
 * - photo: the featured image, darkened toward the bottom, with the title set
 *          over it.
 * - type:  a solid background with the title set large — for posts without
 *          an image, and for categories and archives.
 *
 * Images are drawn with GD in Heebo and cached in uploads/lets-seo/share/. The
 * file name carries a hash of everything drawn on it, so changing the title,
 * the image or the settings produces a new file (and a new URL, so social
 * networks fetch it again instead of showing their cached copy).
 *
 * GD has no bidi support: it draws characters left to right in the order it
 * gets them. Hebrew lines are therefore put into visual order first — see
 * visual_order().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_SEO_Share_Image {

	const WIDTH  = 1200;
	const HEIGHT = 630;

	/** Bump when the drawing changes, so every cached image is redrawn. */
	const TEMPLATE_VERSION = 1;

	const SUBDIR = 'lets-seo/share';

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'warm' ), 30, 2 );
	}

	/**
	 * Draw the image right after a save, so the first visitor (usually
	 * Facebook's crawler) does not wait for it.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function warm( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! in_array( $post->post_type, Lets_SEO::post_types(), true ) ) {
			return;
		}

		self::for_post( $post );
	}

	/**
	 * @return bool
	 */
	public static function available() {
		return (bool) Lets_SEO::get( 'share_enabled' ) && function_exists( 'imagettftext' ) && function_exists( 'imagecreatetruecolor' );
	}

	/**
	 * @param WP_Post     $post  Post.
	 * @param string|null $title Title to draw; defaults to the share title.
	 * @return array<string,mixed>|null
	 */
	public static function for_post( $post, $title = null ) {
		if ( ! self::available() ) {
			return null;
		}

		$style = (string) Lets_SEO::post_meta( $post->ID, 'share_style' );
		$style = '' === $style ? (string) Lets_SEO::get( 'share_style' ) : $style;

		if ( 'off' === $style ) {
			return null;
		}

		if ( null === $title ) {
			$og    = trim( (string) Lets_SEO::post_meta( $post->ID, 'og_title' ) );
			$title = '' !== $og ? $og : Lets_SEO::decode( get_the_title( $post ) );
		}

		$term   = Lets_SEO_Context::primary_term( $post );
		$kicker = $term ? Lets_SEO::decode( $term->name ) : '';
		$image  = 'photo' === $style ? (int) get_post_thumbnail_id( $post ) : 0;

		return self::get( 'post-' . $post->ID, $title, $kicker, $image );
	}

	/**
	 * @param WP_Term $term Term.
	 * @return array<string,mixed>|null
	 */
	public static function for_term( $term ) {
		if ( ! self::available() ) {
			return null;
		}

		$taxonomy = get_taxonomy( $term->taxonomy );
		$kicker   = $taxonomy ? $taxonomy->labels->singular_name : '';

		return self::get( 'term-' . $term->term_id, Lets_SEO::decode( $term->name ), $kicker, 0 );
	}

	/**
	 * Home page and archives with no image of their own.
	 *
	 * @param string $title Title.
	 * @return array<string,mixed>|null
	 */
	public static function for_site( $title ) {
		if ( ! self::available() ) {
			return null;
		}

		$title  = '' !== trim( $title ) ? $title : Lets_SEO::site_name();
		$kicker = Lets_SEO::decode( get_bloginfo( 'description' ) );

		return self::get( 'site-' . substr( md5( $title ), 0, 8 ), $title, $kicker, 0 );
	}

	/**
	 * The cached image for these inputs, drawn if it does not exist yet.
	 *
	 * @param string $key      Cache key (post-12, term-5, site-…).
	 * @param string $title    Title.
	 * @param string $kicker   Small line above the title.
	 * @param int    $image_id Background image attachment, 0 for the type style.
	 * @return array<string,mixed>|null
	 */
	public static function get( $key, $title, $kicker, $image_id ) {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}

		$bg_file = $image_id ? self::background_file( $image_id ) : '';

		$spec = array(
			'title'   => $title,
			'kicker'  => $kicker,
			'site'    => Lets_SEO::site_name(),
			'domain'  => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'bg_file' => $bg_file,
			'bg'      => (string) Lets_SEO::get( 'share_bg' ),
			'fg'      => (string) Lets_SEO::get( 'share_fg' ),
			'fonts'   => LETS_SEO_DIR . '/fonts',
		);

		/**
		 * Adjust what goes on a share image.
		 *
		 * @param array  $spec Title, kicker, site, domain, bg_file, bg, fg, fonts.
		 * @param string $key  Cache key.
		 */
		$spec = apply_filters( 'lets_seo_share_image_spec', $spec, $key );

		$hash = substr( md5( wp_json_encode( array( $spec, $bg_file ? filemtime( $bg_file ) : 0, self::TEMPLATE_VERSION ) ) ), 0, 10 );
		$key  = sanitize_file_name( $key );
		$dir  = trailingslashit( $uploads['basedir'] ) . self::SUBDIR;
		$name = $key . '-' . $hash . '.jpg';
		$path = $dir . '/' . $name;

		if ( ! file_exists( $path ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return null;
			}

			if ( ! self::render( $spec, $path ) ) {
				return null;
			}

			// Older versions of this image are no longer referenced anywhere.
			foreach ( (array) glob( $dir . '/' . $key . '-*.jpg' ) as $old ) {
				if ( $old !== $path ) {
					wp_delete_file( $old );
				}
			}
		}

		return array(
			'url'    => set_url_scheme( trailingslashit( $uploads['baseurl'] ) . self::SUBDIR . '/' . $name ),
			'width'  => self::WIDTH,
			'height' => self::HEIGHT,
			'alt'    => $title,
			'type'   => 'image/jpeg',
		);
	}

	/**
	 * A file GD can read for an attachment, converting formats it cannot.
	 *
	 * @param int $image_id Attachment ID.
	 * @return string Path, or '' when there is no usable image.
	 */
	protected static function background_file( $image_id ) {
		$file = get_attached_file( $image_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return '';
		}

		$mime = (string) wp_check_filetype( $file )['type'];
		$info = gd_info();
		$ok   = in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif' ), true )
			|| ( 'image/webp' === $mime && ! empty( $info['WebP Support'] ) )
			|| ( 'image/avif' === $mime && ! empty( $info['AVIF Support'] ) );

		if ( $ok ) {
			return $file;
		}

		// e.g. WebP on a GD built without it: let WordPress (often Imagick) make a JPEG copy.
		$uploads = wp_upload_dir();
		$copy    = trailingslashit( $uploads['basedir'] ) . self::SUBDIR . '/src-' . $image_id . '-' . filemtime( $file ) . '.jpg';

		if ( file_exists( $copy ) ) {
			return $copy;
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return '';
		}

		$editor->resize( self::WIDTH * 2, self::HEIGHT * 2, false );
		wp_mkdir_p( dirname( $copy ) );
		$saved = $editor->save( $copy, 'image/jpeg' );

		return is_wp_error( $saved ) ? '' : $saved['path'];
	}

	/**
	 * Draw a share image. Uses nothing from WordPress, so it can be tested on its own.
	 *
	 * @param array<string,string> $spec Title, kicker, site, domain, bg_file, bg, fg, fonts.
	 * @param string               $path JPEG to write.
	 * @return bool
	 */
	public static function render( array $spec, $path ) {
		$w  = self::WIDTH;
		$h  = self::HEIGHT;
		$im = imagecreatetruecolor( $w, $h );

		if ( ! $im ) {
			return false;
		}

		imagealphablending( $im, true );

		$fonts = array(
			'black' => $spec['fonts'] . '/Heebo-Black.ttf',
			'bold'  => $spec['fonts'] . '/Heebo-Bold.ttf',
			'light' => $spec['fonts'] . '/Heebo-Light.ttf',
		);

		$photo = self::draw_background_photo( $im, (string) $spec['bg_file'] );

		if ( $photo ) {
			$fg = array( 255, 255, 255 );
		} else {
			$bg = self::rgb( $spec['bg'], array( 15, 15, 15 ) );
			$fg = self::rgb( $spec['fg'], array( 255, 255, 255 ) );
			imagefilledrectangle( $im, 0, 0, $w, $h, imagecolorallocate( $im, $bg[0], $bg[1], $bg[2] ) );
		}

		$ink   = imagecolorallocate( $im, $fg[0], $fg[1], $fg[2] );
		$muted = imagecolorallocatealpha( $im, $fg[0], $fg[1], $fg[2], 45 );
		$faint = imagecolorallocatealpha( $im, $fg[0], $fg[1], $fg[2], 100 );

		$rtl   = (bool) preg_match( '/\p{Hebrew}|\p{Arabic}/u', $spec['title'] );
		$pad   = 80;
		$right = $w - $pad;

		// Top: site name on the reading side.
		self::text( $im, 26, $fonts['bold'], $ink, $spec['site'], $rtl ? $right : $pad, 104, $rtl );

		// Bottom: domain on the far side, under a hairline.
		imagefilledrectangle( $im, $pad, $h - 118, $right, $h - 118, $faint );
		self::text( $im, 22, $fonts['light'], $muted, $spec['domain'], $rtl ? $pad : $right, $h - 64, ! $rtl );

		// The kicker and title live between the site name and the hairline.
		$region_top    = 150;
		$region_bottom = $h - 150;
		$room          = $region_bottom - $region_top;

		$kicker = trim( (string) $spec['kicker'] );
		$k_size = 26;
		$k_gap  = '' !== $kicker ? $k_size + 26 : 0;

		// Title: the largest size at which it fits both the line budget and the height.
		$max_lines = $photo ? 3 : 4;
		$sizes     = $photo ? range( 74, 50, 4 ) : range( 88, 50, 4 );
		$width     = $w - 2 * $pad;
		$lines     = array();
		$size      = end( $sizes );

		foreach ( $sizes as $candidate ) {
			$lines = self::wrap( $spec['title'], $fonts['black'], $candidate, $width );
			$size  = $candidate;
			if ( count( $lines ) <= $max_lines && count( $lines ) * (int) round( $candidate * 1.18 ) + $k_gap <= $room ) {
				break;
			}
		}

		$line_h = (int) round( $size * 1.18 );
		$fit    = (int) min( $max_lines, floor( ( $room - $k_gap ) / $line_h ) );

		if ( count( $lines ) > $fit ) {
			$lines = self::truncate( $lines, max( 1, $fit ), $fonts['black'], $size, $width );
		}

		$block = count( $lines ) * $line_h;

		// Photo: title sits at the bottom, over the darkest part. Type: centered in the region.
		$bottom = $photo ? $region_bottom : $region_top + (int) ( ( $room + $k_gap + $block ) / 2 );
		$top    = $bottom - $block;

		if ( '' !== $kicker ) {
			self::text( $im, $k_size, $fonts['light'], $muted, self::cut_to_width( $kicker, $fonts['light'], $k_size, $width ), $rtl ? $right : $pad, $top - 26, $rtl );
		}

		foreach ( $lines as $i => $line ) {
			$baseline = $top + ( $i + 1 ) * $line_h - (int) round( $size * 0.24 );
			self::text( $im, $size, $fonts['black'], $ink, $line, $rtl ? $right : $pad, $baseline, $rtl );
		}

		$ok = imagejpeg( $im, $path, 88 );
		imagedestroy( $im );

		return $ok;
	}

	/**
	 * Cover-crop the photo onto the canvas and darken it toward the bottom.
	 *
	 * @param resource|GdImage $im   Canvas.
	 * @param string           $file Image file.
	 * @return bool Whether a photo was drawn.
	 */
	protected static function draw_background_photo( $im, $file ) {
		if ( '' === $file || ! is_readable( $file ) ) {
			return false;
		}

		$src = @imagecreatefromstring( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- unsupported formats return false.

		if ( ! $src ) {
			return false;
		}

		$w     = self::WIDTH;
		$h     = self::HEIGHT;
		$sw    = imagesx( $src );
		$sh    = imagesy( $src );
		$scale = max( $w / $sw, $h / $sh );
		$cw    = (int) round( $w / $scale );
		$ch    = (int) round( $h / $scale );
		$sx    = (int) round( ( $sw - $cw ) / 2 );
		$sy    = (int) round( ( $sh - $ch ) * 0.35 ); // Faces and subjects tend to sit in the upper part.

		imagecopyresampled( $im, $src, 0, 0, $sx, $sy, $w, $h, $cw, $ch );
		imagedestroy( $src );

		for ( $y = 0; $y < $h; $y++ ) {
			$t     = $y / ( $h - 1 );
			$dark  = 0.28 + 0.62 * pow( $t, 1.5 );
			$alpha = (int) round( 127 * ( 1 - $dark ) );
			imagefilledrectangle( $im, 0, $y, $w, $y, imagecolorallocatealpha( $im, 0, 0, 0, $alpha ) );
		}

		return true;
	}

	/**
	 * Draw one line aligned to an edge.
	 *
	 * @param resource|GdImage $im      Canvas.
	 * @param int              $size    Font size (px).
	 * @param string           $font    Font file.
	 * @param int              $color   Color.
	 * @param string           $text    Logical-order text.
	 * @param int              $x       Edge x.
	 * @param int              $y       Baseline y.
	 * @param bool             $from_right Whether $x is the right edge.
	 */
	protected static function text( $im, $size, $font, $color, $text, $x, $y, $from_right ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return;
		}

		$visual = self::visual_order( $text );
		$box    = imagettfbbox( $size, 0, $font, $visual );
		$width  = $box[2] - $box[0];
		$left   = $from_right ? $x - $width - $box[0] : $x - $box[0];

		imagettftext( $im, $size, 0, $left, $y, $color, $font, $visual );
	}

	/**
	 * Greedy word wrap.
	 *
	 * @param string $text  Text.
	 * @param string $font  Font file.
	 * @param int    $size  Font size.
	 * @param int    $width Max line width.
	 * @return string[] Lines, logical order.
	 */
	protected static function wrap( $text, $font, $size, $width ) {
		$words = preg_split( '/\s+/u', trim( $text ) );
		$lines = array();
		$line  = '';

		foreach ( $words as $word ) {
			$try = '' === $line ? $word : $line . ' ' . $word;
			if ( '' !== $line && self::width( $try, $font, $size ) > $width ) {
				$lines[] = $line;
				$line    = $word;
			} else {
				$line = $try;
			}
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Keep the first lines and end the last one with an ellipsis.
	 *
	 * @param string[] $lines     Lines.
	 * @param int      $max_lines Lines to keep.
	 * @param string   $font      Font file.
	 * @param int      $size      Font size.
	 * @param int      $width     Max width.
	 * @return string[]
	 */
	protected static function truncate( array $lines, $max_lines, $font, $size, $width ) {
		$lines                   = array_slice( $lines, 0, $max_lines );
		$lines[ $max_lines - 1 ] = self::cut_to_width( self::trim_punctuation( $lines[ $max_lines - 1 ] ) . '…', $font, $size, $width );
		return $lines;
	}

	/**
	 * @param string $text Text.
	 * @return string Text without trailing spaces and punctuation.
	 */
	protected static function trim_punctuation( $text ) {
		return preg_replace( '/[\s,.;:\-–—]+$/u', '', $text );
	}

	/**
	 * @param string $text     Text.
	 * @param string $font     Font file.
	 * @param int    $size     Font size.
	 * @param int    $width    Max width.
	 * @return string
	 */
	protected static function cut_to_width( $text, $font, $size, $width ) {
		if ( self::width( $text, $font, $size ) <= $width ) {
			return $text;
		}

		$words = preg_split( '/\s+/u', trim( preg_replace( '/\s*…$/u', '', $text ) ) );

		while ( count( $words ) > 1 ) {
			array_pop( $words );
			$try = self::trim_punctuation( implode( ' ', $words ) ) . '…';
			if ( self::width( $try, $font, $size ) <= $width ) {
				return $try;
			}
		}

		return $words[0];
	}

	/**
	 * @param string $text Text.
	 * @param string $font Font file.
	 * @param int    $size Font size.
	 * @return int
	 */
	protected static function width( $text, $font, $size ) {
		$box = imagettfbbox( $size, 0, $font, $text );
		return $box[2] - $box[0];
	}

	/**
	 * Put a right-to-left line into the left-to-right order GD draws in.
	 *
	 * The whole line is reversed (so Hebrew reads correctly), then every
	 * run of Latin letters and digits — "WordPress", "2026", "Let's" — is
	 * reversed back so it still reads left to right, and brackets are
	 * mirrored. Enough for titles; not a full Unicode bidi implementation.
	 *
	 * @param string $text Logical-order text.
	 * @return string Visual-order text.
	 */
	public static function visual_order( $text ) {
		if ( ! preg_match( '/\p{Hebrew}|\p{Arabic}/u', $text ) ) {
			return $text;
		}

		$mirror = array(
			'(' => ')',
			')' => '(',
			'[' => ']',
			']' => '[',
			'{' => '}',
			'}' => '{',
			'<' => '>',
			'>' => '<',
			'«' => '»',
			'»' => '«',
		);

		$chars = array_reverse( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
		foreach ( $chars as $i => $c ) {
			if ( isset( $mirror[ $c ] ) ) {
				$chars[ $i ] = $mirror[ $c ];
			}
		}

		return preg_replace_callback(
			"/[A-Za-z0-9](?:[A-Za-z0-9 .,:\\/%&'’+@#_-]*[A-Za-z0-9])?/u",
			function ( $m ) {
				return implode( '', array_reverse( preg_split( '//u', $m[0], -1, PREG_SPLIT_NO_EMPTY ) ) );
			},
			implode( '', $chars )
		);
	}

	/**
	 * @param string $hex      #rrggbb.
	 * @param int[]  $fallback RGB when the value is not a color.
	 * @return int[]
	 */
	protected static function rgb( $hex, array $fallback ) {
		$hex = ltrim( trim( (string) $hex ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return $fallback;
		}

		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}
}
