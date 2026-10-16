<?php
/**
 * 网易云「本人授权」音源 · PHP 后端
 * ------------------------------------------------------------------
 * 思路：把你本人的网易云 VIP 账号 Cookie 保存在这台 PHP 服务器上，
 * 由本脚本用 NetEase 的 WEAPI / EAPI 协议向网易云取回「下载直链」。
 * 洛雪音乐（LX Music / lxserver）端只需一个极薄的 JS 桥接源，
 * 把 songmid + 音质转给本服务即可，Cookie 永远不会离开你的服务器。
 *
 * 仅用于个人学习与研究，请使用你本人账号、仅收听你有权收听的内容，
 * 并遵守网易云相关服务条款。
 *
 * 依赖：PHP 7.4+ ，扩展 openssl、curl（无需 bcmath / gmp）。
 */

declare(strict_types=1);

// ============================ 配置加载 ============================
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    send_json(['error' => '[WY:CONFIG] 未找到 config.php，请复制 config.php.example 为 config.php 并填写 Cookie'], 500);
}
$config = require $configFile;
$GLOBALS['config'] = $config;

// Cookie 优先使用「网页端/接口写入的可写数据文件」（data/cookie.txt），
// 回退到 config.php。这样无需重新上传 config.php 即可在网页端修改 Cookie。
$cookie = trim((string) ($config['cookie'] ?? ''));
$dataCookieFile = __DIR__ . '/data/cookie.txt';
if (is_file($dataCookieFile)) {
    $dataCookie = trim((string) @file_get_contents($dataCookieFile));
    if ($dataCookie !== '') {
        $cookie = $dataCookie;
    }
}
if ($cookie === '') {
    send_json(['error' => '[WY:CONFIG] 未配置 Cookie：请在 config.php 填写，或在管理页(/admin.php)设置'], 500);
}

// ============================ 可选鉴权 ============================
if (!empty($config['apiToken']) && is_string($config['apiToken'])) {
    $provided = $_SERVER['HTTP_X_SOURCE_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!is_string($provided) || !hash_equals((string) $config['apiToken'], $provided)) {
        send_json(['error' => '[WY:AUTH] 访问令牌无效'], 401);
    }
}

// ============================ 协议常量 ============================
const NETEASE_IV          = '0102030405060708';
const NETEASE_PRESET_KEY  = '0CoJUm6Qyw8W8jud';
const NETEASE_EAPI_KEY    = 'e82ckenh8dichen8';
const NETEASE_RSA_MODULUS = '00e0b509f6259df8642dbc35662901477df22677ec152b5ff68ace615bb7b725152b3ab17a876aea8a5aa76d2e417629ec4ee341f56135fccf695280104e0312ecbda92557c93870114af6c9d05c4f7f0c3685b7a46bee255932575cce10b424d813cfe4875d3e82047b97ddef52741d546b8e289dc6935b3ece0462db0a22b8e7';
const NETEASE_RSA_EXP     = '65537';

const DOWNLOAD_PATH = '/api/song/enhance/download/url/v1';
const DOWNLOAD_URL  = 'https://interface.music.163.com/eapi/song/enhance/download/url/v1';
const LOGIN_URL     = 'https://interface.music.163.com/weapi/w/nuser/account/get';
const LYRIC_URL     = 'https://interface.music.163.com/weapi/song/lyric/v1';
const LYRIC_LEGACY_URL = 'https://interface.music.163.com/api/song/lyric';

const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

// ============================ 工具函数 ============================
function send_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $code, string $msg): array
{
    return ['error' => "[WY:{$code}] {$msg}"];
}

function positive_id($value): ?string
{
    if (is_int($value) && $value > 0) {
        $value = (string) $value;
    }
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    return preg_match('/^[1-9]\d*$/', $value) ? $value : null;
}

function is_list($arr): bool
{
    if (!is_array($arr)) {
        return false;
    }
    $i = 0;
    foreach ($arr as $k => $v) {
        if ($k !== $i++) {
            return false;
        }
    }
    return true;
}

