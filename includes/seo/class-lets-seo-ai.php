<?php
/**
 * Let's SEO — the layer for AI agents and answer engines.
 *
 * - /llms.txt       a map of the site in Markdown (llmstxt.org).
 * - /llms-full.txt  the full text of the latest articles in one file.
 * - {url}.md        a clean Markdown copy of any post or page, with front
 *                   matter, the AI summary and the FAQ. Advertised from each
 *                   page with <link rel="alternate" type="text/markdown">.
 * - robots.txt      explicit rules for AI search bots and AI training bots.
 *
 * The Markdown copies are served with X-Robots-Tag: noindex and a canonical
 * Link header, so they never compete with the real page in Google.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_SEO_AI {

	const CACHE_GROUP = 'lets_seo_ai';

	/** How many posts llms-full.txt carries in full. */
	const FULL_LIMIT = 50;

	/** AI search / answer bots: they fetch pages to answer a user and link back. */
	const SEARCH_BOTS = array( 'OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User', 'DuckAssistBot', 'MistralAI-User' );

	/** AI training crawlers: they collect text to train models. */
	const TRAINING_BOTS = array( 'GPTBot', 'ClaudeBot', 'anthropic-ai', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'meta-externalagent', 'Bytespider', 'Amazonbot', 'cohere-ai' );

	public static function init() {
		add_filter( 'redirect_canonical', array( __CLASS__, 'keep_our_urls' ), 1 );
		add_filter( 'do_redirect_guess_404_permalink', array( __CLASS__, 'keep_our_urls_guess' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'head_link' ), 3 );
		add_action( 'save_post', array( __CLASS__, 'flush_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_cache' ) );

		if ( Lets_SEO::output_enabled() ) {
			add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 99, 2 );
		}
	}

	/**
	 * What the current request asks for: 'llms', 'llms-full', 'md' or ''.
	 *
	 * @return string
	 */
	protected static function request_kind() {
		$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}

		if ( 'llms.txt' === $path ) {
			return 'llms';
		}
		if ( 'llms-full.txt' === $path ) {
			return 'llms-full';
		}
		if ( Lets_SEO::get( 'ai_markdown' ) && '.md' === substr( $path, -3 ) ) {
			return 'md';
		}

		return '';
	}

	/**
	 * WordPress would "helpfully" redirect /slug.md to /slug/. Not here.
	 *
	 * @param string|false $redirect Redirect URL.
	 * @return string|false
	 */
	public static function keep_our_urls( $redirect ) {
		return '' !== self::request_kind() ? false : $redirect;
	}

	/**
	 * @param bool $guess Whether to guess a URL for a 404.
	 * @return bool
	 */
	public static function keep_our_urls_guess( $guess ) {
		return '' !== self::request_kind() ? false : $guess;
	}

	/**
	 * Runs after Elementor has set up the front end, so its layouts render.
	 */
	public static function serve() {
		$kind = self::request_kind();

		if ( 'llms' === $kind ) {
			self::send( self::llms_txt(), 'text/plain', home_url( '/' ) );
		}
		if ( 'llms-full' === $kind ) {
			self::send( self::llms_full_txt(), 'text/plain', home_url( '/' ) );
		}
		if ( 'md' === $kind ) {
			$post = self::post_for_md_request();
			if ( $post ) {
				self::send( self::post_markdown( $post ), 'text/markdown', get_permalink( $post ) );
			}
		}
	}

	/**
	 * @param string $body         Body.
	 * @param string $type         MIME type.
	 * @param string $canonical    The HTML page this text stands for.
	 */
	protected static function send( $body, $type, $canonical ) {
		status_header( 200 );
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'Link: <' . esc_url_raw( $canonical ) . '>; rel="canonical"' );
		header( 'Cache-Control: public, max-age=3600' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- plain text / Markdown response.
		exit;
	}

	/**
	 * /some-post.md → the published post at /some-post/, if it may be shared.
	 *
	 * @return WP_Post|null
	 */
	protected static function post_for_md_request() {
		$path = trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = substr( $path, 0, -3 );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( '' !== $home && 0 === strpos( $base, $home ) ) {
			$base = trim( substr( $base, strlen( $home ) ), '/' );
		}

		if ( '' === $base || 'index' === $base ) {
			$id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		} else {
			$id = url_to_postid( home_url( user_trailingslashit( $base ) ) );
			if ( ! $id ) {
				$id = url_to_postid( home_url( $base ) );
			}
		}

		$post = $id ? get_post( $id ) : null;

		if ( ! $post || ! self::shareable( $post ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function shareable( $post ) {
		return in_array( $post->post_type, Lets_SEO::post_types(), true )
			&& ! Lets_SEO::post_is_hidden( $post )
			&& ! Lets_SEO::post_meta( $post->ID, 'exclude_ai' );
	}

	/**
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function md_url( $post ) {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;

		if ( $post->ID === $front ) {
			return home_url( '/index.md' );
		}

		return untrailingslashit( get_permalink( $post ) ) . '.md';
	}

	public static function head_link() {
		if ( ! Lets_SEO::get( 'ai_markdown' ) || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( $post instanceof WP_Post && self::shareable( $post ) ) {
			printf(
				'<link rel="alternate" type="text/markdown" title="%s" href="%s" />' . "\n",
				esc_attr( Lets_SEO::decode( get_the_title( $post ) ) . ' (Markdown)' ),
				esc_url( self::md_url( $post ) )
			);
		}
	}

	/**
	 * A post as Markdown with YAML front matter.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function post_markdown( $post ) {
		$key    = 'md_' . $post->ID . '_' . strtotime( $post->post_modified_gmt );
		$cached = get_transient( 'lets_seo_' . $key );

		if ( false !== $cached ) {
			return $cached;
		}

		$title   = Lets_SEO::decode( get_the_title( $post ) );
		$summary = trim( (string) Lets_SEO::post_meta( $post->ID, 'ai_summary' ) );
		$desc    = Lets_SEO_Context::post_description( $post );
		$body    = Lets_SEO_Markdown::convert( Lets_SEO::rendered_content( $post ) );

		$meta = array(
			'title'         => $title,
			'description'   => $desc,
			'url'           => get_permalink( $post ),
			'language'      => str_replace( '_', '-', get_locale() ),
			'site'          => Lets_SEO::site_name(),
			'date_published' => get_post_time( 'c', true, $post ),
			'date_modified' => get_post_modified_time( 'c', true, $post ),
		);

		if ( post_type_supports( $post->post_type, 'author' ) ) {
			$meta['author'] = get_the_author_meta( 'display_name', $post->post_author );
		}

		$term = Lets_SEO_Context::primary_term( $post );
		if ( $term ) {
			$meta['category'] = Lets_SEO::decode( $term->name );
		}

		$tags = get_the_tags( $post->ID );
		if ( is_array( $tags ) ) {
			$meta['tags'] = array_map(
				function ( $t ) {
					return Lets_SEO::decode( $t->name );
				},
				$tags
			);
		}

		$md = self::front_matter( $meta ) . "\n# " . $title . "\n\n";

		if ( '' !== $summary ) {
			$md .= '> **תקציר:** ' . str_replace( "\n", "\n> ", $summary ) . "\n\n";
		}

		// Elementor layouts usually repeat the title as their own H1.
		$body = preg_replace( '/^#\s+' . preg_quote( $title, '/' ) . '\s*\n+/u', '', ltrim( $body ) );
		$md  .= $body;

		$faq = Lets_SEO::post_meta( $post->ID, 'faq' );
		if ( is_array( $faq ) && ! empty( $faq ) ) {
			$md .= "\n## שאלות נפוצות\n\n";
			foreach ( $faq as $item ) {
				if ( ! empty( $item['q'] ) && ! empty( $item['a'] ) ) {
					$md .= '### ' . trim( $item['q'] ) . "\n\n" . trim( $item['a'] ) . "\n\n";
				}
			}
		}

		$md .= "\n---\n\nמקור: " . get_permalink( $post ) . "\n";

		set_transient( 'lets_seo_' . $key, $md, WEEK_IN_SECONDS );

		return $md;
	}

	/**
	 * @return string
	 */
	public static function llms_txt() {
		$cached = get_transient( 'lets_seo_llms' );
		if ( false !== $cached ) {
			return $cached;
		}

		$intro = trim( (string) Lets_SEO::get( 'llms_intro' ) );
		if ( '' === $intro ) {
			$intro = trim( (string) Lets_SEO::get( 'org_description' ) );
		}
		if ( '' === $intro ) {
			$intro = Lets_SEO::decode( get_bloginfo( 'description' ) );
		}

		$out  = '# ' . Lets_SEO::site_name() . "\n\n";
		$out .= '' !== $intro ? '> ' . str_replace( "\n", "\n> ", $intro ) . "\n\n" : '';
		$out .= 'כתובת האתר: ' . home_url( '/' ) . "\n";
		$out .= 'לכל עמוד יש גרסת Markdown: מוסיפים .md לכתובת (למשל ' . home_url( '/index.md' ) . ").\n";
		$out .= 'הטקסט המלא של המאמרים: ' . home_url( '/llms-full.txt' ) . "\n\n";

		$sections = array(
			'page' => 'עמודים',
			'post' => 'מאמרים',
		);

		if ( post_type_exists( 'product' ) ) {
			$sections['product'] = 'מוצרים';
		}

		foreach ( Lets_SEO::post_types() as $type ) {
			if ( ! isset( $sections[ $type ] ) ) {
				$object            = get_post_type_object( $type );
				$sections[ $type ] = $object ? $object->labels->name : $type;
			}
		}

		foreach ( $sections as $type => $label ) {
			$lines = array();

			foreach ( self::shareable_posts( $type, 'page' === $type ? 100 : 300 ) as $post ) {
				$summary = trim( (string) Lets_SEO::post_meta( $post->ID, 'ai_summary' ) );
				$summary = '' !== $summary ? $summary : Lets_SEO_Context::post_description( $post );
				$lines[] = '- [' . Lets_SEO::decode( get_the_title( $post ) ) . '](' . self::md_url( $post ) . ')' . ( '' !== $summary ? ': ' . Lets_SEO::cut( preg_replace( '/\s+/u', ' ', $summary ), 200 ) : '' );
			}

			if ( $lines ) {
				$out .= '## ' . $label . "\n\n" . implode( "\n", $lines ) . "\n\n";
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'number'     => 50,
			)
		);
		if ( is_array( $terms ) && count( $terms ) > 1 ) {
			$out .= "## קטגוריות\n\n";
			foreach ( $terms as $term ) {
				if ( ! Lets_SEO::term_meta( $term->term_id, 'noindex' ) ) {
					$out .= '- [' . Lets_SEO::decode( $term->name ) . '](' . get_term_link( $term ) . ")\n";
				}
			}
			$out .= "\n";
		}

		set_transient( 'lets_seo_llms', $out, DAY_IN_SECONDS );

		return $out;
	}

	/**
	 * @return string
	 */
	public static function llms_full_txt() {
		$cached = get_transient( 'lets_seo_llms_full' );
		if ( false !== $cached ) {
			return $cached;
		}

		$out = '# ' . Lets_SEO::site_name() . " — הטקסט המלא\n\n";

		foreach ( self::shareable_posts( 'post', self::FULL_LIMIT ) as $post ) {
			$out .= self::post_markdown( $post ) . "\n\n";
		}

		set_transient( 'lets_seo_llms_full', $out, DAY_IN_SECONDS );

		return $out;
	}

	/**
	 * @param string $type  Post type.
	 * @param int    $limit Max posts.
	 * @return WP_Post[]
	 */
	protected static function shareable_posts( $type, $limit ) {
		$posts = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'publish',
				'posts_per_page'   => $limit,
				'has_password'     => false,
				'orderby'          => 'page' === $type ? 'menu_order title' : 'date',
				'order'            => 'page' === $type ? 'ASC' : 'DESC',
				'suppress_filters' => false,
			)
		);

		return array_values( array_filter( $posts, array( __CLASS__, 'shareable' ) ) );
	}

	/**
	 * @param array<string,string|string[]> $meta Front matter values.
	 * @return string
	 */
	protected static function front_matter( array $meta ) {
		$out = "---\n";

		foreach ( $meta as $key => $value ) {
			if ( is_array( $value ) ) {
				$out .= $key . ":\n";
				foreach ( $value as $item ) {
					$out .= '  - ' . wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
				}
			} elseif ( '' !== (string) $value ) {
				// JSON strings are valid YAML scalars and take care of quoting.
				$out .= $key . ': ' . wp_json_encode( (string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
			}
		}

		return $out . "---\n";
	}

	public static function flush_cache() {
		delete_transient( 'lets_seo_llms' );
		delete_transient( 'lets_seo_llms_full' );
	}

	/**
	 * @param string $output Core output.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public static function robots_txt( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}

		$base = array(
			'Disallow: /wp-admin/',
			'Allow: /wp-admin/admin-ajax.php',
			'Disallow: /?s=',
			'Disallow: /search/',
			'Disallow: /*?replytocom=',
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$base[] = 'Disallow: /*add-to-cart=*';
			$base[] = 'Disallow: /cart/';
			$base[] = 'Disallow: /checkout/';
			$base[] = 'Disallow: /my-account/';
		}

		$lines   = array( 'User-agent: *' );
		$lines   = array_merge( $lines, $base );
		$lines[] = '';

		$groups = array(
			array( 'AI search and answer engines (they cite and link back)', self::SEARCH_BOTS, (bool) Lets_SEO::get( 'ai_search_bots' ) ),
			array( 'AI model training', self::TRAINING_BOTS, (bool) Lets_SEO::get( 'ai_training_bots' ) ),
		);

		foreach ( $groups as $group ) {
			list( $label, $bots, $allowed ) = $group;

			$lines[] = '# ' . $label;
			foreach ( $bots as $bot ) {
				$lines[] = 'User-agent: ' . $bot;
			}
			// A bot that matches its own group ignores the * group, so repeat the base rules.
			$lines   = array_merge( $lines, $allowed ? array_merge( array( 'Allow: /' ), $base ) : array( 'Disallow: /' ) );
			$lines[] = '';
		}

		$lines[] = '# AI-readable site map: ' . home_url( '/llms.txt' );
		$lines[] = 'Sitemap: ' . home_url( '/wp-sitemap.xml' );

		return implode( "\n", $lines ) . "\n";
	}
}
