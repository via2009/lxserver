# 网易云「本人授权」音源（PHP 版，适配 LX Music / lxserver）

把你**本人的网易云 VIP 账号 Cookie** 保存在自己的 PHP 服务器上，由 PHP 用网易云的
WEAPI / EAPI 协议取回**下载直链**。洛雪音乐（LX Music 桌面版 / 移动版 / **lxserver**）
端只用一个极薄的 JS 桥接源把 `songmid + 音质` 转给 PHP，**Cookie 永远不会离开你的服务器**。

> 仅用于个人学习与研究，请使用你本人账号、仅收听你有权收听的内容，并遵守网易云相关服务条款。
> 取址会消耗账号的下载额度，且可能涉及网易云下载权限；频繁请求可能被限流。

---

## 一、为什么这样设计（原作者的源 vs 本方案）

- 原项目 `ZhonX07/lx-music-source-netease` 是一个**直接在 LX Music 桌面版里运行**的 JS 源，
  把 Cookie 写死在 `.js` 文件里——只能桌面版用，且 Cookie 随源文件流转，有泄露风险。
- `lxserver` 的「自定义源」是 **JS 脚本（Node VM 模式）**，PHP 无法直接在里面跑。
- 因此本方案拆成两层：
  1. **PHP 后端**（本仓库 `index.php`）：持有 Cookie，完成网易云加密与取址，对外暴露 HTTP 接口。
  2. **JS 桥接源**（`lx-bridge.js`）：导入到 LX Music / lxserver，把请求转发给 PHP。

好处：Cookie 只在服务器；换 Cookie 不用重新导入源；同一套后端可同时给桌面端、移动端、lxserver 用。

---

## 二、环境要求

- PHP **7.4+**，开启扩展：`openssl`、`curl`（**无需** `bcmath` / `gmp`，RSA 已用纯 PHP 大整数实现）。
- 一台能访问 `music.163.com` / `interface.music.163.com` 的服务器（国内网络最佳）。
- 你本人的网易云 **VIP** 账号 Cookie（普通账号只能取免费/已购歌曲）。

---

## 三、部署 PHP 后端

1. 把 `index.php`、`config.php.example`、`.htaccess` 上传到你的 PHP 网站根目录（或子目录）。
2. 复制配置并填写 Cookie：
   ```bash
   cp config.php.example config.php
   # 编辑 config.php，把 cookie 填进去
   ```
3. （强烈建议）设置 `apiToken`，并改用 **HTTPS** 部署，避免 Cookie 与令牌明文暴露。
4. 快速本地验证（可选）：
   ```bash
   php -S 0.0.0.0:9580   # 然后访问 http://<你的IP>:9580/ 应看到 usage 说明
   ```

### 测试接口

```bash
# 先用浏览器登录网易云，拿到 songmid（歌曲 ID），比如 123456
# 取播放直链（128k / 320k / flac / flac24bit）
curl "https://your-php-server/url/wy/123456/320k"

# 取歌词
curl "https://your-php-server/lyric/wy/123456"

# 若设置了 apiToken
curl -H "X-Source-Token: 你的令牌" "https://your-php-server/url/wy/123456/320k"
```

正常返回 `{"url":"https://..."}`；异常返回 `{"error":"[WY:XXX] ..."}`。

---

## 四、接入 lxserver（或 LX Music）

### 方式 A：lxserver（Web 播放器）
1. 打开 lxserver → **基本配置 → 自定义源管理 → 导入**。
2. 选「上传 .js 文件」，选择本仓库的 `lx-bridge.js`，并**先把文件里的 `API` 改成你的 PHP 地址**（`TOKEN` 与 `config.php` 的 `apiToken` 保持一致）。
3. 导入后**勾选启用**该源。
4. 如果 lxserver 提示需要 VM 模式 / 该源带有「VM」标识：
   - 到 **系统设置 → 权限与缓存限制**，打开「**允许 VM 模式运行脚本**」（v1.9.4+ 默认关闭），
   - 启用该源时按提示输入**管理员密码**即可（这是 lxserver 的安全机制，防止越权运行脚本）。
