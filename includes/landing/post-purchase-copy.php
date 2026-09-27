<?php
/**
 * Post-Purchase Upsell landing page — every word on the page, in one place.
 *
 * Edit text here. The markup lives in templates/landing-post-purchase.php and
 * the visuals in assets/landing/landing.css. Loaded from functions.php so the
 * SEO module can read the copy (Markdown/AI version, description, schema)
 * without rendering the template.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Page template this copy belongs to. */
const LETS_LP_POST_PURCHASE_TEMPLATE = 'templates/landing-post-purchase.php';

/**
 * @return array<string,mixed>
 */
function lets_lp_post_purchase() {
	return array(
		'cta_primary'   => array( 'label' => 'התחילו עכשיו', 'url' => 'https://app.lets.co.il' ),
		'cta_secondary' => array( 'label' => 'קבעו שיחת היכרות', 'url' => '#contact' ),
		'contact_email' => 'hey@lets.co.il',

		'hero' => array(
			'eyebrow' => 'Post-Purchase Upsell · Shopify & WooCommerce',
			'title'   => 'מכירה נוספת בקליק אחד, רגע אחרי התשלום.',
			'lead'    => 'הלקוח סיים לשלם והכרטיס שלו שמור. זה הרגע הכי חם בחנות — ו-LETS מציג בו הצעה שמתקבלת בלחיצה אחת, בלי להקליד פרטי כרטיס שוב. ההזמנה הראשונה לא נוגעים בה. ההכנסה עולה.',
			'note'    => 'עובד על חשבון ה-PayPlus שלכם · Shopify ו-WooCommerce · עברית ואנגלית',
		),

		'how' => array(
			'eyebrow' => 'איך זה עובד',
			'title'   => 'שלושה צעדים. אפס חיכוך.',
			'steps'   => array(
				array( 'הלקוח משלים תשלום', 'בקופה הרגילה של החנות. PayPlus שומר טוקן של הכרטיס — לא את מספר הכרטיס — ומכאן LETS יכול לחייב בלי לבקש אותו שוב.' ),
				array( 'בעמוד התודה מופיעה הצעה', 'מוצר משלים, שדרוג או מנוי — לפי מה שנקנה, הקולקציה, התגית או סכום ההזמנה. המחיר מחושב בשרת, לא בדפדפן.' ),
				array( 'קליק אחד, וזהו', 'החיוב עובר על הטוקן השמור, בחנות נוצרת הזמנה מקושרת, והמסמך החשבונאי יוצא אוטומטית. הלקוח רואה אישור. אתם רואים הכנסה.' ),
			),
		),

		'features' => array(
			array(
				'eyebrow' => 'הצעה בקליק אחד',
				'title'   => 'בלי קופה שנייה. בלי להקליד כרטיס.',
				'body'    => 'ההצעה מופיעה בעמוד התודה של Shopify או WooCommerce מיד אחרי התשלום. הלקוח לוחץ פעם אחת — והחיוב עובר על אמצעי התשלום שכבר שמור אצלכם ב-PayPlus. אין טופס, אין הסחת דעת, ואין הזמנה ראשונה שמתבטלת בדרך.',
				'checks'  => array(
					'מחיר ההצעה מחושב בשרת — אי אפשר לשנות אותו מהדפדפן',
					'הודעת הסכמה ברורה לפני כל חיוב נוסף',
					'לחיצה כפולה = חיוב אחד. תמיד.',
					'נכשל החיוב? ההזמנה המקורית לא מושפעת',
				),
				'visual'  => 'phone',
				'label'   => 'Thank-you page',
			),
			array(
				'eyebrow' => 'בונה תהליכים',
				'title'   => 'ההצעה הנכונה, ללקוח הנכון, אחרי המוצר הנכון.',
				'body'    => 'מגדירים מתי כל הצעה מוצגת ומה קורה אחרי כל תשובה. קיבל? מציגים הצעה נוספת או מסיימים. דחה? מציגים משהו אחר. הכול על קנבס ויזואלי, בלי קוד, וכשיש כמה תהליכים — סדר העדיפויות קובע איזה מהם הלקוח רואה.',
				'chips'   => array( 'כל מוצר שנרכש', 'מוצר ספציפי', 'קולקציה', 'תגית', 'ערך הזמנה מעל סכום' ),
				'chips_title' => 'טריגרים זמינים:',
				'visual'  => 'flow',
				'label'   => 'Flow builder',
			),
			array(
				'eyebrow' => 'חיוב ומסמכים',
				'title'   => 'הכסף דרך PayPlus. ההזמנה בחנות. החשבונית לבד.',
				'body'    => 'כל הצעה שהתקבלה יוצרת הזמנה נפרדת ומקושרת להזמנה המקורית, מסומנת כשולמה — כך המלאי, הליקוט והדוחות ממשיכים לעבוד כרגיל. החיוב נרשם ביומן התשלומים עם מספר האישור, והמסמך החשבונאי מופק אוטומטית דרך מערכת החשבוניות שלכם.',
				'checks'  => array(
					'על חשבון ה-PayPlus שלכם, לא על חשבון שלנו',
					'לא תלוי ב-Shopify Payments',
					'לכל חיוב מפתח ייחודי — אין חיוב כפול',
					'החזר, ביטול ומסמך זיכוי מאותו מסך',
				),
				'visual'  => 'ledger',
				'label'   => 'Payments',
			),
			array(
				'eyebrow' => 'ביצועים',
				'title'   => 'רואים בדיוק איזו הצעה מכניסה כסף.',
				'body'    => 'לוח הביצועים מציג את המשפך המלא לכל תהליך ולכל הצעה: חשיפות, קבלות, חיובים שהצליחו והכנסה. משכפלים את מה שעובד. עוצרים את מה שלא.',
				'checks'  => array(
					'הכנסה, שיעור המרה והצלחת חיוב לכל תקופה',
					'ערך ממוצע להצעה ויום השיא',
					'יומן פעילות — כל חשיפה, קבלה וחיוב',
				),
				'visual'  => 'analytics',
				'label'   => 'Performance',
			),
		),

		'more' => array(
			'eyebrow' => 'ועוד',
			'title'   => 'כלול. בלי תוספות.',
			'cards'   => array(
				array( 'design', 'עיצוב הכרטיס', 'צבע מבטא, פינות, תצוגה מקדימה חיה של הכרטיס האמיתי. ברירת המחדל מונוכרומטית ונקייה.' ),
				array( 'rtl', 'עברית ואנגלית', 'כל המסכים — למוכר וללקוח — בעברית מלאה עם RTL, או באנגלית. מחליפים בלחיצה.' ),
				array( 'status', 'גם בעמוד סטטוס ההזמנה', 'הלקוח חוזר לעקוב אחרי המשלוח? ההצעה מחכה לו שם, על אותו כרטיס שמור.' ),
				array( 'sub', 'הצעות למנוי', 'מציעים מעבר למנוי או תוספת למנוי קיים — "עברו למנוי חודשי" לצד "הוסיפו את הספל".' ),
			),
		),

		'platforms' => array(
			'eyebrow' => 'פלטפורמות',
			'title'   => 'Shopify או WooCommerce. אותו מנוע.',
			'shopify' => array(
				'title'  => 'אפליקציה ב-App Store',
				'body'   => 'אפליקציה ב-Shopify App Store. ההצעה מוצגת כבלוק בעמוד התודה ובעמוד סטטוס ההזמנה, בתוך ה-checkout של Shopify, והניהול כולו מתוך ממשק Shopify.',
				'checks' => array( 'התקנה בקליק מה-App Store', 'עובד בכל תוכנית של Shopify', 'בלי תלות ב-Shopify Payments' ),
			),
			'woo' => array(
				'title'  => 'תוסף לוורדפרס',
				'body'   => 'תוסף ל-WooCommerce שמתחבר ל-LETS בטוקן חד-פעמי. ההצעה מוצגת בעמוד התודה של החנות, ואם רוצים — כל הקופה עוברת דרך PayPlus.',
				'checks' => array( 'התקנה כתוסף רגיל', 'חיבור מאובטח, החתימה נשארת בשרת', 'אזור אישי ללקוח כלול' ),
			),
		),

		'faq' => array(
			'eyebrow' => 'שאלות נפוצות',
			'title'   => 'מה שבדרך כלל שואלים אותנו.',
			'items'   => array(
				array( 'האם צריך חשבון PayPlus?', 'כן. LETS עובד על חשבון ה-PayPlus שלכם. מזינים את פרטי החיבור פעם אחת בהגדרות, לוחצים "בדיקת חיבור" — וזהו. אין חשבון סליקה נוסף.' ),
				array( 'איך אפשר לחייב בלי שהלקוח מקליד כרטיס?', 'בקופה PayPlus שומר טוקן של הכרטיס — לא את מספר הכרטיס. כשהלקוח מאשר את ההצעה, LETS מחייב את הטוקן, אחרי הודעת הסכמה מפורשת. פרטי הכרטיס לא עוברים דרך החנות ולא דרכנו.' ),
				array( 'מה קורה אם הלקוח לוחץ פעמיים?', 'חיוב אחד. לכל הצעה יש מפתח ייחודי שמורכב מהחנות, מהתהליך, מההצעה ומההזמנה — הלחיצה השנייה מזהה שהחיוב כבר בדרך ולא מחייבת שוב.' ),
				array( 'ומה אם החיוב נכשל?', 'ההזמנה המקורית לא מושפעת. הלקוח רואה הודעה ברורה, לא נוצרת הזמנה נוספת, ואתם רואים את הניסיון ביומן התשלומים.' ),
				array( 'איך ההצעה נרשמת בחנות?', 'כהזמנה נפרדת שמקושרת להזמנה המקורית ומסומנת כשולמה. כך המלאי, הליקוט, המשלוח והדוחות עובדים בדיוק כמו בכל הזמנה אחרת. המסמך החשבונאי מופק אוטומטית.' ),
				array( 'זה עובד בכל תוכנית של Shopify?', 'כן. ההצעה מוצגת בעמוד התודה ובעמוד סטטוס ההזמנה, שזמינים בכל תוכנית. ומכיוון שהחיוב עובר דרך PayPlus, אין תלות ב-Shopify Payments.' ),
				array( 'אפשר להציע מנוי כהצעה?', 'כן. הצעה יכולה להיות מוצר חד-פעמי מהקטלוג או מנוי מאחת התבניות שלכם — למשל "עברו למנוי חודשי" לצד "הוסיפו את הספל".' ),
			),
		),

		'cta' => array(
			'title' => 'מוכנים להוסיף הכנסה לכל הזמנה?',
			'lead'  => 'נשמח להראות לכם את זה על החנות שלכם.',
		),

		// Text inside the drawn product visuals (illustrative data, not claims).
		'mock' => array(
			'ty_title'      => 'ההזמנה התקבלה. תודה, נועה!',
			'ty_sub'        => 'הזמנה #1042 · אישור נשלח למייל',
			'ty_item'       => 'סרום לחות · 50 מ״ל',
			'ty_shipping'   => 'משלוח',
			'ty_total'      => 'סה״כ שולם',
			'ty_hint'       => 'הכרטיס נשמר ב-PayPlus לחיוב ההצעה בלבד.',
			'offer_eyebrow' => 'הצעה חד-פעמית',
			'offer_title'   => 'השלימו את הסט',
			'offer_sub'     => 'משהו שמתאים בדיוק למה שקניתם — נוסף להזמנה שכבר ביצעתם.',
			'offer_name'    => 'ערכת טיפוח לנסיעות',
			'offer_was'     => '₪49',
			'offer_now'     => '₪39',
			'offer_save'    => 'חיסכון ₪10',
			'offer_btn'     => 'הוסיפו להזמנה שלי',
			'offer_consent' => 'החיוב לאמצעי התשלום השמור. בלי להקליד כרטיס מחדש.',
			'offer_decline' => 'לא תודה, סיימתי',
			'toast_title'   => 'נוסף להזמנה · ₪39 חויבו',
			'toast_sub'     => 'הזמנה #1043 נוצרה ומקושרת ל-#1042',
		),
	);
}