function quality_map(): array
{
    return [
        '128k'       => ['level' => 'standard', 'format' => 'mp3',  'bitrate' => 128000],
        '320k'       => ['level' => 'exhigh',   'format' => 'mp3',  'bitrate' => 320000],
        'flac'       => ['level' => 'lossless', 'format' => 'flac'],
        'flac24bit'  => ['level' => 'hires',    'format' => 'flac'],
    ];
}

function parse_cookie(string $raw): array
{
    $raw = preg_replace('/^Cookie:\s*/i', '', $raw);
    if (preg_match('/[\x00-\x1f\x7f]/', $raw)) {
        throw new RuntimeException('[WY:CONFIG] Cookie 必须为单行，不能包含控制字符');
    }
    $cookies = [];
    foreach (explode(';', $raw) as $item) {
        $item = trim($item);
        if ($item === '') {
            continue;
        }
        $at = strpos($item, '=');
        if ($at < 1) {
            throw new RuntimeException('[WY:CONFIG] Cookie 格式应为 name=value; name=value');
        }
        $key   = trim(substr($item, 0, $at));
        $value = trim(substr($item, $at + 1));
        if (!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $key) || preg_match('/["\\\\]/', $value)) {
            throw new RuntimeException('[WY:CONFIG] Cookie 含有无效字符，请复制请求头的 Cookie 值');
        }
        if (array_key_exists($key, $cookies)) {
            throw new RuntimeException('[WY:CONFIG] Cookie 存在重复字段，请使用同一次请求的 Cookie');
        }
        $cookies[$key] = $value;
    }
    if (empty($cookies['MUSIC_U']) || empty($cookies['__csrf'])) {
        throw new RuntimeException('[WY:CONFIG] Cookie 需要同时包含 MUSIC_U 和 __csrf；请登录网页版后重新复制');
    }
    $cookies['os']     = $cookies['os'] ?? 'pc';
    $cookies['appver'] = $cookies['appver'] ?? '3.1.17.204416';
    return $cookies;
}

// ============================ 加密（WEAPI / EAPI / RSA） ============================
// ============================ 纯 PHP 大整数（无需 bcmath / gmp 扩展） ============================
// 内部表示：以 _BI_BASE 为基的「小端 limb 数组」（$a[0] 为最低位）。
// 仅用于 RSA 模幂运算，可移植到任何启用了 64 位整数或 32 位整数的 PHP。
const _BI_BASE = PHP_INT_SIZE >= 8 ? 1000000000 : 10000;

function _bi_trim(array $a): array
{
    $a = array_values($a);
    while (count($a) > 1 && end($a) === 0) {
        array_pop($a);
    }
    return $a;
}

function _bi_is_zero(array $a): bool
{
    return count($a) === 1 && $a[0] === 0;
}

function _bi_from_int(int $n): array
{
    $n = $n < 0 ? 0 : $n;
    if ($n === 0) {
        return [0];
    }
    $limbs = [];
    while ($n > 0) {
        $limbs[] = $n % _BI_BASE;
        $n = intdiv($n, _BI_BASE);
    }
    return $limbs;
}

function _bi_from_hex(string $hex): array
{
    $hex = strtolower(ltrim($hex, '0'));
    if ($hex === '') {
        return [0];
    }
    $num = [0];
    $len = strlen($hex);
    for ($i = 0; $i < $len; $i++) {
        $d = hexdec($hex[$i]);
        $carry = $d;
        for ($k = 0; $k < count($num); $k++) {
            $prod = $num[$k] * 16 + $carry;
            $num[$k] = $prod % _BI_BASE;
            $carry = intdiv($prod, _BI_BASE);
        }
        while ($carry > 0) {
            $num[] = $carry % _BI_BASE;
            $carry = intdiv($carry, _BI_BASE);
        }
    }
    return $num;
}

function _bi_cmp(array $a, array $b): int
{
    $a = _bi_trim($a);
    $b = _bi_trim($b);
    $la = count($a);
    $lb = count($b);
    if ($la !== $lb) {
        return $la <=> $lb;
    }
    for ($i = $la - 1; $i >= 0; $i--) {
        if ($a[$i] !== $b[$i]) {
            return $a[$i] <=> $b[$i];
        }
    }
    return 0;
}

