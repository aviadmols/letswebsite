<?php
/**
 * Landing page copy: defaults from a JSON file in the theme, an optional
 * per-page override saved on the page, and an export / import box on the
 * page's edit screen.
 *
 * The idea: export the JSON, hand it to any AI tool to improve the texts,
 * paste the result back. Import only ever changes values — the structure
 * (keys, which visual a section shows) comes from the default file, so a
 * bad import cannot break the page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_Landing_Copy {

	const META       = '_lets_landing_copy';
	const META_TIME  = '_lets_landing_copy_time';
	const NONCE      = 'lets_landing_copy';
	const DOWNLOAD   = 'lets_landing_copy_download';
	const NOTICE_KEY = 'lets_landing_copy_notice_';

	/** @var array<string,array{json:string,protected:string[]}> template slug → definition */
	protected static $pages = array();

	/**
	 * @param string   $template  Page template slug, e.g. templates/landing-post-purchase.php.
	 * @param string   $json      Absolute path of the default copy file.
	 * @param string[] $protected Keys an import may never change (structure, not text).
	 */
	public static function register( $template, $json, array $protected = array() ) {
		self::$pages[ $template ] = array(
			'json'      => $json,
			'protected' => $protected,
		);
	}

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ), 10, 2 );
		add_action( 'save_post_page', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_post_' . self::DOWNLOAD, array( __CLASS__, 'download' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * The registered template a page uses, or ''.
	 *
	 * @param int|WP_Post $post Post.
	 * @return string
	 */
	public static function template_of( $post ) {
		$slug = (string) get_page_template_slug( $post );
		return isset( self::$pages[ $slug ] ) ? $slug : '';
	}

	/**
	 * @param string $template Template slug.
	 * @return array<string,mixed>
	 */
	public static function defaults( $template ) {
		static $cache = array();

		if ( ! isset( $cache[ $template ] ) ) {
			$file = isset( self::$pages[ $template ] ) ? self::$pages[ $template ]['json'] : '';
			$data = $file && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

			$cache[ $template ] = is_array( $data ) ? $data : array();
		}

		return $cache[ $template ];
	}

	/**
	 * @param int $post_id Page.
	 * @return array<string,mixed>|null The saved override, or null when the page uses the defaults.
	 */
	public static function override( $post_id ) {
		$raw = $post_id ? get_post_meta( $post_id, self::META, true ) : '';

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * The copy a page renders with: defaults, with the page's override merged on top.
	 *
	 * @param string $template Template slug.
	 * @param int    $post_id  Page.
	 * @return array<string,mixed>
	 */
	public static function get( $template, $post_id = 0 ) {
		$defaults = self::defaults( $template );
		$override = self::override( $post_id );

		if ( null === $override ) {
			return $defaults;
		}

		$report = array();

		return self::merge( $defaults, $override, self::$pages[ $template ]['protected'], $report );
	}

	/**
	 * Take text from $import wherever it fits the shape of $default.
	 *
	 * - strings: replaced by the imported string.
	 * - lists of strings (checks, chips): the imported list, any length.
	 * - lists of tuples (faq items, steps, cards — each a fixed-length list of
	 *   strings): the imported list, any length, each item matched to the
	 *   default's shape.
	 * - lists of objects (features): item by item, count fixed by the default.
	 * - objects: key by key from the default; unknown keys are ignored, and
	 *   protected keys always keep the default value.
	 *
	 * @param mixed                $default   Default value.
	 * @param mixed                $import    Imported value.
	 * @param string[]             $protected Keys an import may never change.
	 * @param array<string,mixed>  $report    Filled with 'changed' (int) and 'ignored' (string[]).
	 * @param string               $path      Dotted path, for the report.
	 * @return mixed
	 */
	public static function merge( $default, $import, array $protected, array &$report, $path = '' ) {
		$report += array(
			'changed' => 0,
			'ignored' => array(),
		);

		if ( is_string( $default ) ) {
			if ( is_string( $import ) ) {
				if ( $import !== $default ) {
					++$report['changed'];
				}
				return $import;
			}
			$report['ignored'][] = $path;
			return $default;
		}

		if ( ! is_array( $default ) ) {
			return $default;
		}
		if ( ! is_array( $import ) ) {
			$report['ignored'][] = $path;
			return $default;
		}

		if ( array_is_list( $default ) ) {
			if ( ! array_is_list( $import ) ) {
				$report['ignored'][] = $path;
				return $default;
			}

			$shape = isset( $default[0] ) ? $default[0] : '';

			// List of strings.
			if ( is_string( $shape ) ) {
				$out = array();
				foreach ( $import as $i => $value ) {
					if ( is_string( $value ) ) {
						$out[] = $value;
					} else {
						$report['ignored'][] = $path . '[' . $i . ']';
					}
				}
				if ( $out !== $default ) {
					++$report['changed'];
				}
				return $out;
			}

			// List of tuples — any length, each item shaped like the default's.
			if ( is_array( $shape ) && array_is_list( $shape ) ) {
				$out = array();
				foreach ( $import as $i => $item ) {
					$base = isset( $default[ $i ] ) ? $default[ $i ] : $shape;
					if ( is_array( $item ) && array_is_list( $item ) && count( $item ) === count( $base ) ) {
						$out[] = self::merge( $base, $item, $protected, $report, $path . '[' . $i . ']' );
					} else {
						$report['ignored'][] = $path . '[' . $i . ']';
					}
				}
				return $out;
			}

			// List of objects — count fixed by the default.
			$out = array();
			foreach ( $default as $i => $item ) {
				$out[] = isset( $import[ $i ] ) && is_array( $import[ $i ] )
					? self::merge( $item, $import[ $i ], $protected, $report, $path . '[' . $i . ']' )
					: $item;
			}
			return $out;
		}

		// Object.
		$out = array();
		foreach ( $default as $key => $value ) {
			$key_path = '' === $path ? (string) $key : $path . '.' . $key;
			if ( in_array( (string) $key, $protected, true ) || ! array_key_exists( $key, $import ) ) {
				$out[ $key ] = $value;
				continue;
			}
			$out[ $key ] = self::merge( $value, $import[ $key ], $protected, $report, $key_path );
		}
		foreach ( $import as $key => $value ) {
			if ( ! array_key_exists( $key, $default ) && '_' !== substr( (string) $key, 0, 1 ) ) {
				$report['ignored'][] = '' === $path ? (string) $key : $path . '.' . $key;
			}
		}

		return $out;
	}

	/**
	 * The JSON handed to the editor / AI tool.
	 *
	 * @param string $template Template slug.
	 * @param int    $post_id  Page.
	 * @return string
	 */
	public static function export( $template, $post_id ) {
		$copy = self::get( $template, $post_id );

		$readme = array(
			'_readme' => array(
				'he' => 'זה התוכן של דף הנחיתה. שפרו רק את הטקסטים (הערכים). אל תשנו שמות של מפתחות, אל תשנו ערכים של "visual", ושמרו על מספר הפריטים ב-"features". אפשר להוסיף או להסיר שאלות ב-faq.items ושורות ב-checks. שמרו על אורכים דומים: כותרת hero עד ~45 תווים, כותרות משנה עד ~55, פסקאות עד ~300. עברית, גוף שני רבים, טון ישיר וברור. החזירו JSON תקין בלבד, באותו מבנה. מפתחות שמתחילים בקו תחתון מתעלמים מהם בייבוא.',
				'en' => 'This is the landing page copy. Improve the texts (values) only. Do not rename keys, do not change "visual" values, keep the number of items in "features". You may add or remove faq.items and checks. Keep similar lengths: hero title up to ~45 characters, section titles up to ~55, paragraphs up to ~300. Hebrew, plural second person, direct and clear. Return valid JSON only, same structure. Keys starting with an underscore are ignored on import.',
			),
		);

		return (string) wp_json_encode( $readme + $copy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 */
	public static function add_box( $post_type, $post ) {
		if ( 'page' !== $post_type || ! $post instanceof WP_Post || '' === self::template_of( $post ) ) {
			return;
		}

		add_meta_box( 'lets-landing-copy', 'תוכן הדף — ייצוא וייבוא (JSON)', array( __CLASS__, 'render_box' ), 'page', 'normal', 'high' );
	}

	/**
	 * @param WP_Post $post Post.
	 */
	public static function render_box( $post ) {
		$template = self::template_of( $post );
		$override = self::override( $post->ID );
		$time     = (int) get_post_meta( $post->ID, self::META_TIME, true );
		$download = wp_nonce_url( add_query_arg( array( 'action' => self::DOWNLOAD, 'post' => $post->ID ), admin_url( 'admin-post.php' ) ), self::DOWNLOAD . $post->ID );

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<div class="lets-landing-copy" dir="rtl">
			<p>
				<?php if ( null === $override ) : ?>
					<strong>מצב:</strong> הדף מציג את תוכן ברירת המחדל מהקוד.
				<?php else : ?>
					<strong>מצב:</strong> הדף מציג תוכן מיובא<?php echo $time ? ' (נשמר ' . esc_html( wp_date( 'd.m.Y H:i', $time ) ) . ')' : ''; ?>.
				<?php endif; ?>
			</p>

			<h4 style="margin:16px 0 6px">ייצוא</h4>
			<p class="description" style="margin-bottom:8px">
				העתיקו את ה-JSON לכלי AI (ChatGPT, Claude…) עם בקשה לשפר את הטקסטים. ההוראות לכלי כלולות בשדה <code>_readme</code>.
				<a href="<?php echo esc_url( $download ); ?>">הורדה כקובץ</a>
			</p>
			<textarea readonly rows="10" style="width:100%;direction:ltr;text-align:left;font-family:Menlo,Consolas,monospace;font-size:12px" onclick="this.select()"><?php echo esc_textarea( self::export( $template, $post->ID ) ); ?></textarea>

			<h4 style="margin:20px 0 6px">ייבוא</h4>
			<p class="description" style="margin-bottom:8px">
				הדביקו כאן את ה-JSON המשופר ולחצו "עדכון" לדף. רק טקסטים משתנים — המבנה נשאר מהקוד, ומפתח לא מוכר פשוט יתעלם.
			</p>
			<textarea name="lets_landing_import" rows="8" style="width:100%;direction:ltr;text-align:left;font-family:Menlo,Consolas,monospace;font-size:12px" placeholder="{ … }"></textarea>

			<?php if ( null !== $override ) : ?>
				<p style="margin-top:12px">
					<label><input type="checkbox" name="lets_landing_reset" value="1" /> לחזור לתוכן ברירת המחדל מהקוד (מוחק את הייבוא)</label>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param int     $post_id Page.
	 * @param WP_Post $post    Page.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$template = self::template_of( $post );
		if ( '' === $template ) {
			return;
		}

		if ( ! empty( $_POST['lets_landing_reset'] ) ) {
			delete_post_meta( $post_id, self::META );
			delete_post_meta( $post_id, self::META_TIME );
			self::notice( 'success', 'התוכן חזר לברירת המחדל מהקוד.' );
			return;
		}

		$raw = isset( $_POST['lets_landing_import'] ) ? trim( (string) wp_unslash( $_POST['lets_landing_import'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON, validated below.
		if ( '' === $raw ) {
			return;
		}

		$raw    = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$import = json_decode( $raw, true );

		if ( ! is_array( $import ) ) {
			self::notice( 'error', 'הייבוא נכשל: זה לא JSON תקין (' . json_last_error_msg() . '). התוכן הקודם נשאר.' );
			return;
		}

		$report = array();
		$merged = self::merge( self::defaults( $template ), $import, self::$pages[ $template ]['protected'], $report );

		update_post_meta( $post_id, self::META, wp_slash( (string) wp_json_encode( $merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		update_post_meta( $post_id, self::META_TIME, time() );

		$message = sprintf( 'הייבוא הצליח: %d שדות שונים מברירת המחדל.', (int) $report['changed'] );
		if ( ! empty( $report['ignored'] ) ) {
			$message .= ' התעלמנו מ: ' . implode( ', ', array_slice( $report['ignored'], 0, 8 ) ) . ( count( $report['ignored'] ) > 8 ? '…' : '' );
		}
		self::notice( 'success', $message );
	}

	public static function download() {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( self::DOWNLOAD . $post_id );

		$template = self::template_of( $post_id );
		if ( '' === $template ) {
			wp_die( 'Not a landing page' );
		}

		$post = get_post( $post_id );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $post->post_name . '-copy' ) . '.json"' );
		echo self::export( $template, $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download.
		exit;
	}

	/**
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message.
	 */
	protected static function notice( $type, $message ) {
		set_transient( self::NOTICE_KEY . get_current_user_id(), array( $type, $message ), MINUTE_IN_SECONDS );
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		$notice = get_transient( self::NOTICE_KEY . get_current_user_id() );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( self::NOTICE_KEY . get_current_user_id() );

		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
	}
}