/**
 * Plain HTML version of the copy, for the SEO module (Markdown/AI copy,
 * description fallback, content analysis).
 *
 * @param array<string,mixed> $c Copy.
 * @return string
 */
function lets_lp_as_html( array $c ) {
	$h  = '<h1>' . esc_html( $c['hero']['title'] ) . '</h1><p>' . esc_html( $c['hero']['lead'] ) . '</p>';
	$h .= '<h2>' . esc_html( $c['how']['title'] ) . '</h2><ol>';
	foreach ( $c['how']['steps'] as $s ) {
		$h .= '<li><strong>' . esc_html( $s[0] ) . '</strong> — ' . esc_html( $s[1] ) . '</li>';
	}
	$h .= '</ol>';
	foreach ( $c['features'] as $f ) {
		$h .= '<h2>' . esc_html( $f['title'] ) . '</h2><p>' . esc_html( $f['body'] ) . '</p>';
		if ( ! empty( $f['checks'] ) ) {
			$h .= '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $f['checks'] ) ) . '</li></ul>';
		}
		if ( ! empty( $f['chips'] ) ) {
			$h .= '<p>' . esc_html( $f['chips_title'] ) . ' ' . esc_html( implode( ', ', $f['chips'] ) ) . '</p>';
		}
	}
	$h .= '<h2>' . esc_html( $c['more']['title'] ) . '</h2><ul>';
	foreach ( $c['more']['cards'] as $card ) {
		$h .= '<li><strong>' . esc_html( $card[1] ) . '</strong> — ' . esc_html( $card[2] ) . '</li>';
	}
	$h .= '</ul><h2>' . esc_html( $c['platforms']['title'] ) . '</h2>';
	foreach ( array( 'shopify' => 'Shopify', 'woo' => 'WooCommerce' ) as $key => $name ) {
		$h .= '<h3>' . $name . ' — ' . esc_html( $c['platforms'][ $key ]['title'] ) . '</h3><p>' . esc_html( $c['platforms'][ $key ]['body'] ) . '</p>';
		$h .= '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $c['platforms'][ $key ]['checks'] ) ) . '</li></ul>';
	}
	$h .= '<h2>' . esc_html( $c['faq']['title'] ) . '</h2>';
	foreach ( $c['faq']['items'] as $q ) {
		$h .= '<h3>' . esc_html( $q[0] ) . '</h3><p>' . esc_html( $q[1] ) . '</p>';
	}
	return $h;
}