function _bi_add(array $a, array $b): array
{
    $res = [];
    $carry = 0;
    $n = max(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        $s = ($a[$i] ?? 0) + ($b[$i] ?? 0) + $carry;
        $res[$i] = $s % _BI_BASE;
        $carry = intdiv($s, _BI_BASE);
    }
    if ($carry) {
        $res[] = $carry;
    }
    return $res;
}

function _bi_sub(array $a, array $b): array
{
    // 调用方保证 $a >= $b
    $res = [];
    $borrow = 0;
    $n = count($a);
    for ($i = 0; $i < $n; $i++) {
        $s = ($a[$i] ?? 0) - ($b[$i] ?? 0) - $borrow;
        if ($s < 0) {
            $s += _BI_BASE;
            $borrow = 1;
        } else {
            $borrow = 0;
        }
        $res[$i] = $s;
    }
    return _bi_trim($res);
}

function _bi_mul(array $a, array $b): array
{
    $a = _bi_trim($a);
    $b = _bi_trim($b);
    if (_bi_is_zero($a) || _bi_is_zero($b)) {
        return [0];
    }
    $n = count($a);
    $m = count($b);
    $res = array_fill(0, $n + $m, 0);
    for ($i = 0; $i < $n; $i++) {
        $carry = 0;
        for ($j = 0; $j < $m; $j++) {
            $prod = $a[$i] * $b[$j] + $res[$i + $j] + $carry;
            $res[$i + $j] = $prod % _BI_BASE;
            $carry = intdiv($prod, _BI_BASE);
        }
        $res[$i + $m] += $carry;
    }
    $carry = 0;
    for ($i = 0; $i < count($res); $i++) {
        $v = $res[$i] + $carry;
        $res[$i] = $v % _BI_BASE;
        $carry = intdiv($v, _BI_BASE);
    }
    while ($carry) {
        $res[] = $carry % _BI_BASE;
        $carry = intdiv($carry, _BI_BASE);
    }
    return _bi_trim($res);
}

function _bi_mul_small(array $a, int $x): array
{
    if ($x === 0) {
        return [0];
    }
    $res = [];
    $carry = 0;
    for ($i = 0; $i < count($a); $i++) {
        $prod = $a[$i] * $x + $carry;
        $res[$i] = $prod % _BI_BASE;
        $carry = intdiv($prod, _BI_BASE);
    }
    while ($carry) {
        $res[] = $carry % _BI_BASE;
        $carry = intdiv($carry, _BI_BASE);
    }
    return $res;
}

function _bi_divmod_small(array $a, int $x): array
{
    if ($x === 0) {
        throw new RuntimeException('[WY:SERVER] 内部错误：除零');
    }
    if ($x === 1) {
        return [_bi_trim($a), [0]];
    }
    $a = _bi_trim($a);
    $rem = 0;
    $q = array_fill(0, count($a), 0);
    for ($i = count($a) - 1; $i >= 0; $i--) {
        $cur = $rem * _BI_BASE + $a[$i];
        $q[$i] = intdiv($cur, $x);
        $rem = $cur % $x;
    }
    return [_bi_trim($q), _bi_from_int($rem)];
}

