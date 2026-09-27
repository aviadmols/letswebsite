<?php
/**
 * Let's SEO — the SEO box on every post, page and product; SEO fields on
 * categories and tags; and an SEO column in the post lists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_SEO_Meta {

	const NONCE = 'lets_seo_meta';

	/**
	 * Per-post fields and their types. Stored as _lets_seo_{name}.
	 *
	 * @return array<string,string>
	 */
	public static function fields() {
		return array(
			'title'          => 'text',
			'description'    => 'textarea',
			'focus_keyword'  => 'text',
			'canonical'      => 'url',
			'noindex'        => 'bool',
			'nofollow'       => 'bool',
			'og_title'       => 'text',
			'og_description' => 'textarea',
			'og_image'       => 'int',
			'share_style'    => 'choice',
			'schema_type'    => 'choice',
			'ai_summary'     => 'textarea',
			'exclude_ai'     => 'bool',
			'primary_term'   => 'int',
			'faq'            => 'faq',
		);
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	public static function choices() {
		return array(
			'share_style' => array(
				''      => 'ברירת המחדל של האתר',
				'photo' => 'תמונה ראשית + כותרת',
				'type'  => 'טיפוגרפיה בלבד',
				'off'   => 'בלי תמונה מעוצבת (התמונה הראשית כמו שהיא)',
			),
			'schema_type' => array(
				''               => 'אוטומטי',
				'BlogPosting'    => 'פוסט בבלוג (BlogPosting)',
				'Article'        => 'מאמר (Article)',
				'NewsArticle'    => 'כתבת חדשות (NewsArticle)',
				'TechArticle'    => 'מאמר טכני / מדריך (TechArticle)',
				'AboutPage'      => 'עמוד אודות (AboutPage)',
				'ContactPage'    => 'עמוד צור קשר (ContactPage)',
				'CollectionPage' => 'עמוד אוסף / קטגוריה (CollectionPage)',
			),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_action( 'admin_init', array( __CLASS__, 'term_hooks' ) );

		add_action( 'admin_init', array( __CLASS__, 'column_hooks' ) );
	}

	/**
	 * Expose the fields over REST (block editor, AI tools, integrations).
	 */
	public static function register_meta() {
		foreach ( Lets_SEO::post_types() as $type ) {
			foreach ( self::fields() as $name => $kind ) {
				$args = array(
					'object_subtype' => $type,
					'single'         => true,
					'show_in_rest'   => true,
					'auth_callback'  => function ( $allowed, $key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				);

				if ( 'bool' === $kind ) {
					$args['type'] = 'boolean';
				} elseif ( 'int' === $kind ) {
					$args['type'] = 'integer';
				} elseif ( 'faq' === $kind ) {
					$args['type']         = 'array';
					$args['show_in_rest'] = array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'q' => array( 'type' => 'string' ),
									'a' => array( 'type' => 'string' ),
								),
							),
						),
					);
				} else {
					$args['type'] = 'string';
				}

				register_meta( 'post', Lets_SEO::META_PREFIX . $name, $args );
			}
		}
	}

	public static function add_box() {
		foreach ( Lets_SEO::post_types() as $type ) {
			add_meta_box( 'lets-seo', 'SEO', array( __CLASS__, 'render_box' ), $type, 'normal', 'high' );
		}
	}

	/**
	 * @param string $hook Admin page.
	 */
	public static function assets( $hook ) {
		$screen  = get_current_screen();
		$on_post = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && in_array( $screen->post_type, Lets_SEO::post_types(), true );
		$on_term = 'term.php' === $hook;
		$on_page = '' !== Lets_SEO_Settings::$hook && Lets_SEO_Settings::$hook === $hook;

		if ( 'edit.php' === $hook ) {
			// Only the list column's status dots.
			wp_enqueue_style( 'lets-seo-admin', LETS_SEO_URL . '/assets/admin.css', array(), LETS_SEO_VERSION );
			return;
		}

		if ( ! $on_post && ! $on_term && ! $on_page ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'lets-seo-admin', LETS_SEO_URL . '/assets/admin.css', array(), LETS_SEO_VERSION );
		wp_enqueue_script( 'lets-seo-admin', LETS_SEO_URL . '/assets/admin.js', array( 'jquery' ), LETS_SEO_VERSION, true );
		wp_localize_script(
			'lets-seo-admin',
			'letsSeo',
			array(
				'site'    => Lets_SEO::site_name(),
				'sep'     => (string) Lets_SEO::get( 'separator' ),
				'tagline' => Lets_SEO::decode( get_bloginfo( 'description' ) ),
				'single'  => (string) Lets_SEO::get( 'title_single' ),
				'host'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			)
		);
	}

	/**
	 * @param WP_Post $post Post.
	 */
	public static function render_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$v = array();
		foreach ( array_keys( self::fields() ) as $name ) {
			$v[ $name ] = Lets_SEO::post_meta( $post->ID, $name );
		}

		$choices   = self::choices();
		$faq       = is_array( $v['faq'] ) ? $v['faq'] : array();
		$permalink = 'publish' === $post->post_status ? get_permalink( $post ) : home_url( '/' . $post->post_name );
		$share     = 'auto-draft' !== $post->post_status ? Lets_SEO_Share_Image::for_post( $post ) : null;
		$og_image  = Lets_SEO::image( (int) $v['og_image'] );
		$terms     = get_the_terms( $post, 'product' === $post->post_type ? 'product_cat' : 'category' );
		$competing = Lets_SEO::competing_plugin();
		?>
		<div class="lets-seo" dir="rtl" data-title="<?php echo esc_attr( Lets_SEO::decode( get_the_title( $post ) ) ); ?>">

			<?php if ( $competing ) : ?>
				<p class="lets-seo__notice">
					<?php echo esc_html( $competing ); ?> פעיל כרגע, ולכן השדות כאן נשמרים אבל עוד לא מודפסים באתר.
					אחרי הייבוא וכיבוי <?php echo esc_html( $competing ); ?> הם ייכנסו לתוקף.
				</p>
			<?php endif; ?>

			<nav class="lets-seo__tabs" role="tablist">
				<button type="button" class="is-active" data-tab="search">חיפוש</button>
				<button type="button" data-tab="share">שיתוף</button>
				<button type="button" data-tab="ai">AI</button>
				<button type="button" data-tab="schema">סכמה ו־FAQ</button>
				<button type="button" data-tab="advanced">מתקדם</button>
			</nav>

			<section class="lets-seo__panel is-active" data-panel="search">
				<div class="lets-seo__serp" aria-label="תצוגה מקדימה בגוגל">
					<div class="lets-seo__serp-site"><?php echo esc_html( Lets_SEO::site_name() ); ?></div>
					<div class="lets-seo__serp-url"><?php echo esc_html( rawurldecode( $permalink ) ); ?></div>
					<div class="lets-seo__serp-title" data-preview="title"></div>
					<div class="lets-seo__serp-desc" data-preview="description"></div>
				</div>

				<p class="lets-seo__field">
					<label for="lets_seo_title">כותרת SEO</label>
					<input type="text" id="lets_seo_title" name="lets_seo[title]" value="<?php echo esc_attr( $v['title'] ); ?>" data-count="60" data-min="30" placeholder="<?php echo esc_attr( Lets_SEO::get( 'title_single' ) ); ?>" />
					<span class="lets-seo__hint">ריק = לפי התבנית. אפשר להשתמש ב־{title} {site} {sep} {category}. עד 60 תווים.</span>
				</p>

				<p class="lets-seo__field">
					<label for="lets_seo_description">תיאור (meta description)</label>
					<textarea id="lets_seo_description" name="lets_seo[description]" rows="3" data-count="160" data-min="120" placeholder="<?php echo esc_attr( Lets_SEO_Context::post_description( $post ) ); ?>"><?php echo esc_textarea( $v['description'] ); ?></textarea>
					<span class="lets-seo__hint">120–160 תווים. ריק = התקציר, ואם אין — תחילת הטקסט.</span>
				</p>

				<p class="lets-seo__field">
					<label for="lets_seo_focus_keyword">ביטוי מפתח</label>
					<input type="text" id="lets_seo_focus_keyword" name="lets_seo[focus_keyword]" value="<?php echo esc_attr( $v['focus_keyword'] ); ?>" />
				</p>

				<?php self::render_analysis( $post ); ?>
			</section>

			<section class="lets-seo__panel" data-panel="share">
				<div class="lets-seo__share-preview">
					<?php
					$preview = $og_image ? $og_image : $share;
					if ( $preview ) :
						?>
						<img src="<?php echo esc_url( $preview['url'] ); ?>" alt="" />
					<?php else : ?>
						<div class="lets-seo__share-empty">תמונת השיתוף תיווצר אחרי השמירה.</div>
					<?php endif; ?>
					<div class="lets-seo__share-meta">
						<span><?php echo esc_html( strtoupper( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ); ?></span>
						<strong data-preview="og_title"></strong>
						<span data-preview="og_description"></span>
					</div>
				</div>

				<p class="lets-seo__field">
					<label for="lets_seo_share_style">עיצוב תמונת השיתוף</label>
					<select id="lets_seo_share_style" name="lets_seo[share_style]">
						<?php foreach ( $choices['share_style'] as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v['share_style'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="lets-seo__hint">נוצרת אוטומטית (1200×630) מהכותרת, הקטגוריה והתמונה הראשית, ומתעדכנת בכל שמירה.</span>
				</p>

				<div class="lets-seo__field">
					<label>תמונה ידנית (גוברת על התמונה המעוצבת)</label>
					<div class="lets-seo__media" data-media>
						<input type="hidden" name="lets_seo[og_image]" value="<?php echo esc_attr( (int) $v['og_image'] ); ?>" />
						<img src="<?php echo $og_image ? esc_url( $og_image['url'] ) : ''; ?>" alt="" <?php echo $og_image ? '' : 'hidden'; ?> />
						<button type="button" class="button" data-media-pick>בחר תמונה</button>
						<button type="button" class="button-link" data-media-clear <?php echo $og_image ? '' : 'hidden'; ?>>הסר</button>
					</div>
				</div>

				<p class="lets-seo__field">
					<label for="lets_seo_og_title">כותרת לשיתוף</label>
					<input type="text" id="lets_seo_og_title" name="lets_seo[og_title]" value="<?php echo esc_attr( $v['og_title'] ); ?>" placeholder="כותרת הפוסט" />
				</p>

				<p class="lets-seo__field">
					<label for="lets_seo_og_description">תיאור לשיתוף</label>
					<textarea id="lets_seo_og_description" name="lets_seo[og_description]" rows="2" placeholder="התיאור מלשונית החיפוש"><?php echo esc_textarea( $v['og_description'] ); ?></textarea>
				</p>
			</section>

			<section class="lets-seo__panel" data-panel="ai">
				<p class="lets-seo__field">
					<label for="lets_seo_ai_summary">תקציר ל־AI (TL;DR)</label>
					<textarea id="lets_seo_ai_summary" name="lets_seo[ai_summary]" rows="4" data-count="400" data-min="80"><?php echo esc_textarea( $v['ai_summary'] ); ?></textarea>
					<span class="lets-seo__hint">
						2–4 משפטים שעונים ישר על השאלה שהמאמר עונה עליה. מופיע בראש גרסת ה־Markdown, ב־llms.txt ובסכמה (abstract),
						וזה הטקסט שמנועי תשובות כמו ChatGPT, Perplexity ו־Google AI Overviews הכי קל להם לצטט.
					</span>
				</p>

				<p class="lets-seo__field lets-seo__check">
					<label><input type="checkbox" name="lets_seo[exclude_ai]" value="1" <?php checked( (bool) $v['exclude_ai'] ); ?> /> להוציא את העמוד הזה מ־llms.txt ומגרסת ה־Markdown</label>
				</p>

				<?php if ( 'publish' === $post->post_status && Lets_SEO::get( 'ai_markdown' ) && Lets_SEO_AI::shareable( $post ) ) : ?>
					<p class="lets-seo__hint">
						גרסת Markdown: <a href="<?php echo esc_url( Lets_SEO_AI::md_url( $post ) ); ?>" target="_blank" rel="noopener" dir="ltr"><?php echo esc_html( rawurldecode( Lets_SEO_AI::md_url( $post ) ) ); ?></a>
					</p>
				<?php endif; ?>
			</section>

			<section class="lets-seo__panel" data-panel="schema">
				<p class="lets-seo__field">
					<label for="lets_seo_schema_type">סוג התוכן (Schema.org)</label>
					<select id="lets_seo_schema_type" name="lets_seo[schema_type]">
						<?php foreach ( $choices['schema_type'] as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v['schema_type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<div class="lets-seo__field">
					<label>שאלות נפוצות (FAQ)</label>
					<span class="lets-seo__hint">נכנסות לסכמת FAQPage ולגרסת ה־Markdown. כדאי שיופיעו גם בגוף העמוד עצמו.</span>
					<div class="lets-seo__faq" data-faq>
						<?php foreach ( $faq as $i => $item ) : ?>
							<?php self::render_faq_row( $i, $item ); ?>
						<?php endforeach; ?>
					</div>
					<template data-faq-template><?php self::render_faq_row( '__i__', array() ); ?></template>
					<button type="button" class="button" data-faq-add>+ הוסף שאלה</button>
				</div>
			</section>

			<section class="lets-seo__panel" data-panel="advanced">
				<p class="lets-seo__field lets-seo__check">
					<label><input type="checkbox" name="lets_seo[noindex]" value="1" <?php checked( (bool) $v['noindex'] ); ?> /> noindex — לא להציג בגוגל (ויוצא גם מה־sitemap ומ־llms.txt)</label>
				</p>
				<p class="lets-seo__field lets-seo__check">
					<label><input type="checkbox" name="lets_seo[nofollow]" value="1" <?php checked( (bool) $v['nofollow'] ); ?> /> nofollow — לא לעקוב אחרי הקישורים בעמוד</label>
				</p>

				<p class="lets-seo__field">
					<label for="lets_seo_canonical">כתובת קנונית</label>
					<input type="url" id="lets_seo_canonical" name="lets_seo[canonical]" value="<?php echo esc_attr( $v['canonical'] ); ?>" placeholder="<?php echo esc_attr( rawurldecode( $permalink ) ); ?>" dir="ltr" />
					<span class="lets-seo__hint">רק אם התוכן הזה הוא העתק של עמוד אחר.</span>
				</p>

				<?php if ( is_array( $terms ) && count( $terms ) > 1 ) : ?>
					<p class="lets-seo__field">
						<label for="lets_seo_primary_term">קטגוריה ראשית</label>
						<select id="lets_seo_primary_term" name="lets_seo[primary_term]">
							<option value="0">הראשונה ברשימה</option>
							<?php foreach ( $terms as $term ) : ?>
								<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( (int) $v['primary_term'], $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="lets-seo__hint">קובעת את פירורי הלחם, את {category} ואת הכיתוב על תמונת השיתוף.</span>
					</p>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * @param int|string           $i    Row index.
	 * @param array<string,string> $item Question and answer.
	 */
	protected static function render_faq_row( $i, array $item ) {
		?>
		<div class="lets-seo__faq-row">
			<input type="text" name="lets_seo[faq][<?php echo esc_attr( $i ); ?>][q]" value="<?php echo esc_attr( isset( $item['q'] ) ? $item['q'] : '' ); ?>" placeholder="שאלה" />
			<textarea name="lets_seo[faq][<?php echo esc_attr( $i ); ?>][a]" rows="2" placeholder="תשובה"><?php echo esc_textarea( isset( $item['a'] ) ? $item['a'] : '' ); ?></textarea>
			<button type="button" class="button-link lets-seo__faq-remove" data-faq-remove aria-label="הסר שאלה">×</button>
		</div>
		<?php
	}

	/**
	 * The checklist under the search tab. Based on the last saved version.
	 *
	 * @param WP_Post $post Post.
	 */
	protected static function render_analysis( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$checks = self::analyze( $post );

		if ( ! $checks ) {
			return;
		}

		$good = count( array_filter( wp_list_pluck( $checks, 'ok' ) ) );
		?>
		<div class="lets-seo__analysis">
			<h4>בדיקת SEO <span><?php echo esc_html( $good . '/' . count( $checks ) ); ?></span></h4>
			<ul>
				<?php foreach ( $checks as $check ) : ?>
					<li class="<?php echo $check['ok'] ? 'is-good' : 'is-bad'; ?>"><?php echo esc_html( $check['text'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="lets-seo__hint">לפי הגרסה השמורה האחרונה — שמור כדי לרענן.</p>
		</div>
		<?php
	}

	/**
	 * @param WP_Post $post Post.
	 * @return array<int,array{ok:bool,text:string}>
	 */
	public static function analyze( $post ) {
		$html    = Lets_SEO::rendered_content( $post );
		$text    = Lets_SEO::plain_text( $html );
		$words   = Lets_SEO::word_count( $text );
		$title   = trim( (string) Lets_SEO::post_meta( $post->ID, 'title' ) );
		$title   = '' !== $title ? $title : Lets_SEO::get( 'title_single' );
		$title   = Lets_SEO::replace_vars( $title, array( 'title' => Lets_SEO::decode( get_the_title( $post ) ) ) );
		$desc    = Lets_SEO_Context::post_description( $post );
		$has_own = '' !== trim( (string) Lets_SEO::post_meta( $post->ID, 'description' ) );
		$kw      = trim( (string) Lets_SEO::post_meta( $post->ID, 'focus_keyword' ) );
		$checks  = array();

		$len      = mb_strlen( $title );
		$checks[] = array(
			'ok'   => $len >= 30 && $len <= 60,
			'text' => sprintf( 'אורך הכותרת: %d תווים (מומלץ 30–60)', $len ),
		);

		$len      = mb_strlen( $desc );
		$checks[] = array(
			'ok'   => $has_own && $len >= 120 && $len <= 160,
			'text' => $has_own ? sprintf( 'אורך התיאור: %d תווים (מומלץ 120–160)', $len ) : 'אין תיאור ייעודי — גוגל ישלוף טקסט בעצמו',
		);

		$min      = 'post' === $post->post_type ? 600 : 300;
		$checks[] = array(
			'ok'   => $words >= $min,
			'text' => sprintf( 'אורך התוכן: %d מילים (מומלץ לפחות %d)', $words, $min ),
		);

		if ( '' === $kw ) {
			$checks[] = array(
				'ok'   => false,
				'text' => 'לא הוגדר ביטוי מפתח',
			);
		} else {
			$in = function ( $haystack ) use ( $kw ) {
				return false !== mb_stripos( (string) $haystack, $kw );
			};

			$first    = mb_substr( $text, 0, max( 300, (int) ( mb_strlen( $text ) * 0.1 ) ) );
			$count    = mb_substr_count( mb_strtolower( $text ), mb_strtolower( $kw ) );
			$density  = $words ? ( $count * Lets_SEO::word_count( $kw ) / $words ) * 100 : 0;
			$headings = preg_match_all( '#<h[23][^>]*>(.*?)</h[23]>#is', $html, $m ) ? implode( ' ', array_map( 'wp_strip_all_tags', $m[1] ) ) : '';

			$checks[] = array(
				'ok'   => $in( $title ),
				'text' => 'ביטוי המפתח מופיע בכותרת',
			);
			$checks[] = array(
				'ok'   => $in( $desc ),
				'text' => 'ביטוי המפתח מופיע בתיאור',
			);
			$checks[] = array(
				'ok'   => $in( $first ),
				'text' => 'ביטוי המפתח מופיע בפתיחה',
			);
			$checks[] = array(
				'ok'   => $in( $headings ),
				'text' => 'ביטוי המפתח מופיע בכותרת משנה (H2/H3)',
			);
			$checks[] = array(
				'ok'   => $in( rawurldecode( $post->post_name ) ) || $in( str_replace( '-', ' ', rawurldecode( $post->post_name ) ) ),
				'text' => 'ביטוי המפתח מופיע בכתובת (slug)',
			);
			$checks[] = array(
				'ok'   => $density >= 0.5 && $density <= 3,
				'text' => sprintf( 'צפיפות ביטוי המפתח: %s%% (%s, מומלץ 0.5%%–3%%)', number_format( $density, 1 ), 1 === $count ? 'פעם אחת' : $count . ' פעמים' ),
			);

			$others = get_posts(
				array(
					'post_type'      => Lets_SEO::post_types(),
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'post__not_in'   => array( $post->ID ),
					'meta_key'       => Lets_SEO::META_PREFIX . 'focus_keyword', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $kw, // phpcs:ignore WordPress.DB.SlowDBQuery
					'fields'         => 'ids',
				)
			);
			$checks[] = array(
				'ok'   => empty( $others ),
				'text' => empty( $others ) ? 'ביטוי המפתח לא משמש עמוד אחר' : sprintf( 'ביטוי המפתח כבר משמש את "%s" — שני עמודים יתחרו זה בזה', get_the_title( $others[0] ) ),
			);
		}

		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$internal = 0;
		$external = 0;
		if ( preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)#i', $html, $links ) ) {
			foreach ( $links[1] as $href ) {
				$link_host = wp_parse_url( $href, PHP_URL_HOST );
				if ( ! $link_host || $link_host === $host ) {
					if ( 0 !== strpos( $href, '#' ) ) {
						++$internal;
					}
				} else {
					++$external;
				}
			}
		}

		$checks[] = array(
			'ok'   => $internal > 0,
			'text' => sprintf( 'קישורים פנימיים: %d', $internal ),
		);
		$checks[] = array(
			'ok'   => $external > 0,
			'text' => sprintf( 'קישורים למקורות חיצוניים: %d', $external ),
		);

		$images  = preg_match_all( '#<img\s[^>]*>#i', $html, $imgs ) ? $imgs[0] : array();
		$missing = count(
			array_filter(
				$images,
				function ( $img ) {
					return ! preg_match( '#\salt=["\'][^"\']+#i', $img );
				}
			)
		);

		$checks[] = array(
			'ok'   => has_post_thumbnail( $post ),
			'text' => has_post_thumbnail( $post ) ? 'יש תמונה ראשית' : 'אין תמונה ראשית',
		);
		if ( $images ) {
			$checks[] = array(
				'ok'   => 0 === $missing,
				'text' => 0 === $missing ? 'לכל התמונות יש טקסט חלופי (alt)' : sprintf( '%d תמונות בלי טקסט חלופי (alt)', $missing ),
			);
		}

		$h1 = preg_match_all( '#<h1[\s>]#i', $html );
		if ( $h1 > 1 ) {
			$checks[] = array(
				'ok'   => false,
				'text' => sprintf( 'יש %d כותרות H1 בגוף העמוד — צריכה להיות אחת', $h1 ),
			);
		}

		$checks[] = array(
			'ok'   => '' !== trim( (string) Lets_SEO::post_meta( $post->ID, 'ai_summary' ) ),
			'text' => '' !== trim( (string) Lets_SEO::post_meta( $post->ID, 'ai_summary' ) ) ? 'יש תקציר ל־AI' : 'אין תקציר ל־AI (לשונית AI)',
		);

		return $checks;
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input = isset( $_POST['lets_seo'] ) && is_array( $_POST['lets_seo'] ) ? wp_unslash( $_POST['lets_seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.

		foreach ( self::fields() as $name => $kind ) {
			$value = self::sanitize( $name, $kind, isset( $input[ $name ] ) ? $input[ $name ] : null );
			$key   = Lets_SEO::META_PREFIX . $name;

			// Empty means "use the default", so store nothing rather than an empty value.
			if ( '' === $value || 0 === $value || false === $value || array() === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	/**
	 * @param string $name  Field.
	 * @param string $kind  Field type.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	protected static function sanitize( $name, $kind, $value ) {
		switch ( $kind ) {
			case 'bool':
				return ! empty( $value );

			case 'int':
				return absint( $value );

			case 'url':
				return esc_url_raw( trim( (string) $value ) );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'choice':
				$choices = self::choices();
				return isset( $choices[ $name ][ (string) $value ] ) ? (string) $value : '';

			case 'faq':
				$out = array();
				foreach ( is_array( $value ) ? $value : array() as $item ) {
					$q = isset( $item['q'] ) ? sanitize_text_field( $item['q'] ) : '';
					$a = isset( $item['a'] ) ? sanitize_textarea_field( $item['a'] ) : '';
					if ( '' !== $q && '' !== $a ) {
						$out[] = array(
							'q' => $q,
							'a' => $a,
						);
					}
				}
				return $out;

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/* ---------------------------------------------------------------------
	 * Terms
	 * ------------------------------------------------------------------ */

	public static function term_hooks() {
		foreach ( Lets_SEO::taxonomies() as $taxonomy ) {
			add_action( $taxonomy . '_edit_form', array( __CLASS__, 'render_term_fields' ), 20 );
			add_action( 'edited_' . $taxonomy, array( __CLASS__, 'save_term' ) );
		}
	}

	/**
	 * @param WP_Term $term Term.
	 */
	public static function render_term_fields( $term ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<div class="lets-seo lets-seo--term" dir="rtl">
			<h2>SEO</h2>
			<p class="lets-seo__field">
				<label for="lets_seo_term_title">כותרת SEO</label>
				<input type="text" id="lets_seo_term_title" name="lets_seo[title]" value="<?php echo esc_attr( Lets_SEO::term_meta( $term->term_id, 'title' ) ); ?>" data-count="60" data-min="30" placeholder="<?php echo esc_attr( Lets_SEO::get( 'title_archive' ) ); ?>" />
			</p>
			<p class="lets-seo__field">
				<label for="lets_seo_term_description">תיאור (meta description)</label>
				<textarea id="lets_seo_term_description" name="lets_seo[description]" rows="3" data-count="160" data-min="120"><?php echo esc_textarea( Lets_SEO::term_meta( $term->term_id, 'description' ) ); ?></textarea>
			</p>
			<p class="lets-seo__field lets-seo__check">
				<label><input type="checkbox" name="lets_seo[noindex]" value="1" <?php checked( (bool) Lets_SEO::term_meta( $term->term_id, 'noindex' ) ); ?> /> noindex — לא להציג את הארכיון הזה בגוגל</label>
			</p>
		</div>
		<?php
	}

	/**
	 * @param int $term_id Term ID.
	 */
	public static function save_term( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$input  = isset( $_POST['lets_seo'] ) && is_array( $_POST['lets_seo'] ) ? wp_unslash( $_POST['lets_seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$values = array(
			'title'       => sanitize_text_field( isset( $input['title'] ) ? $input['title'] : '' ),
			'description' => sanitize_textarea_field( isset( $input['description'] ) ? $input['description'] : '' ),
			'noindex'     => ! empty( $input['noindex'] ),
		);

		foreach ( $values as $name => $value ) {
			if ( '' === $value || false === $value ) {
				delete_term_meta( $term_id, Lets_SEO::META_PREFIX . $name );
			} else {
				update_term_meta( $term_id, Lets_SEO::META_PREFIX . $name, $value );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * List column
	 * ------------------------------------------------------------------ */

	public static function column_hooks() {
		foreach ( Lets_SEO::post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		}
	}

	/**
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public static function add_column( $columns ) {
		$columns['lets_seo'] = 'SEO';
		return $columns;
	}

	/**
	 * A quick read of the fields that matter most, without rendering the post.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'lets_seo' !== $column ) {
			return;
		}

		if ( Lets_SEO::post_meta( $post_id, 'noindex' ) ) {
			echo '<span class="lets-seo-dot is-muted" title="noindex"></span> noindex';
			return;
		}

		$missing = array();
		$desc    = mb_strlen( trim( (string) Lets_SEO::post_meta( $post_id, 'description' ) ) );

		if ( $desc < 120 || $desc > 160 ) {
			$missing[] = 'תיאור';
		}
		if ( '' === trim( (string) Lets_SEO::post_meta( $post_id, 'focus_keyword' ) ) ) {
			$missing[] = 'ביטוי מפתח';
		}
		if ( '' === trim( (string) Lets_SEO::post_meta( $post_id, 'ai_summary' ) ) ) {
			$missing[] = 'תקציר AI';
		}

		$state = empty( $missing ) ? 'is-good' : ( count( $missing ) > 1 ? 'is-bad' : 'is-ok' );

		printf(
			'<span class="lets-seo-dot %s"></span> %s',
			esc_attr( $state ),
			esc_html( empty( $missing ) ? 'מוכן' : 'חסר: ' . implode( ', ', $missing ) )
		);
	}
}
