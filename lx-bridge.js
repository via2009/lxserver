/**
 * @name 网易云 PHP 代理源
 * @description 通过自建 PHP 后端（本人 VIP Cookie）解析网易云播放直链，适用于 LX Music / lxserver
 * @version 1.0.0
 * @author your-name
 * @homepage https://github.com/your-username/netease-lx-source
 *
 * 作用：本脚本只是「翻译官」。它把洛雪的取址请求转发到你自己的 PHP 服务，
 *       真正的网易云加密与 Cookie 都在 PHP 那边完成，因此 Cookie 不会出现在本文件里。
 *
 * 接入：把下面 API 改成你的 PHP 后端地址，然后在 LX Music / lxserver 的
 *      「自定义源管理」里「上传 .js」或「在线导入」本文件即可。
 */
const { EVENT_NAMES, request, on, send } = globalThis.lx;

// ===== 改成你的 PHP 后端地址（结尾不要带斜杠）=====
// 例：const API = 'https://music.example.com';
// 若 PHP 与 lxserver 同机，可填本机/局域网地址，如 'http://127.0.0.1/music' 或 'http://192.168.1.10:8080'（请改成你自己的实际地址）
const API = 'https://your-php-server';

// 若 PHP 端 config.php 设置了 apiToken，请填到下面（与之一致）；留空则不发送令牌。
const TOKEN = '';

const QUALITY = ['128k', '320k', 'flac', 'flac24bit'];

function httpGet(url) {
  return new Promise((resolve, reject) => {
    const headers = { 'User-Agent': 'lx-music-php-bridge/1.0' };
    if (TOKEN) headers['X-Source-Token'] = TOKEN;
    request(url, { method: 'GET', headers, timeout: 20000 }, (err, resp) => {
      if (err) return reject(err);
      let body = resp && resp.body != null ? resp.body : null;
      if (typeof body === 'string') {
        try {
          body = JSON.parse(body);
        } catch (e) {
          return reject(new Error('PHP 返回非 JSON：' + body.slice(0, 200)));
        }
      }
      if (!body || body.error) {
        return reject(new Error((body && body.error) || 'PHP 未返回数据'));
      }
      resolve(body);
    });
  });
}

on(EVENT_NAMES.request, async ({ source, action, info }) => {
  if (source !== 'wy') {
    throw new Error('[WY:UNSUPPORTED] 本源仅支持网易云');
  }

  // 兼容不同宿主（LX Music 桌面端 / lxserver）对 info 结构的差异
  const songmid =
    (info && info.musicInfo && info.musicInfo.songmid) ||
    (info && info.songmid) ||
    null;
  if (!songmid) {
    throw new Error('[WY:ID] 缺少有效的网易云 songmid（info.musicInfo.songmid）');
  }

  if (action === 'musicUrl') {
    const type = info.type;
    if (!QUALITY.includes(type)) {
      throw new Error('[WY:QUALITY] 不支持该音质');
    }
    const data = await httpGet(
      `${API}/?action=url&id=${encodeURIComponent(songmid)}&quality=${encodeURIComponent(type)}`
    );
    if (!data.url) {
      throw new Error('[WY:URL] PHP 未返回播放地址');
    }
    return data.url;
  }

  if (action === 'lyric') {
    const data = await httpGet(`${API}/?action=lyric&id=${encodeURIComponent(songmid)}`);
    return {
      lyric: data.lyric || '',
      tlyric: data.tlyric || null,
      rlyric: data.rlyric || null,
      lxlyric: data.lxlyric || null,
    };
  }

  throw new Error('[WY:UNSUPPORTED] 不支持的操作：' + action);
});

send(EVENT_NAMES.inited, {
  openDevTools: false,
  sources: {
    wy: {
      name: '网易云(PHP)',
      type: 'music',
      actions: ['musicUrl', 'lyric'],
      qualitys: QUALITY,
    },
  },
});
