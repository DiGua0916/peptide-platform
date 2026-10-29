<?php
/** Strict ZIP inspection and extraction for private operational artifacts. */
namespace PeptideOps;

defined( 'ABSPATH' ) || exit;

final class Archive {

	private const DEFAULTS = array(
		'max_files'       => 30000,
		'max_bytes'       => 1073741824,
		'max_file_bytes'  => 268435456,
		'max_ratio'       => 300,
		'allow_php'       => true,
		'allow_sensitive' => false,
		'require_single_root' => false,
		'ignore_junk'     => true,
	);

	/** Metadata is validated before any destination is created. */
	public static function inspect( string $file, array $limits = array() ) {
		if ( ! class_exists( '\ZipArchive' ) ) { return self::error( 'zip_missing', '服务器没有可用的 ZIP 扩展。' ); }
		$limits = array_merge( self::DEFAULTS, $limits );
		if ( ! is_file( $file ) || is_link( $file ) || ! is_readable( $file ) ) { return self::error( 'zip_missing', '找不到可读取的压缩包。' ); }
		$raw_names = self::raw_names( $file ); if ( is_wp_error( $raw_names ) ) { return $raw_names; }
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file, \ZipArchive::RDONLY ) ) { return self::error( 'zip_invalid', '压缩包无法打开或格式不完整。' ); }
		try {
			if ( $zip->numFiles < 1 || $zip->numFiles > (int) $limits['max_files'] ) { return self::error( 'zip_count', '压缩包文件数量为空或超过限制。' ); }
			if ( count( $raw_names ) !== $zip->numFiles ) { return self::error( 'zip_stat', '压缩包原始目录与读取结果不一致。' ); }
			$entries = array(); $seen = array(); $directories = array(); $roots = array(); $bytes = 0; $compressed = 0; $files = 0;
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$stat = $zip->statIndex( $i, \ZipArchive::FL_UNCHANGED );
				if ( ! is_array( $stat ) ) { return self::error( 'zip_stat', '压缩包目录损坏。' ); }
				$path = self::entry_path( $raw_names[ $i ] );
				if ( is_wp_error( $path ) ) { return $path; }
				if ( $raw_names[ $i ] !== $stat['name'] ) { return self::error( 'zip_encoding', '压缩包路径编码不受支持，请使用UTF-8目录重新打包。' ); }
				$directory = str_ends_with( (string) $stat['name'], '/' );
				$os = 0; $attributes = 0;
				if ( $zip->getExternalAttributesIndex( $i, $os, $attributes ) ) {
					$mode = ( $attributes >> 16 ) & 0170000;
					if ( in_array( $mode, array( 0120000, 0060000, 0020000, 0010000, 0140000 ), true ) || ( $attributes & 0x400 ) ) { return self::error( 'zip_link', '压缩包包含链接或特殊设备文件，不能使用。' ); }
					if ( 0040000 === $mode ) { $directory = true; }
				}
				if ( ! empty( $stat['encryption_method'] ) ) { return self::error( 'zip_encrypted', '不接受加密或需要密码的 ZIP。' ); }
				$key = self::case_key( rtrim( $path, '/' ) );
				if ( isset( $seen[ $key ] ) ) { return self::error( 'zip_duplicate', '压缩包存在重复或大小写冲突路径。' ); }
				if ( ! $directory && isset( $directories[ $key ] ) ) { return self::error( 'zip_collision', '压缩包文件与子目录路径冲突。' ); }
				$seen[ $key ] = $directory ? 'directory' : 'file';
				$parents = explode( '/', rtrim( $path, '/' ) ); array_pop( $parents ); $ancestor = '';
				foreach ( $parents as $part ) {
					$ancestor = '' === $ancestor ? $part : $ancestor . '/' . $part;
					if ( isset( $seen[ self::case_key( $ancestor ) ] ) && 'file' === $seen[ self::case_key( $ancestor ) ] ) { return self::error( 'zip_collision', '压缩包将文件当作目录使用。' ); }
					$directories[ self::case_key( $ancestor ) ] = true;
				}
				$size = (int) $stat['size']; $packed = (int) $stat['comp_size'];
				if ( $size < 0 || $packed < 0 || $size > (int) $limits['max_file_bytes'] ) { return self::error( 'zip_size', '压缩包中的单个文件超过允许大小。' ); }
				$bytes += $size; $compressed += $packed;
				if ( $bytes > (int) $limits['max_bytes'] ) { return self::error( 'zip_size', '压缩包解压后的总大小超过限制。' ); }
				if ( $size > 1048576 && $size / max( 1, $packed ) > (float) $limits['max_ratio'] ) { return self::error( 'zip_ratio', '压缩比异常，已停止处理。' ); }
				$ignored = ! empty( $limits['ignore_junk'] ) && self::junk( $path );
				if ( ! $ignored ) {
					if ( empty( $limits['allow_sensitive'] ) && self::sensitive( $path ) ) { return self::error( 'zip_sensitive', '升级包包含配置、数据库或私钥文件。' ); }
					if ( empty( $limits['allow_php'] ) && preg_match( '/\.(?:php\d*|phtml|phar)$/i', $path ) ) { return self::error( 'zip_php', '此操作不接受可执行 PHP 文件。' ); }
					$roots[ explode( '/', $path )[0] ] = true;
				}
				if ( ! $directory ) { ++$files; }
				$entries[] = array( 'name' => $path, 'source_name' => (string) $stat['name'], 'directory' => $directory, 'ignored' => $ignored, 'bytes' => $size, 'compressed_bytes' => $packed, 'crc' => sprintf( '%08x', (int) $stat['crc'] ) );
			}
			if ( empty( $roots ) || ( ! empty( $limits['require_single_root'] ) && 1 !== count( $roots ) ) ) { return self::error( 'zip_root', '升级包必须只包含一个目标目录。' ); }
			return array( 'entries' => $entries, 'files' => $files, 'bytes' => $bytes, 'compressed_bytes' => $compressed, 'top_level' => array_keys( $roots ), 'sha256' => hash_file( 'sha256', $file ) );
		} finally { $zip->close(); }
	}

	/** Copy streams into a new, empty directory; never call extractTo(). */
	public static function extract( string $file, string $destination, array $limits = array() ) {
		$info = self::inspect( $file, $limits );
		if ( is_wp_error( $info ) ) { return $info; }
		$limits = array_merge( self::DEFAULTS, $limits );
		if ( file_exists( $destination ) || is_link( $destination ) ) { return self::error( 'zip_destination', '解压目录已存在，请创建新的暂存目录。' ); }
		$parent = realpath( dirname( $destination ) );
		if ( ! $parent || ! self::same_path( $parent, dirname( $destination ) ) || is_link( dirname( $destination ) ) || ! self::ordinary_name( basename( $destination ) ) ) { return self::error( 'zip_destination', '解压目录不安全。' ); }
		if ( ! mkdir( $destination, 0700 ) ) { return self::error( 'zip_destination', '无法创建私有解压目录。' ); }
		$root = realpath( $destination ); $zip = new \ZipArchive();
		if ( ! $root || true !== $zip->open( $file, \ZipArchive::RDONLY ) ) { return self::error( 'zip_invalid', '解压前无法重新打开压缩包。' ); }
		$total = 0; $written = 0;
		try {
			foreach ( $info['entries'] as $entry ) {
				if ( $entry['ignored'] ) { continue; }
				$target = $root . '/' . rtrim( $entry['name'], '/' );
				$directory = $entry['directory'] ? $target : dirname( $target );
				if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) ) { return self::error( 'zip_write', '无法创建解压子目录。' ); }
				if ( ! self::within( (string) realpath( $directory ), $root ) || is_link( $directory ) ) { return self::error( 'zip_destination', '解压目录越过了暂存区。' ); }
				if ( $entry['directory'] ) { continue; }
				$input = $zip->getStream( $entry['source_name'] ); $output = fopen( $target, 'xb' );
				if ( ! is_resource( $input ) || ! is_resource( $output ) ) {
					if ( is_resource( $input ) ) { fclose( $input ); } if ( is_resource( $output ) ) { fclose( $output ); }
					return self::error( 'zip_write', '无法读取或写入压缩包文件。' );
				}
				$size = 0; $crc = hash_init( 'crc32b' );
				try {
					while ( ! feof( $input ) ) {
						$chunk = fread( $input, 131072 );
						if ( false === $chunk ) { return self::error( 'zip_read', '压缩包数据读取失败。' ); }
						$size += strlen( $chunk ); $total += strlen( $chunk );
						if ( $size > $entry['bytes'] || $size > (int) $limits['max_file_bytes'] || $total > (int) $limits['max_bytes'] ) { return self::error( 'zip_size', '压缩包实际展开大小与清单不符。' ); }
						hash_update( $crc, $chunk );
						if ( '' !== $chunk && strlen( $chunk ) !== fwrite( $output, $chunk ) ) { return self::error( 'zip_write', '压缩包文件未完整写入，可能磁盘已满。' ); }
					}
				} finally { fclose( $input ); fclose( $output ); }
				if ( $size !== $entry['bytes'] || hash_final( $crc ) !== strtolower( $entry['crc'] ) ) { return self::error( 'zip_crc', '压缩包文件校验失败。' ); }
				chmod( $target, 0600 ); ++$written;
			}
			return array( 'files' => $written, 'bytes' => $total, 'sha256' => $info['sha256'] );
		} finally { $zip->close(); }
	}

	/** Snapshot an installed tree without following links or overwriting an artifact. */
	public static function create_tree( string $source, string $file, string $prefix = '', array $exclude = array(), array $limits = array() ) {
		if ( ! class_exists( '\ZipArchive' ) ) { return self::error( 'zip_missing', '服务器没有可用的 ZIP 扩展。' ); }
		$root = realpath( $source );
		if ( ! $root || ! self::same_path( $root, $source ) || ! is_dir( $root ) || is_link( $source ) || file_exists( $file ) || is_link( $file ) ) { return self::error( 'zip_source', '快照来源或目标文件不安全。' ); }
		if ( self::within( self::normalize( $file ), $root ) ) { return self::error( 'zip_source', '快照文件不能写进被备份的目录。' ); }
		$prefix = trim( $prefix, '/' );
		if ( '' !== $prefix && is_wp_error( self::entry_path( $prefix . '/' ) ) ) { return self::error( 'zip_path', '快照目录名不安全。' ); }
		$limits = array_merge( self::DEFAULTS, array( 'ignore_junk' => false ), $limits ); $zip = new \ZipArchive();
		if ( true !== $zip->open( $file, \ZipArchive::CREATE | \ZipArchive::EXCL ) ) { return self::error( 'zip_write', '无法创建新的快照压缩包。' ); }
		$files = array(); $bytes = 0; $failure = null;
		try {
			$stack = array( $root );
			while ( $stack ) {
				$directory = array_pop( $stack ); $items = scandir( $directory );
				if ( false === $items ) { $failure = self::error( 'zip_source', '无法读取快照来源目录。' ); break; }
				foreach ( $items as $name ) {
					if ( '.' === $name || '..' === $name ) { continue; }
					$path = $directory . '/' . $name; $relative = ltrim( substr( self::normalize( $path ), strlen( self::normalize( $root ) ) ), '/' );
					$skip = false; foreach ( $exclude as $item ) { $item = trim( str_replace( '\\', '/', (string) $item ), '/' ); if ( $relative === $item || str_starts_with( $relative, $item . '/' ) ) { $skip = true; break; } }
					if ( $skip ) { continue; }
					if ( is_link( $path ) || ! self::same_path( (string) realpath( $path ), $path ) || ! self::within( (string) realpath( $path ), $root ) ) { $failure = self::error( 'zip_link', '快照来源包含链接或越界目录。' ); break 2; }
					$entry = ( '' === $prefix ? '' : $prefix . '/' ) . $relative;
					if ( is_wp_error( self::entry_path( $entry ) ) || ( empty( $limits['allow_sensitive'] ) && self::sensitive( $entry ) ) ) { $failure = self::error( 'zip_sensitive', '快照来源包含不允许的配置或路径。' ); break 2; }
					if ( is_dir( $path ) ) { $zip->addEmptyDir( $entry ); $stack[] = $path; continue; }
					if ( ! is_file( $path ) || ! is_readable( $path ) ) { $failure = self::error( 'zip_source', '快照来源存在无法读取的文件。' ); break 2; }
					$size = filesize( $path ); $bytes += $size;
					if ( count( $files ) >= $limits['max_files'] || $size > $limits['max_file_bytes'] || $bytes > $limits['max_bytes'] ) { $failure = self::error( 'zip_size', '快照超过允许的文件或空间限制。' ); break 2; }
					$files[ $relative ] = array( 'bytes' => $size, 'sha256' => hash_file( 'sha256', $path ) );
					if ( ! $zip->addFile( $path, $entry ) ) { $failure = self::error( 'zip_write', '无法把文件写入快照。' ); break 2; }
				}
			}
		} finally { if ( ! $zip->close() && ! $failure ) { $failure = self::error( 'zip_write', '快照压缩包未完整写入。' ); } }
		if ( $failure ) { if ( is_file( $file ) ) { unlink( $file ); } return $failure; }
		foreach ( $files as $relative => $expected ) {
			$path = $root . '/' . $relative;
			if ( ! is_file( $path ) || filesize( $path ) !== $expected['bytes'] || hash_file( 'sha256', $path ) !== $expected['sha256'] ) { unlink( $file ); return self::error( 'zip_changed', '备份过程中来源文件发生变化，请稍后重试。' ); }
		}
		$verified = self::inspect( $file, $limits );
		if ( is_wp_error( $verified ) ) { unlink( $file ); return $verified; }
		ksort( $files, SORT_STRING );
		return array( 'files' => count( $files ), 'bytes' => $bytes, 'sha256' => $verified['sha256'], 'manifest' => $files );
	}

	private static function entry_path( string $name ) {
		if ( '' === $name || strlen( $name ) > 1024 || ! preg_match( '//u', $name ) || str_contains( $name, '\\' ) || str_starts_with( $name, '/' ) ) { return self::error( 'zip_path', '压缩包包含不安全或无法识别的路径。' ); }
		$path = rtrim( $name, '/' );
		if ( '' === $path ) { return self::error( 'zip_path', '压缩包包含空路径。' ); }
		foreach ( explode( '/', $path ) as $part ) { if ( ! self::ordinary_name( $part ) ) { return self::error( 'zip_path', '压缩包包含越界、保留名称或非法字符。' ); } }
		return $path . ( str_ends_with( $name, '/' ) ? '/' : '' );
	}

	private static function ordinary_name( string $part ): bool {
		return '' !== $part && '.' !== $part && '..' !== $part && rtrim( $part, " ." ) === $part && ltrim( $part, ' ' ) === $part && ! preg_match( '/[<>:"|?*\x00-\x1f\x7f]/u', $part ) && ! preg_match( '/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part );
	}

	/** libzip on Windows normalizes backslashes; validate the original central-directory names first. */
	private static function raw_names( string $file ) {
		$h = fopen( $file, 'rb' ); if ( ! $h ) { return self::error( 'zip_read', '无法读取压缩包原始目录。' ); }
		try {
			$size = filesize( $file ); if ( $size < 22 ) { return self::error( 'zip_invalid', '压缩包格式不完整。' ); }
			$tail_size = min( 65557, $size ); fseek( $h, $size - $tail_size ); $tail = fread( $h, $tail_size ); $at = false;
			for ( $i = strlen( $tail ) - 22; $i >= 0; --$i ) { if ( substr( $tail, $i, 4 ) === "PK\x05\x06" && $i + 22 + unpack( 'v', substr( $tail, $i + 20, 2 ) )[1] === strlen( $tail ) ) { $at = $i; break; } }
			if ( false === $at ) { return self::error( 'zip_invalid', '压缩包缺少完整目录结尾。' ); }
			$end = unpack( 'vdisk/vstart/ventries_disk/ventries/Vbytes/Voffset/vcomment', substr( $tail, $at + 4, 18 ) );
			if ( $end['disk'] || $end['start'] || $end['entries'] !== $end['entries_disk'] || 65535 === $end['entries'] || 4294967295 === $end['bytes'] || 4294967295 === $end['offset'] ) { return self::error( 'zip_format', '不接受分卷或ZIP64升级包，请使用普通单卷ZIP。' ); }
			if ( $end['entries'] > 30000 || $end['offset'] + $end['bytes'] > $size - $tail_size + $at ) { return self::error( 'zip_count', '压缩包目录越界或项目数超过限制。' ); }
			fseek( $h, $end['offset'] ); $names = array(); $consumed = 0;
			for ( $i = 0; $i < $end['entries']; ++$i ) {
				$header = fread( $h, 46 ); if ( 46 !== strlen( $header ) || substr( $header, 0, 4 ) !== "PK\x01\x02" ) { return self::error( 'zip_invalid', '压缩包中央目录损坏。' ); }
				$lengths = unpack( 'vname/vextra/vcomment', substr( $header, 28, 6 ) ); $length = $lengths['name'] + $lengths['extra'] + $lengths['comment']; $consumed += 46 + $length;
				if ( $consumed > $end['bytes'] || ! $lengths['name'] ) { return self::error( 'zip_invalid', '压缩包目录长度不一致。' ); }
				$name = fread( $h, $lengths['name'] ); if ( strlen( $name ) !== $lengths['name'] ) { return self::error( 'zip_read', '压缩包目录读取不完整。' ); }
				$names[] = $name; if ( $lengths['extra'] + $lengths['comment'] ) { fseek( $h, $lengths['extra'] + $lengths['comment'], SEEK_CUR ); }
			}
			if ( $consumed !== $end['bytes'] ) { return self::error( 'zip_invalid', '压缩包含不支持的附加目录数据。' ); }
			return $names;
		} finally { fclose( $h ); }
	}

	private static function sensitive( string $path ): bool {
		foreach ( explode( '/', strtolower( rtrim( $path, '/' ) ) ) as $part ) {
			if ( in_array( $part, array( '.git', '.svn', '.hg', '.ssh', 'wp-config.php', '.env', '.htaccess', '.user.ini', 'id_rsa', 'id_ed25519' ), true ) || str_starts_with( $part, '.env.' ) || preg_match( '/\.(?:sql|sqlite|sqlite3|pem|key|pfx|p12|bak)$/', $part ) ) { return true; }
		}
		return false;
	}

	private static function junk( string $path ): bool { return '__MACOSX' === explode( '/', $path )[0] || '.DS_Store' === basename( $path ); }
	private static function case_key( string $name ): string { return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name ); }
	private static function normalize( string $path ): string { return rtrim( str_replace( '\\', '/', $path ), '/' ); }
	private static function same_path( string $a, string $b ): bool { $a = self::normalize( $a ); $b = self::normalize( $b ); return '\\' === DIRECTORY_SEPARATOR ? strtolower( $a ) === strtolower( $b ) : $a === $b; }
	private static function within( string $path, string $root ): bool {
		$path = self::normalize( $path ); $root = self::normalize( $root );
		if ( '\\' === DIRECTORY_SEPARATOR ) { $path = strtolower( $path ); $root = strtolower( $root ); }
		return '' !== $path && ( $path === $root || str_starts_with( $path, $root . '/' ) );
	}
	private static function error( string $code, string $message ): \WP_Error { return new \WP_Error( 'pc_ops_' . $code, $message ); }
}
