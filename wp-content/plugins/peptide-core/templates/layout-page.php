<?php
/**
 * 装修预览页（EDIT-BLOCKS-04）：主题的页头 + 当前模板首页的模块 + 主题的页脚。
 * 只在有权限的人预览模板时使用；主题可以提供 peptide-layout.php 替换这个文件。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="vc-layout">
	<?php echo \PeptideCore\Modules\Layout::render_preview( 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 模块渲染时已逐项转义。 ?>
</main>
<?php
get_footer();
