<?php
/**
 * 网易云音源 · Cookie 网页管理（admin.php）
 * ------------------------------------------------------------------
 * 在浏览器里直接查看/修改网易云 Cookie，保存到 data/cookie.txt，
 * 立即生效，无需重新上传 config.php。
 *
 * 安全：
 *   - 需要管理员密码（config.php 的 adminPassword），支持 HTTP Basic 或 ?key=。
 *   - 未设置 adminPassword 时本接口被禁用（见 common.php::require_admin）。
 *   - data/ 目录已禁止外访（见 .htaccess）。
 *   - 建议仅在 HTTPS 或本机/局域网内访问本页面；若部署到公网，请务必设置强密码。
 *
 * 访问：https://你的域名/admin.php   （或 ?key=你的密码）
 */
declare(strict_types=1);
require __DIR__ . '/common.php';
require_admin();

$cfg = load_config();
$GLOBALS['config'] = $cfg;

$dataFile = __DIR__ . '/data/cookie.txt';
$msg = '';
$msgType = '';

// 处理保存：POST（表单）或 GET（?save=1&cookie=，用于「清除」链接）
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['save'])) {
    $raw = ($_SERVER['REQUEST_METHOD'] === 'POST')
        ? (string) ($_POST['cookie'] ?? '')
        : (string) ($_GET['cookie'] ?? '');
    $raw = trim($raw);

    if ($raw === '') {
        // 留空 = 清除数据文件，回退到 config.php
        if (is_file($dataFile)) {
            @unlink($dataFile);
        }
        $msg = '已清除 data/cookie.txt，将回退使用 config.php 中的 Cookie。';
        $msgType = 'ok';
    } else {
        try {
            parse_cookie($raw); // 仅校验格式，不实际请求

            if (!is_dir(__DIR__ . '/data')) {
                @mkdir(__DIR__ . '/data', 0700, true);
            }
            if (!is_dir(__DIR__ . '/data')) {
                throw new RuntimeException('无法创建 data/ 目录，请通过 FTP/SSH 手动创建并赋予 web 用户写权限（如 chmod 755）');
            }
            // 在 data/ 内写入 .htaccess，确保即使父级 .htaccess 未生效也能禁止外访 Cookie
            $dataHtaccess = __DIR__ . '/data/.htaccess';
            if (!is_file($dataHtaccess)) {
                @file_put_contents($dataHtaccess, "Require all denied\n");
            }
            file_put_contents($dataFile, $raw, LOCK_EX);
            @chmod($dataFile, 0600);
            $msg = 'Cookie 已保存到 data/cookie.txt，立即生效。';
            $msgType = 'ok';
        } catch (RuntimeException $e) {
            $msg = '保存失败：' . $e->getMessage();
            $msgType = 'err';
        }
    }
}

$current       = get_cookie_value();
$currentPreview = $current === '' ? '（当前为空）'
    : (strlen($current) > 24 ? substr($current, 0, 12) . ' … ' . substr($current, -12) : $current);
$source        = (is_file($dataFile) && trim((string) @file_get_contents($dataFile)) !== '')
    ? 'data/cookie.txt（网页设置，优先生效）'
    : 'config.php（文件配置，回退）';
// 仅参数部分（不含前导 ?），便于拼到不同链接的合适位置
$keyQuery = isset($_GET['key']) ? 'key=' . rawurlencode((string) $_GET['key']) : '';

?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>网易云音源 · Cookie 管理</title>
<style>
  :root { --bg:#f6f7fb; --card:#fff; --bd:#e5e7eb; --tx:#1f2937; --mut:#6b7280;
          --ok:#047857; --okbg:#ecfdf5; --err:#b91c1c; --errbg:#fef2f2; --pri:#2563eb; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--tx);
         font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif; }
  .wrap { max-width:760px; margin:28px auto; padding:0 16px; }
  h1 { font-size:20px; margin:0 0 4px; }
  .sub { color:var(--mut); margin:0 0 18px; font-size:13px; }
  .card { background:var(--card); border:1px solid var(--bd); border-radius:12px; padding:18px 18px 20px; }
  p { margin:0 0 10px; }
  code { background:#f3f4f6; padding:2px 6px; border-radius:5px; word-break:break-all; font-size:13px; }
  label { display:block; font-weight:600; margin-bottom:6px; }
  textarea { width:100%; min-height:120px; padding:10px 12px; border:1px solid var(--bd);
             border-radius:8px; font:13px/1.5 ui-monospace,Menlo,Consolas,monospace; resize:vertical; }
  .actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:12px; }
  button, .btn { font:inherit; padding:9px 16px; border-radius:8px; border:1px solid var(--pri);
                 background:var(--pri); color:#fff; cursor:pointer; text-decoration:none; }
  button.ghost { background:#fff; color:var(--pri); }
  .msg { padding:10px 12px; border-radius:8px; margin:0 0 14px; font-size:14px; }
  .msg.ok { background:var(--okbg); color:var(--ok); border:1px solid #a7f3d0; }
  .msg.err { background:var(--errbg); color:var(--err); border:1px solid #fecaca; }
  .tip { color:var(--mut); font-size:12.5px; margin-top:16px; }
  a.link { color:var(--pri); }
</style>
</head>
<body>
<div class="wrap">
  <h1>网易云音源 · Cookie 管理</h1>
  <p class="sub">在此网页直接修改网易云 Cookie，保存后立即生效，无需重新上传 config.php。</p>

  <div class="card">
    <p>当前 Cookie 来源：<b><?php echo htmlspecialchars($source, ENT_QUOTES, 'UTF-8'); ?></b></p>
    <p>当前值预览：<code><?php echo htmlspecialchars($currentPreview, ENT_QUOTES, 'UTF-8'); ?></code></p>

    <?php if ($msg !== ''): ?>
      <p class="msg <?php echo $msgType; ?>"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <form method="post" action="">
      <label for="cookie">网易云 Cookie（粘贴浏览器复制的完整 Cookie，需含 MUSIC_U 与 __csrf）</label>
      <textarea id="cookie" name="cookie" placeholder="MUSIC_U=...;__csrf=..."><?php echo htmlspecialchars($current, ENT_QUOTES, 'UTF-8'); ?></textarea>
      <div class="actions">
        <button type="submit">保存并立即生效</button>
        <a class="btn ghost link" href="?save=1&amp;cookie=<?php echo $keyQuery !== '' ? '&amp;' . $keyQuery : ''; ?>">清除（回退 config.php）</a>
        <a class="btn ghost link" href="test.php<?php echo $keyQuery !== '' ? '?' . $keyQuery : ''; ?>">测试 Cookie 有效性</a>
      </div>
    </form>

    <p class="tip">
      提示：保存后下次取址立即使用新 Cookie。建议本页仅在 HTTPS 或本机/局域网内访问；
      若部署到公网，务必设置强 <code>adminPassword</code>。清除后将从 <code>config.php</code> 读取 Cookie。
    </p>
  </div>
</div>
</body>
</html>
