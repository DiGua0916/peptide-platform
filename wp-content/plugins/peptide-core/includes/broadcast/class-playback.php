<?php
/** Bounded parameter compilation; storefront clients keep their existing plain-text ticker contract. */
namespace PeptideCore\Broadcast;

defined( 'ABSPATH' ) || exit;

final class Playback {

	/** Deterministic legacy fallback plus the referenced templates needed for runtime parameter sampling. */
	public static function compile( array $config ) {
		// Mask before compiling either fallback text or runtime pools; raw values never leave the management DTO.
		$config['parameters'] = array_map( static function ( array $column ): array {
			$values = ! empty( $column['privacy'] ) ? array_map( array( self::class, 'mask_value' ), $column['values'] ) : $column['values'];
			// This flag preserves independently sourced pool entries that happen to share the same masked display.
			return array( 'key' => $column['key'], 'values' => $values, 'privacy' => ! empty( $column['privacy'] ) );
		}, $config['parameters'] );
		$mode = $config['parameterMode'] ?? 'random';
		if ( ! in_array( $mode, array( 'random', 'ordered' ), true ) ) { return Config::error( 'parameterMode', 'invalid_mode', '参数抽取方式只能为 random 或 ordered。' ); }
		if ( 'random' === $mode ) {
			$valid = self::validate_random_lengths( $config );
			if ( is_wp_error( $valid ) ) { return $valid; }
		}
		$columns = array(); $steps = count( $config['items'] );
		foreach ( $config['parameters'] as $column ) {
			$columns[ $column['key'] ] = $column['values'];
			foreach ( $config['items'] as $template ) {
				if ( str_contains( $template, '{' . $column['key'] . '}' ) ) { $steps = max( $steps, count( $column['values'] ) ); break; }
			}
		}
		$render = static function ( string $text, int $step ) use ( $columns ): string {
			return (string) preg_replace_callback( '/\{([^{}]+)\}/u', static function ( array $match ) use ( $columns, $step ): string {
				return isset( $columns[ $match[1] ] ) ? $columns[ $match[1] ][ $step % count( $columns[ $match[1] ] ) ] : $match[0];
			}, $text );
		};
		$items = array(); $seen = array();
		for ( $step = 0; $config['items'] && $step < $steps; ++$step ) {
			$text = Config::clean_text( $render( $config['items'][ $step % count( $config['items'] ) ], $step ), 'items.' . $step );
			if ( is_wp_error( $text ) ) { return $text; }
			$lower = Config::lower( $text ); if ( is_wp_error( $lower ) ) { return $lower; }
			if ( '' !== $text && ! isset( $seen[ $lower ] ) ) { $items[] = $text; $seen[ $lower ] = true; }
		}
		$keywords = array(); $seen = array();
		foreach ( $config['keywords'] as $index => $keyword ) {
			$repeat = 1;
			foreach ( $columns as $key => $values ) { if ( str_contains( $keyword, '{' . $key . '}' ) ) { $repeat = max( $repeat, count( $values ) ); } }
			for ( $step = 0; $step < $repeat; ++$step ) {
				$text = Config::clean_text( $render( $keyword, $step ), 'keywords.' . $index );
				if ( is_wp_error( $text ) ) { return $text; }
				$lower = Config::lower( $text ); if ( is_wp_error( $lower ) ) { return $lower; }
				if ( '' === $text || isset( $seen[ $lower ] ) ) { continue; }
				// Legacy clients receive a bounded fallback; the complete runtime pools/templates remain below.
				if ( count( $keywords ) >= Config::MAX_ITEMS ) { break 2; }
				$seen[ $lower ] = true; $keywords[] = $text;
			}
		}
		// Only referenced parameter values belong in public playback. Unused table columns stay private.
		$referenced = array();
		foreach ( array_merge( $config['items'], $config['keywords'] ) as $text ) {
			preg_match_all( '/\{([^{}]+)\}/u', $text, $matches );
			foreach ( $matches[1] as $key ) { $referenced[ $key ] = true; }
		}
		$parameters = array_values( array_filter( $config['parameters'], static fn( array $column ): bool => isset( $referenced[ $column['key'] ] ) ) );
		return array(
			'version' => 1, 'enabled' => $config['enabled'], 'badgeEnabled' => $config['badgeEnabled'],
			'name' => '', 'items' => $items, 'keywords' => $keywords,
			'parameterMode' => $mode, 'templates' => $config['items'], 'keywordTemplates' => $config['keywords'], 'parameters' => $parameters,
			'enterMs' => $config['enterMs'], 'holdMs' => $config['holdMs'], 'exitMs' => $config['exitMs'], 'gapMs' => $config['gapMs'],
		);
	}

	/** Hide every whitespace-delimited word while retaining only its first Unicode character. */
	public static function mask_value( string $value ): string {
		if ( class_exists( '\Normalizer' ) ) { $value = (string) \Normalizer::normalize( $value, \Normalizer::FORM_C ); }
		return implode( ' ', array_map( static function ( string $word ): string {
			if ( '***' === $word || preg_match( '/\A.\*{3}\z/us', $word ) ) { return $word; }
			preg_match_all( '/./us', $word, $characters );
			return count( $characters[0] ) > 1 ? $characters[0][0] . '***' : '***';
		}, preg_split( '/[\s\p{Z}]+/u', trim( $value ), -1, PREG_SPLIT_NO_EMPTY ) ) );
	}

	/** Bound every possible independent draw without enumerating a Cartesian product or consuming RNG. */
	private static function validate_random_lengths( array $config ) {
		$longest = array();
		foreach ( $config['parameters'] as $column ) {
			$length = 0;
			foreach ( $column['values'] as $value ) { $length = max( $length, (int) preg_match_all( '/./us', $value ) ); }
			$longest[ $column['key'] ] = $length;
		}
		foreach ( array( 'items', 'keywords' ) as $field ) {
			foreach ( $config[ $field ] as $index => $template ) {
				$length = (int) preg_match_all( '/./us', $template );
				preg_match_all( '/\{([^{}]+)\}/u', $template, $matches, PREG_SET_ORDER );
				foreach ( $matches as $match ) {
					if ( array_key_exists( $match[1], $longest ) ) { $length += $longest[ $match[1] ] - (int) preg_match_all( '/./us', $match[0] ); }
				}
				if ( $length > Config::MAX_TEXT_LENGTH ) {
					return Config::error( $field . '.' . $index, 'too_long_combination', sprintf( '%s.%d 的随机参数组合可能超过 %d 个字符，请缩短模板或参数值。', $field, $index, Config::MAX_TEXT_LENGTH ), array( 'maxCharacters' => $length ) );
				}
			}
		}
		return true;
	}
}
