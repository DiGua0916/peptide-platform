# 2026-11-02 · Codex · COA-PAGE-01

用户要求独立COA页面，参考截图的报告展示、搜索和PDF查看/下载；随即明确分工：本聊天只做页面与后端功能，视觉排版交前端窗口。以下是实施前确定的增量合同，不改主题、CSS或既有批次发布规则。

- 原生WordPress自定义页面包含 `[pc_report_library]`，保留“装修此页面”的 `custom_<pageID>` / context_content 路径。标题和说明仍可通过页面编辑器调整；前端可以消费下面的API或PHP函数重做视觉。
- 报告的独立资料库公开开关默认关闭。只有明确选入的资料才进入公开列表、PDF及预览接口；原219份的业务draft/admin/batch0含义保留，不伪造库存批次、不调用supersede。公开资料库不表示每份来源报告对应当前在售批次。
- 新增GET `/peptide/v1/report-library`：q、product_id、date_from/date_to、sort(newest/oldest)、page、per_page(1–24)；返回items、total、page、per_page、total_pages、products产品筛选项、filters。无JS也可通过短代码GET表单搜索/筛选/翻页。
- DTO仅含公开展示必要字段：id,title,product(null或id/name/url),source_product,specification,report_number,source_lot,issued_on,issuer_name,file(mime/size_bytes),view_url,download_url,thumbnail_url。无client/内部备注/路径/stored/SHA/导入标记。不发布参考站的平均纯度等统计。
- 新增 `/report-library/{id}/file?download=0|1` 和 `/thumbnail`，每次核显式公开开关和文件有效性；源PDF仍存私有目录，独立路由不能放宽旧`/reports/{id}/file`的访问规则。关闭公开/撤回/回收立即拒绝新请求；缩略图是原PDF首页派生图，不改原PDF。
- 公共PHP入口计划 `peptide_core_get_report_library(array)`，前端不读私有meta、不拼文件路径。查询条件错误返回WP_Error/HTTP400，未知或未公开文件统一404。

## 实际交付

已创建原生页面11710，标题COA report library，地址`/coa-library/`，`pc_report_library_page_id`记录页面ID，内容为可编辑说明和短代码（默认12份/页）。页面发布在本地站，未部署公网；没有修改主题、CSS、导航菜单或其它装修文档。检测报告后台增加“查看COA页面”入口，每份报告增加资料库公开状态；原生报告编辑器新增显式公开复选框，仅publish_pc_reports可操作，新PUBLIC/THUMB私有meta注册权限也使用该发布cap，普通旧meta仍沿原edit权限。

明确选入本轮219份来源文件（11491–11709）：原WP draft /业务draft /admin /batch0与既有归档字段逐项保持。全部219份可在独立资料库显示并查看/下载，198份对应54父品、21份来源品名可搜索但不猜父品，209份明确签发日期/10份空日期，日期未知末尾。默认12份、19页；越界旧页书签自动返回最后有效页。新报告默认不公开，在编辑器勾选后方可进入资料库，不因上传就自动发出。

用pypdfium2从219份原PDF首页生成219张640px宽JPEG，合计12879250字节；原PDF逐份SHA核对不变。预览副本仍在Private_Files私有目录，通过受控thumbnail URL读取，meta source_sha256绑定当前原文件，不将扫描图/原PDF放Git或公共媒体直链。图像原比例保留，不固定高度。未来新上传没有预览时短代码显示真实PDF文字入口，前端不得伪造证书预览。

新增Report_Archive与Report_Archive_Page；Meta、Plugin、functions、Report_Metabox、Reports、Admin\Report_Library_Page共8个PHP源文件及本交接。Report_Archive::query与公共PHP函数、短代码和REST复用同一公开集，管理登录不会额外显示未选文件。产品facet基于完整公开集，搜索也支持公开父品SKU；产品未公开时不返回其内部标题/链接。GET筛选类型/日期/长度非法400，文件未公开或不存在统一404；原批次查询及旧下载仍按旧权限拒绝本批draft/admin文件。