/**
 * @param WP_Post|int|null $post Post.
 * @return bool Whether the post uses this landing template.
 */
function lets_lp_is_post_purchase( $post ) {
	return LETS_LP_POST_PURCHASE_TEMPLATE === get_page_template_slug( $post );
}

// The page has no post_content — give the SEO module the real text.
add_filter(
	'lets_seo_rendered_content',
	function ( $html, $post ) {
		return lets_lp_is_post_purchase( $post ) ? lets_lp_as_html( lets_lp_post_purchase() ) : $html;
	},
	10,
	2
);

// The FAQ on the page, as FAQPage schema, so nobody has to type it twice.
add_filter(
	'lets_seo_schema_graph',
	function ( $graph ) {
		if ( ! is_page() || ! lets_lp_is_post_purchase( get_queried_object_id() ) ) {
			return $graph;
		}

		$copy = lets_lp_post_purchase();

		foreach ( $graph as &$node ) {
			if ( isset( $node['@id'] ) && '#webpage' === substr( $node['@id'], -8 ) ) {
				$node['@type']      = array( 'WebPage', 'FAQPage' );
				$node['mainEntity'] = array_map(
					function ( $item ) {
						return array(
							'@type'          => 'Question',
							'name'           => $item[0],
							'acceptedAnswer' => array(
								'@type' => 'Answer',
								'text'  => $item[1],
							),
						);
					},
					$copy['faq']['items']
				);
			}
		}

		return $graph;
	}
);
