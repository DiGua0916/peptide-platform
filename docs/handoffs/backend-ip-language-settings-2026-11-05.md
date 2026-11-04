# 访客 IP 语言识别设置

2026-11-05 · Codex。用户本轮明确选择：**只做识别设置，翻译稍后**。不修改站点前台视觉、商品正文、订单、收货地区、支付、邮件或全站 WPLANG。

## 后端

- 每店独立配置 `pc_storefront_language_settings`，入口：客户后台 → 店铺设置 → 系统设置 → 访客语言识别，地址 `admin.php?page=pc-system&section=languages`。保存要求 `pc_manage_settings` 和专用 POST nonce；写入只限该独立 option，不覆盖原品牌/客服/支付设置。
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

源码提交 `3975a8bb7b1af0bfa94e55869b54ea0078a8361d`，只安装/发布以上三新类与 Plugin/Store_Settings 两个接线文件，共5个PHP；测试与文档不上传运行目录。实际结果：

- 146项核心/控制器、37项管理保存、139项原国家提供器检查通过，原提供器真实MMDB一项明确跳过；5PHP lint、diff检查通过。独审另用真实WordPress REST类离线15项通过。这些均不是海外真实IP测试。
- LocalWP仅复制5file、原工作树另外27个脏文件及index保留。实际匿名HTTP7项通过；浏览器原生表单保存成功，US=en、PR=es、默认=en、开关关闭。首次HTTP检查漏读重复Vary，修正检查脚本后确认所有Vary完整，应用未因此改动。
- 先做两机新的既有age加密备份，不解密，不声称已做恢复。生产首次buyer部署因健康脚本把多行Vary当普通dict覆盖而假失败，已只回退自己的current；保留失败证据。V2只修健康检查的重复头聚合并加强public拒绝，43项工具合成检查及独审通过，未放宽private/no-store/Cookie边界。
- V2重读buyer实际current时同事已更新为pay-trust-sync，完整保留。merchant首次因同事随后也推进pay-trust-sync而在写入前拒绝过时baseline；重新只读核实只有4个受保护支付PHP变化、其他保护不变、5个目标字节不变，重新冻结后发布。
- 商城端于北京时间2026-11-05 00:25、管理端00:28完成；各自克隆实际 `pay-trust-sync-9bf5d98df255-*` current再覆盖5file，完整非白名单manifest、支付/MU/私有配置/CDN/HQ/挂载/生命周期守护保留，优雅FPM reload与4项实际直源TLS健康检查通过。两机不是分布式原子发布；正常HTTP仍执行普通WordPress生命周期，不宣称全服务器零DB副作用。
- 额外生产HTTP7项通过：两端默认en/disabled、合成手动es/manual、伪造country查询400；另一次商城公网Cloudflare GET为DYNAMIC而非HIT，无Age。各响应private/no-store、完整Vary含Cookie、无Set-Cookie；两机5PHP精确SHA匹配，current前后不变。没有下单/付款/改客户地址。
- 已在真实登录的服务器管理页打开并核对3个选择和关闭状态；未在生产点保存或启用开关，未改变原有设置。截图与local/production-http、部署回执在 `D:/Codex/Tasks/2026-11-04/ip-language-settings/outputs/`。

生产最终current：

- buyer `/srv/vialcove/releases/ip-language-3975a8bb7b1a-buyer-1793809526650926714`
- merchant `/srv/vialcove/store-admin/releases/ip-language-3975a8bb7b1a-merchant-1793809679365094667`

**待后续启用时验收**：此轮只做识别设置。静态检查尚未确认生产本地MMDB可用，Woo地区数据库option回退没有读取，不能把“未找到常规候选”当作已证明数据库不存在，也不能声称真实US/PR定位已通过。后续确认MMDB资源及代理正规化IP链路，再用真实两地出口验收；翻译与前端消费者按用户决定暂缓。

Git整合另正常合并同事 `a355c91/c2d6015` 的加购确认/优惠码展示修复，独立执行其28项加购与7项优惠展示测试通过；本次没有把这些主题文件发布到生产。GitHub初次直连失败，改用用户已启用的系统代理仅作用于本次Git命令，不改变机器/全局Git代理设置；最终同步状态以本阶段后续push回执为准。