function _bi_divmod(array $a, array $b): array
{
    $a = _bi_trim($a);
    $b = _bi_trim($b);
    if (_bi_is_zero($b)) {
        throw new RuntimeException('[WY:SERVER] 内部错误：除零');
    }
    if (_bi_cmp($a, $b) < 0) {
        return [[0], $a];
    }
    if (count($b) === 1) {
        return _bi_divmod_small($a, $b[0]);
    }
    $base = _BI_BASE;
    $n = count($b);
    $m = count($a) - $n;
    // 归一化：使 b 的最高位 >= base/2
    $d = intdiv($base, $b[$n - 1] + 1);
    if ($d > 1) {
        $a = _bi_mul_small($a, $d);
        $b = _bi_mul_small($b, $d);
    }
    // 在最高位补 0，保证 u[j+n] 合法
    $u = $a;
    $u[] = 0;
    $q = array_fill(0, $m + 1, 0);

    for ($j = $m; $j >= 0; $j--) {
        $num = $u[$j + $n] * $base + $u[$j + $n - 1];
        $qhat = intdiv($num, $b[$n - 1]);
        $rhat = $num % $b[$n - 1];
        while ($qhat >= $base
            || $qhat * $b[$n - 2] > $rhat * $base + $u[$j + $n - 2]) {
            $qhat--;
            $rhat += $b[$n - 1];
            if ($rhat >= $base) {
                break;
            }
        }
        // 乘减：u[j..j+n] -= qhat * b
        $k = 0;
        for ($i = 0; $i < $n; $i++) {
            $sub = $qhat * $b[$i] + $k;
            $k = intdiv($sub, $base);
            $t = $u[$j + $i] - ($sub % $base);
            if ($t < 0) {
                $t += $base;
                $k++;
            }
            $u[$j + $i] = $t;
        }
        $t = $u[$j + $n] - $k;
        if ($t < 0) {
            // 加回一次 b（qhat 恰好大了 1）
            $c = 0;
            for ($i = 0; $i < $n; $i++) {
                $s = $u[$j + $i] + $b[$i] + $c;
                $u[$j + $i] = $s % $base;
                $c = intdiv($s, $base);
            }
            $u[$j + $n] = $t + $base;
            $qhat--;
        } else {
            $u[$j + $n] = $t;
        }
        $q[$j] = $qhat;
    }

    // 余数在 u[0..n-1]，并解除归一化
    $rem = _bi_trim(array_slice($u, 0, $n));
    if ($d > 1) {
        $rem = _bi_divmod_small($rem, $d)[0];
    }
    return [_bi_trim($q), $rem];
}

function _bi_mod(array $a, array $m): array
{
    return _bi_divmod($a, $m)[1];
}

function _bi_to_decimal(array $a): string
{
    $a = _bi_trim($a);
    if (_bi_is_zero($a)) {
        return '0';
    }
    $digits = '';
    while (!_bi_is_zero($a)) {
        $dm = _bi_divmod_small($a, 10);
        $digits = (string) ($dm[1][0] ?? 0) . $digits;
        $a = $dm[0];
    }
    return $digits;
}

function _bi_powmod(array $base, array $exp, array $mod): array
{
    $base = _bi_mod($base, $mod);
    $result = _bi_from_int(1);
    $bits = [];
    $e = _bi_trim($exp);
    while (!_bi_is_zero($e)) {
        $bits[] = $e[0] & 1;
        $e = _bi_divmod_small($e, 2)[0];
    }
    $bits = array_reverse($bits);
    foreach ($bits as $bit) {
        $result = _bi_mod(_bi_mul($result, $result), $mod);
        if ($bit === 1) {
            $result = _bi_mod(_bi_mul($result, $base), $mod);
        }
    }
    return $result;
}

function rsa_encrypt(string $secret): string
{
    // $secret 为 16 字节；与网易云一致，先反转再作为大端整数做幂模运算。
    $reversed = strrev($secret);
    $modulus = _bi_from_hex(NETEASE_RSA_MODULUS);
    $exp     = _bi_from_int((int) NETEASE_RSA_EXP);

    $base = _bi_from_int(0);
    $len  = strlen($reversed);
    for ($i = 0; $i < $len; $i++) {
        $base = _bi_mul_small($base, 256);
        $base = _bi_add($base, _bi_from_int(ord($reversed[$i])));
    }

    $c = _bi_powmod($base, $exp, $modulus);
    return str_pad(_bi_to_decimal($c), 256, '0', STR_PAD_LEFT);
}

function random_secret(int $len = 16): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $secret   = '';
    $bytes    = random_bytes($len);
    for ($i = 0; $i < $len; $i++) {
        $secret .= $alphabet[ord($bytes[$i]) % 62];
    }
    return $secret;
}

