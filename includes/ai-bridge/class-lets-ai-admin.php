<?php
/**
 * Admin page for LETS AI Bridge (Tools → AI Bridge): create and revoke
 * tokens, grant or take back write access, see recent writes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_AI_Admin {

	const PAGE_SLUG = 'lets-ai-bridge';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_lets_ai_create_token', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_lets_ai_revoke_token', array( __CLASS__, 'handle_revoke' ) );
		add_action( 'admin_post_lets_ai_toggle_write', array( __CLASS__, 'handle_toggle_write' ) );
	}

	public static function menu() {
		if ( class_exists( 'Lets_Admin' ) ) {
			add_submenu_page( Lets_Admin::MENU, 'AI Bridge', 'AI Bridge', 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render' ) );
		} else {
			add_management_page( 'AI Bridge', 'AI Bridge', 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render' ) );
		}
	}

	/**
	 * @return string URL of the AI Bridge page.
	 */
	public static function url() {
		return class_exists( 'Lets_Admin' ) ? Lets_Admin::url( self::PAGE_SLUG ) : admin_url( 'tools.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @param array<string,string> $args Query args.
	 */
	protected static function back( array $args ) {
		wp_safe_redirect( add_query_arg( $args, self::url() ) );
		exit;
	}

	public static function handle_create() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'lets_ai_create_token' );

		$label  = isset( $_POST['token_label'] ) ? sanitize_text_field( wp_unslash( $_POST['token_label'] ) ) : 'Claude';
		$result = Lets_AI_Tokens::create( $label );

		if ( ! empty( $_POST['grant_write'] ) ) {
			$granted   = get_option( Lets_AI_REST::WRITE_TOKENS_OPTION, array() );
			$granted   = is_array( $granted ) ? $granted : array();
			$granted[] = $result['id'];
			update_option( Lets_AI_REST::WRITE_TOKENS_OPTION, array_values( array_unique( $granted ) ), false );
		}

		// The plaintext token is shown once, from a short-lived per-user transient.
		set_transient( 'lets_ai_new_token_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

		self::back( array( 'ai_created' => '1' ) );
	}

	public static function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'lets_ai_revoke_token' );

		Lets_AI_Tokens::revoke( isset( $_POST['token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['token_id'] ) ) : '' );

		self::back( array( 'ai_revoked' => '1' ) );
	}

	public static function handle_toggle_write() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'lets_ai_toggle_write' );

		$id      = isset( $_POST['token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['token_id'] ) ) : '';
		$enable  = ! empty( $_POST['enable'] );
		$granted = get_option( Lets_AI_REST::WRITE_TOKENS_OPTION, array() );
		$granted = is_array( $granted ) ? $granted : array();

		if ( $enable && '' !== $id ) {
			$granted[] = $id;
		} else {
			$granted = array_diff( $granted, array( $id ) );
		}

		update_option( Lets_AI_REST::WRITE_TOKENS_OPTION, array_values( array_unique( $granted ) ), false );

		self::back( array( 'ai_write' => $enable ? 'on' : 'off' ) );
	}

	/**
	 * The one-time block with the new token and the ready-to-paste setup.
	 */
	protected static function render_new_token() {
		$fresh = get_transient( 'lets_ai_new_token_' . get_current_user_id() );

		if ( empty( $_GET['ai_created'] ) || ! $fresh || empty( $fresh['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		delete_transient( 'lets_ai_new_token_' . get_current_user_id() );

		$mcp    = rest_url( Lets_AI_REST::NS . '/mcp' );
		$claude = sprintf( 'claude mcp add --transport http --scope user lets-wordpress-db %s --header "Authorization: Bearer %s"', $mcp, $fresh['token'] );
		$json   = wp_json_encode(
			array(
				'mcpServers' => array(
					'lets-wordpress-db' => array(
						'url'     => $mcp,
						'headers' => array( 'Authorization' => 'Bearer ' . $fresh['token'] ),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		?>
		<div class="notice notice-success" style="padding:12px 16px;">
			<p><strong>הטוקן נוצר. העתק אותו עכשיו — הוא לא יוצג שוב.</strong></p>
			<p><code dir="ltr" style="font-size:14px;user-select:all;word-break:break-all;"><?php echo esc_html( $fresh['token'] ); ?></code></p>
			<p><strong>Claude Code</strong> — להדביק בטרמינל, או פשוט לשלוח את הטוקן ל־Claude בצ'אט:</p>
			<pre dir="ltr" style="background:#1e1e1e;color:#d4d4d4;padding:12px;overflow:auto;text-align:left;user-select:all;"><?php echo esc_html( $claude ); ?></pre>
			<p><strong>Cursor</strong> — <code>mcp.json</code>:</p>
			<pre dir="ltr" style="background:#1e1e1e;color:#d4d4d4;padding:12px;overflow:auto;text-align:left;"><?php echo esc_html( $json ); ?></pre>
		</div>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}

		$tokens  = Lets_AI_Tokens::all();
		$granted = get_option( Lets_AI_REST::WRITE_TOKENS_OPTION, array() );
		$granted = is_array( $granted ) ? $granted : array();
		$changes = get_option( Lets_AI_DB_Writer::LOG_INDEX_OPTION, array() );
		$changes = is_array( $changes ) ? array_slice( array_reverse( $changes ), 0, 15 ) : array();
		?>
		<div class="wrap" dir="rtl">
			<h1>AI Bridge — גישה של Claude ל־DB</h1>
			<p>
				טוקן כאן נותן לכלי AI (Claude Code / Cursor) גישה למסד הנתונים של האתר דרך REST / MCP, בלי לחשוף את סיסמת ה־MySQL.
				כל טוקן יכול <strong>לקרוא</strong> את כל הטבלאות. <strong>כתיבה</strong> ניתנת לכל טוקן בנפרד:
				עריכת meta ו־options דרך WordPress, והרצת INSERT / UPDATE / DELETE על כל טבלה.
				כל כתיבה נשמרת ביומן עם הערך הקודם וניתנת לביטול. שינויי מבנה (DROP, ALTER, TRUNCATE) חסומים תמיד.
			</p>

			<?php self::render_new_token(); ?>

			<?php if ( ! empty( $_GET['ai_revoked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>הטוקן בוטל.</p></div>
			<?php endif; ?>

			<div class="card" style="max-width:760px;padding:16px 20px;margin-top:16px;">
				<h2 style="margin-top:0;">יצירת טוקן</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'lets_ai_create_token' ); ?>
					<input type="hidden" name="action" value="lets_ai_create_token" />
					<p>
						<label for="token_label"><strong>שם</strong></label><br />
						<input type="text" class="regular-text" id="token_label" name="token_label" value="Claude" />
					</p>
					<p>
						<label><input type="checkbox" name="grant_write" value="1" checked /> לאפשר כתיבה ל־DB (אפשר לבטל בכל רגע בטבלה למטה)</label>
					</p>
					<?php submit_button( 'צור טוקן', 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="card" style="max-width:760px;padding:16px 20px;margin-top:16px;">
				<h2 style="margin-top:0;">טוקנים</h2>
				<?php if ( empty( $tokens ) ) : ?>
					<p>אין טוקנים עדיין.</p>
				<?php else : ?>
					<table class="widefat striped">
						<thead><tr><th>שם</th><th>קידומת</th><th>נוצר</th><th>שימוש אחרון</th><th>הרשאה</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $tokens as $t ) : ?>
							<?php $can_write = in_array( $t['id'], $granted, true ); ?>
							<tr>
								<td><?php echo esc_html( $t['label'] ); ?></td>
								<td><code dir="ltr"><?php echo esc_html( $t['prefix'] . '…' ); ?></code></td>
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $t['created'] ) ); ?></td>
								<td><?php echo ! empty( $t['last_used'] ) ? esc_html( wp_date( 'Y-m-d H:i', (int) $t['last_used'] ) ) : '—'; ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
										<?php if ( ! $can_write ) : ?>onsubmit="return confirm('לתת לטוקן הזה הרשאה לשנות נתונים באתר?');"<?php endif; ?>>
										<?php wp_nonce_field( 'lets_ai_toggle_write' ); ?>
										<input type="hidden" name="action" value="lets_ai_toggle_write" />
										<input type="hidden" name="token_id" value="<?php echo esc_attr( $t['id'] ); ?>" />
										<input type="hidden" name="enable" value="<?php echo $can_write ? '' : '1'; ?>" />
										<button type="submit" class="button button-small"><?php echo $can_write ? 'קריאה + כתיבה — להפוך לקריאה בלבד' : 'קריאה בלבד — לאפשר כתיבה'; ?></button>
									</form>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('לבטל את הטוקן?');">
										<?php wp_nonce_field( 'lets_ai_revoke_token' ); ?>
										<input type="hidden" name="action" value="lets_ai_revoke_token" />
										<input type="hidden" name="token_id" value="<?php echo esc_attr( $t['id'] ); ?>" />
										<?php submit_button( 'בטל', 'delete small', 'submit', false ); ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php if ( $changes ) : ?>
				<div class="card" style="max-width:760px;padding:16px 20px;margin-top:16px;">
					<h2 style="margin-top:0;">כתיבות אחרונות</h2>
					<table class="widefat striped">
						<thead><tr><th>זמן</th><th>טוקן</th><th>מה השתנה</th><th>בוטל</th></tr></thead>
						<tbody>
						<?php foreach ( $changes as $c ) : ?>
							<tr>
								<td dir="ltr"><?php echo esc_html( $c['time'] ); ?></td>
								<td><?php echo esc_html( $c['token'] ); ?></td>
								<td dir="ltr"><?php echo esc_html( $c['target'] ); ?></td>
								<td><?php echo $c['reverted'] ? '✔' : ''; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<p style="color:#b32d2e;max-width:760px;">
				טוקן עם הרשאת כתיבה שווה לגישת מנהל למסד הנתונים. לא לשתף בפומבי, ולבטל מיד אם דלף.
				MCP: <code dir="ltr"><?php echo esc_html( rest_url( Lets_AI_REST::NS . '/mcp' ) ); ?></code>
			</p>
		</div>
		<?php
	}
}
