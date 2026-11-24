<?php
/**
 * Dočasný export databáze pro přechod na Rosti. Po stažení se maže.
 * Běží po dávkách (každé volání max ~20 s), stav drží v pracovní složce.
 * Volání: ?k=<token>&a=step  (opakovat, dokud nevrátí DONE)   ?k=<token>&a=reset
 * Nenačítá WordPress (funguje i v režimu údržby), údaje k DB čte z wp-config.php.
 */
const TOKEN_HASH = '__TOKEN_HASH__';
const WORK       = __DIR__ . '/wp-content/uploads/__WORKDIR__';
const BUDGET     = 20;
const ROWS       = 2000;

header( 'Content-Type: text/plain; charset=utf-8' );
header( 'X-Robots-Tag: noindex' );
if ( ! isset( $_GET['k'] ) || ! hash_equals( TOKEN_HASH, hash( 'sha256', (string) $_GET['k'] ) ) ) {
	http_response_code( 404 );
	exit;
}
@set_time_limit( 120 );
@ignore_user_abort( true );
$t0 = microtime( true );

$cfg = file_get_contents( __DIR__ . '/wp-config.php' );
$c   = array();
foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ) as $k ) {
	if ( ! preg_match( "/define\(\s*['\"]" . $k . "['\"]\s*,\s*['\"](.*?)['\"]\s*\)/", $cfg, $m ) ) {
		exit( "ERR config $k" );
	}
	$c[ $k ] = stripcslashes( $m[1] );
}
$prefix = preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $cfg, $m ) ? $m[1] : 'wp_';
$host   = $c['DB_HOST'];
$port   = null;
if ( preg_match( '/^(.*):(\d+)$/', $host, $m ) ) {
	$host = $m[1];
	$port = (int) $m[2];
}
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
$db = new mysqli( $host, $c['DB_USER'], $c['DB_PASSWORD'], $c['DB_NAME'], $port );
$db->set_charset( 'utf8mb4' );

if ( ! is_dir( WORK ) ) {
	mkdir( WORK, 0700, true );
	file_put_contents( WORK . '/.htaccess', "Require all denied\nDeny from all\n" );
	file_put_contents( WORK . '/index.html', '' );
}
$state_file = WORK . '/state.json';
$a          = $_GET['a'] ?? 'step';

if ( 'reset' === $a ) {
	foreach ( glob( WORK . '/part-*.sql.gz' ) as $f ) {
		unlink( $f );
	}
	@unlink( $state_file );
	exit( "RESET\n" );
}

$state = is_file( $state_file ) ? json_decode( file_get_contents( $state_file ), true ) : null;
if ( ! $state ) {
	$tables = array();
	$res    = $db->query( 'SHOW FULL TABLES WHERE Table_type = "BASE TABLE"' );
	while ( $r = $res->fetch_row() ) {
		if ( 0 === strpos( $r[0], $prefix ) ) {
			$tables[] = $r[0];
		}
	}
	sort( $tables );
	// Tabulka options napřed – import na Rosti podle ní pozná, že jde o správnou databázi.
	usort( $tables, function ( $x, $y ) use ( $prefix ) {
		return ( $y === $prefix . 'options' ) <=> ( $x === $prefix . 'options' ) ?: strcmp( $x, $y );
	} );
	$state = array( 'tables' => $tables, 'i' => 0, 'last' => null, 'offset' => 0, 'part' => 0, 'rows' => 0, 'started' => time() );
}

$state['part']++;
$gz = gzopen( sprintf( '%s/part-%04d.sql.gz', WORK, $state['part'] ), 'wb5' );
if ( 1 === $state['part'] ) {
	gzwrite( $gz, "-- SAM export " . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" );
}

$n = count( $state['tables'] );
while ( $state['i'] < $n && microtime( true ) - $t0 < BUDGET ) {
	$t = $state['tables'][ $state['i'] ];
	if ( null === $state['last'] && 0 === $state['offset'] ) {
		$create = $db->query( "SHOW CREATE TABLE `$t`" )->fetch_row()[1];
		gzwrite( $gz, "\nDROP TABLE IF EXISTS `$t`;\n$create;\n" );
	}
	// Primární klíč: jeden číselný sloupec → stránkování podle klíče, jinak OFFSET.
	$pk  = array();
	$res = $db->query( "SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'" );
	while ( $r = $res->fetch_assoc() ) {
		$pk[ (int) $r['Seq_in_index'] ] = $r['Column_name'];
	}
	ksort( $pk );
	$keyset = false;
	if ( 1 === count( $pk ) ) {
		$col  = reset( $pk );
		$type = $db->query( "SHOW COLUMNS FROM `$t` LIKE '" . $db->real_escape_string( $col ) . "'" )->fetch_assoc()['Type'];
		$keyset = (bool) preg_match( '/int/i', $type );
	}
	$order = $pk ? ' ORDER BY ' . implode( ',', array_map( function ( $x ) { return "`$x`"; }, $pk ) ) : '';
	if ( $keyset ) {
		$where = null === $state['last'] ? '' : " WHERE `$col` > " . (int) $state['last'];
		$q     = "SELECT * FROM `$t`$where$order LIMIT " . ROWS;
	} else {
		$q = "SELECT * FROM `$t`$order LIMIT " . ROWS . ' OFFSET ' . (int) $state['offset'];
	}
	$res    = $db->query( $q, MYSQLI_USE_RESULT );
	$fields = $res->fetch_fields();
	$num    = array();
	foreach ( $fields as $f ) {
		$num[] = in_array( $f->type, array( MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_INT24, MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL ), true );
	}
	$ci   = $keyset ? array_search( $col, array_map( function ( $f ) { return $f->name; }, $fields ), true ) : null;
	$cnt  = 0;
	$buf  = '';
	$last = $state['last'];
	while ( $row = $res->fetch_row() ) {
		$vals = array();
		foreach ( $row as $j => $v ) {
			$vals[] = null === $v ? 'NULL' : ( $num[ $j ] ? $v : "'" . $db->real_escape_string( $v ) . "'" );
		}
		$buf .= ( '' === $buf ? "INSERT INTO `$t` VALUES " : ',' ) . '(' . implode( ',', $vals ) . ')';
		if ( strlen( $buf ) > 900000 ) {
			gzwrite( $gz, $buf . ";\n" );
			$buf = '';
		}
		if ( $keyset ) {
			$last = $row[ $ci ];
		}
		$cnt++;
	}
	$res->free();
	if ( '' !== $buf ) {
		gzwrite( $gz, $buf . ";\n" );
	}
	$state['rows'] += $cnt;
	if ( $cnt < ROWS ) { // tabulka hotová
		$state['i']++;
		$state['last']   = null;
		$state['offset'] = 0;
	} elseif ( $keyset ) {
		$state['last'] = $last;
	} else {
		$state['offset'] += $cnt;
	}
}
if ( $state['i'] >= $n ) {
	gzwrite( $gz, "\nSET FOREIGN_KEY_CHECKS=1;\n-- SAM export hotovo\n" );
}
gzclose( $gz );
file_put_contents( $state_file, json_encode( $state ) );
printf( "%s tabulka %d/%d, řádků %d, část %d, %.1f s\n", $state['i'] >= $n ? 'DONE' : 'CONTINUE', $state['i'], $n, $state['rows'], $state['part'], microtime( true ) - $t0 );
