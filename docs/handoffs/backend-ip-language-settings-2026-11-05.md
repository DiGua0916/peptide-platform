# 访客 IP 语言识别设置

2026-11-05 · Codex。用户本轮明确选择：**只做识别设置，翻译稍后**。不修改站点前台视觉、商品正文、订单、收货地区、支付、邮件或全站 WPLANG。

## 后端

- 每店独立配置 `pc_storefront_language_settings`，入口：客户后台 → 后台设置 → 系统设置 → 访客语言识别，地址 `admin.php?page=pc-system&section=languages`。保存要求 `pc_manage_settings` 和专用 POST nonce；写入只限该独立 option，不覆盖原品牌/客服/支付设置。
- 初始规则：US → `en`，PR → `es`，其他/无法识别 → `en`。自动识别初始关闭，可由管理员打开；关闭不妨碍客户手动偏好生效。
- 只复用现有 `Tracking\Country_Provider` 和服务端正规化 `REMOTE_ADDR`、本地 MMDB。不相信任意 `X-Forwarded-For`、`CF-IPCountry`、浏览器国家参数或账单国家。不调用外部 IP 服务，不保存原始 IP，不创建访客追踪记录。
- 不在早期 `determine_locale` 中查 GeoIP；接口在 WordPress/Woo 初始化后读取。缺少/失效数据库安全回退默认语言，不创建数据库或后台更新任务。

## 前端后续接入

`GET /wp-json/peptide/v1/storefront-language` 公开只读，返回 `config` 与 `selection`；接口只接受标量 `_locale` 兼容参数，拒绝国家/IP/语言等选择参数。成功和原生 REST 错误均为 `private, no-store, max-age=0`，合并 `Vary: Cookie`。不应被 CDN 的 Cache Everything 规则强制缓存。

```json
{
  "config": {
    "version": 1,
    "enabled": false,
    "default_language": "en",
    "country_languages": {"US": "en", "PR": "es"},
    "supported_languages": [
      {"code": "en", "locale": "en_US", "label": "English"},
      {"code": "es", "locale": "es_PR", "label": "Español"}
    ],
    "preference_cookie": "pc_storefront_language"
  },
  "selection": {
    "language": "en", "locale": "en_US", "source": "disabled",
    "country_code": null, "translation_applied": false
  }
}
```

`source` 为 `manual | geoip | default | disabled`。有效手动偏好优先，即使自动识别关闭；自动打开且无手动偏好时才做国家查询。PR 返回 `es`、US 返回 `en`，其他已识别国家使用默认语言，无法识别的国家为 null。不返回 IP、客户标识或地址。

后续客户选择语言时，前端可写**本站 host-only** cookie `pc_storefront_language=en|es; Path=/; SameSite=Lax; Secure`，设置合理有效期；“自动”选择应删除同名同 Path cookie。此轮后端不写 cookie。请求使用 `credentials: 'same-origin'` 和 `cache: 'no-store'`，不要将个性选择内嵌进共享缓存 HTML，也不要把精确 IP 发给前端/第三方。

**现在还没有翻译消费者**：返回 `es` 只是选定语言，`translation_applied` 固定 false。不得仅设置 `html lang` 就声称页面已翻译。后续由前端与内容工作提供西语 UI 字典、正文译文、手动切换及必要的多语言路由/缓存/SEO，再消费此接口。当前没有自动重定向、后台语言切换或购物车刷新。

## 验证与发布

完成后追加本次实际执行的检查、安装与提交记录；纯规则中的 US/PR 断言不等于真实两地出口 IP 验收。当前用户没有提供波多黎各真实出口，不声称该项已通过。
