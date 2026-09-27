<?php
/**
 * Signup / contact popup and the leads it collects.
 *
 * Any link to #signup (or an element with class lets-open-signup) opens the
 * popup, on every page — so Elementor buttons join in by pointing at #signup.
 * A submission is saved as a "lead" post in wp-admin (לידים) and emailed to
 * the address in popup-copy.json. Saving first means a mail problem never
 * loses a lead.
 *
 * Spam protection is cache-friendly (no nonce): a honeypot field, a minimum
 * time on the form, and a per-IP rate limit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_Leads {

	const CPT = 'lets_lead';

	const NS = 'lets/v1';

	/** Submissions accepted per IP per hour. */
	const RATE_LIMIT = 6;

	/** Seconds the form must have been open before it can be sent. */
	const MIN_SECONDS = 3;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_popup' ) );

		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::CPT, array( __CLASS__, 'add_box' ) );
	}

	/**
	 * Popup texts: the JSON defaults, with whatever was filled in under
	 * LETS → הגדרות אתר on top.
	 *
	 * @param bool $with_settings False for the bare defaults.
	 * @return array<string,mixed>
	 */
	public static function copy( $with_settings = true ) {
		static $copy = null;

		if ( null === $copy ) {
			$data = json_decode( (string) file_get_contents( __DIR__ . '/popup-copy.json' ), true );
			$copy = is_array( $data ) ? $data : array();
		}

		if ( ! $with_settings || ! class_exists( 'Lets_Admin' ) ) {
			return $copy;
		}

		$map = array(
			'notify_to'     => 'notify_to',
			'contact_email' => 'contact_email',
			'contact_phone' => 'contact_phone',
			'app_url'       => 'app_url',
			'popup_title'   => 'title',
			'popup_lead'    => 'lead',
			'popup_submit'  => 'submit',
			'popup_privacy' => 'privacy',
		);
		$out = $copy;

		foreach ( Lets_Admin::settings() as $setting => $value ) {
			if ( isset( $map[ $setting ] ) && '' !== trim( (string) $value ) ) {
				$out[ $map[ $setting ] ] = $value;
			}
		}

		return $out;
	}

	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => 'לידים',
					'singular_name' => 'ליד',
					'menu_name'     => 'לידים',
					'all_items'     => 'כל הלידים',
					'search_items'  => 'חיפוש לידים',
					'not_found'     => 'אין לידים עדיין.',
					'edit_item'     => 'ליד',
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => class_exists( 'Lets_Admin' ) ? Lets_Admin::MENU : true,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-email-alt',
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
			)
		);
	}

	public static function register_route() {
		register_rest_route(
			self::NS,
			'/lead',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function assets() {
		$dir = get_stylesheet_directory_uri();
		$ver = HELLO_ELEMENTOR_CHILD_VERSION;

		wp_enqueue_style( 'lets-fonts', $dir . '/assets/fonts/fonts.css', array(), $ver );
		wp_enqueue_style( 'lets-popup', $dir . '/assets/leads/popup.css', array( 'lets-fonts' ), $ver );
		wp_enqueue_script( 'lets-popup', $dir . '/assets/leads/popup.js', array(), $ver, true );
		wp_localize_script(
			'lets-popup',
			'letsPopup',
			array(
				'endpoint' => rest_url( self::NS . '/lead' ),
				'errors'   => self::copy()['errors'],
				'sending'  => self::copy()['sending'],
			)
		);
	}

	public static function render_popup() {
		$c = self::copy();
		?>
		<div class="lets-popup" id="lets-signup" hidden dir="rtl" role="dialog" aria-modal="true" aria-labelledby="lets-popup-title">
			<div class="lets-popup__overlay" data-popup-close></div>
			<div class="lets-popup__panel">
				<button type="button" class="lets-popup__close" data-popup-close aria-label="<?php echo esc_attr( $c['close'] ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
				</button>

				<form class="lets-popup__form" novalidate>
					<h2 class="lets-popup__title" id="lets-popup-title"><?php echo esc_html( $c['title'] ); ?></h2>
					<p class="lets-popup__lead"><?php echo esc_html( $c['lead'] ); ?></p>

					<div class="lets-popup__grid">
						<label class="lets-popup__field">
							<span><?php echo esc_html( $c['fields']['name'] ); ?></span>
							<input type="text" name="name" autocomplete="name" required />
						</label>
						<label class="lets-popup__field">
							<span><?php echo esc_html( $c['fields']['phone'] ); ?></span>
							<input type="tel" name="phone" autocomplete="tel" inputmode="tel" required dir="ltr" />
						</label>
						<label class="lets-popup__field lets-popup__field--wide">
							<span><?php echo esc_html( $c['fields']['email'] ); ?></span>
							<input type="email" name="email" autocomplete="email" inputmode="email" required dir="ltr" />
						</label>
						<label class="lets-popup__field lets-popup__field--wide">
							<span><?php echo esc_html( $c['fields']['store'] ); ?></span>
							<input type="text" name="store" autocomplete="url" inputmode="url" dir="ltr" placeholder="mystore.co.il" />
						</label>
					</div>

					<fieldset class="lets-popup__platforms">
						<legend><?php echo esc_html( $c['fields']['platform'] ); ?></legend>
						<?php foreach ( $c['platforms'] as $i => $platform ) : ?>
							<label class="lets-popup__chip">
								<input type="radio" name="platform" value="<?php echo esc_attr( $platform ); ?>" <?php checked( 0, $i ); ?> />
								<span><?php echo esc_html( $platform ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<label class="lets-popup__field lets-popup__field--wide">
						<span><?php echo esc_html( $c['fields']['message'] ); ?></span>
						<textarea name="message" rows="2"></textarea>
					</label>

					<!-- Bots fill this; people never see it. -->
					<label class="lets-popup__hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off" /></label>
					<input type="hidden" name="ts" value="" />
					<input type="hidden" name="source" value="" />

					<p class="lets-popup__error" role="alert" hidden></p>

					<button type="submit" class="lets-popup__submit"><?php echo esc_html( $c['submit'] ); ?></button>
					<p class="lets-popup__privacy"><?php echo esc_html( $c['privacy'] ); ?></p>

					<p class="lets-popup__app">
						<?php echo esc_html( $c['app_line'] ); ?>
						<a href="<?php echo esc_url( $c['app_url'] ); ?>"><?php echo esc_html( $c['app_link'] ); ?> ←</a>
					</p>
				</form>

				<div class="lets-popup__success" hidden>
					<span class="lets-popup__check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
					<h2 class="lets-popup__title"><?php echo esc_html( $c['success_title'] ); ?></h2>
					<p class="lets-popup__lead"><?php echo esc_html( $c['success_body'] ); ?></p>
					<p class="lets-popup__contact">
						<a href="mailto:<?php echo esc_attr( $c['contact_email'] ); ?>"><?php echo esc_html( $c['contact_email'] ); ?></a>
						<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $c['contact_phone'] ) ); ?>"><?php echo esc_html( $c['contact_phone'] ); ?></a>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * POST /wp-json/lets/v1/lead
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function submit( $request ) {
		$p = $request->get_json_params();
		$p = is_array( $p ) ? $p : $request->get_params();

		$field = function ( $key, $max = 200 ) use ( $p ) {
			return isset( $p[ $key ] ) && is_scalar( $p[ $key ] ) ? mb_substr( sanitize_text_field( (string) $p[ $key ] ), 0, $max ) : '';
		};

		// Bots: honeypot filled, or the form sent faster than a person could.
		if ( '' !== $field( 'website' ) ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}
		$ts = (int) $field( 'ts' );
		if ( $ts && time() - $ts < self::MIN_SECONDS ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'lets_lead_rate_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::RATE_LIMIT ) {
			return new WP_Error( 'lets_lead_rate', self::copy()['errors']['generic'], array( 'status' => 429 ) );
		}
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );

		$name    = $field( 'name', 120 );
		$email   = sanitize_email( $field( 'email', 200 ) );
		$phone   = preg_replace( '/[^0-9+\-\s()]/', '', $field( 'phone', 40 ) );
		$store   = $field( 'store', 200 );
		$plat    = $field( 'platform', 40 );
		$source  = esc_url_raw( $field( 'source', 500 ) );
		$message = isset( $p['message'] ) && is_scalar( $p['message'] ) ? mb_substr( sanitize_textarea_field( (string) $p['message'] ), 0, 2000 ) : '';
		$errors  = self::copy()['errors'];

		if ( '' === $name ) {
			return new WP_Error( 'lets_lead_name', $errors['name'], array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'lets_lead_email', $errors['email'], array( 'status' => 400 ) );
		}
		if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 7 ) {
			return new WP_Error( 'lets_lead_phone', $errors['phone'], array( 'status' => 400 ) );
		}

		$lead_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => $name,
				'meta_input'  => array(
					'_lead_email'    => $email,
					'_lead_phone'    => $phone,
					'_lead_store'    => $store,
					'_lead_platform' => $plat,
					'_lead_message'  => $message,
					'_lead_source'   => $source,
					'_lead_ip'       => $ip,
				),
			),
			true
		);

		if ( is_wp_error( $lead_id ) ) {
			return new WP_Error( 'lets_lead_save', $errors['generic'], array( 'status' => 500 ) );
		}

		self::notify( $lead_id, compact( 'name', 'email', 'phone', 'store', 'plat', 'message', 'source' ) );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * @param int                  $lead_id Lead post.
	 * @param array<string,string> $d       Lead details.
	 */
	protected static function notify( $lead_id, array $d ) {
		$to = sanitize_email( (string) self::copy()['notify_to'] );
		if ( ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}

		$lines = array(
			'שם: ' . $d['name'],
			'אימייל: ' . $d['email'],
			'טלפון: ' . $d['phone'],
			'פלטפורמה: ' . $d['plat'],
			'חנות: ' . $d['store'],
			'',
			$d['message'],
			'',
			'מהעמוד: ' . $d['source'],
			'לצפייה בליד: ' . admin_url( 'post.php?post=' . $lead_id . '&action=edit' ),
		);

		wp_mail(
			$to,
			sprintf( 'ליד חדש מהאתר: %s (%s)', $d['name'], $d['plat'] ),
			implode( "\n", $lines ),
			array(
				'Content-Type: text/plain; charset=UTF-8',
				'Reply-To: ' . $d['name'] . ' <' . $d['email'] . '>',
			)
		);
	}

	/* --- wp-admin ---------------------------------------------------------- */

	/**
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public static function columns( $columns ) {
		return array(
			'cb'       => $columns['cb'],
			'title'    => 'שם',
			'contact'  => 'פרטי קשר',
			'platform' => 'פלטפורמה',
			'store'    => 'חנות',
			'source'   => 'מהעמוד',
			'date'     => 'תאריך',
		);
	}

	/**
	 * @param string $column  Column.
	 * @param int    $post_id Lead.
	 */
	public static function column( $column, $post_id ) {
		switch ( $column ) {
			case 'contact':
				$email = get_post_meta( $post_id, '_lead_email', true );
				$phone = get_post_meta( $post_id, '_lead_phone', true );
				echo '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a><br><a href="tel:' . esc_attr( $phone ) . '" dir="ltr">' . esc_html( $phone ) . '</a>';
				break;
			case 'platform':
				echo esc_html( get_post_meta( $post_id, '_lead_platform', true ) );
				break;
			case 'store':
				echo esc_html( get_post_meta( $post_id, '_lead_store', true ) );
				break;
			case 'source':
				$source = (string) get_post_meta( $post_id, '_lead_source', true );
				echo $source ? '<a href="' . esc_url( $source ) . '" target="_blank" rel="noopener">' . esc_html( wp_parse_url( $source, PHP_URL_PATH ) ) . '</a>' : '—';
				break;
		}
	}

	public static function add_box() {
		add_meta_box( 'lets-lead', 'פרטי הליד', array( __CLASS__, 'render_box' ), self::CPT, 'normal', 'high' );
	}

	/**
	 * @param WP_Post $post Lead.
	 */
	public static function render_box( $post ) {
		$rows = array(
			'אימייל'    => get_post_meta( $post->ID, '_lead_email', true ),
			'טלפון'     => get_post_meta( $post->ID, '_lead_phone', true ),
			'פלטפורמה'  => get_post_meta( $post->ID, '_lead_platform', true ),
			'חנות'      => get_post_meta( $post->ID, '_lead_store', true ),
			'הודעה'     => get_post_meta( $post->ID, '_lead_message', true ),
			'מהעמוד'    => get_post_meta( $post->ID, '_lead_source', true ),
			'IP'        => get_post_meta( $post->ID, '_lead_ip', true ),
		);
		echo '<table class="widefat striped" dir="rtl"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:120px">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( (string) $value ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
