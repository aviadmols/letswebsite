<?php
/**
 * LETS AI Bridge — bootstrap.
 *
 * Generates API tokens so Claude / Cursor can work with the site database via
 * REST and a lightweight MCP JSON-RPC endpoint. Every token can read; file
 * writes, post meta / option edits and raw SQL writes are granted per token on
 * the admin page (Tools -> AI Bridge).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-lets-ai-tokens.php';
require_once __DIR__ . '/class-lets-ai-sql.php';
require_once __DIR__ . '/class-lets-ai-rest.php';
require_once __DIR__ . '/class-lets-ai-db-writer.php';
require_once __DIR__ . '/class-lets-ai-admin.php';

Lets_AI_REST::init();
Lets_AI_Admin::init();
