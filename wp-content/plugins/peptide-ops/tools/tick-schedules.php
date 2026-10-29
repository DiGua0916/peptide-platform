<?php
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( ! class_exists( '\PeptideHq\Backup_Schedules' ) ) { exit( 69 ); }
\PeptideHq\Backup_Schedules::tick();
echo "Schedule check completed.\n";
