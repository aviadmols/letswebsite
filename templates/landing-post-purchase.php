<?php
/**
 * Template Name: LETS — Post-Purchase Upsell (דף מכירה)
 * Template Post Type: page
 *
 * Landing page for the post-purchase upsell pillar of the LETS app.
 * Copy: includes/landing/post-purchase-copy.php. Styles: assets/landing/.
 * The product visuals are drawn in HTML/CSS, not images.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array{label:string,url:string} $btn   Button.
 * @param string                         $class Extra classes.
 */
function lets_lp_button( array $btn, $class = '' ) {
	?>
	<a class="lp-btn <?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $btn['url'] ); ?>">
		<?php echo esc_html( $btn['label'] ); ?>
		<svg class="lp-btn__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
	</a>
	<?php
}

/**
 * @param string $name Icon.
 */
function lets_lp_icon( $name ) {
	$paths = array(
		'box'    => '<path d="M12 3 3 7.5v9L12 21l9-4.5v-9L12 3z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/>',
		'design' => '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18c-2 0-2-2-1-3s1-3-1-3-3 0-3-2 2-3 2-5 1-5 3-5z"/>',
		'rtl'    => '<path d="M4 6h16M4 12h10M4 18h16"/><path d="m19 9-3 3 3 3"/>',
		'status' => '<path d="M3 7h13v10H3zM16 10h3l2 3v4h-5z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
		'sub'    => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/>',
	);
	echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $name ] . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG.
}

/**
 * The thank-you page with the LETS offer card, as a shopper sees it.
 *
 * @param array<string,string> $m       Mock copy.
 * @param bool                 $compact Phone layout (single column).
 */
function lets_lp_mock_thankyou( array $m, $compact = false ) {
	?>
	<div class="lp-store">
		<span class="lp-store__logo">Store</span>
		<span class="lp-store__nav"><span>חדש</span><span>טיפוח</span><span>סטים</span><span>עלינו</span></span>
	</div>
	<div class="lp-ty <?php echo $compact ? 'lp-ty--stack' : ''; ?>">
		<div>
			<span class="lp-ty__check"><svg viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
			<div class="lp-ty__title"><?php echo esc_html( $m['ty_title'] ); ?></div>
			<div class="lp-ty__sub"><?php echo esc_html( $m['ty_sub'] ); ?></div>
			<?php if ( ! $compact ) : ?>
				<div class="lp-ty__summary">
					<div class="lp-ty__row"><span><?php echo esc_html( $m['ty_item'] ); ?></span><span>₪189</span></div>
					<div class="lp-ty__row"><span><?php echo esc_html( $m['ty_shipping'] ); ?></span><span>₪0</span></div>
					<div class="lp-ty__row lp-ty__row--total"><span><?php echo esc_html( $m['ty_total'] ); ?></span><span>₪189</span></div>
				</div>
				<div class="lp-ty__hint"><?php echo esc_html( $m['ty_hint'] ); ?></div>
			<?php endif; ?>
		</div>
		<div class="lp-offer">
			<span class="lp-offer__eyebrow"><?php echo esc_html( $m['offer_eyebrow'] ); ?></span>
			<div class="lp-offer__title"><?php echo esc_html( $m['offer_title'] ); ?></div>
			<div class="lp-offer__sub"><?php echo esc_html( $m['offer_sub'] ); ?></div>
			<div class="lp-offer__product">
				<span class="lp-offer__thumb"><?php lets_lp_icon( 'box' ); ?></span>
				<span>
					<span class="lp-offer__name"><?php echo esc_html( $m['offer_name'] ); ?></span>
					<span class="lp-offer__price"><s><?php echo esc_html( $m['offer_was'] ); ?></s><b><?php echo esc_html( $m['offer_now'] ); ?></b><span class="lp-offer__save"><?php echo esc_html( $m['offer_save'] ); ?></span></span>
				</span>
			</div>
			<span class="lp-offer__btn"><?php echo esc_html( $m['offer_btn'] ); ?></span>
			<div class="lp-offer__consent"><?php echo esc_html( $m['offer_consent'] ); ?></div>
			<div class="lp-offer__decline"><?php echo esc_html( $m['offer_decline'] ); ?></div>
		</div>
	</div>
	<?php
}

/**
 * @param string               $kind Visual name.
 * @param array<string,string> $m    Mock copy.
 */
