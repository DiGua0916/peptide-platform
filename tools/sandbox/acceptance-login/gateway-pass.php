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
        $passNext = isset($_GET['next']) && is_string($_GET['next']) && preg_match('#^/(?!/)[A-Za-z0-9_\-./?=&%]*$#D', $_GET['next']) ? $_GET['next'] : '/';
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $passNext, true, 303);
        exit;
    }
    // 不对或过期：落回下面的普通登录页。
}
