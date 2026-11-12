// 개발 전용: 매뉴얼 PPT 용 화면 캡처. 로그인 세션을 붙이는 로컬 프록시를 띄우고 Edge headless 로 화면을 찍는다(통합 브라우저의 뷰포트가 불안정해 사용).
// 사용: node tools/capture_screens.mjs <출력폴더> tools/capture_jobs.json   (데모 서버가 SRMS_BASE, 기본 http://127.0.0.1:8765 에 떠 있어야 함)
// 결과 PNG 는 오른쪽·아래 여백이 남아 있으므로 복사 전에 잘라낸다(make_manual_ppt.py 는 여백을 자동으로 자르지만 크롭 좌표는 원본 기준).
import http from 'node:http';
import { spawn } from 'node:child_process';
import fs from 'node:fs';

const BASE = process.env.SRMS_BASE ?? 'http://127.0.0.1:8765';
const PROXY = Number(process.env.SRMS_PROXY_PORT ?? 9001);
const EDGE = process.env.EDGE_PATH ?? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const PW = process.env.SRMS_DEMO_PASSWORD ?? 'Demo1234';
const [OUT, JOBS] = process.argv.slice(2);
if (!OUT || !JOBS) {
  console.error('usage: node tools/capture_screens.mjs <outDir> <jobs.json>');
  process.exit(1);
}
fs.mkdirSync(OUT, { recursive: true });
const jobs = JSON.parse(fs.readFileSync(JOBS, 'utf8'));

const jar = {};
const cookieHeader = (role) => Object.entries(jar[role] ?? {}).map(([k, v]) => `${k}=${v}`).join('; ');
function absorb(role, res) {
  for (const c of res.headers.getSetCookie?.() ?? []) {
    const [kv] = c.split(';');
    const i = kv.indexOf('=');
    (jar[role] ??= {})[kv.slice(0, i)] = kv.slice(i + 1);
  }
}

async function login(role) {
  jar[role] = {};
  let res = await fetch(`${BASE}/login`, { redirect: 'manual' });
  absorb(role, res);
  const html = await res.text();
  const csrf = /name="_csrf"[^>]*value="([^"]+)"/.exec(html)?.[1] ?? /value="([^"]+)"[^>]*name="_csrf"/.exec(html)?.[1];
  if (!csrf) throw new Error('csrf token not found');
  const body = new URLSearchParams({ _csrf: csrf, company_cd: 'DEMO', login_id: role, password: PW });
  res = await fetch(`${BASE}/login`, { method: 'POST', body, redirect: 'manual', headers: { cookie: cookieHeader(role), 'content-type': 'application/x-www-form-urlencoded' } });
  absorb(role, res);
  if (res.status !== 302) throw new Error(`login failed for ${role}: ${res.status}`);
}

let current = '';
let hideNav = true;
const server = http.createServer(async (req, resp) => {
  try {
    const r = await fetch(BASE + req.url, { headers: { cookie: cookieHeader(current) }, redirect: 'manual' });
    const out = {};
    r.headers.forEach((v, k) => {
      if (!['content-encoding', 'content-length', 'transfer-encoding', 'content-security-policy', 'set-cookie'].includes(k)) out[k] = v;
    });
    let buf = Buffer.from(await r.arrayBuffer());
    if ((r.headers.get('content-type') ?? '').includes('text/html')) {
      const css = '<style>' + (hideNav ? 'nav.navbar{display:none!important}' : '') + 'body{background:#fff}</style>';
      buf = Buffer.from(buf.toString('utf8').replace('</head>', css + '</head>'), 'utf8');
    }
    resp.writeHead(r.status, out);
    resp.end(buf);
  } catch (e) {
    resp.writeHead(500);
    resp.end(String(e));
  }
});
await new Promise((ok) => server.listen(PROXY, '127.0.0.1', ok));

for (const role of new Set(jobs.map((j) => j.role))) await login(role);

for (const j of jobs) {
  current = j.role;
  hideNav = j.hideNav !== false;
  const file = `${OUT}/${j.name}.png`;
  const status = await new Promise((done) => {
    const p = spawn(EDGE, [
      '--headless=new', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1',
      `--window-size=${j.width ?? 1100},${j.height ?? 3000}`, '--virtual-time-budget=6000',
      `--screenshot=${file}`, `http://127.0.0.1:${PROXY}${j.path}`,
    ], { stdio: 'ignore' });
    const t = setTimeout(() => { p.kill(); done('timeout'); }, 60000);
    p.on('exit', (c) => { clearTimeout(t); done(c); });
  });
  console.log(j.name, status, fs.existsSync(file) ? fs.statSync(file).size : 'missing');
}
server.close();
