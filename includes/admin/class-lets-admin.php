<?php
/**
 * The "LETS" menu in wp-admin: one place for everything the theme adds to
 * the site — overview, SEO, leads, landing pages, popup and site settings.
 *
 * Other modules attach their pages under the 'lets' parent slug; this class
 * registers the parent (early, so submenus have something to hang on) and
 * owns the overview and site-settings pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_Admin {

	/** Parent menu slug. Submenus register with this as their parent. */
	const MENU = 'lets';

	const SETTINGS_PAGE = 'lets-settings';

	const OPTION = 'lets_site_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * @return array<string,string>
	 */
	public static function defaults() {
		return array(
			'notify_to'     => '',
			'contact_email' => '',
			'contact_phone' => '',
			'app_url'       => '',
			'popup_title'   => '',
			'popup_lead'    => '',
			'popup_submit'  => '',
			'popup_privacy' => '',
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * @param string $page Submenu page slug.
	 * @return string
	 */
	public static function url( $page ) {
		return admin_url( 'admin.php?page=' . $page );
	}

	public static function menu() {
		add_menu_page( 'LETS', 'LETS', 'manage_options', self::MENU, array( __CLASS__, 'render_overview' ), self::icon(), 3 );
		add_submenu_page( self::MENU, 'סקירה', 'סקירה', 'manage_options', self::MENU, array( __CLASS__, 'render_overview' ) );
		add_submenu_page( self::MENU, 'הגדרות אתר', 'הגדרות אתר', 'manage_options', self::SETTINGS_PAGE, array( __CLASS__, 'render_settings' ) );
	}

	/**
	 * A lime dot in a black ring — the site's mark, as a data URI.
	 *
	 * @return string
	 */
	protected static function icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="8" fill="none" stroke="#9ca2a7" stroke-width="2"/><circle cx="10" cy="10" r="4" fill="#e2ff78"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * @param string $hook Admin page hook.
	 */
	public static function assets( $hook ) {
		if ( 0 !== strpos( $hook, 'toplevel_page_lets' ) && 0 !== strpos( $hook, 'lets_page_' ) ) {
			return;
		}
		wp_enqueue_style( 'lets-admin', get_stylesheet_directory_uri() . '/assets/admin/admin.css', array(), HELLO_ELEMENTOR_CHILD_VERSION );
	}

	public static function register_settings() {
		register_setting(
			'lets_site',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input Submitted values.
	 * @return array<string,string>
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();

		foreach ( self::defaults() as $key => $default ) {
			$value = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';

			if ( in_array( $key, array( 'notify_to', 'contact_email' ), true ) ) {
				$out[ $key ] = sanitize_email( $value );
			} elseif ( 'app_url' === $key ) {
				$out[ $key ] = esc_url_raw( trim( $value ) );
			} elseif ( 'popup_lead' === $key ) {
				$out[ $key ] = sanitize_textarea_field( $value );
			} else {
				$out[ $key ] = sanitize_text_field( $value );
			}
		}

		return $out;
	}

	/* --- Overview ------------------------------------------------------------ */

	public static function render_overview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}

		$competing = class_exists( 'Lets_SEO' ) ? Lets_SEO::competing_plugin() : '';
		$leads     = class_exists( 'Lets_Leads' ) ? wp_count_posts( Lets_Leads::CPT ) : null;
		$leads_new = class_exists( 'Lets_Leads' ) ? count(
			get_posts(
				array(
					'post_type'      => Lets_Leads::CPT,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'date_query'     => array( array( 'after' => '7 days ago' ) ),
				)
			)
		) : 0;
		$landing   = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 50,
				'meta_key'       => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare'   => 'LIKE',
				'meta_value'     => 'templates/landing-', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$bridge = class_exists( 'Lets_AI_Admin' );
		?>
		<div class="wrap lets-admin" dir="rtl">
			<h1>LETS — ניהול התבנית</h1>
			<p class="lets-admin__intro">כל מה שהתבנית מוסיפה לאתר, במקום אחד.</p>

			<div class="lets-admin__grid">

				<div class="lets-admin__card">
					<h2>SEO</h2>
					<?php if ( ! class_exists( 'Lets_SEO' ) ) : ?>
						<p>המודול לא טעון.</p>
					<?php elseif ( $competing ) : ?>
						<p><span class="lets-admin__dot is-warn"></span> ממתין: <strong><?php echo esc_html( $competing ); ?></strong> פעיל ומדפיס את התגיות. שכבת ה-AI ותמונות השיתוף כבר עובדות.</p>
					<?php else : ?>
						<p><span class="lets-admin__dot is-ok"></span> פעיל: כותרות, תגיות, סכמה, sitemap, robots, llms.txt ותמונות שיתוף.</p>
					<?php endif; ?>
					<p class="lets-admin__links">
						<a class="button" href="<?php echo esc_url( self::url( 'lets-seo' ) ); ?>">הגדרות SEO</a>
						<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener">llms.txt</a>
						<a href="<?php echo esc_url( home_url( '/wp-sitemap.xml' ) ); ?>" target="_blank" rel="noopener">sitemap</a>
					</p>
				</div>

				<div class="lets-admin__card">
					<h2>לידים</h2>
					<?php if ( $leads ) : ?>
						<p class="lets-admin__big"><?php echo (int) $leads->publish; ?></p>
						<p><?php echo (int) $leads_new; ?> בשבוע האחרון · מהפופאפ "התחילו עכשיו"</p>
					<?php endif; ?>
					<p class="lets-admin__links">
						<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Lets_Leads::CPT ) ); ?>">כל הלידים</a>
						<a href="<?php echo esc_url( self::url( self::SETTINGS_PAGE ) ); ?>">לאן נשלח המייל</a>
					</p>
				</div>

				<div class="lets-admin__card">
					<h2>פופאפ הרשמה</h2>
					<p>נפתח מכל קישור שמצביע על <code dir="ltr">#signup</code> — גם כפתור באלמנטור. בדפי הנחיתה הכפתור "התחילו עכשיו" כבר מחובר.</p>
					<p class="lets-admin__links">
						<a class="button" href="<?php echo esc_url( self::url( self::SETTINGS_PAGE ) ); ?>">טקסטים והגדרות</a>
						<a href="<?php echo esc_url( home_url( '/#signup' ) ); ?>" target="_blank" rel="noopener">תצוגה מקדימה</a>
					</p>
				</div>

				<div class="lets-admin__card">
					<h2>דפי נחיתה</h2>
					<?php if ( $landing ) : ?>
						<ul class="lets-admin__list">
							<?php foreach ( $landing as $page ) : ?>
								<li>
									<a href="<?php echo esc_url( get_edit_post_link( $page ) ); ?>"><?php echo esc_html( get_the_title( $page ) ); ?></a>
									<span class="lets-admin__muted"><?php echo 'publish' === $page->post_status ? '' : '(' . esc_html( get_post_status_object( $page->post_status )->label ) . ')'; ?></span>
									<a class="lets-admin__muted" href="<?php echo esc_url( get_permalink( $page ) ); ?>" target="_blank" rel="noopener">צפייה</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p>עוד אין. יוצרים עמוד חדש ובוחרים לו תבנית "LETS — …".</p>
					<?php endif; ?>
					<p class="lets-admin__links">
						<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page' ) ); ?>">עמוד חדש</a>
					</p>
					<p class="lets-admin__muted">הטקסטים של כל דף: תיבת "תוכן הדף — ייצוא וייבוא" במסך העריכה שלו.</p>
				</div>

				<div class="lets-admin__card">
					<h2>AI Bridge</h2>
					<?php if ( $bridge ) : ?>
						<p><span class="lets-admin__dot is-ok"></span> פעיל. טוקנים לגישה של Claude / Cursor למסד הנתונים.</p>
						<p class="lets-admin__links"><a class="button" href="<?php echo esc_url( self::url( Lets_AI_Admin::PAGE_SLUG ) ); ?>">ניהול טוקנים</a></p>
					<?php else : ?>
						<p><span class="lets-admin__dot is-off"></span> לא פעיל עדיין. המודול נמצא בקוד (<code>includes/ai-bridge</code>) אבל חסר בו קובץ ה-endpoint, ולכן הוא לא נטען.</p>
					<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
	}

	/* --- Site settings -------------------------------------------------------- */

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}

		$s    = self::settings();
		$name = self::OPTION;
		$copy = class_exists( 'Lets_Leads' ) ? Lets_Leads::copy( false ) : array();
		$ph   = function ( $key ) use ( $copy ) {
			return isset( $copy[ $key ] ) && is_string( $copy[ $key ] ) ? $copy[ $key ] : '';
		};
		?>
		<div class="wrap lets-admin" dir="rtl">
			<h1>הגדרות אתר</h1>
			<p class="lets-admin__intro">הגדרות שנוגעות לכל האתר. שדה ריק = ברירת המחדל מהקוד (מוצגת כרמז).</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'lets_site' ); ?>

				<div class="lets-admin__card lets-admin__card--wide">
					<h2>לידים ויצירת קשר</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="lets-notify_to">לאן נשלח מייל על ליד חדש</label></th>
							<td><input type="email" id="lets-notify_to" class="regular-text" name="<?php echo esc_attr( $name ); ?>[notify_to]" value="<?php echo esc_attr( $s['notify_to'] ); ?>" placeholder="<?php echo esc_attr( $ph( 'notify_to' ) ); ?>" dir="ltr" />
							<p class="description">הליד נשמר תמיד ב"לידים", גם אם המייל לא הגיע.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="lets-contact_email">אימייל ליצירת קשר (מוצג בפופאפ)</label></th>
							<td><input type="email" id="lets-contact_email" class="regular-text" name="<?php echo esc_attr( $name ); ?>[contact_email]" value="<?php echo esc_attr( $s['contact_email'] ); ?>" placeholder="<?php echo esc_attr( $ph( 'contact_email' ) ); ?>" dir="ltr" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="lets-contact_phone">טלפון (מוצג בפופאפ)</label></th>
							<td><input type="text" id="lets-contact_phone" class="regular-text" name="<?php echo esc_attr( $name ); ?>[contact_phone]" value="<?php echo esc_attr( $s['contact_phone'] ); ?>" placeholder="<?php echo esc_attr( $ph( 'contact_phone' ) ); ?>" dir="ltr" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="lets-app_url">כתובת האפליקציה</label></th>
							<td><input type="url" id="lets-app_url" class="regular-text" name="<?php echo esc_attr( $name ); ?>[app_url]" value="<?php echo esc_attr( $s['app_url'] ); ?>" placeholder="<?php echo esc_attr( $ph( 'app_url' ) ); ?>" dir="ltr" />
							<p class="description">הקישור "לאפליקציה" בתחתית הפופאפ.</p></td>
						</tr>
					</table>
				</div>

				<div class="lets-admin__card lets-admin__card--wide">
					<h2>פופאפ "התחילו עכשיו"</h2>
					<p class="description">נפתח מכל קישור ל-<code dir="ltr">#signup</code>. כדי לחבר כפתור באלמנטור: שדה הקישור של הכפתור = <code dir="ltr">#signup</code>.</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="lets-popup_title">כותרת</label></th>
							<td><input type="text" id="lets-popup_title" class="regular-text" name="<?php echo esc_attr( $name ); ?>[popup_title]" value="<?php echo esc_attr( $s['popup_title'] ); ?>" placeholder="<?php echo esc_attr( $ph( 'title' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="lets-popup_lead">טקסט פתיחה</label></th>
							<td><textarea id="lets-popup_lead" class="large-text" rows="2" name="<?php echo esc_attr( $name ); ?>[popup_lead]" placeholder="<?php echo esc_attr( $ph( 'lead' ) ); ?>"><?php echo esc_textarea( $s['popup_lead'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="lets-popup_submit">טקסט הכפתור</label></th>
							<td><input type="text" id="lets-popup_submit" class="regular-text" name="<?php echo esc_attr( $name ); ?>[popup_submit]" value="<?php echo esc_attr( $s['popup_submit'] ); ?>" placeholder="<?php echo esc_attr( $ph( 'submit' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="lets-popup_privacy">שורת פרטיות</label></th>
							<td><input type="text" id="lets-popup_privacy" class="large-text" name="<?php echo esc_attr( $name ); ?>[popup_privacy]" value="<?php echo esc_attr( $s['popup_privacy'] ); ?>" placeholder="<?php echo esc_attr( $ph( 'privacy' ) ); ?>" /></td>
						</tr>
					</table>
				</div>

				<?php submit_button( 'שמירה' ); ?>
			</form>
		</div>
		<?php
	}
}
