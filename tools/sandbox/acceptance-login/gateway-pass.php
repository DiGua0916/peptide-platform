<?php
// 沙盒验收专用通行链接：由 install.sh 插到 gateway.php 里“$config = …”那一行之后。
// GET /_sandbox/login?k=<过期时间>.<签名>&next=<本站路径>：签名和期限都对就发访问 cookie，然后跳到 next。
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['k']) && is_string($_GET['k'])) {
    $passHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $passCfg = is_readable(__DIR__ . '/acceptance.json') ? json_decode((string) file_get_contents(__DIR__ . '/acceptance.json'), true) : null;
    $passParts = explode('.', $_GET['k']);
    if (is_array($passCfg) && !empty($passCfg['key']) && in_array($passHost, $config['hosts'], true)
        && count($passParts) === 2 && ctype_digit($passParts[0]) && (int) $passParts[0] >= time()
        && hash_equals(hash_hmac('sha256', 'gate.' . $passParts[0] . '.' . $passHost, (string) $passCfg['key']), $passParts[1])) {
        $passExpiry = (int) $passParts[0];
        $passSig = rtrim(strtr(base64_encode(md5((string) $passExpiry . $passHost . ' ' . $config['secret'], true)), '+/', '-_'), '=');
        $passOpt = ['expires' => $passExpiry, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];
        setcookie('vc_sbx_sig', $passSig, $passOpt);
        setcookie('vc_sbx_exp', (string) $passExpiry, $passOpt);
        // 用过签名链接的这个 IP 记进名单（最多 10 个）：之后直接打开地址也不用再点链接。
        $passIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $passIps = is_array($passCfg['ips'] ?? null) ? $passCfg['ips'] : [];
        if ($passIp !== '' && !in_array($passIp, $passIps, true) && count($passIps) < 10) {
            $passCfg['ips'] = array_merge($passIps, [$passIp]);
            @file_put_contents(__DIR__ . '/acceptance.json', json_encode($passCfg), LOCK_EX);
        }
        $passNext = isset($_GET['next']) && is_string($_GET['next']) && preg_match('#^/(?!/)[A-Za-z0-9_\-./?=&%]*$#D', $_GET['next']) ? $_GET['next'] : '/';
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $passNext, true, 303);
        exit;
    }
    // 不对或过期：落回下面的普通登录页。
}
// 验收期间：名单里的 IP 打开任何沙盒地址都直接放行，不出现登录页（名单在 acceptance.json 的 ips 里，到期或删掉文件后失效）。
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !isset($_GET['k'])) {
    $ipCfg = is_readable(__DIR__ . '/acceptance.json') ? json_decode((string) file_get_contents(__DIR__ . '/acceptance.json'), true) : null;
    $ipHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (is_array($ipCfg) && !empty($ipCfg['ips']) && (int) ($ipCfg['expires'] ?? 0) >= time() && in_array($ipHost, $config['hosts'], true)
        && in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (array) $ipCfg['ips'], true)) {
        $ipExpiry = (int) $ipCfg['expires'];
        $ipSig = rtrim(strtr(base64_encode(md5((string) $ipExpiry . $ipHost . ' ' . $config['secret'], true)), '+/', '-_'), '=');
        $ipOpt = ['expires' => $ipExpiry, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];
        setcookie('vc_sbx_sig', $ipSig, $ipOpt);
        setcookie('vc_sbx_exp', (string) $ipExpiry, $ipOpt);
        header('Cache-Control: private, no-store');
        header('Location: ' . (str_starts_with($ipHost, 'sandbox-shop.') ? '/' : '/wp-admin/'), true, 303);
        exit;
    }
}
