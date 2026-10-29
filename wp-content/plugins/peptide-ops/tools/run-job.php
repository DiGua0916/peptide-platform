<?php
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args[0] ) || ! class_exists( '\PeptideOps\Runtime' ) ) { throw new RuntimeException( 'This worker requires a configured WordPress CLI runtime.' ); }
\PeptideOps\Runtime::work( (string) $args[0] );