function aes_cbc_encrypt(string $data, string $key, string $iv): string
{
    return (string) openssl_encrypt($data, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

function weapi(array $payload): array
{
    $text   = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $secret = random_secret(16);
    $first  = aes_cbc_encrypt($text, NETEASE_PRESET_KEY, NETEASE_IV);
    $second = aes_cbc_encrypt(base64_encode($first), $secret, NETEASE_IV);
    return [
        'params'    => base64_encode($second),
        'encSecKey' => rsa_encrypt($secret),
    ];
}

function eapi(string $path, array $payload): array
{
    $text    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $message = "nobody{$path}use{$text}md5forencrypt";
    $digest  = md5($message);
    $data    = "{$path}-36cd479b6b5-{$text}-36cd479b6b5-{$digest}";
    $enc     = (string) openssl_encrypt($data, 'AES-128-ECB', NETEASE_EAPI_KEY, OPENSSL_RAW_DATA);
    return ['params' => strtoupper(bin2hex($enc))];
}

// ============================ HTTP ============================
function netease_post(string $url, array $form, array $cookies, int $timeout, string $label = ''): array
{
    $ch = curl_init($url);
    $cookieHeader = '';
    foreach ($cookies as $k => $v) {
        $cookieHeader .= "{$k}={$v}; ";
    }
    $cookieHeader = rtrim($cookieHeader, '; ');

    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'Accept-Encoding: gzip, deflate',
        'Referer: https://music.163.com/',
        'User-Agent: ' . USER_AGENT,
        "Cookie: {$cookieHeader}",
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($form),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',   // 自动解码 gzip / deflate / br，避免拿到压缩二进制被误判为非 JSON
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => (bool) ($GLOBALS['config']['sslVerify'] ?? true),
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
    ]);

    $body        = curl_exec($ch);
    $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err         = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        throw new RuntimeException("[WY:NETWORK] 网络请求失败（{$label}）：{$err}");
    }
    if ($httpCode === 401) {
        throw new RuntimeException('[WY:AUTH] 登录状态无效，请在官方网页重新登录并更新 Cookie');
    }
    if ($httpCode === 403 || $httpCode === 429) {
        throw new RuntimeException("[WY:BLOCKED] 请求被网易云拒绝或限流（{$label}）");
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("[WY:HTTP] 收到非成功 HTTP 响应（{$httpCode}，{$label}）");
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        // 诊断：把实际返回内容截断后回显，便于判断是风控 HTML 还是接口变更
        $snippet = (string) $body;
        $snippet = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]+/', ' ', $snippet);
        $snippet = substr($snippet, 0, 800);
        $isHtml = stripos($contentType, 'html') !== false
            || preg_match('/<html|<body|验证|verify|captcha|滑块/i', $snippet) === 1;
        if ($isHtml) {
            $hint = '服务器收到了 HTML 页面（疑似网易云风控/滑块验证，常见于云服务器/机房 IP 被限流）。'
                . '可考虑改用家庭宽带 IP 部署，或经合规代理访问。';
        } elseif (trim($snippet) === '') {
            $hint = '返回内容为空，可能是连接被中间代理中断。';
        } else {
            $hint = '返回内容不是合法 JSON，可能是接口变更或被中间代理改写。';
        }
        throw new RuntimeException(
            "[WY:RESPONSE] （{$label}）HTTP {$httpCode}，Content-Type：{$contentType}。{$hint} "
            . "原始返回(截断 800 字)：{$snippet}"
        );
    }
    return $data;
}

// 明文 GET 请求（旧版 /api/ 接口，不经 weapi/eapi 加密，对部分被风控的服务器 IP 更友好）
function netease_get(string $url, array $cookies, int $timeout, string $label = ''): array
{
    $ch = curl_init($url);
    $cookieHeader = '';
    foreach ($cookies as $k => $v) {
        $cookieHeader .= "{$k}={$v}; ";
    }
    $cookieHeader = rtrim($cookieHeader, '; ');

    $headers = [
        'Accept: application/json',
        'Accept-Encoding: gzip, deflate',
        'Referer: https://music.163.com/',
        'User-Agent: ' . USER_AGENT,
        "Cookie: {$cookieHeader}",
    ];

    curl_setopt_array($ch, [
        CURLOPT_HTTPGET         => true,
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_ENCODING        => '',
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_SSL_VERIFYPEER  => (bool) ($GLOBALS['config']['sslVerify'] ?? true),
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 5,
    ]);

    $body        = curl_exec($ch);
    $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err         = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        throw new RuntimeException("[WY:NETWORK] 网络请求失败（{$label}）：{$err}");
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("[WY:HTTP] 收到非成功 HTTP 响应（{$httpCode}，{$label}）");
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        $snippet = substr(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]+/', ' ', (string) $body), 0, 800);
        throw new RuntimeException(
            "[WY:RESPONSE] （{$label}）HTTP {$httpCode}，Content-Type：{$contentType}。"
            . "返回内容为空或非 JSON（疑似该服务器 IP 被风控）。原始返回(截断)：{$snippet}"
        );
    }
    return $data;
}

