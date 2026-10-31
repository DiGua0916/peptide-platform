<?php
/**
 * WP-CLI：店铺装修（wp peptide layout）。导入现有首页、发布、取消发布、查看状态（SYNC-HOME-01）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Cli;

use PeptideCore\Modules\Assets;
use PeptideCore\Modules\Layout;
use PeptideCore\Templates;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Layout_Command {

	/**
	 * 把一份积木数据文件导入成某个模板的装修草稿（不会发布）。
	 *
	 * 文件里的链接标记 {url:shop}、{url:reports}、{url:<页面别名>} 会换成本站的相对地址。
	 * 图片用主题登记的资产键；有资产键没登记时停止，除非加 --allow-missing-assets。
	 * 文档已有内容时需要 --force；覆盖前的内容会留在修订记录里。
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : 数据文件。默认是插件自带的 import-templates/home-2026-10-20.json（2026-10-20 的主题首页）。
	 *
	 * [--template=<id>]
	 * : 模板 ID。默认是店铺默认模板。
	 *
	 * [--dry-run]
	 * : 只检查，不写入。
	 *
	 * [--force]
	 * : 文档已有内容时也覆盖草稿。
	 *
	 * [--allow-missing-assets]
	 * : 有资产键还没登记时也导入（这些图片会显示为空）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide layout import --dry-run
	 *     wp peptide layout import --force
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function import( array $args, array $assoc_args ): void {
		$file = $assoc_args['file'] ?? PEPTIDE_CORE_DIR . '/import-templates/home-2026-10-20.json';
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( 'File not found: ' . $file );
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || empty( $data['modules'] ) || ! is_array( $data['modules'] ) ) {
			WP_CLI::error( 'Invalid file: expected {"modules": [...]}.' );
		}

		if ( \PeptideCore\Modules\Rich_Media::has_images( $data['modules'] ) ) { WP_CLI::error( 'Rich text images require the authorized design editor save flow; nothing was imported.' ); }

		$template = isset( $assoc_args['template'] ) ? (int) $assoc_args['template'] : Templates::default_id();
		if ( ! Templates::exists( $template ) ) {
			WP_CLI::error( 'Template not found: ' . $template );
		}
		$doc    = (string) ( $data['document'] ?? 'home' );
		$doc_id = Templates::document_id( $template, $doc );
		if ( ! $doc_id ) {
			WP_CLI::error( 'Unknown document: ' . $doc );
		}

		$wanted  = array_map( 'strval', (array) ( $data['assets'] ?? array() ) );
		$missing = array_values( array_filter( $wanted, static fn( $key ) => ! Assets::exists( $key ) ) );
		if ( $missing ) {
			$message = 'Asset keys not registered by the theme: ' . implode( ', ', $missing ) . '.';
			if ( empty( $assoc_args['allow-missing-assets'] ) ) {
				WP_CLI::error( $message . ' Register them with the peptide_core_layout_assets filter, or pass --allow-missing-assets.' );
			}
			WP_CLI::warning( $message . ' These images will be empty.' );
		}

		$modules = Layout::clean_modules( self::resolve_links( $data['modules'] ) );
		WP_CLI::log( sprintf( 'Template #%d "%s", document "%s" (#%d): %d module(s).', $template, get_the_title( $template ), $doc, $doc_id, count( $modules ) ) );
		foreach ( $modules as $m ) {
			WP_CLI::log( sprintf( '  %-28s id=%-16s items=%d', $m['block'], $m['attrs']['moduleId'], count( $m['attrs']['items'] ) ) );
		}
		if ( count( $modules ) !== count( $data['modules'] ) ) {
			WP_CLI::warning( ( count( $data['modules'] ) - count( $modules ) ) . ' module(s) in the file have an unknown block type and were skipped.' );
		}

		$existing = Layout::modules_from_content( (string) get_post_field( 'post_content', $doc_id ) );
		if ( $existing && empty( $assoc_args['force'] ) ) {
			WP_CLI::error( sprintf( 'The draft already has %d module(s). Pass --force to replace it (the old draft stays in the revision history).', count( $existing ) ) );
		}
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( 'Dry run: nothing written.' );
			return;
		}

		$result = wp_update_post(
			array(
				'ID'           => $doc_id,
				'post_content' => wp_slash( Layout::content_from_modules( $modules ) ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( 'Imported as a draft. Nothing is published yet: open Store design → Theme design → Design to check, then publish.' );
	}

	/**
	 * 发布某个模板的装修文档到网站。
	 *
	 * ## OPTIONS
	 *
	 * [--template=<id>]
	 * : 模板 ID。默认是店铺默认模板。
	 *
	 * [--doc=<doc>]
	 * : 文档：home / header / footer / global。默认 home。
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function publish( array $args, array $assoc_args ): void {
		$doc_id = self::doc_id( $assoc_args );
		if ( ! Layout::modules_from_content( (string) get_post_field( 'post_content', $doc_id ) ) ) {
			WP_CLI::error( 'The draft has no modules. Nothing to publish.' );
		}
		if ( \PeptideCore\Modules\Rich_Media::has_images( Layout::modules_from_content( (string) get_post_field( 'post_content', $doc_id ) ) ) ) { WP_CLI::error( 'Publish rich text images in the authorized design editor.' ); }
		Layout::publish( $doc_id );
		WP_CLI::success( 'Published.' );
	}

	/**
	 * 取消发布：网站恢复主题原来的页面，草稿保留。
	 *
	 * ## OPTIONS
	 *
	 * [--template=<id>]
	 * : 模板 ID。默认是店铺默认模板。
	 *
	 * [--doc=<doc>]
	 * : 文档。默认 home。
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function unpublish( array $args, array $assoc_args ): void {
		Layout::unpublish( self::doc_id( $assoc_args ) );
		WP_CLI::success( 'Unpublished. Visitors see the theme\'s original page.' );
	}

	/**
	 * 显示所有模板的装修状态。
	 *
	 * @subcommand status
	 */
	public function status(): void {
		$rows = array();
		foreach ( Templates::all() as $t ) {
			foreach ( array_keys( Templates::documents() ) as $doc ) {
				$doc_id = Templates::document_id( (int) $t->ID, $doc );
				$st     = Layout::status( $doc_id );
				$rows[] = array(
					'template'  => $t->ID . ( Templates::default_id() === (int) $t->ID ? '*' : '' ) . ' ' . $t->post_title,
					'document'  => $doc,
					'draft'     => count( Layout::modules_from_content( (string) get_post_field( 'post_content', $doc_id ) ) ) . ' module(s)',
					'published' => $st['published'] ? $st['at'] . ' by ' . $st['by'] : 'no',
					'changes'   => $st['published'] && $st['changed'] ? 'unpublished changes' : '',
				);
			}
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'template', 'document', 'draft', 'published', 'changes' ) );
		WP_CLI::log( '* store default template' );
	}

	/**
	 * @param array<string,string> $assoc_args
	 */
	private static function doc_id( array $assoc_args ): int {
		$template = isset( $assoc_args['template'] ) ? (int) $assoc_args['template'] : Templates::default_id();
		$doc_id   = Templates::exists( $template ) ? Templates::document_id( $template, (string) ( $assoc_args['doc'] ?? 'home' ) ) : 0;
		if ( ! $doc_id ) {
			WP_CLI::error( 'Template or document not found.' );
		}
		return $doc_id;
	}

	/**
	 * 把数据里的 {url:...} 标记换成本站的相对地址。
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function resolve_links( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'resolve_links' ), $value );
		}
		if ( ! is_string( $value ) || ! str_contains( $value, '{url:' ) ) {
			return $value;
		}
		return (string) preg_replace_callback(
			'/\{url:([a-z0-9-]+)\}/',
			static function ( array $m ): string {
				return self::page_url( $m[1] );
			},
			$value
		);
	}

	public static function page_url( string $page ): string {
		$url = '';
		if ( 'reports' === $page ) {
			$reports = get_page_by_path( 'batch-reports' );
			$url     = $reports ? (string) get_permalink( $reports ) : home_url( '/?vc_reports=1' );
		} elseif ( in_array( $page, array( 'shop', 'cart', 'checkout', 'myaccount' ), true ) && function_exists( 'wc_get_page_permalink' ) ) {
			$url = (string) wc_get_page_permalink( $page );
		} else {
			$content = get_page_by_path( $page );
			$url     = $content ? (string) get_permalink( $content ) : home_url( '/' . $page . '/' );
		}
		return wp_make_link_relative( $url );
	}
}
