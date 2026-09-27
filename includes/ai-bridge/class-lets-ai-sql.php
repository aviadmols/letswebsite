<?php
/**
 * Raw SQL for LETS AI Bridge — read validation and undoable writes.
 *
 * Statements are never rewritten to be checked. Instead a "mask" of the
 * statement is built with the insides of string literals blanked out and
 * comments turned into spaces, at the same byte offsets as the original. All
 * checks run on the mask, so a keyword or a ';' inside a quoted value cannot
 * trip them, and a position found in the mask is the same position in the
 * real statement.
 *
 * Writes (db_execute) snapshot the rows they are about to touch, inside a
 * transaction, so db_revert can put them back. Schema changes (ALTER, DROP,
 * CREATE, TRUNCATE) are never allowed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_AI_SQL {

	/** Most rows a single UPDATE / DELETE / INSERT may touch. Keeps the undo snapshot bounded. */
	const MAX_ROWS = 500;

	/** Largest undo snapshot kept for one statement, in bytes (serialized). */
	const MAX_SNAPSHOT_BYTES = 4194304;

	/** Keywords refused anywhere in a write statement. */
	const WRITE_BLOCKED = '/\b(DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|CALL|LOAD_FILE|OUTFILE|DUMPFILE|HANDLER|RENAME|SLEEP|BENCHMARK|PREPARE|EXECUTE|DEALLOCATE|LOAD\s+DATA|LOAD\s+XML)\b/i';

	/** Keywords refused anywhere in a read statement. */
	const READ_BLOCKED = '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|GRANT|REVOKE|CALL|LOAD_FILE|OUTFILE|DUMPFILE|HANDLER|RENAME|SLEEP|BENCHMARK)\b/i';

	/**
	 * The statement a request carries, from sql or base64 sql_b64.
	 *
	 * sql_b64 exists for hosts whose firewall rejects any request body that
	 * looks like SQL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error
	 */
	public static function from_request( $request ) {
		$b64 = $request->get_param( 'sql_b64' );

		if ( is_string( $b64 ) && '' !== trim( $b64 ) ) {
			$sql = base64_decode( trim( $b64 ), true );
			if ( false === $sql ) {
				return new WP_Error( 'lets_ai_bad_b64', 'sql_b64 is not valid base64.', array( 'status' => 400 ) );
			}
			return $sql;
		}

		$sql = $request->get_param( 'sql' );

		return is_string( $sql ) ? $sql : '';
	}

	/**
	 * Trim a BOM, whitespace and one trailing ';'.
	 *
	 * @param string $sql Statement.
	 * @return string
	 */
	protected static function clean( $sql ) {
		$sql = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $sql );
		return rtrim( trim( $sql ), "; \t\n\r\0\x0B" );
	}

	/**
	 * Blank string literals and comments, keeping every byte offset.
	 *
	 * Backticked identifiers are kept as they are, since table names are read
	 * from the mask.
	 *
	 * @param string $sql Statement.
	 * @return string|WP_Error
	 */
	public static function mask( $sql ) {
		$len = strlen( $sql );
		$out = '';
		$i   = 0;

		while ( $i < $len ) {
			$c    = $sql[ $i ];
			$next = $i + 1 < $len ? $sql[ $i + 1 ] : '';

			if ( "'" === $c || '"' === $c ) {
				$out .= $c;
				++$i;
				while ( $i < $len ) {
					$d = $sql[ $i ];
					if ( '\\' === $d && $i + 1 < $len ) {
						$out .= 'xx';
						$i   += 2;
						continue;
					}
					if ( $d === $c ) {
						if ( $i + 1 < $len && $sql[ $i + 1 ] === $c ) {
							$out .= 'xx';
							$i   += 2;
							continue;
						}
						break;
					}
					$out .= 'x';
					++$i;
				}
				if ( $i < $len ) {
					$out .= $c;
					++$i;
				}
				continue;
			}

			if ( '`' === $c ) {
				$end  = strpos( $sql, '`', $i + 1 );
				$end  = false === $end ? $len - 1 : $end;
				$out .= substr( $sql, $i, $end - $i + 1 );
				$i    = $end + 1;
				continue;
			}

			if ( '/' === $c && '*' === $next ) {
				// MySQL runs the contents of /*! ... */ — it is code, not a comment.
				$marker = $i + 2 < $len ? $sql[ $i + 2 ] : '';
				if ( '!' === $marker || '+' === $marker ) {
					return new WP_Error( 'lets_ai_exec_comment', 'Refused: /*! and /*+ comments are not allowed.', array( 'status' => 400 ) );
				}
				$end  = strpos( $sql, '*/', $i + 2 );
				$end  = false === $end ? $len : $end + 2;
				$out .= str_repeat( ' ', $end - $i );
				$i    = $end;
				continue;
			}

			$dash_comment = '-' === $c && '-' === $next && ( $i + 2 >= $len || ctype_space( $sql[ $i + 2 ] ) );
			if ( $dash_comment || '#' === $c ) {
				$end  = strpos( $sql, "\n", $i );
				$end  = false === $end ? $len : $end;
				$out .= str_repeat( ' ', $end - $i );
				$i    = $end;
				continue;
			}

			$out .= $c;
			++$i;
		}

		return $out;
	}

	/**
	 * The mask with backticked identifiers emptied too, for keyword scans
	 * (a column literally named `update` should not look like an UPDATE).
	 *
	 * @param string $mask Mask.
	 * @return string
	 */
	protected static function scan_text( $mask ) {
		return preg_replace_callback(
			'/`[^`]*`/',
			function ( $m ) {
				return str_repeat( ' ', strlen( $m[0] ) );
			},
			$mask
		);
	}

	/**
	 * Validate a read-only statement and cap SELECTs with a LIMIT.
	 *
	 * @param string $sql   Statement.
	 * @param int    $limit Row cap added to a SELECT with no LIMIT.
	 * @return string|WP_Error Statement to run.
	 */
	public static function validate_read( $sql, $limit = 100 ) {
		$sql = self::clean( $sql );

		if ( '' === $sql ) {
			return new WP_Error( 'lets_ai_empty_sql', 'Empty SQL.', array( 'status' => 400 ) );
		}

		$mask = self::mask( $sql );
		if ( is_wp_error( $mask ) ) {
			return $mask;
		}
		$scan = self::scan_text( $mask );

		if ( false !== strpos( $scan, ';' ) ) {
			return new WP_Error( 'lets_ai_multi_sql', 'Multiple SQL statements are not allowed.', array( 'status' => 400 ) );
		}

		if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $scan, $m ) ) {
			return new WP_Error( 'lets_ai_not_readonly', 'db_query runs SELECT, SHOW, DESCRIBE and EXPLAIN. Use db_execute to change data.', array( 'status' => 400 ) );
		}

		if ( preg_match( self::READ_BLOCKED, $scan ) || preg_match( '/\bINTO\b/i', $scan ) ) {
			return new WP_Error( 'lets_ai_forbidden_sql', 'Forbidden keyword in a read query.', array( 'status' => 400 ) );
		}

		if ( 'SELECT' === strtoupper( $m[1] ) && ! preg_match( '/\bLIMIT\s+\d+/i', $scan ) ) {
			$sql .= ' LIMIT ' . (int) $limit;
		}

		return $sql;
	}

	/**
	 * POST /execute  { sql | sql_b64, allow_all_rows?, allow_no_undo? }
	 *
	 * Runs one INSERT / UPDATE / DELETE / REPLACE. UPDATE and DELETE must be
	 * single-table and have a WHERE (unless allow_all_rows). Anything that
	 * cannot be undone — REPLACE, ON DUPLICATE KEY UPDATE, a table with no
	 * primary key — is refused unless allow_no_undo is passed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function execute( $request ) {
		global $wpdb;

		$sql = self::from_request( $request );
		if ( is_wp_error( $sql ) ) {
			return $sql;
		}

		$plan = self::plan_write( self::clean( $sql ), (bool) $request->get_param( 'allow_all_rows' ) );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( ! $plan['undoable'] && ! $request->get_param( 'allow_no_undo' ) ) {
			return new WP_Error(
				'lets_ai_no_undo',
				'Refused: this statement cannot be undone (' . $plan['why_no_undo'] . '). Pass allow_no_undo to run it anyway.',
				array( 'status' => 409 )
			);
		}

		$wpdb->query( 'START TRANSACTION' );

		$before = array();

		if ( $plan['undoable'] && in_array( $plan['verb'], array( 'UPDATE', 'DELETE' ), true ) ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM {$plan['table_ref']} {$plan['tail']}) AS lets_ai_count" ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $wpdb->last_error ) {
				return self::rollback( $wpdb->last_error );
			}
			if ( $count > self::MAX_ROWS ) {
				return self::rollback( sprintf( 'Refused: matches %d rows, the limit is %d. Narrow the WHERE or split it up.', $count, self::MAX_ROWS ), 413 );
			}

			$before = $wpdb->get_results( "SELECT * FROM {$plan['table_ref']} {$plan['tail']} FOR UPDATE", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $wpdb->last_error ) {
				return self::rollback( $wpdb->last_error );
			}
		}

		$result = $wpdb->query( $plan['sql'] ); // phpcs:ignore WordPress.DB.PreparedSQL -- validated single write statement.

		if ( false === $result ) {
			return self::rollback( $wpdb->last_error ? $wpdb->last_error : 'The statement failed.' );
		}

		$affected  = (int) $wpdb->rows_affected;
		$insert_id = (int) $wpdb->insert_id;
		$after     = array();

		if ( $plan['undoable'] ) {
			if ( 'UPDATE' === $plan['verb'] ) {
				$after = self::rows_by_pk( $plan['table'], $plan['pk'], $before );
			} elseif ( 'INSERT' === $plan['verb'] && $affected > 0 ) {
				if ( $affected > self::MAX_ROWS ) {
					return self::rollback( sprintf( 'Refused: inserted %d rows, the limit is %d.', $affected, self::MAX_ROWS ), 413 );
				}
				$pk    = $plan['pk'][0];
				$after = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM `{$plan['table']}` WHERE `{$pk}` >= %d ORDER BY `{$pk}` LIMIT %d", $insert_id, $affected ), // phpcs:ignore WordPress.DB.PreparedSQL
					ARRAY_A
				);
			}

			if ( strlen( serialize( array( $before, $after ) ) ) > self::MAX_SNAPSHOT_BYTES ) {
				return self::rollback( 'Refused: the rows touched are too large to keep an undo copy of. Split the statement up.', 413 );
			}
		}

		$wpdb->query( 'COMMIT' );
		self::purge_caches();

		$change = Lets_AI_DB_Writer::record(
			array(
				'tool'     => 'db_execute',
				'target'   => 'sql',
				'summary'  => $plan['verb'] . ' ' . $plan['table'] . ' (' . $affected . ' rows)',
				'sql'      => $plan['sql'],
				'verb'     => $plan['verb'],
				'table'    => $plan['table'],
				'pk'       => $plan['pk'],
				'undoable' => $plan['undoable'],
				'before'   => $before,
				'after'    => $after,
			),
			$request
		);

		return rest_ensure_response(
			array(
				'ok'            => true,
				'change_id'     => $change['id'],
				'verb'          => $plan['verb'],
				'table'         => $plan['table'],
				'rows_affected' => $affected,
				'insert_id'     => $insert_id ? $insert_id : null,
				'undoable'      => $plan['undoable'],
			)
		);
	}

	/**
	 * Undo one recorded db_execute.
	 *
	 * Refuses when the rows were changed again since, unless forced.
	 *
	 * @param array<string,mixed> $change Recorded change.
	 * @param bool                $force  Revert even over later edits.
	 * @return true|WP_Error
	 */
	public static function revert( array $change, $force ) {
		global $wpdb;

		if ( empty( $change['undoable'] ) ) {
			return new WP_Error( 'lets_ai_no_undo', 'That statement was run with allow_no_undo and has no undo copy.', array( 'status' => 409 ) );
		}

		$table  = $change['table'];
		$pk     = $change['pk'];
		$before = (array) $change['before'];
		$after  = (array) $change['after'];

		if ( ! $force ) {
			// What the rows should look like now if nothing touched them since.
			$expected = 'DELETE' === $change['verb'] ? array() : $after;
			$keys     = 'DELETE' === $change['verb'] ? $before : $after;
			$current  = self::rows_by_pk( $table, $pk, $keys );

			if ( self::keyed( $current, $pk ) !== self::keyed( $expected, $pk ) ) {
				return new WP_Error(
					'lets_ai_conflict',
					'Refused: those rows were changed again after this statement. Pass force to revert anyway.',
					array( 'status' => 409 )
				);
			}
		}

		$wpdb->query( 'START TRANSACTION' );

		// Remove what the statement left behind (its PK may have changed), then put the old rows back.
		foreach ( $after as $row ) {
			if ( false === $wpdb->delete( $table, array_intersect_key( $row, array_flip( $pk ) ) ) ) {
				return self::rollback( $wpdb->last_error );
			}
		}
		foreach ( $before as $row ) {
			if ( false === $wpdb->replace( $table, $row ) ) {
				return self::rollback( $wpdb->last_error );
			}
		}

		$wpdb->query( 'COMMIT' );
		self::purge_caches();

		return true;
	}

	/**
	 * Work out what a write statement touches and whether it can be undone.
	 *
	 * @param string $sql            Cleaned statement.
	 * @param bool   $allow_all_rows Whether UPDATE / DELETE may run without WHERE.
	 * @return array<string,mixed>|WP_Error
	 */
	protected static function plan_write( $sql, $allow_all_rows ) {
		if ( '' === $sql ) {
			return new WP_Error( 'lets_ai_empty_sql', 'Empty SQL.', array( 'status' => 400 ) );
		}

		// The bridge's own tokens, grants and undo log are off limits, so a token cannot widen its own access.
		if ( false !== stripos( $sql, 'lets_ai_bridge' ) ) {
			return new WP_Error( 'lets_ai_protected', 'Refused: the AI Bridge settings cannot be changed through SQL.', array( 'status' => 403 ) );
		}

		$mask = self::mask( $sql );
		if ( is_wp_error( $mask ) ) {
			return $mask;
		}
		$scan = self::scan_text( $mask );

		if ( false !== strpos( $scan, ';' ) ) {
			return new WP_Error( 'lets_ai_multi_sql', 'One statement at a time.', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $scan, $m ) ) {
			return new WP_Error( 'lets_ai_bad_verb', 'db_execute runs INSERT, UPDATE, DELETE or REPLACE. Use db_query to read. Schema changes (ALTER, CREATE, DROP, TRUNCATE) are not allowed.', array( 'status' => 400 ) );
		}
		if ( preg_match( self::WRITE_BLOCKED, $scan ) ) {
			return new WP_Error( 'lets_ai_forbidden_sql', 'Forbidden keyword in SQL.', array( 'status' => 400 ) );
		}

		$verb = strtoupper( $m[1] );
		$plan = array(
			'sql'         => $sql,
			'verb'        => $verb,
			'table'       => '',
			'table_ref'   => '',
			'tail'        => '',
			'pk'          => array(),
			'undoable'    => true,
			'why_no_undo' => '',
		);

		if ( 'UPDATE' === $verb ) {
			if ( ! preg_match( '/^\s*UPDATE\s+(?:(?:LOW_PRIORITY|IGNORE)\s+)*(.+?)\s+SET\b/is', $mask, $t, PREG_OFFSET_CAPTURE ) ) {
				return new WP_Error( 'lets_ai_bad_update', 'Could not read the UPDATE.', array( 'status' => 400 ) );
			}
			$plan['table_ref'] = trim( $t[1][0] );
			$tail_at           = self::top_level_clause( $scan, $t[0][1] + strlen( $t[0][0] ) );
		} elseif ( 'DELETE' === $verb ) {
			if ( ! preg_match( '/^\s*DELETE\s+(?:(?:LOW_PRIORITY|QUICK|IGNORE)\s+)*FROM\s+(.+?)(?=\s+(?:WHERE|ORDER|LIMIT)\b|\s*$)/is', $mask, $t, PREG_OFFSET_CAPTURE ) ) {
				return new WP_Error( 'lets_ai_bad_delete', 'Only single-table DELETE FROM … is supported.', array( 'status' => 400 ) );
			}
			$plan['table_ref'] = trim( $t[1][0] );
			$tail_at           = self::top_level_clause( $scan, $t[1][1] + strlen( $t[1][0] ) );
		} else {
			if ( ! preg_match( '/^\s*(?:INSERT|REPLACE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?(`[^`]+`|[A-Za-z0-9_$]+)/i', $mask, $t ) ) {
				return new WP_Error( 'lets_ai_bad_insert', 'Could not read the table of the INSERT.', array( 'status' => 400 ) );
			}
			$plan['table_ref'] = $t[1];
			$tail_at           = false;
		}

		if ( preg_match( '/,|\b(JOIN|USING)\b/i', $plan['table_ref'] ) ) {
			return new WP_Error( 'lets_ai_multi_table', 'Only single-table statements are supported, so they can be undone.', array( 'status' => 400 ) );
		}

		$plan['table'] = trim( strtok( $plan['table_ref'], " \t\n\r" ), '`' );

		if ( ! Lets_AI_REST::table_exists( $plan['table'] ) ) {
			return new WP_Error( 'lets_ai_unknown_table', 'No such table: ' . $plan['table'], array( 'status' => 404 ) );
		}

		if ( 'UPDATE' === $verb || 'DELETE' === $verb ) {
			$plan['tail'] = false === $tail_at ? '' : substr( $sql, $tail_at );
			if ( ! preg_match( '/^\s*WHERE\b/i', $plan['tail'] ) && ! $allow_all_rows ) {
				return new WP_Error( 'lets_ai_no_where', 'Refused: ' . $verb . ' without WHERE. Pass allow_all_rows if every row is really meant.', array( 'status' => 400 ) );
			}
		}

		$plan['pk'] = self::primary_key( $plan['table'] );

		if ( empty( $plan['pk'] ) ) {
			$plan['undoable']    = false;
			$plan['why_no_undo'] = 'the table has no primary key';
		} elseif ( 'REPLACE' === $verb ) {
			$plan['undoable']    = false;
			$plan['why_no_undo'] = 'REPLACE overwrites rows without saying which';
		} elseif ( 'INSERT' === $verb ) {
			if ( preg_match( '/\bON\s+DUPLICATE\s+KEY\b/i', $scan ) ) {
				$plan['undoable']    = false;
				$plan['why_no_undo'] = 'ON DUPLICATE KEY UPDATE changes existing rows';
			} elseif ( 1 !== count( $plan['pk'] ) || ! self::is_auto_increment( $plan['table'], $plan['pk'][0] ) ) {
				$plan['undoable']    = false;
				$plan['why_no_undo'] = 'the new rows cannot be told apart without an auto-increment primary key';
			}
		}

		return $plan;
	}

	/**
	 * Offset of the first WHERE / ORDER / LIMIT at parenthesis depth 0 from $from,
	 * so a WHERE inside a subquery in SET is not taken for the statement's own.
	 *
	 * @param string $scan Scan text.
	 * @param int    $from Offset to start at.
	 * @return int|false
	 */
	protected static function top_level_clause( $scan, $from ) {
		$depth = 0;
		$len   = strlen( $scan );

		for ( $i = $from; $i < $len; $i++ ) {
			$c = $scan[ $i ];
			if ( '(' === $c ) {
				++$depth;
			} elseif ( ')' === $c ) {
				--$depth;
			} elseif ( 0 === $depth && ( 0 === $i || ! preg_match( '/\w/', $scan[ $i - 1 ] ) ) && preg_match( '/\G(WHERE|ORDER\s+BY|LIMIT)\b/i', $scan, $m, 0, $i ) ) {
				return $i;
			}
		}

		return false;
	}

	/**
	 * @param string $table Table.
	 * @return string[] Primary key columns, in index order.
	 */
	protected static function primary_key( $table ) {
		global $wpdb;

		$keys = (array) $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- table checked against SHOW TABLES.

		usort(
			$keys,
			function ( $a, $b ) {
				return (int) $a['Seq_in_index'] - (int) $b['Seq_in_index'];
			}
		);

		return wp_list_pluck( $keys, 'Column_name' );
	}

	/**
	 * @param string $table  Table.
	 * @param string $column Column.
	 * @return bool
	 */
	protected static function is_auto_increment( $table, $column ) {
		global $wpdb;

		$col = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $col && false !== stripos( (string) $col['Extra'], 'auto_increment' );
	}

	/**
	 * Current rows with the same primary keys as $rows.
	 *
	 * @param string                         $table Table.
	 * @param string[]                       $pk    Primary key columns.
	 * @param array<int,array<string,mixed>> $rows  Rows whose keys to look up.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function rows_by_pk( $table, array $pk, array $rows ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return array();
		}

		$where = array();
		foreach ( $rows as $row ) {
			$parts = array();
			foreach ( $pk as $col ) {
				$parts[] = $wpdb->prepare( "`{$col}` = %s", $row[ $col ] ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			$where[] = '(' . implode( ' AND ', $parts ) . ')';
		}

		return (array) $wpdb->get_results( "SELECT * FROM `{$table}` WHERE " . implode( ' OR ', $where ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Rows keyed and sorted by primary key, for comparing two sets.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param string[]                       $pk   Primary key columns.
	 * @return array<string,array<string,mixed>>
	 */
	protected static function keyed( array $rows, array $pk ) {
		$out = array();
		foreach ( $rows as $row ) {
			$key         = implode( "\0", array_intersect_key( $row, array_flip( $pk ) ) );
			$out[ $key ] = array_map(
				function ( $v ) {
					return null === $v ? null : (string) $v;
				},
				$row
			);
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param string $message Error.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	protected static function rollback( $message, $status = 400 ) {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'lets_ai_sql_error', $message, array( 'status' => $status ) );
	}

	/**
	 * A raw write bypasses every WordPress hook, so drop the caches that would
	 * otherwise keep serving the old data.
	 */
	public static function purge_caches() {
		wp_cache_flush();
		Lets_AI_DB_Writer::purge_page_caches();
	}
}