// ============================ 业务校验 ============================
function check_code($code, string $stage): void
{
    $code = is_numeric($code) ? (int) $code : null;
    if ($code === 200) {
        return;
    }
    if ($code === 301 || $code === 401) {
        throw new RuntimeException('[WY:AUTH] Cookie 已失效或账号未登录，请重新登录并更新 Cookie');
    }
    if ($code === 429 || $code === -460) {
        throw new RuntimeException('[WY:BLOCKED] 网易云要求验证或限制请求，请先在官方客户端处理');
    }
    if ($code === 403 || $code === 404 || $code === -110) {
        throw new RuntimeException("[WY:DENIED] {$stage}被拒绝：可能无权限、需单独购买、地区受限或额度不足");
    }
    throw new RuntimeException("[WY:UPSTREAM] {$stage}未获成功响应（代码 " . ($code ?? '缺失') . "），请检查官方客户端");
}

function verify_login(array $cookies, int $timeout): void
{
    try {
        $url  = LOGIN_URL . '?csrf_token=' . urlencode($cookies['__csrf']);
        $form = weapi([
            'csrf_token' => $cookies['__csrf'],
            'e_r'        => false,
        ]);
        $result = netease_post($url, $form, $cookies, $timeout, '登录验证 account/get');

        check_code($result['code'] ?? null, '登录验证');

        $accountId = positive_id($result['account']['id'] ?? null);
        $profileId = positive_id($result['profile']['userId'] ?? null);
        if (!$accountId || !$profileId || $accountId !== $profileId) {
            throw new RuntimeException('[WY:AUTH] 网易云未确认有效的登录账号，请重新登录并更新 Cookie');
        }

        $expected = trim((string) ($GLOBALS['config']['expectedUserId'] ?? ''));
        if ($expected !== '' && $accountId !== positive_id($expected)) {
            throw new RuntimeException('[WY:ACCOUNT] Cookie 对应账号与 expectedUserId 不一致');
        }
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        // 登录校验接口因风控/限流/网络不通而不可用时，不阻断取址：
        // Cookie 是否真正有效，由后续 download/url/v1 取址结果(AUTH/DENIED)最终判定。
        $skippable = strpos($msg, '[WY:RESPONSE]') !== false
            || strpos($msg, '[WY:BLOCKED]') !== false
            || strpos($msg, '[WY:NETWORK]') !== false
            || strpos($msg, '[WY:HTTP]') !== false;
        if ($skippable) {
            return;
        }
        throw $e;
    }
}

function extract_url(array $result, string $songId, array $quality): string
{
    check_code($result['code'] ?? null, '下载授权');

    $rows = (isset($result['data']) && is_list($result['data']))
        ? $result['data']
        : [$result['data'] ?? null];

    $data = null;
    foreach ($rows as $row) {
        if (is_array($row) && positive_id($row['id'] ?? null) === $songId) {
            $data = $row;
            break;
        }
    }
    if ($data === null) {
        throw new RuntimeException('[WY:RESPONSE] 下载响应未包含请求的歌曲 ID');
    }

    check_code($data['code'] ?? null, '歌曲下载授权');

    if (!empty($data['freeTrialInfo']) || !empty($data['trialInfo'])
        || ($data['isTrial'] ?? null) === true || ($data['isTrial'] ?? null) === 1) {
        throw new RuntimeException('[WY:TRIAL] 网易云仅返回试听内容，本源不将试听作为完整歌曲');
    }

    $url = trim((string) ($data['url'] ?? ''));
    if ($url === '' || strcasecmp($url, 'null') === 0) {
        throw new RuntimeException('[WY:DENIED] 网易云未提供下载地址，请确认购买、下载权限、地区与剩余额度');
    }

    $format = strtolower((string) ($data['type'] ?? ''));
    if ($format !== $quality['format']) {
        throw new RuntimeException('[WY:FORMAT] 返回格式与所选音质不符，或属于不支持的受保护格式');
    }
    if (($data['level'] ?? '') !== $quality['level']) {
        throw new RuntimeException('[WY:QUALITY] 未获得所选音质；请手动选择账号实际可用的音质重试');
    }
    if (isset($quality['bitrate'])) {
        $br = is_numeric($data['br'] ?? null) ? (float) $data['br'] : 0;
        if ($br < $quality['bitrate']) {
            throw new RuntimeException('[WY:QUALITY] 接口返回的码率低于所选音质，请手动降低音质重试');
        }
    }

    $u = parse_url($url);
    if ($u === false
        || !in_array($u['scheme'] ?? '', ['http', 'https'], true)
        || !empty($u['user']) || !empty($u['pass']) || !empty($u['fragment'])
        || (!empty($u['port']) && !in_array($u['port'], ['80', '443'], true))
        || !preg_match('/^(?:[a-z0-9-]+\.)*music\.(?:126\.net|163\.com)$/i', $u['host'] ?? '')) {
        throw new RuntimeException('[WY:URL] 返回地址不在预期网易云域名范围，已拒绝');
    }
    return $url;
}

