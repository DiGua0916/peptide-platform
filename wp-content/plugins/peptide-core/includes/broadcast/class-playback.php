<?php
/** Bounded parameter compilation; storefront clients keep their existing plain-text ticker contract. */
namespace PeptideCore\Broadcast;

defined( 'ABSPATH' ) || exit;

final class Playback {

	/** Deterministic legacy fallback plus the referenced templates needed for runtime parameter sampling. */
	public static function compile( array $config ) {
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
				$seen[ $lower ] = true; $keywords[] = $text;
				if ( count( $keywords ) > Config::MAX_ITEMS ) { return Config::error( 'keywords', 'too_many_expanded', '重点词替换参数后最多 1,000 项，请减少重点词或参数值。' ); }
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
