<?php
/** Bounded parameter compilation; storefront clients keep their existing plain-text ticker contract. */
namespace PeptideCore\Broadcast;

defined( 'ABSPATH' ) || exit;

final class Playback {

	/** Compile one longest-column cycle. No Cartesian products or client-side evaluation. */
	public static function compile( array $config ) {
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
		return array(
			'version' => 1, 'enabled' => $config['enabled'], 'badgeEnabled' => $config['badgeEnabled'],
			'name' => '', 'items' => $items, 'keywords' => $keywords,
			'enterMs' => $config['enterMs'], 'holdMs' => $config['holdMs'], 'exitMs' => $config['exitMs'], 'gapMs' => $config['gapMs'],
		);
	}
}