function lets_lp_visual( $kind, array $m ) {
	switch ( $kind ) {
		case 'phone':
			?>
			<div class="lp-phone">
				<div class="lp-phone__screen">
					<div class="lp-phone__notch"></div>
					<?php lets_lp_mock_thankyou( $m, true ); ?>
				</div>
			</div>
			<?php
			break;

		case 'flow':
			?>
			<div class="lp-flow">
				<div class="lp-flow__zoom"><span>100%</span><span>−</span><span>+</span></div>
				<div class="lp-flow__lane">
					<div class="lp-node lp-node--trigger">
						<div class="lp-node__kind"><i></i> טריגר</div>
						<div class="lp-node__title">לאחר שהלקוח משלים תשלום</div>
						<div class="lp-node__tags"><span>קולקציה: טיפוח</span><span>ערך הזמנה מעל ₪150</span></div>
					</div>
					<div class="lp-flow__link"></div>
					<div class="lp-node lp-node--offer">
						<div class="lp-node__kind"><i></i> הצעה</div>
						<div class="lp-node__product"><i></i><span><b>ערכת טיפוח לנסיעות</b><span>₪49 → ₪39</span></span></div>
						<div class="lp-node__outs"><div><span class="is-yes">אישור</span> ← הצעה נוספת</div><div><span class="is-no">דחייה</span> ← סיום תהליך</div></div>
					</div>
					<div class="lp-flow__link"></div>
					<div class="lp-node lp-node--offer">
						<div class="lp-node__kind"><i></i> הצעה</div>
						<div class="lp-node__product"><i></i><span><b>מנוי חודשי לסרום</b><span>₪159 / חודש · 15% הנחה</span></span></div>
						<div class="lp-node__outs"><div><span class="is-yes">אישור</span> ← סיום תהליך</div><div><span class="is-no">דחייה</span> ← סיום תהליך</div></div>
					</div>
				</div>
			</div>
			<?php
			break;

		case 'ledger':
			?>
			<div class="lp-ledger">
				<div class="lp-ledger__head">
					<span class="lp-ledger__title">חיוב הצעה · הזמנה #1043</span>
					<span class="lp-badge lp-badge--ok">החיוב הצליח</span>
				</div>
				<div class="lp-ledger__amount">₪39.00</div>
				<div class="lp-ledger__meta"><span>Visa •••• 4242</span><span>אישור 0483921</span><span>PayPlus · טוקן שמור</span></div>
				<ul class="lp-timeline">
					<li><b>הלקוח אישר את ההצעה</b><span>עמוד תודה · 14:32:05 · הסכמה לחיוב נוסף נרשמה</span></li>
					<li><b>החיוב עבר על הטוקן השמור</b><span>PayPlus · 14:32:06 · מפתח ייחודי: upsell:1042:7</span></li>
					<li class="is-order"><b>הזמנה #1043 נוצרה בחנות</b><span>מקושרת ל-#1042 · מסומנת כשולמה · המלאי עודכן</span></li>
					<li class="is-doc"><b>חשבונית מס/קבלה 20261 הופקה</b><span>נשלחה ללקוח במייל · מופיעה באזור האישי</span></li>
				</ul>
			</div>
			<?php
			break;

		case 'analytics':
			?>
			<div class="lp-analytics">
				<div class="lp-kpis">
					<div class="lp-kpi"><div class="lp-kpi__label">הכנסה מהצעות</div><div class="lp-kpi__value">₪5,916</div><div class="lp-kpi__delta">+18% מהתקופה הקודמת</div></div>
					<div class="lp-kpi"><div class="lp-kpi__label">שיעור המרה</div><div class="lp-kpi__value">29.0%</div><div class="lp-kpi__delta">424 חשיפות · 123 קבלות</div></div>
					<div class="lp-kpi"><div class="lp-kpi__label">הצלחת חיוב</div><div class="lp-kpi__value">91.1%</div><div class="lp-kpi__delta">112 הזמנות נוספות</div></div>
					<div class="lp-kpi"><div class="lp-kpi__label">ערך ממוצע להצעה</div><div class="lp-kpi__value">₪52.8</div><div class="lp-kpi__delta">יום שיא ₪433</div></div>
				</div>
				<div class="lp-chart">
					<div class="lp-chart__head"><b>הכנסה לאורך זמן</b><span class="lp-muted">30 הימים האחרונים</span></div>
					<svg viewBox="0 0 600 160" aria-hidden="true">
						<defs><linearGradient id="lpg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e2ff78" stop-opacity=".9"/><stop offset="1" stop-color="#e2ff78" stop-opacity="0"/></linearGradient></defs>
						<path d="M0 120 L40 92 L80 104 L120 70 L160 84 L200 60 L240 78 L280 44 L320 66 L360 30 L400 58 L440 40 L480 52 L520 22 L560 38 L600 26 L600 160 L0 160 Z" fill="url(#lpg)"/>
						<path d="M0 120 L40 92 L80 104 L120 70 L160 84 L200 60 L240 78 L280 44 L320 66 L360 30 L400 58 L440 40 L480 52 L520 22 L560 38 L600 26" fill="none" stroke="#000" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
						<circle cx="520" cy="22" r="5" fill="#000"/><circle cx="520" cy="22" r="2" fill="#e2ff78"/>
					</svg>
					<ul class="lp-funnel">
						<li><span>חשיפות</span><i></i><em>424</em></li>
						<li><span>קבלות</span><i></i><em>123</em></li>
						<li><span>חיובים שהצליחו</span><i></i><em>112</em></li>
						<li><span>הזמנות נוספות</span><i></i><em>112</em></li>
					</ul>
				</div>
			</div>
			<?php
			break;
	}
}

