<?php
/**
 * 总后台 AI 的“素材人群分析”用途（2026-11-13）：没有专门人设时借用全站分析师人设，并追加固定的分析步骤。
 * 纯 PHP：WordPress 函数、加密、店铺表和 HTTP 都是内存替身，不会请求任何 AI 服务。
 * 运行：php wp-content/plugins/peptide-hq/tests/cr-hq-ai-creative-pure.php
 */

namespace WpOrg\Requests {
	final class Requests {
		public const POST = 'POST';
		public const GET  = 'GET';
		public static array $sent = array();
		public static function request( $url, $headers, $body, $method, $opts ) {
			self::$sent[] = array( 'url' => $url, 'body' => json_decode( (string) $body, true ) );
			return (object) array( 'status_code' => 200, 'body' => json_encode( array( 'model' => 'stub-model', 'content' => array( array( 'type' => 'text', 'text' => '1. 来的是什么人' ) ), 'stop_reason' => 'end_turn' ) ) );
		}
	}
}

namespace PeptideHq {
	final class Crypto { public static function decrypt( string $s ): string { return 'stub-key'; } public static function encrypt( string $s ): string { return $s; } }
	final class Sites { public static function get( int $id ): ?array { return array( 'id' => $id, 'name' => '测试店' ); } public static function all(): array { return array( array( 'id' => 7, 'name' => '测试店', 'public_url' => 'https://shop.test' ) ); } }
	final class Register { public const NS = 'peptide-hq/v1'; }
	final class Mail { public static function verify() { return true; } }
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WPINC', 'wp-includes' );
	define( 'DAY_IN_SECONDS', 86400 );
	$GLOBALS['opt'] = array();
	$GLOBALS['tr']  = array();
	class WP_Error { public function __construct( public string $code = '', public string $message = '', public $data = null ) {} public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
	function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['opt'][ $k ] = $v; return true; }
	function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
	function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
	function wp_date( $f, $t = null ) { return gmdate( $f, $t ?? time() ); }
	function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
	function wp_http_validate_url( $u ) { return $u; }
	function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
	function esc_url_raw( $u ) { return $u; }
	function add_action( ...$a ) {}

	require dirname( __DIR__ ) . '/includes/class-ai.php';
	use PeptideHq\Ai;
	use WpOrg\Requests\Requests;

	$n = 0;
	$check = static function ( string $label, bool $ok ) use ( &$n ): void {
		if ( ! $ok ) {
			throw new RuntimeException( 'FAIL ' . $label );
		}
		++$n;
	};

	$check( 'uses: creative registered', isset( Ai::USES['creative'] ) && '素材人群分析' === Ai::USES['creative'] );
	$check( 'task: five fixed sections', 5 === preg_match_all( '/^\d\. /m', Ai::CREATIVE_TASK ) && str_contains( Ai::CREATIVE_TASK, '可信度' ) );

	// 配好服务商、密钥、模型；只有全站分析师人设。
	$GLOBALS['opt'][ Ai::OPTION ]   = array( 'enabled' => true, 'provider' => 'anthropic', 'model' => 'stub', 'keys' => array( 'anthropic' => 'enc' ) );
	$GLOBALS['opt'][ Ai::PERSONAS ] = array( array( 'id' => 'p-biz', 'name' => '全站分析师', 'use' => 'business', 'content' => '你是全站分析师。', 'updated' => 1 ) );
	$cfg = Ai::site_config( 7 );
	$check( 'config: creative has no own persona', null === $cfg['creative'] && is_array( $cfg['business'] ) );

	$r = Ai::analyse( 7, 'creative', array( '素材' => array( '编号' => 'C0002' ) ) );
	$check( 'analyse: falls back to business persona', is_array( $r ) && '全站分析师' === $r['persona'] && '1. 来的是什么人' === $r['text'] );
	$sent = end( Requests::$sent );
	$check( 'request: went to the configured provider only', 'https://api.anthropic.com/v1/messages' === $sent['url'] );
	$check( 'prompt: business persona + creative task', str_starts_with( $sent['body']['system'], '你是全站分析师。' ) && str_contains( $sent['body']['system'], '【本次任务：单条广告素材的人群分析】' ) );
	$check( 'prompt: facts and type in user message', str_contains( $sent['body']['messages'][0]['content'], '分析类型：素材人群分析' ) && str_contains( $sent['body']['messages'][0]['content'], 'C0002' ) );
	$check( 'fallback: no creative copy issued from business template', ! isset( Ai::site_personas()[7]['creative'] ) );

	Requests::$sent = array();
	$r = Ai::analyse( 7, 'business', array( 'x' => 1 ) );
	$sent = end( Requests::$sent );
	$check( 'business: no creative task appended', is_array( $r ) && ! str_contains( $sent['body']['system'], '单条广告素材' ) );

	// 导入专门的素材人群分析人设后改用它。
	$GLOBALS['opt'][ Ai::PERSONAS ][] = array( 'id' => 'p-cr', 'name' => '人群与素材分析师', 'use' => 'creative', 'content' => '你是人群与素材分析师。', 'updated' => 2 );
	Requests::$sent = array();
	$r = Ai::analyse( 7, 'creative', array( 'x' => 1 ) );
	$sent = end( Requests::$sent );
	$check( 'own persona: used when present', is_array( $r ) && '人群与素材分析师' === $r['persona'] && str_starts_with( $sent['body']['system'], '你是人群与素材分析师。' ) && str_contains( $sent['body']['system'], '【本次任务' ) );

	// 两种人设都没有：明确报错，不请求 AI。
	$GLOBALS['opt'][ Ai::PERSONAS ]      = array();
	$GLOBALS['opt'][ Ai::SITE_PERSONAS ] = array();
	Requests::$sent = array();
	$r = Ai::analyse( 7, 'creative', array( 'x' => 1 ) );
	$check( 'no persona: error and no request', is_wp_error( $r ) && 'pchq_ai_persona' === $r->get_error_code() && ! Requests::$sent );

	// 店铺设置保存三种用途。
	$GLOBALS['opt'][ Ai::PERSONAS ] = array( array( 'id' => 'p-cr', 'name' => 'x', 'use' => 'creative', 'content' => 'x', 'updated' => 1 ) );
	Ai::save_settings( array( 'sites' => array( 7 => array( 'enabled' => '1', 'creative' => 'p-cr', 'customer' => 'nope' ) ) ) );
	$row = Ai::settings()['sites'][7];
	$check( 'settings: per-use persona saved for all uses', 'p-cr' === $row['creative'] && '' === $row['customer'] && '' === $row['business'] && true === $row['enabled'] );

	echo 'PASS ' . $n . " HQ creative-audience AI checks; no WordPress, database, or network.\n";
}
