<?php
/**
 * Let's SEO — HTML to Markdown.
 *
 * Built for rendered WordPress / Elementor output: layout wrappers collapse
 * into paragraphs, and scripts, forms, navigation and decorative SVGs are
 * dropped, so an AI agent gets the article and nothing else.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_SEO_Markdown {

	/** Elements whose contents are never part of the article. */
	const SKIP = array( 'script', 'style', 'noscript', 'svg', 'form', 'button', 'input', 'select', 'textarea', 'nav', 'template', 'canvas', 'dialog' );

	/** Elements that start a new block. */
	const BLOCKS = array( 'p', 'div', 'section', 'article', 'main', 'aside', 'header', 'footer', 'figure', 'figcaption', 'details', 'summary', 'address', 'dl', 'dt', 'dd' );

	/**
	 * @param string $html HTML.
	 * @return string Markdown.
	 */
	public static function convert( $html ) {
		$html = trim( (string) $html );

		if ( '' === $html ) {
			return '';
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="lets-md-root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $doc->getElementById( 'lets-md-root' );
		$md   = $root ? self::children( $root, 0 ) : '';

		// Collapse the spaces and blank lines that nested wrappers leave behind.
		$md = preg_replace( '/(?<=\S) {2,}(?=\S)/u', ' ', $md );
		$md = preg_replace( '/ +([.,;:!?])/u', '$1', $md );
		$md = preg_replace( "/[ \t]+\n/", "\n", $md );
		$md = preg_replace( "/\n{3,}/", "\n\n", $md );

		return trim( $md ) . "\n";
	}

	/**
	 * @param DOMNode $node  Parent.
	 * @param int     $depth List nesting depth.
	 * @return string
	 */
	protected static function children( $node, $depth ) {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= self::node( $child, $depth );
		}
		return $out;
	}

	/**
	 * @param DOMNode $node  Node.
	 * @param int     $depth List nesting depth.
	 * @return string
	 */
	protected static function node( $node, $depth ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return self::escape( preg_replace( '/\s+/u', ' ', $node->nodeValue ) );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		/** @var DOMElement $node */
		$tag = strtolower( $node->nodeName );

		if ( in_array( $tag, self::SKIP, true ) || self::is_hidden( $node ) ) {
			return '';
		}

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$text = self::inline( $node );
				return '' === $text ? '' : "\n\n" . str_repeat( '#', (int) $tag[1] ) . ' ' . $text . "\n\n";

			case 'br':
				return "\n";

			case 'hr':
				return "\n\n---\n\n";

			case 'strong':
			case 'b':
				return self::wrap_inline( self::children( $node, $depth ), '**' );

			case 'em':
			case 'i':
				return self::wrap_inline( self::children( $node, $depth ), '_' );

			case 'code':
				if ( $node->parentNode && 'pre' === strtolower( $node->parentNode->nodeName ) ) {
					return $node->textContent;
				}
				return '`' . str_replace( '`', '\`', $node->textContent ) . '`';

			case 'pre':
				return "\n\n```\n" . rtrim( $node->textContent ) . "\n```\n\n";

			case 'a':
				return self::link( $node, $depth );

			case 'img':
				return self::image( $node );

			case 'picture':
				$img = $node->getElementsByTagName( 'img' )->item( 0 );
				return $img ? self::image( $img ) : '';

			case 'iframe':
			case 'video':
				$src = $node->getAttribute( 'src' );
				if ( '' === $src && 'video' === $tag ) {
					$source = $node->getElementsByTagName( 'source' )->item( 0 );
					$src    = $source ? $source->getAttribute( 'src' ) : '';
				}
				$title = trim( $node->getAttribute( 'title' ) );
				return '' === $src ? '' : "\n\n[" . ( '' !== $title ? $title : 'וידאו' ) . '](' . self::url( $src ) . ")\n\n";

			case 'ul':
			case 'ol':
				return self::list_block( $node, 'ol' === $tag, $depth );

			case 'blockquote':
				$text  = trim( self::children( $node, $depth ) );
				$lines = explode( "\n", $text );
				return "\n\n> " . implode( "\n> ", $lines ) . "\n\n";

			case 'table':
				return self::table( $node );

			default:
				$inner = self::children( $node, $depth );
				return in_array( $tag, self::BLOCKS, true ) ? "\n\n" . trim( $inner ) . "\n\n" : $inner;
		}
	}

	/**
	 * @param DOMElement $node  Link.
	 * @param int        $depth List depth.
	 * @return string
	 */
	protected static function link( $node, $depth ) {
		$href  = trim( $node->getAttribute( 'href' ) );
		$inner = preg_replace( '/\s+/u', ' ', self::children( $node, $depth ) );
		$text  = trim( $inner );

		if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'javascript:' ) ) {
			return $inner;
		}
		if ( '' === $text ) {
			$text = trim( $node->getAttribute( 'aria-label' ) );
		}
		if ( '' === $text ) {
			return '';
		}

		return self::outer_space( $inner, 'lead' ) . '[' . $text . '](' . self::url( $href ) . ')' . self::outer_space( $inner, 'trail' );
	}

	/**
	 * Wrap inline text in a marker, keeping the spaces around it outside:
	 * "** bold **" is not bold in Markdown.
	 *
	 * @param string $inner  Converted contents.
	 * @param string $marker ** or _.
	 * @return string
	 */
	protected static function wrap_inline( $inner, $marker ) {
		$text = trim( $inner );

		if ( '' === $text ) {
			return $inner;
		}

		return self::outer_space( $inner, 'lead' ) . $marker . $text . $marker . self::outer_space( $inner, 'trail' );
	}

	/**
	 * @param string $text Text.
	 * @param string $side 'lead' or 'trail'.
	 * @return string ' ' when that side of the text is whitespace.
	 */
	protected static function outer_space( $text, $side ) {
		return preg_match( 'lead' === $side ? '/^\s/u' : '/\s$/u', $text ) ? ' ' : '';
	}

	/**
	 * @param DOMElement $node Image.
	 * @return string
	 */
	protected static function image( $node ) {
		$src = $node->getAttribute( 'src' );

		// Lazy-loaded images keep the real source in a data attribute.
		foreach ( array( 'data-src', 'data-lazy-src', 'data-original' ) as $attr ) {
			if ( $node->hasAttribute( $attr ) && ( '' === $src || 0 === strpos( $src, 'data:' ) ) ) {
				$src = $node->getAttribute( $attr );
			}
		}

		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return '';
		}

		$alt = trim( $node->getAttribute( 'alt' ) );

		return "\n\n![" . str_replace( array( '[', ']' ), '', $alt ) . '](' . self::url( $src ) . ")\n\n";
	}

	/**
	 * @param DOMElement $node    List.
	 * @param bool       $ordered Numbered list.
	 * @param int        $depth   Nesting depth.
	 * @return string
	 */
	protected static function list_block( $node, $ordered, $depth ) {
		$out    = "\n";
		$number = 1;

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}

			$text   = trim( self::children( $child, $depth + 1 ) );
			$text   = preg_replace( "/\n{2,}/", "\n", $text );
			$marker = $ordered ? $number++ . '.' : '-';
			// Continuation lines (and nested lists) line up under the item's text.
			$out .= $marker . ' ' . str_replace( "\n", "\n" . str_repeat( ' ', strlen( $marker ) + 1 ), $text ) . "\n";
		}

		return 0 === $depth ? "\n" . $out . "\n" : $out;
	}

	/**
	 * @param DOMElement $node Table.
	 * @return string
	 */
	protected static function table( $node ) {
		$rows = array();

		foreach ( $node->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = array();
			foreach ( $tr->childNodes as $cell ) {
				if ( XML_ELEMENT_NODE === $cell->nodeType && in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
					$cells[] = str_replace( '|', '\|', trim( preg_replace( '/\s+/u', ' ', self::inline( $cell ) ) ) );
				}
			}
			if ( $cells ) {
				$rows[] = $cells;
			}
		}

		if ( ! $rows ) {
			return '';
		}

		$width = max( array_map( 'count', $rows ) );
		$out   = '';

		foreach ( $rows as $i => $cells ) {
			$cells = array_pad( $cells, $width, '' );
			$out  .= '| ' . implode( ' | ', $cells ) . " |\n";
			if ( 0 === $i ) {
				$out .= '|' . str_repeat( ' --- |', $width ) . "\n";
			}
		}

		return "\n\n" . $out . "\n";
	}

	/**
	 * Contents of a node on a single line.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	protected static function inline( $node ) {
		return trim( preg_replace( '/\s+/u', ' ', self::children( $node, 0 ) ) );
	}

	/**
	 * Elements hidden from everyone (Elementor's per-device hiding is left alone —
	 * that content is still real, just on another screen size).
	 *
	 * @param DOMElement $node Element.
	 * @return bool
	 */
	protected static function is_hidden( $node ) {
		if ( $node->hasAttribute( 'hidden' ) || 'true' === $node->getAttribute( 'aria-hidden' ) ) {
			return true;
		}
		return (bool) preg_match( '/display\s*:\s*none/i', $node->getAttribute( 'style' ) );
	}

	/**
	 * @param string $url URL, possibly relative.
	 * @return string
	 */
	protected static function url( $url ) {
		$url = trim( $url );

		if ( 0 === strpos( $url, '//' ) ) {
			return 'https:' . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return home_url( $url );
		}

		return str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), $url );
	}

	/**
	 * Escape characters that would turn plain text into Markdown syntax.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected static function escape( $text ) {
		return preg_replace( '/([\\\\`*_\[\]])/', '\\\\$1', $text );
	}
}