$lets_lp = lets_lp_post_purchase();
$m       = $lets_lp['mock'];

get_header();
?>
<main id="content" class="site-main lp" dir="rtl" lang="he">

	<!-- Hero -->
	<section class="lp-hero">
		<div class="lp-wrap">
			<div class="lp-hero__grid">
				<div class="lp-hero__text lp-reveal">
					<div class="lp-eyebrow"><?php echo esc_html( $lets_lp['hero']['eyebrow'] ); ?></div>
					<h1 class="lp-h1"><?php echo esc_html( $lets_lp['hero']['title'] ); ?></h1>
					<p class="lp-lead" style="margin-block-start:28px"><?php echo esc_html( $lets_lp['hero']['lead'] ); ?></p>
					<div class="lp-actions">
						<?php lets_lp_button( $lets_lp['cta_primary'] ); ?>
						<?php lets_lp_button( $lets_lp['cta_secondary'], 'lp-btn--ghost' ); ?>
					</div>
					<p class="lp-note"><?php echo esc_html( $lets_lp['hero']['note'] ); ?></p>
				</div>
				<div class="lp-hero__visual lp-reveal lp-reveal--2">
					<div class="lp-panel" data-sales-image="hero">
						<div class="lp-browser">
							<div class="lp-browser__bar"><span class="lp-browser__dots"><i></i><i></i><i></i></span><span class="lp-browser__url">store.co.il/checkout/thank-you</span></div>
							<div class="lp-browser__body"><?php lets_lp_mock_thankyou( $m ); ?></div>
						</div>
						<div class="lp-toast">
							<span class="lp-toast__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
							<span><b><?php echo esc_html( $m['toast_title'] ); ?></b><span><?php echo esc_html( $m['toast_sub'] ); ?></span></span>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>

	<!-- How it works -->
	<section class="lp-section lp-section--tight">
		<div class="lp-wrap">
			<div class="lp-reveal">
				<div class="lp-eyebrow lp-eyebrow--he"><?php echo esc_html( $lets_lp['how']['eyebrow'] ); ?></div>
				<h2 class="lp-h2"><?php echo esc_html( $lets_lp['how']['title'] ); ?></h2>
			</div>
			<div class="lp-steps">
				<?php foreach ( $lets_lp['how']['steps'] as $i => $step ) : ?>
					<div class="lp-step lp-reveal <?php echo $i ? 'lp-reveal--' . ( $i + 1 ) : ''; ?>">
						<span class="lp-step__num">0<?php echo (int) $i + 1; ?></span>
						<h3 class="lp-step__title"><?php echo esc_html( $step[0] ); ?></h3>
						<p class="lp-step__body"><?php echo esc_html( $step[1] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<!-- Features -->
	<?php foreach ( $lets_lp['features'] as $i => $f ) : ?>
		<section class="lp-feature <?php echo $i % 2 ? 'lp-feature--flip' : ''; ?>">
			<div class="lp-wrap">
				<div class="lp-feature__grid">
					<div class="lp-feature__text lp-reveal">
						<div class="lp-eyebrow lp-eyebrow--he"><?php echo esc_html( $f['eyebrow'] ); ?></div>
						<h2 class="lp-h2"><?php echo esc_html( $f['title'] ); ?></h2>
						<p class="lp-feature__body"><?php echo esc_html( $f['body'] ); ?></p>
						<?php if ( ! empty( $f['checks'] ) ) : ?>
							<ul class="lp-checks">
								<?php foreach ( $f['checks'] as $check ) : ?>
									<li><?php echo esc_html( $check ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php if ( ! empty( $f['chips'] ) ) : ?>
							<p class="lp-muted" style="margin-block-start:22px;font-size:16px;font-weight:700"><?php echo esc_html( $f['chips_title'] ); ?></p>
							<div class="lp-chips" style="margin-block-start:10px">
								<?php foreach ( $f['chips'] as $j => $chip ) : ?>
									<span class="lp-chip <?php echo 0 === $j ? 'lp-chip--on' : ''; ?>"><?php echo esc_html( $chip ); ?></span>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
					<div class="lp-reveal lp-reveal--2">
						<div class="lp-panel <?php echo $i % 2 ? '' : 'lp-panel--dark'; ?>" data-sales-image="<?php echo esc_attr( $f['visual'] ); ?>">
							<span class="lp-panel__label"><?php echo esc_html( $f['label'] ); ?></span>
							<div style="margin-block-start:28px"><?php lets_lp_visual( $f['visual'], $m ); ?></div>
						</div>
					</div>
				</div>
			</div>
		</section>
	<?php endforeach; ?>

	<!-- More -->
	<section class="lp-section">
		<div class="lp-wrap">
			<div class="lp-reveal">
				<div class="lp-eyebrow lp-eyebrow--he"><?php echo esc_html( $lets_lp['more']['eyebrow'] ); ?></div>
				<h2 class="lp-h2"><?php echo esc_html( $lets_lp['more']['title'] ); ?></h2>
			</div>
			<div class="lp-cards">
				<?php foreach ( $lets_lp['more']['cards'] as $i => $card ) : ?>
					<div class="lp-card lp-reveal <?php echo $i ? 'lp-reveal--' . min( 3, $i + 1 ) : ''; ?>">
						<div class="lp-card__icon"><?php lets_lp_icon( $card[0] ); ?></div>
						<h3 class="lp-card__title"><?php echo esc_html( $card[1] ); ?></h3>
						<p class="lp-card__body"><?php echo esc_html( $card[2] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<!-- Platforms -->
	<section class="lp-section lp-section--tight">
		<div class="lp-wrap">
			<div class="lp-reveal">
				<div class="lp-eyebrow lp-eyebrow--he"><?php echo esc_html( $lets_lp['platforms']['eyebrow'] ); ?></div>
				<h2 class="lp-h2"><?php echo esc_html( $lets_lp['platforms']['title'] ); ?></h2>
			</div>
			<div class="lp-platforms">
				<?php foreach ( array( 'shopify' => 'Shopify', 'woo' => 'WooCommerce' ) as $key => $name ) : ?>
					<?php $p = $lets_lp['platforms'][ $key ]; ?>
					<div class="lp-platform <?php echo 'shopify' === $key ? 'lp-platform--dark lp-reveal' : 'lp-reveal lp-reveal--2'; ?>">
						<div class="lp-platform__logo"><?php echo esc_html( $name ); ?></div>
						<h3 class="lp-h3"><?php echo esc_html( $p['title'] ); ?></h3>
						<p class="lp-platform__body"><?php echo esc_html( $p['body'] ); ?></p>
						<ul class="lp-checks">
							<?php foreach ( $p['checks'] as $check ) : ?>
								<li><?php echo esc_html( $check ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<!-- FAQ -->
	<section class="lp-section">
		<div class="lp-wrap">
			<div class="lp-faq">
				<div class="lp-reveal">
					<div class="lp-eyebrow lp-eyebrow--he"><?php echo esc_html( $lets_lp['faq']['eyebrow'] ); ?></div>
					<h2 class="lp-h2"><?php echo esc_html( $lets_lp['faq']['title'] ); ?></h2>
				</div>
				<div class="lp-faq__list lp-reveal lp-reveal--2">
					<?php foreach ( $lets_lp['faq']['items'] as $i => $q ) : ?>
						<details <?php echo 0 === $i ? 'open' : ''; ?>>
							<summary><?php echo esc_html( $q[0] ); ?></summary>
							<div class="lp-faq__a"><?php echo esc_html( $q[1] ); ?></div>
						</details>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</section>

	<!-- CTA -->
	<section class="lp-section lp-section--tight" id="contact" style="padding-block-start:0">
		<div class="lp-wrap">
			<div class="lp-cta lp-reveal">
				<div>
					<h2 class="lp-h2"><?php echo esc_html( $lets_lp['cta']['title'] ); ?></h2>
					<p class="lp-lead" style="margin-block-start:14px"><?php echo esc_html( $lets_lp['cta']['lead'] ); ?></p>
					<div class="lp-actions">
						<?php lets_lp_button( $lets_lp['cta_primary'], 'lp-btn--dark' ); ?>
						<a class="lp-btn lp-btn--ghost lp-latin" href="mailto:<?php echo esc_attr( $lets_lp['contact_email'] ); ?>"><?php echo esc_html( $lets_lp['contact_email'] ); ?></a>
					</div>
				</div>
				<div class="lp-cta__dot" aria-hidden="true"></div>
			</div>
		</div>
	</section>

</main>
<?php
get_footer();