5. 在 lxserver 里搜索网易云歌曲 → 选择音质播放，取址会走你的 PHP 后端（用你本人的 VIP Cookie）。

> lxserver 已内置网易云**搜索**，自定义源主要负责**播放取址**（这正是 VIP Cookie 发挥作用的地方）。

### 方式 B：LX Music 桌面版 / 移动版（直接可用）
- 「设置 → 自定义源管理 → 在线导入 / 上传」，导入改好 `API` 的 `lx-bridge.js` 即可。
- 桌面版不需要 VM 模式开关。

---

## 五、如何获取网易云 Cookie

1. 浏览器登录 <https://music.163.com/>。
2. 按 **F12** → **Network** → 刷新页面 → 选一个发往 `music.163.com` 的请求。
3. 在 **Request Headers** 里找到 `Cookie`，**复制整段值**（需同时含 `MUSIC_U` 和 `__csrf`）。
4. 粘到 `config.php` 的 `cookie` 字段，保存即可，无需重启（每次请求读取最新配置）。

> Cookie 等同登录凭证，请勿分享、勿提交到公开仓库。网易云 Cookie 会过期，过期后重新复制更新即可。

---

## 六、网页端管理 Cookie（免重新上传配置，可选）

不想每次改 Cookie 都重新上传 `config.php`？本仓库附带两个管理页，可在浏览器里直接查看 / 修改 Cookie：

- `admin.php` —— **网页表单**：查看当前 Cookie 来源、直接粘贴新的网易云 Cookie 保存（写入 `data/cookie.txt`、**立即生效**），或一键「清除」回退到 `config.php`。
- `test.php` —— **Cookie 健康检测**：通过 eapi 取址接口实测 Cookie 是否仍被网易云接受、VIP 是否仍有效（见下方说明）。
- `common.php` —— 仅供上述两页调用的内部函数库（已被 `.htaccess` 禁止外访，无需关心）。

### 启用前必做

1. 在 `config.php` 设置 **`adminPassword`**（强密码）。**未设置时管理接口会被禁用（安全默认）**。
   ```php
   'adminPassword' => '改成你自己的强密码',
   ```
2. 确保 PHP 进程对站点目录有写权限（保存时会自动创建 `data/` 目录；若自动创建失败，请手动建 `data/` 并 `chmod 755`）。

### 使用

```
# 在浏览器打开（会弹 Basic 认证，用户名固定 admin，密码填 adminPassword）
https://your-php-server/admin.php
https://your-php-server/test.php

# 不想用浏览器弹窗时，也可在 URL 带 key（仍建议 HTTPS，避免密码明文出现在日志/历史）
https://your-php-server/admin.php?key=你的密码
https://your-php-server/test.php?key=你的密码&id=16607987   # id 可指定测试曲目
```

### Cookie 生效优先级

```
data/cookie.txt（网页设置，优先生效）  →  config.php 的 cookie（回退）
```

即：只要在 `admin.php` 保存过，就一直用 `data/cookie.txt`；想恢复用 `config.php`，点「清除」即可。

### test.php 的判定逻辑（重要）

`test.php` 通过 eapi 接口实测，**不依赖被风控的 weapi 登录校验**（你的云服务器 IP 上 weapi 常返回空 body）：

- **探针 1（320k）**：任意登录用户均可获取 → 判定 Cookie 是否「活着」。
- **探针 2（flac 无损，仅 VIP 可获取）**：仅在探针 1 成功时执行 → 判定 VIP 是否仍有效。

返回示例：
```json
{
  "cookieSource": "data/cookie.txt（网页设置，优先生效）",
  "cookiePresent": true,
  "alive": true,
  "vipActive": true,
  "probe320k": { "ok": true, "url": "https://..." },
  "probeFlac": { "ok": true, "url": "https://..." },
  "message": "Cookie 有效，且 VIP 会员可用（成功取得无损音质）。"
}
```

> 注意：若测试曲目本身没有无损版本，探针 2 会返回 `vipActive=false`（误判为非会员）。可换一首确认有无损的曲目，或用 `&id=` 指定。

