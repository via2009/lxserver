<?php
/**
 * 网易云音源 · Cookie 健康检测（test.php）
 * ------------------------------------------------------------------
 * 通过 eapi 取址接口实际探测 Cookie 是否仍被网易云接受：
 *   - 探针 1：请求 320k（任意登录用户均可获取）→ 判断 Cookie 是否「活着」
 *   - 探针 2（仅在探针 1 成功时）：请求 flac 无损 → 判断 VIP 是否仍有效
 *
 * 注意：本接口依赖管理员密码保护（见 common.php::require_admin），
 *       使用前务必在 config.php 设置 adminPassword。
 *
 * 调用示例：
 *   https://你的域名/test.php            （浏览器会弹 Basic 认证）
 *   https://你的域名/test.php?key=你的密码
 *   https://你的域名/test.php?key=你的密码&id=16607987   （指定测试曲目）
 */
declare(strict_types=1);
require __DIR__ . '/common.php';
require_admin();

$cfg = load_config();
$GLOBALS['config'] = $cfg;
$timeout = (int) ($cfg['timeout'] ?? 15);
if ($timeout < 1 || $timeout > 60) {
    $timeout = 15;
}

$dataFile      = __DIR__ . '/data/cookie.txt';
$dataExists    = is_file($dataFile) && trim((string) @file_get_contents($dataFile)) !== '';
$cookieSource  = $dataExists ? 'data/cookie.txt（网页设置，优先生效）' : 'config.php（文件配置，回退）';
$cookieRaw     = get_cookie_value();

$result = [
    'cookieSource' => $cookieSource,
    'cookiePresent' => $cookieRaw !== '',
    'alive'        => false,   // Cookie 是否仍被网易云接受
    'vipActive'    => null,    // VIP 是否仍有效（null=未能判定）
    'probe320k'    => null,
    'probeFlac'    => null,
    'message'      => '',
];

if ($cookieRaw === '') {
    $result['message'] = '未配置 Cookie（data/cookie.txt 与 config.php 均为空）。';
    send_json($result);
}

try {
    $cookies = parse_cookie($cookieRaw);
} catch (RuntimeException $e) {
    $result['message'] = 'Cookie 解析失败：' . $e->getMessage();
    send_json($result);
}

$testId = positive_id($_GET['id'] ?? '') ?? '16607987';

// 探针 1：320k，任意登录用户均可获取，用来判断 Cookie 是否被接受
try {
    $url320 = get_download_url($testId, quality_map()['320k'], $cookies, $timeout);
    $result['probe320k'] = ['ok' => true, 'url' => $url320];
    $result['alive'] = true;
} catch (RuntimeException $e) {
    $result['probe320k'] = ['ok' => false, 'error' => $e->getMessage()];
}

// 探针 2：仅在探针 1 成功时，flac 无损（仅 VIP 可获取）判断会员是否仍有效
if ($result['alive']) {
    try {
        $urlFlac = get_download_url($testId, quality_map()['flac'], $cookies, $timeout);
        $result['probeFlac'] = ['ok' => true, 'url' => $urlFlac];
        $result['vipActive'] = true;
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        $result['probeFlac'] = ['ok' => false, 'error' => $msg];
        if (strpos($msg, '[WY:QUALITY]') !== false || strpos($msg, '[WY:FORMAT]') !== false) {
            // 账号非 VIP，或该测试曲目本身无无损版本
            $result['vipActive'] = false;
        } else {
            $result['vipActive'] = null;
        }
    }
}

if (!$result['alive']) {
    $result['message'] = 'Cookie 可能已失效（网易云未返回有效的 320k 直链）。请重新登录并刷新 Cookie；'
        . '若确认 Cookie 无误，也可能是网络/风控问题，详见 probe320k.error。';
} elseif ($result['vipActive'] === true) {
    $result['message'] = 'Cookie 有效，且 VIP 会员可用（成功取得无损音质）。';
} elseif ($result['vipActive'] === false) {
    $result['message'] = 'Cookie 有效，但该账号未取得无损（可能未开通 VIP，或测试曲目本身无无损版本）。';
} else {
    $result['message'] = 'Cookie 有效；VIP 状态未能确定（见 probeFlac.error，可换一首确认有无损的曲目再测）。';
}

send_json($result);
