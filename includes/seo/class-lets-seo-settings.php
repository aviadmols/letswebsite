<?php
/**
 * Let's SEO — settings page (Settings → SEO).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_SEO_Settings {

	const PAGE = 'lets-seo';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . Lets_SEO::OPTION, array( 'Lets_SEO_AI', 'flush_cache' ) );
		add_action( 'admin_notices', array( __CLASS__, 'competing_notice' ) );
	}

	public static function menu() {
		add_options_page( 'SEO', 'SEO', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting(
			'lets_seo',
			Lets_SEO::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Lets_SEO::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input Submitted values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = Lets_SEO::defaults();
		$out      = array();

		foreach ( $defaults as $key => $default ) {
			$value = isset( $input[ $key ] ) ? $input[ $key ] : null;

			if ( is_int( $default ) ) {
				// Checkboxes and attachment IDs.
				$out[ $key ] = in_array( $key, array( 'org_logo', 'default_image' ), true ) ? absint( $value ) : ( empty( $value ) ? 0 : 1 );
			} elseif ( in_array( $key, array( 'home_description', 'org_description', 'org_same_as', 'llms_intro' ), true ) ) {
				$out[ $key ] = sanitize_textarea_field( (string) $value );
			} elseif ( in_array( $key, array( 'share_bg', 'share_fg' ), true ) ) {
				$color       = sanitize_hex_color( (string) $value );
				$out[ $key ] = $color ? $color : $default;
			} else {
				$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		$out['org_type']    = 'Person' === $out['org_type'] ? 'Person' : 'Organization';
		$out['share_style'] = in_array( $out['share_style'], array( 'photo', 'type', 'off' ), true ) ? $out['share_style'] : 'photo';

		foreach ( array( 'title_single', 'title_archive' ) as $key ) {
			if ( '' === $out[ $key ] ) {
				$out[ $key ] = $defaults[ $key ];
			}
		}

		// Verification fields accept the whole <meta> tag Google hands out; keep just the code.
		foreach ( array( 'verify_google', 'verify_bing', 'verify_facebook' ) as $key ) {
			if ( preg_match( '/content=["\']([^"\']+)/', wp_unslash( (string) ( isset( $input[ $key ] ) ? $input[ $key ] : '' ) ), $m ) ) {
				$out[ $key ] = sanitize_text_field( $m[1] );
			}
		}

		return $out;
	}

	public static function competing_notice() {
		$competing = Lets_SEO::competing_plugin();
		$screen    = get_current_screen();

		if ( ! $competing || ! current_user_can( 'manage_options' ) || ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'settings_page_' . self::PAGE ), true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>Let\'s SEO</strong> ממתין: %1$s פעיל, ולכן הכותרות, התגיות, הסכמה וה־sitemap של %1$s הם שמודפסים עכשיו. <a href="%2$s">ייבוא מ־%1$s והוראות מעבר</a>.</p></div>',
			esc_html( $competing ),
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}

		$s         = Lets_SEO::settings();
		$name      = Lets_SEO::OPTION;
		$competing = Lets_SEO::competing_plugin();
		$sample    = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);
		?>
		<div class="wrap lets-seo-settings" dir="rtl">
			<h1>SEO</h1>

			<?php if ( isset( $_GET['lets_seo_imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['lets_seo_imported'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification ?></p></div>
			<?php endif; ?>

			<div class="lets-seo-card lets-seo-card--status">
				<h2>מצב</h2>
				<?php if ( $competing ) : ?>
					<p><strong><?php echo esc_html( $competing ); ?> פעיל.</strong> כל עוד הוא פעיל, המודול הזה לא מדפיס כותרות, תגיות, סכמה, sitemap ו־robots.txt — כדי שלא יהיו כפילויות. שכבת ה־AI ותמונות השיתוף כבר עובדות.</p>
					<ol>
						<li>לוחצים "ייבוא" למטה — מעתיק את הכותרות, התיאורים, ה־noindex, התמונות והגדרות האתר. שדות שכבר מולאו כאן לא נדרסים.</li>
						<li>מכבים את <?php echo esc_html( $competing ); ?> בעמוד התוספים.</li>
						<li>נכנסים לעמוד כלשהו באתר ובודקים (View Source) שיש בלוק <code>Let's SEO</code> אחד ואין כפילויות.</li>
						<li>ב־Search Console שולחים את <code><?php echo esc_html( home_url( '/wp-sitemap.xml' ) ); ?></code> (הכתובת הישנה מפנה אליה אוטומטית).</li>
					</ol>
				<?php else : ?>
					<p><strong>פעיל.</strong> כותרות, תגיות, סכמה, sitemap, robots.txt, שכבת AI ותמונות שיתוף.</p>
				<?php endif; ?>

				<p class="lets-seo-links" dir="ltr">
					<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener">llms.txt</a>
					<a href="<?php echo esc_url( home_url( '/llms-full.txt' ) ); ?>" target="_blank" rel="noopener">llms-full.txt</a>
					<a href="<?php echo esc_url( home_url( '/wp-sitemap.xml' ) ); ?>" target="_blank" rel="noopener">sitemap</a>
					<a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener">robots.txt</a>
					<?php if ( $sample ) : ?>
						<a href="<?php echo esc_url( Lets_SEO_AI::md_url( $sample[0] ) ); ?>" target="_blank" rel="noopener">Markdown example</a>
						<a href="<?php echo esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( get_permalink( $sample[0] ) ) ); ?>" target="_blank" rel="noopener">Rich Results Test</a>
					<?php endif; ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( Lets_SEO_Import::ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( Lets_SEO_Import::ACTION ); ?>" />
					<?php submit_button( 'ייבוא מ־Yoast SEO', 'secondary', 'submit', false, Lets_SEO_Import::has_yoast_data() ? array() : array( 'disabled' => 'disabled' ) ); ?>
					<?php if ( ! Lets_SEO_Import::has_yoast_data() ) : ?>
						<span class="description">לא נמצאו נתוני Yoast.</span>
					<?php endif; ?>
				</form>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'lets_seo' ); ?>

				<div class="lets-seo-card">
					<h2>כותרות</h2>
					<table class="form-table" role="presentation">
						<?php
						self::text_row( $name, $s, 'site_name', 'שם האתר', Lets_SEO::decode( get_bloginfo( 'name' ) ) );
						self::text_row( $name, $s, 'separator', 'מפריד', '|', 'small-text' );
						self::text_row( $name, $s, 'title_single', 'תבנית כותרת לפוסט / עמוד', '{title} {sep} {site}' );
						self::text_row( $name, $s, 'title_archive', 'תבנית כותרת לארכיון', '{title} {sep} {site}' );
						self::text_row( $name, $s, 'home_title', 'כותרת דף הבית', '{site} {sep} {tagline}' );
						self::textarea_row( $name, $s, 'home_description', 'תיאור דף הבית', 'ריק = התיאור שהוגדר בעמוד הבית עצמו, ואם אין — תיאור האתר.' );
						?>
					</table>
					<p class="description">משתנים: <code>{title}</code> <code>{site}</code> <code>{sep}</code> <code>{tagline}</code> <code>{category}</code> <code>{page}</code></p>
				</div>

				<div class="lets-seo-card">
					<h2>העסק</h2>
					<p class="description">נכנס לסכמה (Organization) — ככה גוגל ומנועי AI מזהים מי עומד מאחורי האתר ומחברים אותו לפרופילים שלו.</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">סוג</th>
							<td>
								<select name="<?php echo esc_attr( $name ); ?>[org_type]">
									<option value="Organization" <?php selected( $s['org_type'], 'Organization' ); ?>>עסק / ארגון</option>
									<option value="Person" <?php selected( $s['org_type'], 'Person' ); ?>>אדם</option>
								</select>
							</td>
						</tr>
						<?php
						self::text_row( $name, $s, 'org_name', 'שם', Lets_SEO::site_name() );
						self::media_row( $name, $s, 'org_logo', 'לוגו', 'ריבועי או רחב, לפחות 112×112.' );
						self::textarea_row( $name, $s, 'org_description', 'תיאור קצר', 'מה העסק עושה, במשפט או שניים.' );
						self::text_row( $name, $s, 'org_email', 'אימייל', '', 'regular-text', 'ltr' );
						self::text_row( $name, $s, 'org_phone', 'טלפון', '+972…', 'regular-text', 'ltr' );
						self::textarea_row( $name, $s, 'org_same_as', 'פרופילים ברשת', 'כתובת אחת בכל שורה: פייסבוק, אינסטגרם, לינקדאין, יוטיוב, X, GitHub…', 'ltr' );
						?>
					</table>
				</div>

				<div class="lets-seo-card">
					<h2>שיתוף ותמונות</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">תמונות שיתוף מעוצבות</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[share_enabled]" value="1" <?php checked( (bool) $s['share_enabled'] ); ?> /> ליצור תמונה 1200×630 לכל פוסט עם הכותרת</label>
							<?php if ( ! function_exists( 'imagettftext' ) ) : ?>
								<p class="description" style="color:#b32d2e">בשרת הזה אין GD עם FreeType, ולכן התמונות לא ייווצרו — תוצג התמונה הראשית.</p>
							<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">סגנון ברירת מחדל</th>
							<td>
								<select name="<?php echo esc_attr( $name ); ?>[share_style]">
									<option value="photo" <?php selected( $s['share_style'], 'photo' ); ?>>תמונה ראשית + כותרת</option>
									<option value="type" <?php selected( $s['share_style'], 'type' ); ?>>טיפוגרפיה בלבד</option>
									<option value="off" <?php selected( $s['share_style'], 'off' ); ?>>בלי עיצוב (התמונה הראשית כמו שהיא)</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">צבעים (סגנון טיפוגרפי)</th>
							<td>
								<label>רקע <input type="color" name="<?php echo esc_attr( $name ); ?>[share_bg]" value="<?php echo esc_attr( $s['share_bg'] ); ?>" /></label>
								&nbsp;
								<label>טקסט <input type="color" name="<?php echo esc_attr( $name ); ?>[share_fg]" value="<?php echo esc_attr( $s['share_fg'] ); ?>" /></label>
							</td>
						</tr>
						<?php
						self::media_row( $name, $s, 'default_image', 'תמונת שיתוף לדף הבית ולארכיונים', 'ריק = תמונה מעוצבת עם שם האתר.' );
						self::text_row( $name, $s, 'twitter_site', 'חשבון X (Twitter)', '@lets', 'regular-text', 'ltr' );
						?>
					</table>
				</div>

				<div class="lets-seo-card">
					<h2>AI — מנועי תשובות וסוכנים</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">בוטים של חיפוש AI</th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ai_search_bots]" value="1" <?php checked( (bool) $s['ai_search_bots'] ); ?> /> לאפשר</label>
								<p class="description">ChatGPT Search, Claude, Perplexity ודומיהם — קוראים עמודים כדי לענות למשתמש ומפנים חזרה לאתר. מומלץ להשאיר פתוח.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">בוטים של אימון מודלים</th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ai_training_bots]" value="1" <?php checked( (bool) $s['ai_training_bots'] ); ?> /> לאפשר</label>
								<p class="description">GPTBot, ClaudeBot, Google-Extended, CCBot ועוד — אוספים טקסט לאימון. פתוח = המותג שלך "נלמד" ומוזכר בתשובות; סגור = התוכן לא ישמש לאימון.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">גרסת Markdown לכל עמוד</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ai_markdown]" value="1" <?php checked( (bool) $s['ai_markdown'] ); ?> /> להגיש <code dir="ltr">/slug.md</code> ולפרסם אותו ב־llms.txt</label></td>
						</tr>
						<?php self::textarea_row( $name, $s, 'llms_intro', 'פתיח ל־llms.txt', 'מה האתר, למי הוא מיועד ומה אפשר למצוא בו. זה הדבר הראשון שסוכן AI קורא.' ); ?>
					</table>
				</div>

				<div class="lets-seo-card">
					<h2>אינדוקס</h2>
					<table class="form-table" role="presentation">
						<?php
						self::check_row( $name, $s, 'noindex_author', 'ארכיוני כותבים', 'noindex (מומלץ באתר עם כותב אחד)' );
						self::check_row( $name, $s, 'noindex_date', 'ארכיוני תאריכים', 'noindex' );
						self::check_row( $name, $s, 'noindex_tag', 'ארכיוני תגיות', 'noindex' );
						self::check_row( $name, $s, 'redirect_attachments', 'עמודי קבצים מצורפים', 'להפנות לפוסט שהקובץ שייך אליו' );
						?>
					</table>
				</div>

				<div class="lets-seo-card">
					<h2>אימות בעלות</h2>
					<p class="description">אפשר להדביק את כל תגית ה־meta — הקוד יחולץ ממנה.</p>
					<table class="form-table" role="presentation">
						<?php
						self::text_row( $name, $s, 'verify_google', 'Google Search Console', '', 'regular-text', 'ltr' );
						self::text_row( $name, $s, 'verify_bing', 'Bing Webmaster Tools', '', 'regular-text', 'ltr' );
						self::text_row( $name, $s, 'verify_facebook', 'Facebook (domain verification)', '', 'regular-text', 'ltr' );
						?>
					</table>
				</div>

				<?php submit_button( 'שמירה' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param string              $name        Option name.
	 * @param array<string,mixed> $s           Settings.
	 * @param string              $key         Setting.
	 * @param string              $label       Label.
	 * @param string              $placeholder Placeholder.
	 * @param string              $class       Input class.
	 * @param string              $dir         Text direction.
	 */
	protected static function text_row( $name, array $s, $key, $label, $placeholder = '', $class = 'regular-text', $dir = '' ) {
		printf(
			'<tr><th scope="row"><label for="lets-seo-%1$s">%2$s</label></th><td><input type="text" id="lets-seo-%1$s" class="%3$s" name="%4$s[%1$s]" value="%5$s" placeholder="%6$s"%7$s /></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $class ),
			esc_attr( $name ),
			esc_attr( (string) $s[ $key ] ),
			esc_attr( $placeholder ),
			$dir ? ' dir="' . esc_attr( $dir ) . '"' : ''
		);
	}

	/**
	 * @param string              $name  Option name.
	 * @param array<string,mixed> $s     Settings.
	 * @param string              $key   Setting.
	 * @param string              $label Label.
	 * @param string              $help  Help text.
	 * @param string              $dir   Text direction.
	 */
	protected static function textarea_row( $name, array $s, $key, $label, $help = '', $dir = '' ) {
		printf(
			'<tr><th scope="row"><label for="lets-seo-%1$s">%2$s</label></th><td><textarea id="lets-seo-%1$s" class="large-text" rows="3" name="%3$s[%1$s]"%5$s>%4$s</textarea>%6$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_textarea( (string) $s[ $key ] ),
			$dir ? ' dir="' . esc_attr( $dir ) . '"' : '',
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	/**
	 * @param string              $name  Option name.
	 * @param array<string,mixed> $s     Settings.
	 * @param string              $key   Setting.
	 * @param string              $label Label.
	 * @param string              $text  Checkbox text.
	 */
	protected static function check_row( $name, array $s, $key, $label, $text ) {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[%3$s]" value="1"%4$s /> %5$s</label></td></tr>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $key ),
			checked( (bool) $s[ $key ], true, false ),
			esc_html( $text )
		);
	}

	/**
	 * @param string              $name  Option name.
	 * @param array<string,mixed> $s     Settings.
	 * @param string              $key   Setting.
	 * @param string              $label Label.
	 * @param string              $help  Help text.
	 */
	protected static function media_row( $name, array $s, $key, $label, $help = '' ) {
		$image = Lets_SEO::image( (int) $s[ $key ] );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<div class="lets-seo__media" data-media>
					<input type="hidden" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (int) $s[ $key ] ); ?>" />
					<img src="<?php echo $image ? esc_url( $image['url'] ) : ''; ?>" alt="" <?php echo $image ? '' : 'hidden'; ?> />
					<button type="button" class="button" data-media-pick>בחר תמונה</button>
					<button type="button" class="button-link" data-media-clear <?php echo $image ? '' : 'hidden'; ?>>הסר</button>
				</div>
				<?php if ( $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