function get_download_url(string $songId, array $quality, array $cookies, int $timeout): string
{
    $requestId = (int) (microtime(true) * 1000) . '_' . str_pad((string) mt_rand(0, 9999), 4, '0');

    $header = [
        'os'       => $cookies['os'],
        'appver'   => $cookies['appver'],
        '__csrf'   => $cookies['__csrf'],
        'MUSIC_U'  => $cookies['MUSIC_U'],
        'requestId'=> $requestId,
    ];
    if (!empty($cookies['NMTID'])) {
        $header['NMTID'] = $cookies['NMTID'];
    }

    $form   = eapi(DOWNLOAD_PATH, [
        'id'          => $songId,
        'level'       => $quality['level'],
        'immerseType' => 'c51',
        'e_r'         => false,
        'header'      => $header,
    ]);
    $result = netease_post(DOWNLOAD_URL, $form, $cookies, $timeout, '下载取址 download/url/v1');
    return extract_url($result, $songId, $quality);
}

function get_lyric(string $songId, array $cookies, int $timeout): array
{
    $empty = ['lyric' => '', 'tlyric' => null, 'rlyric' => null, 'lxlyric' => null];

    // 策略 1：旧版明文 /api/song/lyric（不经 weapi 加密，对部分被风控的服务器 IP 更友好）
    try {
        $url    = LYRIC_LEGACY_URL . '?id=' . urlencode($songId) . '&lv=-1&kv=-1&tv=-1';
        $result = netease_get($url, $cookies, $timeout, '歌词 legacy/api');
        if (($result['code'] ?? null) == 200 && !empty($result['lrc']['lyric'])) {
            return [
                'lyric'   => $result['lrc']['lyric'] ?? '',
                'tlyric'  => $result['tlyric']['lyric'] ?? null,
                'rlyric'  => $result['romalrc']['lyric'] ?? null,
                'lxlyric' => null,
            ];
        }
    } catch (RuntimeException $e) {
        // 落到策略 2
    }

    // 策略 2：weapi 歌词（原方式，部分服务器 IP 会被风控返回空 body）
    try {
        $form   = weapi([
            'id'  => $songId,
            'lv'  => -1,
            'kv'  => -1,
            'tv'  => -1,
            'e_r' => false,
        ]);
        $result = netease_post(LYRIC_URL, $form, $cookies, $timeout, '歌词 lyric/v1');
        if (($result['code'] ?? null) == 200) {
            return [
                'lyric'   => $result['lrc']['lyric'] ?? '',
                'tlyric'  => $result['tlyric']['lyric'] ?? null,
                'rlyric'  => $result['romalrc']['lyric'] ?? null,
                'lxlyric' => null,
            ];
        }
    } catch (RuntimeException $e) {
        // 落到空歌词
    }

    // 两种都失败（多为该服务器 IP 被风控导致 weapi 返回空），优雅返回空歌词，不阻断播放
    return $empty;
}