---

## 七、音质映射

| LX 音质      | 网易云 level | 格式  | 备注                          |
|-------------|-------------|-------|-------------------------------|
| `128k`      | standard    | mp3   | 码率 ≥ 128000                 |
| `320k`      | exhigh      | mp3   | 码率 ≥ 320000                 |
| `flac`      | lossless    | flac  | 无损                          |
| `flac24bit` | hires       | flac  | Hi-Res，服务端须标明 `hires`  |

实际能否取得某一音质，取决于**你账号对该歌曲的权限**（会员 / 已购 / 额度），脚本按真实响应处理。

---

## 八、安全建议

- `config.php` 含登录凭证：用 `.htaccess`（已提供）禁止外访，且**绝不**提交到 git / 公开备份。
- 公网部署务必 **HTTPS + apiToken**；建议叠加 Nginx/Cloudflare 的 IP 限速。
- 限制来源：可在 `index.php` 顶部加 IP 白名单或 Referer 校验（按需自行扩展）。
- 本服务只向 `music.163.com` / `interface.music.163.com` 发送请求，并仅把 Cookie 发给这两个域名。

---

## 九、常见错误码

| 错误前缀        | 含义 / 处理                                                     |
|----------------|----------------------------------------------------------------|
| `[WY:CONFIG]`  | 检查 config.php 与 Cookie（需 `MUSIC_U` + `__csrf`）            |
| `[WY:AUTH]`    | Cookie 失效，去官网重新登录并更新                               |
| `[WY:ACCOUNT]` | Cookie 与 `expectedUserId` 不一致                               |
| `[WY:DENIED]`  | 无权限 / 需购买 / 地区受限 / 额度不足                           |
| `[WY:TRIAL]`   | 网易云只给试听，不返回完整歌曲                                   |
| `[WY:QUALITY]` | 手动选更低音质，或账号对该曲无此音质权限                         |
| `[WY:FORMAT]`  | 返回格式不符（如受保护格式）                                     |
| `[WY:BLOCKED]` | 被限流 / 需验证，暂停重试并在官方客户端处理                       |
| `[WY:NETWORK]` / `[WY:TIMEOUT]` | 检查服务器到网易云的网络、PHP curl、SSL 证书       |
| `[WY:URL]`     | 返回地址不在预期域名范围，需核对接口是否变更                     |

排查时提供：LX/ lxserver 版本、歌曲 ID、所选音质、错误前缀，以及官方客户端能否同音质下载。
**不要**提供 Cookie、完整响应或带签名的音频 URL。

---

## 十、文件清单

- `index.php` —— PHP 后端（加密 + 取址 + 路由），核心文件。
- `config.php.example` —— 配置模板，复制为 `config.php` 后填写。
- `lx-bridge.js` —— 导入 LX Music / lxserver 的桥接源（改 `API` 即可）。
- `common.php` —— 内部函数库，供 `test.php` / `admin.php` 复用（已被 `.htaccess` 禁止外访）。
- `test.php` —— Cookie 健康检测（需 `adminPassword`）。
- `admin.php` —— 网页端修改 Cookie（需 `adminPassword`，保存到 `data/cookie.txt`）。
- `data/cookie.txt` —— 网页端保存的 Cookie（自动生成，已被 `.htaccess` 禁止外访，**切勿提交**）。
- `.htaccess` —— Apache 下禁止外访 `config.php`、`common.php`、`data/`。
- `.gitignore` —— 忽略 `config.php`、`data/` 等含凭证文件。

---

## 十一、协议与免责

本仓库仅复刻并转写公开的网易云接口协议逻辑，用于个人合法账号的取址，**不构成任何官方背书**。
网易云接口随时可能调整；如失效，请核对本文与官方客户端行为后更新加密/接口路径。
请遵守相关法律法规与平台服务条款，勿用于任何商业或侵权用途。

本项目以 [MIT License](LICENSE) 开源，可自由使用、修改、分发，但需保留版权声明与许可声明。
