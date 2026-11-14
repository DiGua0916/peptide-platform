# 热修复包 A 的沙盒验证

验证三件事在沙盒（MySQL）上真的生效：员工不被追踪、不触发像素；测试单不发购买事件、补发不重复；投手能只读打开订单详情。

只动沙盒，不向外发请求，跑完自己清理。从本机调用：

    scp setup.php cleanup.php run.sh ../accept/cookie.php <后台机>:/var/tmp/f1-hv/
    ssh <后台机> 'bash /var/tmp/f1-hv/run.sh; rm -rf /var/tmp/f1-hv'

输出每行以 PASS / FAIL 开头。需要沙盒里有 `sbx_buyer`（投手）和 `sandbox_admin` 两个账号。