// ============================ 路由 ============================
$timeout = (int) ($config['timeout'] ?? 15);
if ($timeout < 1 || $timeout > 60) {
    $timeout = 15;
}

$skipLogin = strtolower(trim((string) ($_GET['skipLogin'] ?? ''))) === '1';

$action  = '';
$source  = 'wy';
$id      = '';
$quality = '';

$qAction = strtolower(trim((string) ($_GET['action'] ?? '')));
if ($qAction !== '') {
    $action  = $qAction;
    $source  = strtolower(trim((string) ($_GET['source'] ?? 'wy')));
    $id      = trim((string) ($_GET['id'] ?? $_GET['songmid'] ?? ''));
    $quality = strtolower(trim((string) ($_GET['quality'] ?? $_GET['type'] ?? '')));
} else {
    // 支持路径形式：/url/wy/123/320k 或 /lyric/wy/123
    $uri    = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    // 去掉脚本自身路径（兼容 mod_rewrite 把请求转发到 index.php 的情况）
    if ($script !== '' && strpos($uri, $script) === 0) {
        $uri = substr($uri, strlen($script));
    }
    $parts = explode('/', $uri);
    $segs  = [];
    foreach ($parts as $p) {
        if ($p !== '') {
            $segs[] = $p;
        }
    }
    if (count($segs) >= 1) {
        $action = strtolower($segs[0]);
    }
    if (count($segs) >= 2) {
        $source = strtolower($segs[1]);
    }
    if (count($segs) >= 3) {
        $id = $segs[2];
    }
    if (count($segs) >= 4) {
        $quality = strtolower($segs[3]);
    }
    // 仍缺 id / quality 时，从查询串回退（兼容不同接入方式）
    if ($id === '') {
        $id = trim((string) ($_GET['id'] ?? $_GET['songmid'] ?? ''));
    }
    if ($quality === '') {
        $quality = strtolower(trim((string) ($_GET['quality'] ?? $_GET['type'] ?? '')));
    }
}

try {
    if ($action === 'url' || $action === 'link') {
        if ($source !== 'wy') {
            send_json(fail('UNSUPPORTED', '本源仅支持网易云(wy)'), 400);
        }
        $songId = positive_id($id);
        if (!$songId) {
            send_json(fail('ID', '缺少有效的网易云歌曲 ID（数字 songmid）。'
                . '正确调用：?action=url&id=<数字歌曲ID>&quality=320k 或 /url/wy/<数字ID>/320k'), 400);
        }
        $qm = quality_map();
        if (!isset($qm[$quality])) {
            send_json(fail('QUALITY', '不支持该音质，可选：' . implode('/', array_keys($qm))), 400);
        }
        $cookies = parse_cookie($cookie);
        if (!$skipLogin) {
            verify_login($cookies, $timeout);
        }
        $url = get_download_url($songId, $qm[$quality], $cookies, $timeout);
        send_json(['url' => $url]);

    } elseif ($action === 'lyric' || $action === 'lrc') {
        if ($source !== 'wy') {
            send_json(fail('UNSUPPORTED', '本源仅支持网易云(wy)'), 400);
        }
        $songId = positive_id($id);
        if (!$songId) {
            send_json(fail('ID', '缺少有效的网易云歌曲 ID（数字 songmid）。'
                . '正确调用：?action=lyric&id=<数字歌曲ID> 或 /lyric/wy/<数字ID>'), 400);
        }
        $cookies = parse_cookie($cookie);
        if (!$skipLogin) {
            verify_login($cookies, $timeout);
        }
        send_json(get_lyric($songId, $cookies, $timeout));

    } else {
        send_json([
            'name'    => 'netease-authorized-php',
            'version' => '1.0.0',
            'usage'   => [
                'musicUrl' => '?action=url&id=<songmid>&quality=<128k|320k|flac|flac24bit>',
                'lyric'    => '?action=lyric&id=<songmid>',
            ],
        ], 200);
    }
} catch (RuntimeException $e) {
    send_json(['error' => $e->getMessage()], 200);
} catch (Throwable $e) {
    send_json(['error' => '[WY:SERVER] 服务端异常：' . $e->getMessage()], 500);
}
