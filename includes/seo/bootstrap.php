<?php
/**
 * Let's SEO — bootstrap.
 *
 * A self-contained SEO module: per-post and per-term SEO fields, titles and
 * meta tags, Open Graph / Twitter cards, a JSON-LD graph, breadcrumbs,
 * sitemaps and robots.txt, an AI layer (llms.txt, a Markdown copy of every
 * post, rules for AI crawlers) and generated share images.
 *
 * While Yoast SEO (or Rank Math) is active, everything that would print a
 * second set of tags stays off — the fields, the import and the AI layer keep
 * working, so the switch is: import, check, deactivate Yoast.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LETS_SEO_VERSION', '1.0.0' );
define( 'LETS_SEO_DIR', __DIR__ );
define( 'LETS_SEO_URL', get_stylesheet_directory_uri() . '/includes/seo' );

require_once __DIR__ . '/class-lets-seo.php';
require_once __DIR__ . '/class-lets-seo-context.php';
require_once __DIR__ . '/class-lets-seo-breadcrumbs.php';
require_once __DIR__ . '/class-lets-seo-head.php';
require_once __DIR__ . '/class-lets-seo-schema.php';
require_once __DIR__ . '/class-lets-seo-sitemap.php';
require_once __DIR__ . '/class-lets-seo-markdown.php';
require_once __DIR__ . '/class-lets-seo-ai.php';
require_once __DIR__ . '/class-lets-seo-share-image.php';
require_once __DIR__ . '/class-lets-seo-meta.php';
require_once __DIR__ . '/class-lets-seo-settings.php';
require_once __DIR__ . '/class-lets-seo-import.php';

Lets_SEO::init();
