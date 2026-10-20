<?php
/**
 * 装修页面（EDIT-BLOCKS-04 / 05）：主题的页头 + 当前模板首页的模块 + 主题的页脚。
 * 员工预览时显示草稿，访客看到已发布的版本；主题可以提供 peptide-layout.php 替换这个文件。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="vc-layout">
	<?php echo \PeptideCore\Modules\Layout::render_current( 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 模块渲染时已逐项转义。 ?>
</main>
<?php
get_footer();
