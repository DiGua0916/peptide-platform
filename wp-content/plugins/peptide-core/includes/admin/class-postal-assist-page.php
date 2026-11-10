<?php
namespace PeptideCore\Admin;
use PeptideCore\Postal_Assist;
defined('ABSPATH')||exit;
final class Postal_Assist_Page {
	private const ACTION='pc_postal_assist_save';
	public static function register_hooks():void{add_action('admin_post_'.self::ACTION,array(self::class,'handle'));}
	private static function access():void{if(!current_user_can('manage_woocommerce'))wp_die('没有邮编设置权限。','',array('response'=>403));}
	public static function handle():void{
		self::access();if('POST'!==($_SERVER['REQUEST_METHOD']??''))wp_die('只接受 POST。','',array('response'=>405));check_admin_referer(self::ACTION);Apps_Page::require_allowed(Postal_Assist::APP);
		$s=array();foreach(Postal_Assist::defaults()as$key=>$value){$s[$key]=isset($_POST[$key])&&'1'===$_POST[$key];}
		if($s['enabled']&&$s['google_enabled']&&(!$s['merchant_consent']||!Postal_Assist::google_ready($s)))wp_die('Google 外部查询需要服务端密钥及明确的地址传送披露确认。未保存。','',array('response'=>400));
		update_option(Postal_Assist::OPTION,Postal_Assist::normalize($s),false);wp_safe_redirect(Apps_Page::url(array('app'=>Postal_Assist::APP,'saved'=>'1')));exit;
	}
	public static function render():void{
		self::access();$s=Postal_Assist::settings();echo '<div class="wrap pc-mk"><a href="'.esc_url(Apps_Page::url()).'">返回插件</a><h1>邮编辅助填写</h1><p>仅为美国与波多黎各完整地址补空邮编，不改国家、州、城市或街道。顾客手填内容优先；无法确定时保持手填。</p>';
		if('1'===($_GET['saved']??''))echo '<div class="notice notice-success"><p>配置已保存。</p></div>';
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field(self::ACTION);echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">';
		foreach(array('enabled'=>'开启邮编辅助：仅当前已登录客户自己的已保存完整地址精确匹配，且邮编唯一时补全。','google_enabled'=>'启用可选 Google Address Validation：需要商家配置与顾客明确同意。','merchant_consent'=>'我确认已更新隐私说明、设置服务商配额并允许在顾客同意后发送其完整地址给 Google。')as$key=>$label){echo '<p><label><input type="checkbox" name="'.esc_attr($key).'" value="1"'.checked($s[$key],true,false).'> '.esc_html($label).'</label></p>';}
		echo '<h2>Google 服务端配置（可选）</h2><p>默认没有密钥、不调用外部服务。请由管理员在服务器配置 <code>PC_GOOGLE_ADDRESS_VALIDATION_KEY</code> 常量，并在 Google Cloud 开通 Address Validation API、限制 API/服务器来源和费用配额。密钥不会存入本配置或下发浏览器。</p><p>只接受完整地址、USPS DPV=Y、已确认地址及唯一 ZIP；任何更正、门牌/单元号不明或城市/街道不一致均不填。不会按城市猜邮编，也不读取其他顾客地址或订单。</p><p>客户结账页会显示外部地址传送说明及默认不勾选的同意项；未同意不外传。没有外部服务时，新地址和匿名顾客仍须自己填邮编。</p>';
		submit_button('保存邮编设置');echo '</form></div>';
	}
}
