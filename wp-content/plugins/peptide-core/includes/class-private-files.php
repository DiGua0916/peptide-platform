<?php
/**
 * 报告文件的私有存储。文件不通过公开 URL 访问，下载统一走插件的受控接口（BE-03）。
 *
 * 存放位置：
 * - 若 wp-config.php 定义了 PEPTIDE_CORE_PRIVATE_DIR，使用该目录（推荐放在网站根目录之外）；
 * - 否则使用 uploads/peptide-core-private，并写入 .htaccess 禁止直接访问。
 *   注意：Nginx 不读取 .htaccess，使用 Nginx 时必须定义 PEPTIDE_CORE_PRIVATE_DIR 或另行配置禁止访问。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Private_Files {

	public const MAX_BYTES = 20 * MB_IN_BYTES;

	public const ALLOWED_MIMES = array(
		'pdf'      => 'application/pdf',
		'png'      => 'image/png',
		'jpg|jpeg' => 'image/jpeg',
	);

	public static function dir(): string {
		if ( defined( 'PEPTIDE_CORE_PRIVATE_DIR' ) && PEPTIDE_CORE_PRIVATE_DIR ) {
			return untrailingslashit( (string) PEPTIDE_CORE_PRIVATE_DIR );
		}
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( $uploads['basedir'] ) . '/peptide-core-private';
	}

	public static function ensure_dir(): bool {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$guards = array(
			'.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		);
		foreach ( $guards as $name => $contents ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return is_writable( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	}

	/**
	 * 保存一个通过表单上传的文件。
	 *
	 * @param array<string,mixed> $upload $_FILES 中的一项。
	 * @return array{stored:string,original:string,mime:string,size:int}|\WP_Error
	 */
	public static function store_upload( array $upload ) {
		$error = isset( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			return new \WP_Error( 'pc_upload_failed', __( 'The file could not be uploaded.', 'peptide-core' ) );
		}
		$tmp  = (string) ( $upload['tmp_name'] ?? '' );
		$name = (string) ( $upload['name'] ?? '' );
		$size = (int) ( $upload['size'] ?? 0 );

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new \WP_Error( 'pc_upload_failed', __( 'The file could not be uploaded.', 'peptide-core' ) );
		}
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return new \WP_Error( 'pc_upload_too_large', __( 'The file is empty or larger than 20 MB.', 'peptide-core' ) );
		}

		$check = wp_check_filetype_and_ext( $tmp, $name, self::ALLOWED_MIMES );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new \WP_Error( 'pc_upload_type', __( 'Only PDF, PNG and JPEG files are allowed.', 'peptide-core' ) );
		}

		if ( ! self::ensure_dir() ) {
			return new \WP_Error( 'pc_storage_unavailable', __( 'The private report folder is not writable.', 'peptide-core' ) );
		}

		$stored = self::random_name( $check['ext'] );
		if ( ! move_uploaded_file( $tmp, self::dir() . '/' . $stored ) ) {
			return new \WP_Error( 'pc_upload_failed', __( 'The file could not be saved.', 'peptide-core' ) );
		}

		return array(
			'stored'   => $stored,
			'original' => sanitize_file_name( $name ),
			'mime'     => $check['type'],
			'size'     => $size,
		);
	}

	/**
	 * 保存由程序生成的内容（演示数据命令使用）。
	 *
	 * @return array{stored:string,original:string,mime:string,size:int}|\WP_Error
	 */
	public static function store_contents( string $contents, string $original, string $ext, string $mime ) {
		if ( ! self::ensure_dir() ) {
			return new \WP_Error( 'pc_storage_unavailable', __( 'The private report folder is not writable.', 'peptide-core' ) );
		}
		$stored = self::random_name( $ext );
		if ( false === file_put_contents( self::dir() . '/' . $stored, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new \WP_Error( 'pc_storage_unavailable', __( 'The file could not be saved.', 'peptide-core' ) );
		}
		return array(
			'stored'   => $stored,
			'original' => sanitize_file_name( $original ),
			'mime'     => $mime,
			'size'     => strlen( $contents ),
		);
	}

	/**
	 * 复制本机上的报告文件到私有目录（批量导入使用）。校验规则与表单上传相同：
	 * 只允许 PDF / PNG / JPEG，按文件内容核对类型，大小不超过 20 MB。
	 *
	 * @return array{stored:string,original:string,mime:string,size:int}|\WP_Error
	 */
	public static function store_local_file( string $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'pc_file_missing', __( 'The report file was not found.', 'peptide-core' ) );
		}
		$size = (int) filesize( $path );
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return new \WP_Error( 'pc_upload_too_large', __( 'The file is empty or larger than 20 MB.', 'peptide-core' ) );
		}
		$check = wp_check_filetype_and_ext( $path, wp_basename( $path ), self::ALLOWED_MIMES );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new \WP_Error( 'pc_upload_type', __( 'Only PDF, PNG and JPEG files are allowed.', 'peptide-core' ) );
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return new \WP_Error( 'pc_file_missing', __( 'The report file was not found.', 'peptide-core' ) );
		}
		return self::store_contents( $contents, wp_basename( $path ), $check['ext'], $check['type'] );
	}

	/**
	 * 只接受本插件生成的文件名，防止路径穿越。
	 */
	public static function path( string $stored ): ?string {
		if ( ! preg_match( '/^[a-zA-Z0-9]{32}\.(pdf|png|jpg|jpeg)$/', $stored ) ) {
			return null;
		}
		return self::dir() . '/' . $stored;
	}

	public static function delete( string $stored ): void {
		$path = self::path( $stored );
		if ( $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	private static function random_name( string $ext ): string {
		return wp_generate_password( 32, false, false ) . '.' . strtolower( $ext );
	}
}
