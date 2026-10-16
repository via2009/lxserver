<?php
/**
 * 网易云音源 · 共享函数库（common.php）
 * ------------------------------------------------------------------
 * 集中放置加密 / HTTP / 业务校验等函数，供以下文件复用：
 *   - test.php   : Cookie 健康检测
 *   - admin.php  : 网页端修改 Cookie
 *
 * 说明：index.php（取址主程序）为保持「单文件即可独立运行、不依赖本文件」的
 *       独立性，内部仍保留了与这里完全一致的函数副本。修改加解密/业务逻辑时，
 *       请务必同步两处；或后续统一改为只在 common.php 维护（删除 index.php 副本）。
 *
 * 本文件不直接响应 Web 请求；若被直接访问，仅定义函数、无输出（无害）。
 */

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
    $d = intdiv($base, $b[$n - 1] + 1);
    if ($d > 1) {
        $a = _bi_mul_small($a, $d);
        $b = _bi_mul_small($b, $d);
    }
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
        CURLOPT_ENCODING       => '',
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

    return $empty;
}

// ============================ 配置 / Cookie 读取（新增） ============================
function load_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $file = __DIR__ . '/config.php';
    if (!is_file($file)) {
        return [];
    }
    $cfg = require $file;
    $cache = is_array($cfg) ? $cfg : [];
    return $cache;
}

// Cookie 优先读取「网页端/接口写入的可写数据文件」，回退到 config.php。
function get_cookie_value(): string
{
    $dataFile = __DIR__ . '/data/cookie.txt';
    if (is_file($dataFile)) {
        $v = trim((string) @file_get_contents($dataFile));
        if ($v !== '') {
            return $v;
        }
    }
    $cfg = load_config();
    return trim((string) ($cfg['cookie'] ?? ''));
}

// 管理接口鉴权：未设置 adminPassword 时直接禁用（安全默认）。
// 支持 HTTP Basic（用户名固定为 admin）或 ?key= 参数。
function require_admin(): void
{
    $cfg = load_config();
    $pw = trim((string) ($cfg['adminPassword'] ?? ''));
    if ($pw === '') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['error' => '[WY:ADMIN] 未设置 adminPassword，出于安全已禁用管理接口。请在 config.php 增加 adminPassword 字段后使用。']);
        exit;
    }

    // 解析 Basic 凭证：Apache mod_php 会填充 PHP_AUTH_*；Nginx/FPM 等需从 Authorization 头手动解析。
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if (($user === '' || $pass === '') && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $h = (string) $_SERVER['HTTP_AUTHORIZATION'];
        if (stripos($h, 'basic ') === 0) {
            $dec = base64_decode(substr($h, 6), true);
            if ($dec !== false && ($pos = strpos($dec, ':')) !== false) {
                $user = substr($dec, 0, $pos);
                $pass = substr($dec, $pos + 1);
            }
        }
    }

    $ok = ($user === 'admin' && hash_equals($pw, $pass));
    if (!$ok && isset($_GET['key']) && is_string($_GET['key']) && hash_equals($pw, $_GET['key'])) {
        $ok = true;
    }

    if (!$ok) {
        header('WWW-Authenticate: Basic realm="NetEase Source Admin"');
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(401);
        echo json_encode(['error' => '[WY:AUTH] 管理接口需要管理员密码（Basic 用户名为 admin，或 URL 带 ?key=）']);
        exit;
    }
}
