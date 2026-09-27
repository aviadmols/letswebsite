<?php
/**
 * Let's SEO — one JSON-LD @graph per page.
 *
 * Nodes point at each other by @id (…#organization, …#website, …#webpage,
 * …#article, …#breadcrumb), which is how Google and AI crawlers tie the
 * article to its page, author, publisher and site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_SEO_Schema {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 2 );
	}

	public static function output() {
		$graph = self::graph();

		if ( empty( $graph ) ) {
			return;
		}

		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
		);

		echo "<script type=\"application/ld+json\" class=\"lets-seo-schema\">{$json}</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with < and > hex-escaped.
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function graph() {
		$ctx = Lets_SEO_Context::current();

		if ( '404' === $ctx['kind'] ) {
			return array();
		}

		$home  = home_url( '/' );
		$url   = '' !== $ctx['canonical'] ? $ctx['canonical'] : $home;
		$graph = array( self::publisher(), self::website() );

		$webpage = array(
			'@type'      => $ctx['page_type'],
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => $ctx['title'],
			'isPartOf'   => array( '@id' => $home . '#website' ),
			'inLanguage' => self::language(),
			'breadcrumb' => array( '@id' => $url . '#breadcrumb' ),
		);

		if ( '' !== $ctx['description'] ) {
			$webpage['description'] = $ctx['description'];
		}
		if ( in_array( $ctx['kind'], array( 'home', 'front' ), true ) ) {
			$webpage['about'] = array( '@id' => $home . '#organization' );
		}

		if ( $ctx['image'] ) {
			$graph[]                        = self::image_node( $url . '#primaryimage', $ctx['image'] );
			$webpage['primaryImageOfPage'] = array( '@id' => $url . '#primaryimage' );
			$webpage['image']              = array( '@id' => $url . '#primaryimage' );
		}

		$post = $ctx['post'];

		if ( $post instanceof WP_Post ) {
			$webpage['datePublished'] = get_post_time( 'c', true, $post );
			$webpage['dateModified']  = get_post_modified_time( 'c', true, $post );
			$webpage['potentialAction'] = array(
				array(
					'@type'  => 'ReadAction',
					'target' => array( $url ),
				),
			);

			$faq = self::faq( $post );
			if ( $faq ) {
				$webpage['mainEntity'] = $faq;
			}
		}

		$author = null;
		if ( 'author' === $ctx['kind'] && get_queried_object() instanceof WP_User ) {
			$author                = self::person( get_queried_object()->ID );
			$webpage['mainEntity'] = array( '@id' => $author['@id'] );
		}

		$graph[] = $webpage;
		$graph[] = self::breadcrumb( $url );

		if ( $author ) {
			$graph[] = $author;
		}

		if ( $post instanceof WP_Post ) {
			if ( 'article' === $ctx['og_type'] ) {
				$graph[] = self::article( $post, $url, $ctx );
				$graph[] = self::person( (int) $post->post_author );
			} elseif ( 'product' === $ctx['og_type'] ) {
				$product = self::product( $post, $url, $ctx );
				if ( $product ) {
					$graph[] = $product;
				}
			}
		}

		/**
		 * Adjust the JSON-LD graph before it is printed.
		 *
		 * @param array $graph Nodes.
		 * @param array $ctx   SEO context.
		 */
		return apply_filters( 'lets_seo_schema_graph', $graph, $ctx );
	}

	/**
	 * @return array<string,mixed>
	 */
	protected static function publisher() {
		$home = home_url( '/' );
		$type = 'Person' === Lets_SEO::get( 'org_type' ) ? 'Person' : 'Organization';
		$node = array(
			'@type' => $type,
			'@id'   => $home . '#organization',
			'name'  => Lets_SEO::org_name(),
			'url'   => $home,
		);

		$description = trim( (string) Lets_SEO::get( 'org_description' ) );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}

		$logo = Lets_SEO::image( (int) Lets_SEO::get( 'org_logo' ) );
		if ( $logo ) {
			$logo_node = self::image_node( $home . '#logo', $logo );
			$logo_node['caption'] = Lets_SEO::org_name();

			if ( 'Person' === $type ) {
				$node['image'] = $logo_node;
			} else {
				$node['logo']  = $logo_node;
				$node['image'] = array( '@id' => $home . '#logo' );
			}
		}

		$same_as = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) Lets_SEO::get( 'org_same_as' ) ) ), 'wp_http_validate_url' ) );
		if ( $same_as ) {
			$node['sameAs'] = $same_as;
		}

		$email = trim( (string) Lets_SEO::get( 'org_email' ) );
		$phone = trim( (string) Lets_SEO::get( 'org_phone' ) );

		if ( 'Organization' === $type && ( '' !== $email || '' !== $phone ) ) {
			$contact = array(
				'@type'       => 'ContactPoint',
				'contactType' => 'customer service',
			);
			if ( '' !== $email ) {
				$contact['email'] = $email;
			}
			if ( '' !== $phone ) {
				$contact['telephone'] = $phone;
			}
			$contact['availableLanguage'] = array( 'Hebrew', 'English' );
			$node['contactPoint']         = $contact;
		}

		return $node;
	}

	/**
	 * @return array<string,mixed>
	 */
	protected static function website() {
		$home = home_url( '/' );

		return array(
			'@type'           => 'WebSite',
			'@id'             => $home . '#website',
			'url'             => $home,
			'name'            => Lets_SEO::site_name(),
			'description'     => Lets_SEO::decode( get_bloginfo( 'description' ) ),
			'publisher'       => array( '@id' => $home . '#organization' ),
			'inLanguage'      => self::language(),
			'potentialAction' => array(
				array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => home_url( '/?s={search_term_string}' ),
					),
					'query-input' => array(
						'@type'         => 'PropertyValueSpecification',
						'valueRequired' => true,
						'valueName'     => 'search_term_string',
					),
				),
			),
		);
	}

	/**
	 * @param WP_Post             $post Post.
	 * @param string              $url  Canonical URL.
	 * @param array<string,mixed> $ctx  Context.
	 * @return array<string,mixed>
	 */
	protected static function article( $post, $url, array $ctx ) {
		$home = home_url( '/' );
		$text = Lets_SEO::plain_text( Lets_SEO::rendered_content( $post ) );
		$type = (string) Lets_SEO::post_meta( $post->ID, 'schema_type' );
		$type = in_array( $type, array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'HowTo' ), true ) ? $type : 'BlogPosting';

		$node = array(
			'@type'            => $type,
			'@id'              => $url . '#article',
			'isPartOf'         => array( '@id' => $url . '#webpage' ),
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'headline'         => mb_substr( Lets_SEO::decode( get_the_title( $post ) ), 0, 110 ),
			'datePublished'    => get_post_time( 'c', true, $post ),
			'dateModified'     => get_post_modified_time( 'c', true, $post ),
			'author'           => array( '@id' => self::person_id( (int) $post->post_author ) ),
			'publisher'        => array( '@id' => $home . '#organization' ),
			'wordCount'        => Lets_SEO::word_count( $text ),
			'timeRequired'     => 'PT' . Lets_SEO::reading_minutes( Lets_SEO::word_count( $text ) ) . 'M',
			'inLanguage'       => self::language(),
		);

		if ( '' !== $ctx['description'] ) {
			$node['description'] = $ctx['description'];
		}

		// A short answer-first summary; AI answer engines quote this directly.
		$summary = trim( (string) Lets_SEO::post_meta( $post->ID, 'ai_summary' ) );
		if ( '' !== $summary ) {
			$node['abstract'] = $summary;
		}

		if ( $ctx['image'] ) {
			$node['image']        = array( '@id' => $url . '#primaryimage' );
			$node['thumbnailUrl'] = $ctx['image']['url'];
		}

		$section = Lets_SEO_Context::primary_term( $post );
		if ( $section ) {
			$node['articleSection'] = array( Lets_SEO::decode( $section->name ) );
		}

		$keywords = array();
		$focus    = trim( (string) Lets_SEO::post_meta( $post->ID, 'focus_keyword' ) );
		if ( '' !== $focus ) {
			$keywords[] = $focus;
		}
		$post_tags = get_the_tags( $post->ID );
		if ( is_array( $post_tags ) ) {
			foreach ( $post_tags as $tag ) {
				$keywords[] = Lets_SEO::decode( $tag->name );
			}
		}
		if ( $keywords ) {
			$node['keywords'] = array_values( array_unique( $keywords ) );
		}

		if ( comments_open( $post ) || get_comments_number( $post ) ) {
			$node['commentCount'] = (int) get_comments_number( $post );
		}

		return $node;
	}

	/**
	 * @param int $user_id User ID.
	 * @return string
	 */
	protected static function person_id( $user_id ) {
		return home_url( '/' ) . '#/schema/person/' . md5( 'lets-seo-' . $user_id );
	}

	/**
	 * @param int $user_id User ID.
	 * @return array<string,mixed>
	 */
	protected static function person( $user_id ) {
		$node = array(
			'@type' => 'Person',
			'@id'   => self::person_id( $user_id ),
			'name'  => get_the_author_meta( 'display_name', $user_id ),
			'url'   => get_author_posts_url( $user_id ),
		);

		$bio = trim( (string) get_the_author_meta( 'description', $user_id ) );
		if ( '' !== $bio ) {
			$node['description'] = $bio;
		}

		$avatar = get_avatar_url( $user_id, array( 'size' => 256 ) );
		if ( $avatar ) {
			$node['image'] = array(
				'@type'   => 'ImageObject',
				'url'     => $avatar,
				'caption' => $node['name'],
			);
		}

		$site = trim( (string) get_the_author_meta( 'user_url', $user_id ) );
		if ( '' !== $site ) {
			$node['sameAs'] = array( $site );
		}

		return $node;
	}

	/**
	 * @param WP_Post $post Post.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function faq( $post ) {
		$items = Lets_SEO::post_meta( $post->ID, 'faq' );

		if ( ! is_array( $items ) ) {
			return array();
		}

		$out = array();

		foreach ( $items as $item ) {
			$q = isset( $item['q'] ) ? trim( (string) $item['q'] ) : '';
			$a = isset( $item['a'] ) ? trim( (string) $item['a'] ) : '';

			if ( '' === $q || '' === $a ) {
				continue;
			}

			$out[] = array(
				'@type'          => 'Question',
				'name'           => $q,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => wpautop( $a ),
				),
			);
		}

		return $out;
	}

	/**
	 * Product with its offer. Only when WooCommerce is active — ready for when the store opens.
	 *
	 * @param WP_Post             $post Post.
	 * @param string              $url  Canonical URL.
	 * @param array<string,mixed> $ctx  Context.
	 * @return array<string,mixed>|null
	 */
	protected static function product( $post, $url, array $ctx ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return null;
		}

		$home = home_url( '/' );
		$node = array(
			'@type'            => 'Product',
			'@id'              => $url . '#product',
			'name'             => Lets_SEO::decode( $product->get_name() ),
			'url'              => $url,
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'description'      => '' !== $ctx['description'] ? $ctx['description'] : Lets_SEO::cut( Lets_SEO::plain_text( $product->get_short_description() ), 300 ),
			'brand'            => array( '@id' => $home . '#organization' ),
		);

		if ( $product->get_sku() ) {
			$node['sku'] = $product->get_sku();
		}
		if ( $ctx['image'] ) {
			$node['image'] = array( '@id' => $url . '#primaryimage' );
		}

		$price = $product->get_price();

		if ( '' !== $price ) {
			$offer = array(
				'@type'           => 'Offer',
				'url'             => $url,
				'price'           => wc_format_decimal( $price, wc_get_price_decimals() ),
				'priceCurrency'   => get_woocommerce_currency(),
				'availability'    => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
				'itemCondition'   => 'https://schema.org/NewCondition',
				'seller'          => array( '@id' => $home . '#organization' ),
				'priceValidUntil' => gmdate( 'Y-12-31', strtotime( '+1 year' ) ),
			);

			if ( $product->is_on_sale() && $product->get_date_on_sale_to() ) {
				$offer['priceValidUntil'] = $product->get_date_on_sale_to()->date( 'Y-m-d' );
			}

			$node['offers'] = $offer;
		}

		if ( $product->get_review_count() > 0 ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => $product->get_average_rating(),
				'reviewCount' => $product->get_review_count(),
			);
		}

		return $node;
	}

	/**
	 * @param string $url Canonical URL.
	 * @return array<string,mixed>
	 */
	protected static function breadcrumb( $url ) {
		$items = array();

		foreach ( Lets_SEO_Breadcrumbs::trail() as $i => $crumb ) {
			$item = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $crumb['name'],
			);
			// The last crumb is the page itself; Google wants it without an item URL.
			if ( '' !== $crumb['url'] && $crumb['url'] !== $url ) {
				$item['item'] = $crumb['url'];
			}
			$items[] = $item;
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * @param string              $id    @id.
	 * @param array<string,mixed> $image Image details.
	 * @return array<string,mixed>
	 */
	protected static function image_node( $id, array $image ) {
		$node = array(
			'@type'      => 'ImageObject',
			'@id'        => $id,
			'url'        => $image['url'],
			'contentUrl' => $image['url'],
			'inLanguage' => self::language(),
		);

		if ( $image['width'] && $image['height'] ) {
			$node['width']  = $image['width'];
			$node['height'] = $image['height'];
		}
		if ( '' !== $image['alt'] ) {
			$node['caption'] = $image['alt'];
		}

		return $node;
	}

	/**
	 * @return string e.g. he-IL
	 */
	protected static function language() {
		return str_replace( '_', '-', get_locale() );
	}
}
