<?php
/** Server-owned image policy for the two explicitly opted-in Contact rich fields. */
namespace PeptideCore\Modules;

use PeptideCore\Templates;
defined( 'ABSPATH' ) || exit;

final class Rich_Media {
	public const APPROVED_META = '_pc_rich_media_approved';
	public const PUBLISHED_META = '_pc_rich_media_published_refs';
	private int $document;
	private bool $editing;
	private array $approved;
	private array $map = array();
	private function __construct( int $document, bool $editing, array $approved ) {
		$this->document = $document; $this->editing = $editing; $this->approved = $approved;
	}
	public static function supports( array $field ): bool {
		return Rich_Html::supports_color( $field ) && 'image' === ( $field['richTextMedia'] ?? null );
	}
	private static function refs( int $id, string $key ): array {
		$value = get_post_meta( $id, $key, true );
		return is_array( $value ) && 1 === ( $value['version'] ?? null ) && is_array( $value['refs'] ?? null ) ? $value['refs'] : array();
	}
	/** Actor and target are read on the server; request JSON cannot create this object. */
	public static function editing( int $id ): ?self {
		$post = get_post( $id );
		return $post && Templates::LAYOUT === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password && current_user_can( 'edit_post', $id )
			? new self( $id, true, self::refs( $id, self::APPROVED_META ) ) : null;
	}
	/** Only the actual selected, server-published snapshot grants its exact approved references. */
	public static function published( int $id, array $modules ): ?self {
		if ( ! Layout::is_published( $id ) || $modules !== Layout::modules_from_content( (string) get_post_meta( $id, Layout::PUBLISHED_META, true ) ) ) { return null; }
		return new self( $id, false, self::refs( $id, self::PUBLISHED_META ) );
	}
	/** No authority for special layout-parent media; ordinary public images still work. */
	public static function public_only(): self { return new self( 0, false, array() ); }
	public static function error( string $message ): \WP_Error { return new \WP_Error( 'pc_rich_media_invalid', $message, array( 'status' => 400 ) ); }
	public static function has_images( $modules ): bool {
		$found = false;
		self::walk( $modules, static function( $value ) use ( &$found ) { if ( is_string( $value ) && preg_match( '/<\s*img\b/i', $value ) ) { $found = true; } } );
		return $found;
	}
	/** Complete lexical probe over fixed server-schema paths; no HTML parsing or client-controlled recursion.
	 * Do not truncate this probe to the later parser budget: overflow images must still be detected. */
	private static function walk( $modules, callable $visit ): void {
		foreach ( is_array( $modules ) ? $modules : array() as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$type = Registry::type_for_block( (string) ( $row['block'] ?? '' ) ); $def = $type ? Registry::get( $type ) : null;
			if ( ! $def ) { continue; }
			$attrs = is_array( $row['attrs'] ?? null ) ? $row['attrs'] : array();
			$read = static function( array $fields, $settings ) use ( $visit ): void {
				if ( ! is_array( $settings ) ) { return; }
				foreach ( $fields as $field ) {
					if ( ! self::supports( $field ) || ! array_key_exists( $field['key'], $settings ) ) { continue; }
					$value = $settings[ $field['key'] ];
					if ( ! empty( $field['device'] ) && is_array( $value ) ) { foreach ( array( 'desktop', 'mobile' ) as $device ) { if ( isset( $value[ $device ] ) ) { $visit( $value[ $device ] ); } } }
					else { $visit( $value ); }
				}
			};
			foreach ( array( $attrs, is_array( $attrs['mobile'] ?? null ) ? $attrs['mobile'] : array() ) as $variant ) {
				$read( $def['settings'], $variant['settings'] ?? array() );
				foreach ( is_array( $variant['items'] ?? null ) ? $variant['items'] : array() as $item ) { if ( is_array( $item ) ) { $read( $def['items']['fields'] ?? array(), $item['settings'] ?? array() ); } }
			}
		}
	}
	/** Apply bounded HTML-processing budgets only after the complete, side-effect-free image probe. */
	private static function limits( array $modules ): ?\WP_Error {
		if ( count( $modules ) > 80 ) { return new \WP_Error( 'pc_rich_media_limit', __( 'Designs with rich text images support up to 80 blocks. Remove extra blocks before saving.', 'peptide-core' ), array( 'status' => 400 ) ); }
		foreach ( $modules as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$type = Registry::type_for_block( (string) ( $row['block'] ?? '' ) ); $def = $type ? Registry::get( $type ) : null;
			if ( ! $def || empty( $def['items'] ) || ! array_filter( $def['items']['fields'] ?? array(), array( self::class, 'supports' ) ) ) { continue; }
			$attrs = is_array( $row['attrs'] ?? null ) ? $row['attrs'] : array();
			foreach ( array( $attrs, is_array( $attrs['mobile'] ?? null ) ? $attrs['mobile'] : array() ) as $variant ) {
				if ( is_array( $variant['items'] ?? null ) && count( $variant['items'] ) > (int) $def['items']['max'] ) { return new \WP_Error( 'pc_rich_media_limit', sprintf( __( 'This block supports up to %d items per device. Remove extra items before saving rich text images.', 'peptide-core' ), (int) $def['items']['max'] ), array( 'status' => 400 ) ); }
			}
		}
		return null;
	}
	/** A preflight never writes content, approval, a token, or publication metadata. */
	public static function preflight( $modules, ?self $context = null ) {
		$context = $context ?? self::public_only();
		if ( ! self::has_images( $modules ) ) { return $context; }
		$limit = self::limits( $modules ); if ( $limit ) { return $limit; }
		$failure = null;
		self::walk( $modules, static function( $value ) use ( $context, &$failure ): void {
			if ( $failure || ! is_string( $value ) || ! preg_match( '/<\s*img\b/i', $value ) ) { return; }
			$result = Fields::rich_media_html( $value, $context ); if ( is_wp_error( $result ) ) { $failure = $result; }
		} );
		return $failure ?? $context;
	}
	public function map(): array { return $this->map; }
	public function commit( bool $published = false ): void {
		if ( ! $this->editing || ! $this->document ) { return; }
		update_post_meta( $this->document, $published ? self::PUBLISHED_META : self::APPROVED_META, array( 'version' => 1, 'refs' => array_keys( $this->map ) ) );
	}
	private static function valid_layout( $post ): bool {
		if ( ! $post || Templates::LAYOUT !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) { return false; }
		$template = get_post( (int) $post->post_parent );
		return $template && Templates::TEMPLATE === $template->post_type && 'publish' === $template->post_status && '' === $template->post_password;
	}
	/** Rebuild all image attributes; submitted URLs, dimensions, event/style/srcset are ignored. */
	public function image( $processor ) {
		$id_raw = $processor->get_attribute( 'data-pc-attachment' ); $key_raw = $processor->get_attribute( 'data-pc-asset' );
		$id = is_string( $id_raw ) && preg_match( '/^[1-9][0-9]{0,9}$/D', $id_raw ) ? (int) $id_raw : 0;
		$key = is_string( $key_raw ) && preg_match( '/^[a-z0-9][a-z0-9_-]{0,99}$/D', $key_raw ) ? $key_raw : '';
		if ( ( $id > 0 ) === ( '' !== $key ) || ( null !== $id_raw && ! $id ) || ( null !== $key_raw && '' === $key ) ) { return self::error( __( 'Choose an image from the media library or a registered site asset.', 'peptide-core' ) ); }
		$alt = $processor->get_attribute( 'alt' ); $explicit_alt = is_string( $alt ); $ref = $id ? 'attachment:' . $id : 'asset:' . $key;
		if ( $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type || ! in_array( $post->post_status, array( 'inherit', 'publish' ), true ) || '' !== $post->post_password || ! wp_attachment_is_image( $id ) || metadata_exists( 'post', $id, '_pc_review_owner' ) || metadata_exists( 'post', $id, '_pc_review_file' ) ) { return self::error( __( 'This image is not available for public rich text.', 'peptide-core' ) ); }
			if ( $post->post_parent ) {
				$parent = get_post( (int) $post->post_parent );
				if ( $parent && Templates::LAYOUT === $parent->post_type ) {
					if ( ! self::valid_layout( $parent ) || ! self::valid_layout( get_post( $this->document ) ) || ( ! in_array( $ref, $this->approved, true ) && ! ( $this->editing && current_user_can( 'read_post', $id ) && current_user_can( 'edit_post', $parent->ID ) ) ) ) { return self::error( __( 'This design image has not been approved for this document.', 'peptide-core' ) ); }
				} elseif ( ! $parent || '' !== $parent->post_password || ! is_post_publicly_viewable( $parent ) ) { return self::error( __( 'The image belongs to content that is not public.', 'peptide-core' ) ); }
			}
			$src = wp_get_attachment_image_src( $id, 'full' );
			$image = $src ? array( 'src' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2], 'alt' => $explicit_alt ? $alt : (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : null;
		} else {
			$asset = Assets::all()[ $key ] ?? null;
			$image = $asset ? array( 'src' => $asset['url'], 'width' => $asset['width'], 'height' => $asset['height'], 'alt' => $explicit_alt ? $alt : '' ) : null;
		}
		if ( ! $image || ! is_string( $image['src'] ) || ! preg_match( '~^https?://~i', $image['src'] ) || ! filter_var( $image['src'], FILTER_VALIDATE_URL ) || $image['width'] < 1 || $image['height'] < 1 ) { return self::error( __( 'This image has no valid public image source or natural dimensions.', 'peptide-core' ) ); }
		$image['alt'] = sanitize_text_field( $image['alt'] ); $image['alt'] = function_exists( 'mb_substr' ) ? mb_substr( $image['alt'], 0, 200 ) : substr( $image['alt'], 0, 200 );
		$image['attachmentId'] = $id ?: null; $image['assetKey'] = $key ?: null; $this->map[ $ref ] = $image;
		return '<img class="pc-rich-image"' . ( $id ? ' data-pc-attachment="' . $id . '"' : ' data-pc-asset="' . esc_attr( $key ) . '"' ) . ' src="' . esc_attr( $image['src'] ) . '" width="' . $image['width'] . '" height="' . $image['height'] . '" alt="' . esc_attr( $image['alt'] ) . '">';
	}
}
