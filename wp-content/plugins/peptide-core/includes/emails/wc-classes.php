<?php
/**
 * 注册到 WooCommerce 的邮件类名（EMAIL-CENTER-01）。
 *
 * WooCommerce 用邮件类名做设置页的 section 参数和预览的 type 参数，读取时会去掉反斜杠，
 * 所以带命名空间的类名无法编辑和预览。这里用不带命名空间的同名子类注册，实现仍在 PeptideCore\Emails 里。
 * 本文件只在 woocommerce_email_classes 时载入（那时 WC_Email 已经存在）。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.ClassComment.Missing
final class PC_Email_Cart_Recovery_1 extends PeptideCore\Emails\Cart_Recovery_1 {}
final class PC_Email_Cart_Recovery_2 extends PeptideCore\Emails\Cart_Recovery_2 {}
final class PC_Email_Cart_Recovery_3 extends PeptideCore\Emails\Cart_Recovery_3 {}
final class PC_Email_Browse_Reminder extends PeptideCore\Emails\Browse_Reminder {}
final class PC_Email_Register_Invite extends PeptideCore\Emails\Register_Invite {}
final class PC_Email_Register_Verify extends PeptideCore\Emails\Register_Verify {}
final class PC_Email_Admin_Abandoned extends PeptideCore\Emails\Admin_Abandoned {}
final class PC_Email_Admin_Duplicate extends PeptideCore\Emails\Admin_Duplicate {}
final class PC_Email_Admin_Offline extends PeptideCore\Emails\Admin_Offline {}
// phpcs:enable
