<?php
/** A committed writer seam, independent of any transaction on the global WordPress connection. */
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Database {
	public static function open(): \wpdb {
		global $wpdb;
		$db = null;
		try {
			if ( ! is_object( $wpdb ) || ! is_string( $wpdb->prefix ?? null ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/', $wpdb->prefix ) ) {
				throw new \RuntimeException();
			}
			foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ) as $key ) {
				if ( ! defined( $key ) || ! is_string( constant( $key ) ) ) { throw new \RuntimeException(); }
			}
			$override = defined( 'PC_PANAPAY_DB_HOST' ) ? constant( 'PC_PANAPAY_DB_HOST' ) : getenv( 'PC_PANAPAY_DB_HOST' );
			// A custom router may use DB_HOST only as a placeholder. Never infer its write primary.
			if ( false === $override && 'wpdb' !== get_class( $wpdb ) ) { throw new \RuntimeException(); }
			$host = false === $override ? DB_HOST : $override;
			if ( ! is_string( $host ) || '' === $host || '' === DB_NAME || '' === DB_USER || preg_match( '/[\x00\r\n]/', $host )
				|| str_starts_with( strtolower( $host ), 'p:' ) ) { throw new \RuntimeException(); }
			$db = new Database_Connection( DB_USER, DB_PASSWORD, DB_NAME, $host );
			if ( ! $db->ready ) { throw new \RuntimeException(); }
			$db->prefix = $wpdb->prefix;
			$db->base_prefix = $wpdb->prefix;
			$db->options = $wpdb->prefix . 'options';
			$db->posts = $wpdb->prefix . 'posts';
			$db->postmeta = $wpdb->prefix . 'postmeta';
			if ( (string) $db->get_var( 'SELECT DATABASE()' ) !== DB_NAME || '' !== (string) $db->last_error
				|| '1' !== (string) $db->get_var( 'SELECT @@autocommit' ) || '' !== (string) $db->last_error ) {
				throw new \RuntimeException();
			}
			return $db;
		} catch ( \Throwable $error ) {
			if ( $db instanceof \wpdb ) { try { $db->close(); } catch ( \Throwable $ignored ) {} }
			// Do not chain a driver exception containing connection details.
			throw new \RuntimeException( 'payment_database_unavailable' );
		}
	}
}

/** @internal Native wpdb only; never installed as the global connection or a routing drop-in. */
final class Database_Connection extends \wpdb {
	public function show_errors( $show = true ) {
		$previous = $this->show_errors;
		$this->show_errors = false;
		return $previous;
	}

	public function bail( $message, $error_code = '500' ) {
		throw new \RuntimeException( 'payment_database_unavailable' );
	}

	public function print_error( $str = '' ) {
		// Native print_error also appends query text to the global EZSQL_ERROR array.
		return false;
	}

	public function db_connect( $allow_bail = true ) {
		$this->show_errors = false;
		$this->suppress_errors = true;
		try {
			// false avoids WordPress's db-error.php/die path before its bail callback.
			$connected = @parent::db_connect( false );
			if ( ! $connected || ! $this->ready || ! $this->dbh instanceof \mysqli || ! @mysqli_autocommit( $this->dbh, true ) ) {
				try { parent::close(); } catch ( \Throwable $ignored ) {}
				$this->ready = false;
				return false;
			}
			return true;
		} catch ( \Throwable $error ) {
			try { parent::close(); } catch ( \Throwable $ignored ) {}
			$this->ready = false;
			throw new \RuntimeException( 'payment_database_unavailable' );
		}
	}

	public function check_connection( $allow_bail = true ) {
		$level = error_reporting();
		try { return @parent::check_connection( false ); }
		finally { error_reporting( $level ); }
	}
}