source文件的通用meta add/update/delete挂钩会撤销新增库公开、删除有效旧JPEG/PNG预览并清旧source SHA，封住XML-RPC等绕过仅表单失效的路径；正常metabox上传后计算新SHA，有发布权的操作者可显式重新开启。预览删除会核私有目录/内容MIME，拒绝PDF和指向当前原文件的恶意指针；不会删原报告。永久删报告会清自己的预览。撤回/已替代/演示/私密/密码保护/回收均不进入公开库。

REST查询120次、原文件120次、预览600次/每来源10分钟；沿现有可信代理client_ip规范，transient仅盐化HMAC，不存原IP。这是应用层基本限流，不是防大型攻击或完整防爬。文件每次重查开关、路径realpath边界、大小、内容MIME/扩展；PDF头有效，inline/attachment正确，支持HEAD无body，no-store/nosniff。未实现Range分段请求或大型PDF流式预览优化，当前原件最大约10.5MB可完整查看。

## 实际验证和限制

- 本地原生WP15项：219份恰好一次、54产品facet、10空日期、219有效预览、精确DTO字段、除新增库字段外219份原post/meta不变、82父品/183规格及原批次数保持、独立page/装修目标可编辑、编号/产品/日期筛选、日期排序、非法输入和旧私有下载隔离通过。
- 真实匿名本地HTTP17项：公开219、plain permalink rest_route查询、非法参数400、页面搜索1份、越界返回最后3份、PDF inline/attachment各2404003字节且原SHA相同、HEAD长度与空body、JPEG预览SHA匹配、未公开及旧接口404通过。不是Cookie授权测试，也没有制造新Cookie/账号。
- 实际WP权限/回调11项：内存临时移除publisher能力后旧字段可编辑、PUBLIC/THUMB add/edit meta拒绝；恢复发布能力允许；无nonce/访客即便自身nonce不改数据；内存撤销public后DTO/文件/缩略图都拒绝、旧SHA预览不公开，通过；不改真实角色/账号/公开记录。
- 通用源替换真实WP事务11项：InnoDB单连接内新报告和任务自有PDF/JPEG副本，模拟generic add/update/delete文件，公开撤销/预览及旧SHA清理、publisher表单显式重新开启、原PDF不误删通过。fixture始终未commit且未供其它连接访问，最后ROLLBACK、逐个删除仅本任务临时副本、核SQL无残留/原报告不变/公开仍219；不是在正式列表留测试商品或报告。
- 新服务隔离生产类30项、短代码22项+越界定向7项，范围分别记录。8PHP lint、git diffcheck通过。独立源码审查发现并关闭分页越界、publish cap和generic源替换三个P2，最终pin无剩余P1/P2；审查不是完整渗透测试。系统symlink创建不可用，未冒称真实symlink及端到端XML-RPC通过；权限门通过实际WordPress meta capabilities和通用meta hooks验证。
- 同一IAB实际载入219份/54产品筛选、编号2610010106表单提交后1份，640×829原比例预览已加载。tab42保留。一次fill/click/AX批调用60秒超时，重连原tab后确认提交实际已完成，没有让用户重登或切浏览器。截图`outputs/coa-page-search-functional.png`已看，仅功能验收：没有按参考图做前端排版、没有手机/全站视觉验收。用户明确交前端处理，不能将当前默认表单视作设计交付。

前端接入：可以在页面11710点击“装修此页面”，保留原内容/context_content即可复用短代码（BEM `.pc-coa-library__*`）；或使用`peptide_core_get_report_library()` / REST构建视觉。短代码GET为`coa_q,coa_product,coa_from,coa_to,coa_sort,coa_page`，对应服务filter键如上；每实例结果ID及分页锚点唯一。API页越界仍返回真实total与空items，短代码负责恢复有效页，客户端自建列表须处理。源报告批号显示为Report lot，不等于本站销售batch；缺日期留空，不能抄参考站纯度统计。没有自动改页头/页尾菜单，前端可在明确入口位置加入本页链接。

真实PDF/派生预览、页面及219份公开设置在本地DB/私有目录，均不进Git。服务器迁移必须分别带DB、原PDF和预览文件；pull只更新源码。所有任务证据、before/journal/导入和渲染脚本在D:/Codex/Tasks/2026-11-02/coa-public-page；实际提交与远端核对见DELIVERY.json和主记忆。没有云部署、支付/顾客/订单修改或默认群发言。
