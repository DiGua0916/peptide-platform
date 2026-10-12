<?php
/**
 * 插件删除时运行。
 *
 * @package PeptideCore
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

define( 'PEPTIDE_CORE_DIR', __DIR__ );

require_once __DIR__ . '/includes/autoload.php';

\PeptideCore\Installer::uninstall();
