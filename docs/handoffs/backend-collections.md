# 商品专辑后台与前台查询契约

2026-11-01，Codex。用户本聊天明确负责后端；这里只修改 Peptide Core 客户后台与查询/数据功能，不修改公开店铺主题或其它窗口在途前端源码。

## 数据与页面

专辑继续使用原生 `product_cat`。当前原站实时82个已上架母商品/183规格，Bestselling（353）确有82个关联商品。旧term.php只编辑分类资料，不显示商品列表，不代表丢失关联。新入口 `admin.php?page=pc-collections`，编辑 `&collection=353`，商品管理段 `#products`；旧product_cat GET列表/编辑地址转新页，原生POST和其它taxonomy不接管。

后台提供列表搜索/类型/常用筛选、封面/真实商品数/类型/排序/修改时间、复制/删除、JSON导入导出；编辑名称/slug/上下富文本描述/封面/常用/SEO；专辑内商品真实查询、名称/SKU筛选、分页、网格/列表、手动加入/移出、自动规则预览与确认、独立手动排序。访问量没有可靠累计接口，因此明确“未统计”；不编造YY的历史统计。未保存编辑时不允许成员/排序动作覆盖文案。

`_pc_collection`为version1元数据，保type、favorite、sort、footer_description、rules、manual_order、updated_at和内部revision计数；公开API不暴露这些管理状态/规则或私密成员ID。名称、slug、上方描述及thumbnail_id沿用原生字段。SEO复用`_pc_seo`，安全规范化保留原keywords，product_cat标题/meta/canonical/OG使用现有Seo链路，分页URL独立。

## 排序与前端读取

用户明确“专辑里的排序就是前端展示顺序”。每专辑单独存manual_order，不写全局商品menu_order，同商品在不同专辑可以有不同顺序。原生Woo分类主查询默认使用专辑排序，访客显式选择其它排序时遵循其选择；首次切手动保存完整当前序列。

`Collections::product_query_args($term_id,$wc_args)`将专辑次序映射成完整有序include，保limit/offset及已有include交集；`Modules\Products::for_module`的category来源、`collection_page`分页已接入，普通关联专辑积木和分类页读取同一排序。全目录/手选商品积木、自定义销量排行榜保自己的语义，不按某个无关专辑排序。

提议并随本阶段实施公共只读 `peptide_core_get_collection(int $id): ?array`，字段为id/name/slug/url/description/footer_description/image/sort。只返回公开分类资料和公开图片，无revision/rules/manual_order/私有商品ID。前端窗口可用此函数读取用户保存的上下文案与封面，不需读 `_pc_*`。**本轮不修改主题来排版或插入上下文案/封面**；前端模板的对应展示接入仍由前端窗口负责，不能把后台保存说成已经重新排版公开站点。

## 写入、权限与验证

AJAX `pc_collections_request`仅登录POST、独立nonce、taxonomy/product权限，并检查Staff的products授权。领域写入在原生分类表InnoDB事务和advisory lock中执行，48位乐观token同时覆盖原生字段、meta和真实关系变化；旧表单拒绝保存。成员操作仅父商品，移出只改当前term，不改价格/库存/其它专辑。自动规则空条件拒绝，变更需预览确认；价格、库存、标签、状态、规格SKU变化会触发真实成员同步。导入仅创建新slug，预检查失效封面/格式/商品映射，不覆盖旧专辑；每项完整保存，批次异常如实报告partial。

实际验证和最终提交收据将在完成本阶段后补记。当前测试与证据根：`D:/Codex/Tasks/2026-11-01/product-album-management`，服务与控制器测试仅在有marker的56139隔离WP创建唯一前缀fixture并精确清理；原站只读核对与浏览器列表/编辑页检查，不为演示而改真实专辑顺序。

## 本阶段实际验收收据

核心领域38组真实QA、控制器/积木顺序17组真实QA、SEO17项无数据库断言均通过，唯一自建对象已清理。原站独立15项与最终9项只读通过；Bestselling82关联未变化、仍82上架母商品/183规格。实际IAB见紧凑专辑列表与82商品管理，未在原站保存或重排。公开metadata新getter另经匿名只读检查确认无管理状态泄漏；核心领域38组时源码pin见其报告，后续新增getter不改已测事务/规则实现。源码PHP/JS语法与diff通过。

原站UI的读链路已真实浏览，写链路采用隔离WordPress/Woo对象验证，不声称浏览器每一个动作/实体手机/生产环境全部通过。旧专辑未凭空补封面/修改时间/访问量；外部搜索平台联调继续留空。
