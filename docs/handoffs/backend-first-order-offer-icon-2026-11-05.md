# 新人券图标精修 · 2026-11-05

用户要求将紧凑新人券左侧图标做得更精致。仅替换 `class-first-order-offer.php` 的装饰 SVG 与 `first-order-offer.css` 的 seal 样式：原5%笑脸换为叠层礼券、细边齿孔、蝴蝶结礼物及小星点；桌面52px、手机44px，使用64×64矢量画布。保持原单领取按钮及全部优惠逻辑。

本机两文件已备份、CAS安装，未改JS、优惠配置、数据库、购物车或订单。PHP lint和git diff检查通过；实际IAB360px尺寸44×44、无横向溢出，图标细节可见；独立只读审查无裁切或全局CSS污染。此低影响外观修订未新增业务测试，也不声称重新验证支付链路。

截图及备份收据：`D:/Codex/Tasks/2026-11-05/first-order-offer-icon/outputs/refined-icon-mobile.png`、`installed.json`。本轮未发布正式服务器。
