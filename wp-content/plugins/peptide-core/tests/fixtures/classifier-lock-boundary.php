<?php
/** In-memory MySQL named-lock boundary: ownership belongs to one connection, not the PHP writer. */
final class ClassifierLockBoundary072 {
 public string $database = 'fixture_classification'; public int $connection = 1;
 public array $held = array(), $trace = array(); public bool $fail_namespace = false, $fail_get = false, $timeout = false, $lose_before_insert = false;
 public $before_acquire = null;
 public function scalar( string $sql, array $args ): array {
  if ( $sql === 'SELECT DATABASE()' ) { return array( true, $this->fail_namespace ? null : $this->database, $this->fail_namespace ? 'synthetic namespace failure' : '' ); }
  if ( str_contains( $sql, 'GET_LOCK(' ) ) {
   $name = $args[0]; $this->trace[] = array( 'acquire', $name, $this->connection );
   if ( ! str_contains( $sql, ',2)' ) ) { throw new RuntimeException( 'Lock wait must be bounded to two seconds' ); }
   if ( $this->fail_get ) { return array( true, null, 'synthetic GET_LOCK SQL failure' ); }
   if ( $this->before_acquire ) { $f = $this->before_acquire; $this->before_acquire = null; $f(); }
   if ( $this->timeout || ( isset( $this->held[$name] ) && $this->held[$name][0] !== $this->connection ) ) { return array( true, 0, '' ); }
   $depth = isset( $this->held[$name] ) ? $this->held[$name][1] + 1 : 1; $this->held[$name] = array( $this->connection, $depth );
   return array( true, 1, '' );
  }
  if ( str_contains( $sql, 'RELEASE_LOCK(' ) ) {
   $name = $args[0]; $this->trace[] = array( 'release', $name, $this->connection );
   if ( ! isset( $this->held[$name] ) ) { return array( true, null, '' ); }
   if ( $this->held[$name][0] !== $this->connection ) { return array( true, 0, '' ); }
   if ( --$this->held[$name][1] === 0 ) { unset( $this->held[$name] ); } return array( true, 1, '' );
  }
  return array( false, null, '' );
 }
 public function insert_allowed( string $sql, string $name ): bool {
  if ( ! str_contains( $sql, ' SELECT ' ) || ! str_contains( $sql, 'IS_USED_LOCK(' ) || ! str_contains( $sql, '=CONNECTION_ID()' ) ) { throw new RuntimeException( 'Classification INSERT must gate lock ownership in the same SQL' ); }
  if ( $this->lose_before_insert ) { $this->lose_before_insert = false; foreach ( $this->held as $key => $value ) { if ( $value[0] === $this->connection ) { unset( $this->held[$key] ); } } ++$this->connection; }
  $allowed = isset( $this->held[$name] ) && $this->held[$name][0] === $this->connection;
  $this->trace[] = array( 'insert_gate', $name, $this->connection, $allowed ); return $allowed;
 }
}
