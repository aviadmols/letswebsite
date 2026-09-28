<?php
/**
 * Post-Purchase Upsell landing page — copy wiring.
 *
 * The text itself lives in post-purchase-copy.json (edit it there, or
 * import an improved version on the page's edit screen). This file registers
 * the page with the copy system and feeds the SEO module.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Page template this copy belongs to. */
const LETS_LP_POST_PURCHASE_TEMPLATE = 'templates/landing-post-purchase.php';

/** The same page with the dark hero; shares the copy. */
const LETS_LP_POST_PURCHASE_TEMPLATE_V2 = 'templates/landing-post-purchase-v2.php';

foreach ( array( LETS_LP_POST_PURCHASE_TEMPLATE, LETS_LP_POST_PURCHASE_TEMPLATE_V2 ) as $lets_lp_template ) {
	Lets_Landing_Copy::register(
		$lets_lp_template,
		__DIR__ . '/post-purchase-copy.json',
		array( 'visual' ) // which mockup a feature shows — structure, not text.
	);
}
unset( $lets_lp_template );

/**
 * @param int $post_id Page; 0 for the defaults.
 * @return array<string,mixed>
 */
function lets_lp_post_purchase( $post_id = 0 ) {
	return Lets_Landing_Copy::get( LETS_LP_POST_PURCHASE_TEMPLATE, (int) $post_id );
}

/**
 * @param WP_Post|int|null $post Post.
 * @return bool Whether the post uses this landing template.
 */
function lets_lp_is_post_purchase( $post ) {
	return in_array( get_page_template_slug( $post ), array( LETS_LP_POST_PURCHASE_TEMPLATE, LETS_LP_POST_PURCHASE_TEMPLATE_V2 ), true );
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

// The page has no post_content — give the SEO module the real text.
add_filter(
	'lets_seo_rendered_content',
	function ( $html, $post ) {
		return lets_lp_is_post_purchase( $post ) ? lets_lp_as_html( lets_lp_post_purchase( $post->ID ) ) : $html;
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

		$copy = lets_lp_post_purchase( get_queried_object_id() );

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
