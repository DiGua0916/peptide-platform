<?php
/** Merchant collection workspace, backed by WooCommerce product categories. */
namespace PeptideCore\Admin;

use PeptideCore\Collections;
use PeptideCore\Staff;

defined( 'ABSPATH' ) || exit;

final class Collections_Page {
    public const SLUG = 'pc-collections';
    public const NONCE = 'pc_collections_request';

    public static function register_hooks(): void {
        add_action( 'admin_menu', array( self::class, 'menu' ), 80 );
        add_action( 'admin_init', array( self::class, 'legacy_route' ), 20 );
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 40 );
        add_action( 'wp_ajax_pc_collections_request', array( self::class, 'ajax' ) );
        add_filter( 'parent_file', array( self::class, 'parent_file' ), 30 );
        add_filter( 'submenu_file', array( self::class, 'submenu_file' ), 30 );
    }

    public static function allowed(): bool {
        return current_user_can( 'manage_product_terms' ) && current_user_can( 'edit_products' )
            && ( ! Staff::is_staff( get_current_user_id() ) || in_array( 'products', Staff::granted( get_current_user_id() ), true ) );
    }

    public static function url( $id = null ): string {
        $args = array( 'page' => self::SLUG );
        if ( null !== $id ) { $args['collection'] = $id; }
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    public static function menu(): void {
        add_submenu_page( 'edit.php?post_type=product', '商品专辑', '商品专辑', 'manage_product_terms', self::SLUG, array( self::class, 'render' ) );
        remove_submenu_page( 'edit.php?post_type=product', 'edit-tags.php?taxonomy=product_cat&post_type=product' );
    }

    public static function legacy_route(): void {
        global $pagenow;
        if ( ! self::allowed() || wp_doing_ajax() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
            || ! in_array( $pagenow, array( 'term.php', 'edit-tags.php' ), true )
            || 'product_cat' !== ( $_GET['taxonomy'] ?? '' )
            || ( isset( $_GET['action'] ) && ! in_array( $_GET['action'], array( '', 'edit' ), true ) ) ) { return; }
        $id = 'term.php' === $pagenow ? absint( $_GET['tag_ID'] ?? 0 ) : null;
        if ( $id && ! get_term( $id, 'product_cat' ) instanceof \WP_Term ) { return; }
        wp_safe_redirect( self::url( $id ?: null ) );
        exit;
    }

    public static function parent_file( $parent ) { return ( $_GET['page'] ?? '' ) === self::SLUG ? 'edit.php?post_type=product' : $parent; }
    public static function submenu_file( $submenu ) { return ( $_GET['page'] ?? '' ) === self::SLUG ? self::SLUG : $submenu; }

    public static function render(): void {
        if ( ! self::allowed() ) { wp_die( esc_html__( 'You cannot manage product collections.', 'peptide-core' ), '', array( 'response' => 403 ) ); }
        echo '<div class="wrap pc-collections-workspace"><h1 class="screen-reader-text">商品专辑</h1><div id="pc-collections-app"><p role="status">正在读取专辑…</p></div><noscript><p>专辑管理需要启用 JavaScript。商品仍可在商品管理中编辑。</p></noscript></div>';
    }

    public static function assets(): void {
        if ( ( $_GET['page'] ?? '' ) !== self::SLUG || ! self::allowed() ) { return; }
        wp_enqueue_media();
        wp_enqueue_editor();
        $url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
        wp_enqueue_style( 'pc-collections-admin', $url . 'collections-admin.css', array(), PEPTIDE_CORE_VERSION . '.' . filemtime( PEPTIDE_CORE_DIR . '/assets/collections-admin.css' ) );
        wp_enqueue_script( 'pc-collections-admin', $url . 'collections-admin.js', array( 'jquery', 'editor', 'wp-util' ), PEPTIDE_CORE_VERSION . '.' . filemtime( PEPTIDE_CORE_DIR . '/assets/collections-admin.js' ), true );
        $id = $_GET['collection'] ?? 'list';
        if ( ! is_scalar( $id ) || ( ! in_array( $id, array( 'list', 'new' ), true ) && ! ctype_digit( (string) $id ) ) ) { $id = 'list'; }
        wp_localize_script( 'pc-collections-admin', 'pcCollections', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( self::NONCE ), 'collectionId' => $id, 'baseUrl' => self::url(), 'mediaTitle' => '选择专辑封面', 'mediaButton' => '使用此图片' ) );
    }

    public static function ajax(): void {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_send_json_error( array( 'message' => '请使用 POST 提交。', 'code' => 'method' ), 405 ); }
        if ( ! self::allowed() || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'message' => '登录已过期或没有管理权限，请刷新后重试。', 'code' => 'forbidden' ), 403 ); }
        $raw = $_POST['payload'] ?? '{}';
        if ( ! is_string( $raw ) || strlen( $raw ) > 1500000 ) { wp_send_json_error( array( 'message' => '提交内容过大。', 'code' => 'payload' ), 400 ); }
        $data = json_decode( wp_unslash( $raw ), true );
        $operation = $_POST['operation'] ?? '';
        if ( ! is_array( $data ) || ! is_string( $operation ) ) { wp_send_json_error( array( 'message' => '提交格式无效。', 'code' => 'payload' ), 400 ); }
        try { $result = self::handle( sanitize_key( $operation ), $data ); }
        catch ( \Throwable $error ) { $result = new \WP_Error( 'pc_collection_unavailable', '操作暂时未能完成，请刷新专辑后重试。', array( 'status' => 503 ) ); }
        if ( is_wp_error( $result ) ) {
            $info = $result->get_error_data();
            $code = $result->get_error_code();
            $status = str_contains( $code, 'conflict' ) ? 409 : ( str_contains( $code, 'forbidden' ) ? 403 : 400 );
            wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $code ), is_array( $info ) ? (int) ( $info['status'] ?? $status ) : $status );
        }
        wp_send_json_success( $result );
    }

    /** Kept separate for read-only rendering checks and isolated write tests. */
    public static function handle( string $operation, array $data ) {
        if ( ! self::allowed() ) { return new \WP_Error( 'pc_collection_forbidden', '没有管理专辑的权限。', array( 'status' => 403 ) ); }
        if ( isset( $data['id'] ) && ( ( ! is_int( $data['id'] ) && ! ( is_string( $data['id'] ) && ctype_digit( $data['id'] ) ) ) || (int) $data['id'] < 0 ) ) { return new \WP_Error( 'pc_collection_id', '专辑编号无效。' ); }
        $id = (int) ( $data['id'] ?? 0 );
        $revision = is_int( $data['revision'] ?? null ) ? $data['revision'] : ( is_string( $data['revision'] ?? null ) && ctype_digit( $data['revision'] ) ? (int) $data['revision'] : 0 );
        switch ( $operation ) {
            case 'list':
                $result = Collections::all( $data );
                $result['items'] = array_map( array( self::class, 'collection_dto' ), $result['items'] );
                return $result;
            case 'get':
                $collection = Collections::get( $id );
                if ( ! $collection ) { return new \WP_Error( 'pc_collection_missing', '找不到这个专辑。', array( 'status' => 404 ) ); }
                $products = Collections::products( $id, array( 'page' => 1, 'per_page' => 20 ) );
                if ( is_wp_error( $products ) ) { return $products; }
                return array( 'collection' => self::collection_dto( $collection ), 'products' => self::products_dto( $products ) );
            case 'save':
                $data['confirm_rules'] = true === ( $data['confirm_rule_replace'] ?? false );
                $saved = Collections::save( $id, $data, $revision );
                return is_wp_error( $saved ) ? $saved : array( 'collection' => self::collection_dto( $saved ) );
            case 'products':
                $products = Collections::products( $id, $data );
                return is_wp_error( $products ) ? $products : self::products_dto( $products );
            case 'membership':
                $mode = $data['mode'] ?? '';
                if ( ! in_array( $mode, array( 'add', 'remove', 'order' ), true ) || ! is_array( $data['product_ids'] ?? null ) || count( $data['product_ids'] ) > 10000 ) { return new \WP_Error( 'pc_collection_members', '商品操作格式无效。' ); }
                $saved = Collections::mutate_membership( $id, 'order' === $mode ? 'reorder' : $mode, $data['product_ids'], $revision );
                return is_wp_error( $saved ) ? $saved : array( 'collection' => self::collection_dto( $saved ) );
            case 'preview_rules':
                if ( ! is_array( $data['rules'] ?? null ) ) { return new \WP_Error( 'pc_collection_rules', '请选择自动添加规则。' ); }
                $result = Collections::preview_rules( $data['rules'], array( 'page' => 1, 'per_page' => 12 ) );
                return is_wp_error( $result ) ? $result : array( 'count' => $result['total'], 'samples' => self::products_dto( $result )['items'] );
            case 'favorite':
                $collection = Collections::get( $id );
                if ( ! $collection ) { return new \WP_Error( 'pc_collection_missing', '找不到这个专辑。' ); }
                $collection['favorite'] = true === ( $data['value'] ?? false );
                $saved = Collections::save( $id, $collection, $revision );
                return is_wp_error( $saved ) ? $saved : array( 'collection' => self::collection_dto( $saved ) );
            case 'duplicate':
                $collection = Collections::get( $id );
                if ( ! $collection || $collection['revision'] !== $revision ) { return new \WP_Error( 'pc_collection_conflict', '专辑已被修改，请刷新后重试。', array( 'status' => 409 ) ); }
                $saved = Collections::duplicate( $id );
                return is_wp_error( $saved ) ? $saved : array( 'collection' => self::collection_dto( $saved ) );
            case 'delete':
                if ( true !== ( $data['confirmed'] ?? false ) ) { return new \WP_Error( 'pc_collection_confirm', '请先确认删除专辑；商品不会被删除。' ); }
                $deleted = Collections::delete( $id, $revision );
                return is_wp_error( $deleted ) ? $deleted : array( 'deleted' => true );
            case 'export':
                return self::export_document( is_array( $data['ids'] ?? null ) ? $data['ids'] : array() );
            case 'import_preview':
            case 'import_apply':
                if ( 'import_apply' === $operation && true !== ( $data['confirmed'] ?? false ) ) { return new \WP_Error( 'pc_collection_import_confirm', '请先预览并确认导入。' ); }
                return self::import_document( $data['document'] ?? null, 'import_apply' === $operation && true === ( $data['confirmed'] ?? false ) );
        }
        return new \WP_Error( 'pc_collection_operation', '不支持此专辑操作。' );
    }

    public static function collection_dto( array $row ): array {
        $row['count'] = (int) ( $row['product_count'] ?? $row['count'] ?? 0 );
        $row['views'] = null;
        $row['edit_url'] = self::url( $row['id'] );
        return $row;
    }

    private static function products_dto( array $result ): array {
        $result['items'] = array_map( static function( array $row ): array {
            $row['image'] = $row['image_url'] ?? '';
            $row['price_html'] = wp_kses_post( $row['price_html'] ?? '' );
            return $row;
        }, $result['items'] ?? array() );
        return $result;
    }

    private static function export_document( array $ids ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        if ( ! $ids ) {
            $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids', 'number' => 501 ) );
            if ( is_wp_error( $terms ) ) { return $terms; }
            if ( count( $terms ) > 500 ) { return new \WP_Error( 'pc_collection_export_limit', '请分批选择专辑导出，每批最多500个。' ); }
            $ids = $terms;
        }
        if ( count( $ids ) > 500 ) { return new \WP_Error( 'pc_collection_export_limit', '每批最多导出500个专辑。' ); }
        $rows = array();
        foreach ( $ids as $id ) {
            $row = Collections::get( (int) $id );
            if ( ! $row ) { return new \WP_Error( 'pc_collection_missing', '有专辑已不存在，请刷新后重试。' ); }
            $export = array_intersect_key( $row, array_flip( array( 'name', 'slug', 'description', 'footer_description', 'thumbnail_id', 'favorite', 'type', 'sort', 'rules', 'seo_title', 'seo_description' ) ) );
            $members = get_objects_in_term( $id, 'product_cat' );
            if ( is_wp_error( $members ) || count( $members ) > 10000 ) { return new \WP_Error( 'pc_collection_export_members', '专辑商品过多或读取失败，请分批操作。' ); }
            $members = array_values( array_unique( array_merge( $row['manual_order'] ?? array(), array_map( 'intval', $members ) ) ) );
            $export['products'] = array();
            foreach ( $members as $product_id ) {
                if ( ! has_term( $id, 'product_cat', $product_id ) ) { continue; }
                $product = wc_get_product( $product_id );
                if ( ! $product || 'product' !== get_post_type( $product_id ) || ! current_user_can( 'edit_post', $product_id ) || in_array( $product->get_status(), array( 'trash', 'auto-draft' ), true ) ) { continue; }
                $export['products'][] = array( 'id' => $product_id, 'sku' => $product->get_sku() );
            }
            $rows[] = $export;
        }
        $document = array( 'format' => 'peptide-product-collections', 'version' => 1, 'source_site' => home_url(), 'collections' => $rows );
        if ( strlen( wp_json_encode( $document ) ) > 1000000 ) { return new \WP_Error( 'pc_collection_export_size', '导出内容超过1MB，请减少本次所选专辑数量。' ); }
        return array( 'filename' => 'product-collections-' . gmdate( 'Ymd-His' ) . '.json', 'document' => $document );
    }

    /** Imports create new collections only; an existing slug is never overwritten. */
    private static function import_document( $document, bool $apply ) {
        if ( ! is_array( $document ) || 'peptide-product-collections' !== ( $document['format'] ?? '' ) || 1 !== ( $document['version'] ?? null ) || ! is_array( $document['collections'] ?? null ) || ! $document['collections'] || count( $document['collections'] ) > 100 ) { return new \WP_Error( 'pc_collection_import', '请选择本站导出的专辑JSON文件，每批最多导入100个新专辑。' ); }
        if ( isset( $document['source_site'] ) && ! is_string( $document['source_site'] ) ) { return new \WP_Error( 'pc_collection_import', '导出来源格式无效。' ); }
        $prepared = array(); $seen = array();
        foreach ( $document['collections'] as $index => $row ) {
            if ( ! is_array( $row ) || ! is_string( $row['name'] ?? null ) || '' === trim( $row['name'] ) || mb_strlen( $row['name'] ) > 250 ) { return new \WP_Error( 'pc_collection_import_name', '第' . ( $index + 1 ) . '个专辑名称无效。' ); }
            $row = array_intersect_key( $row, array_flip( array( 'name', 'slug', 'description', 'footer_description', 'thumbnail_id', 'favorite', 'type', 'sort', 'rules', 'seo_title', 'seo_description', 'products' ) ) );
            $row['name'] = trim( sanitize_text_field( $row['name'] ) );
            if ( '' === $row['name'] ) { return new \WP_Error( 'pc_collection_import_name', '专辑名称不能为空。' ); }
            foreach ( array( 'slug', 'description', 'footer_description', 'seo_title', 'seo_description' ) as $field ) {
                if ( isset( $row[ $field ] ) && ! is_string( $row[ $field ] ) ) { return new \WP_Error( 'pc_collection_import_field', '第' . ( $index + 1 ) . '个专辑的文字字段格式无效。' ); }
            }
            if ( isset( $row['favorite'] ) && ! is_bool( $row['favorite'] ) ) { return new \WP_Error( 'pc_collection_import_field', '常用专辑标记格式无效。' ); }
            $slug = sanitize_title( is_string( $row['slug'] ?? null ) && '' !== $row['slug'] ? $row['slug'] : $row['name'] );
            if ( '' === $slug || strlen( $slug ) > 200 || isset( $seen[ $slug ] ) || get_term_by( 'slug', $slug, 'product_cat' ) ) { return new \WP_Error( 'pc_collection_import_exists', '专辑别名“' . $slug . '”过长、已存在或重复；导入不会覆盖已有专辑。' ); }
            $seen[ $slug ] = true; $row['slug'] = $slug;
            if ( ! in_array( $row['type'] ?? 'manual', array( 'manual', 'automatic' ), true ) || ! in_array( $row['sort'] ?? 'default', array( 'default', 'manual', 'created_desc', 'sales_desc', 'price_asc', 'price_desc', 'title_asc' ), true ) ) { return new \WP_Error( 'pc_collection_import_settings', '专辑类型或排序方式无效。' ); }
            if ( isset( $row['rules'] ) ) { $rules = Collections::clean_rules( $row['rules'] ); if ( is_wp_error( $rules ) ) { return $rules; } $row['rules'] = $rules; }
            $same_site = untrailingslashit( (string) ( $document['source_site'] ?? '' ) ) === untrailingslashit( home_url() );
            if ( $same_site && isset( $row['thumbnail_id'] ) ) {
                $cover = $row['thumbnail_id'];
                if ( ( ! is_int( $cover ) && ! ( is_string( $cover ) && ctype_digit( $cover ) ) ) || (int) $cover < 0 ) { return new \WP_Error( 'pc_collection_import_cover', '专辑封面编号无效。' ); }
                $cover = (int) $cover;
                if ( $cover && ( 'attachment' !== get_post_type( $cover ) || ! wp_attachment_is_image( $cover ) || ! current_user_can( 'edit_post', $cover ) ) ) { return new \WP_Error( 'pc_collection_import_cover', '专辑封面已失效或无编辑权限，请修正后再导入。' ); }
                $row['thumbnail_id'] = $cover;
            }
            if ( 'automatic' === ( $row['type'] ?? 'manual' ) ) {
                $rules = Collections::preview_rules( is_array( $row['rules'] ?? null ) ? $row['rules'] : array() );
                if ( is_wp_error( $rules ) ) { return $rules; }
                $row['confirm_rules'] = true;
            }
            $ids = array();
            if ( ! is_array( $row['products'] ?? array() ) || count( $row['products'] ?? array() ) > Collections::MAX_PRODUCTS ) { return new \WP_Error( 'pc_collection_import_products', '专辑商品清单格式或数量无效。' ); }
            foreach ( $row['products'] ?? array() as $item ) {
                if ( ! is_array( $item ) ) { return new \WP_Error( 'pc_collection_import_products', '专辑商品清单格式无效。' ); }
                if ( isset( $item['id'] ) && ( ( ! is_int( $item['id'] ) && ! ( is_string( $item['id'] ) && ctype_digit( $item['id'] ) ) ) || (int) $item['id'] < 0 ) ) { return new \WP_Error( 'pc_collection_import_products', '商品编号格式无效。' ); }
                if ( isset( $item['sku'] ) && ! is_string( $item['sku'] ) ) { return new \WP_Error( 'pc_collection_import_products', '商品SKU格式无效。' ); }
                $product_id = 0;
                if ( untrailingslashit( (string) ( $document['source_site'] ?? '' ) ) === untrailingslashit( home_url() ) ) { $product_id = absint( $item['id'] ?? 0 ); }
                if ( ! $product_id && is_string( $item['sku'] ?? null ) && '' !== $item['sku'] ) { $product_id = (int) wc_get_product_id_by_sku( $item['sku'] ); }
                $product = $product_id ? wc_get_product( $product_id ) : false;
                if ( ! $product || 'product' !== get_post_type( $product_id ) || ! current_user_can( 'edit_post', $product_id ) || in_array( $product->get_status(), array( 'trash', 'auto-draft' ), true ) ) { return new \WP_Error( 'pc_collection_import_product_missing', '部分商品无法匹配或无编辑权限，请先确认商品已在本站建立。' ); }
                $ids[] = $product_id;
            }
            $row['_import_product_ids'] = array_values( array_unique( $ids ) );
            if ( 'manual' === ( $row['type'] ?? 'manual' ) ) { $row['product_ids'] = $row['_import_product_ids']; }
            if ( 'manual' === ( $row['sort'] ?? 'default' ) ) { $row['manual_order'] = $row['_import_product_ids']; }
            // Attachment IDs are local. Foreign covers are never fetched or guessed.
            if ( untrailingslashit( (string) ( $document['source_site'] ?? '' ) ) !== untrailingslashit( home_url() ) ) { $row['thumbnail_id'] = 0; }
            $prepared[] = $row;
        }
        if ( ! $apply ) { return array( 'count' => count( $prepared ), 'collection_count' => count( $prepared ), 'names' => array_column( $prepared, 'name' ), 'message' => '将新建这些专辑，不会覆盖已有专辑或更改商品价格。' ); }
        $created = array(); $errors = array();
        foreach ( $prepared as $row ) {
            unset( $row['_import_product_ids'] );
            $saved = Collections::save( 0, $row, 0 );
            if ( is_wp_error( $saved ) ) { $errors[] = $saved->get_error_message(); break; }
            $created[] = $saved['id'];
        }
        return array( 'created' => $created, 'count' => count( $created ), 'errors' => $errors, 'partial' => (bool) $errors );
    }
}
