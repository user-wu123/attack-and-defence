<?php
/**
 * scanner.php — 单文件前端 + PHP 后端（混写，不分离）。
 *
 * 前端：黑客科技风 + Canvas 粒子网络背景。
 * 后端：把表单参数写入临时 JSON，再调用 C:\projects\venv\web_runner.py
 *       执行扫描。用户输入绝不经过 shell，避免命令注入。
 */

// ---------- 工具函数（兼容 PHP 5.6） ----------
function sc_post($key, $default = '') {
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}
function sc_check($key) {
    return isset($_POST[$key]) && $_POST[$key];
}
function sc_esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// 默认值（与 CLI 一致）
$DEFAULT_URL    = 'http://127.0.0.1/vulnerabilities/sqli/?id=admin&Submit=%E6%8F%90%E4%BA%A4';
$DEFAULT_COOKIE = 'sakura_visitor=666c3684d011b30dcb4466869990b5d7; PHPSESSID=mmma7c4dfduvl7rhp1s513lb47; security=low';
$PYTHON         = 'C:\Users\Administrator\AppData\Local\Programs\Python\Python314\python.exe';
$RUNNER         = 'C:\projects\venv\web_runner.py';

// ---------- 处理 AJAX POST：只返回 JSON ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $url     = trim((string)sc_post('url'));
    $cookie  = trim((string)sc_post('cookie'));
    $param   = trim((string)sc_post('param'));
    $timeout = (float)sc_post('timeout', '12');
    $delay   = (float)sc_post('delay', '0.05');
    $schema  = sc_check('schema');
    $no_time = sc_check('no_time');
    $verbose = sc_check('verbose');

    $params = array();
    if ($param !== '') {
        foreach (preg_split('/\s*,\s*/', $param) as $p) {
            if (trim($p) !== '') { $params[] = trim($p); }
        }
    }

    // 校验
    if ($url === '') {
        echo json_encode(array('ok' => false, 'error' => '目标 URL 不能为空'));
        exit;
    }
    if (!preg_match('#^https?://#i', $url)) {
        echo json_encode(array('ok' => false, 'error' => 'URL 必须以 http:// 或 https:// 开头'));
        exit;
    }
    if ($timeout < 1) {
        echo json_encode(array('ok' => false, 'error' => '超时时间必须大于等于 1 秒'));
        exit;
    }
    if (!function_exists('exec')) {
        echo json_encode(array('ok' => false, 'error' => 'PHP 的 exec() 函数被禁用，无法执行扫描'));
        exit;
    }

    @set_time_limit(300);

    $cfg = array(
        'url'     => $url,
        'cookie'  => $cookie,
        'params'  => $params,
        'schema'  => $schema,
        'no_time' => $no_time,
        'verbose' => $verbose,
        'timeout' => $timeout,
        'delay'   => $delay,
    );

    $tmp = tempnam(sys_get_temp_dir(), 'sqli_');
    file_put_contents($tmp, json_encode($cfg, JSON_UNESCAPED_UNICODE));

    $cmd  = escapeshellarg($PYTHON) . ' ' . escapeshellarg($RUNNER) . ' ' . escapeshellarg($tmp) . ' 2>&1';
    $output = array();
    $code = 0;
    @exec($cmd, $output, $code);
    @unlink($tmp);

    $json = implode("\n", $output);
    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        echo json_encode(array('ok' => false, 'error' => '无法解析扫描结果（进程退出码 ' . $code . '）'));
        exit;
    }
    if (empty($decoded['ok'])) {
        $err = isset($decoded['error']) ? $decoded['error'] : '扫描失败';
        echo json_encode(array('ok' => false, 'error' => $err));
        exit;
    }

    echo json_encode(array('ok' => true, 'data' => $decoded['data']), JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SQLi SCANNER // 终端控制台</title>
<style>
    :root {
        --bg: #04070c;
        --panel: rgba(6, 16, 20, 0.82);
        --green: #00ff9c;
        --cyan: #00e5ff;
        --pink: #ff2e63;
        --amber: #ffb020;
        --text: #b6ffce;
        --dim: #4a7d6b;
        --line: #123b34;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
        background:
            radial-gradient(ellipse at 20% 0%, rgba(0,255,156,0.08), transparent 45%),
            radial-gradient(ellipse at 90% 100%, rgba(0,229,255,0.08), transparent 45%),
            var(--bg);
        color: var(--text);
        font-family: "Consolas", "Courier New", ui-monospace, monospace;
        overflow-x: hidden;
        min-height: 100vh;
        position: relative;
    }
    /* 粒子画布 */
    #bg {
        position: fixed; inset: 0;
        z-index: 0;
        display: block;
    }
    /* 网格与扫描线 */
    .grid {
        position: fixed; inset: 0; z-index: 1; pointer-events: none;
        background-image:
            linear-gradient(rgba(0,255,156,0.04) 1px, transparent 1px),
            linear-gradient(90deg, rgba(0,255,156,0.04) 1px, transparent 1px);
        background-size: 42px 42px;
    }
    .scanlines {
        position: fixed; inset: 0; z-index: 2; pointer-events: none;
        background: repeating-linear-gradient(0deg, rgba(0,0,0,0.14) 0, rgba(0,0,0,0.14) 1px, transparent 1px, transparent 3px);
    }
    .vignette {
        position: fixed; inset: 0; z-index: 2; pointer-events: none;
        box-shadow: inset 0 0 180px rgba(0,0,0,0.9);
    }

    .wrap {
        position: relative; z-index: 3;
        max-width: 980px;
        margin: 0 auto;
        padding: 28px 20px 60px;
    }

    /* 标题 */
    header.top { margin-bottom: 22px; }
    header.top .logo {
        font-size: clamp(22px, 4vw, 40px);
        font-weight: bold;
        letter-spacing: 3px;
        color: var(--green);
        text-shadow: 0 0 8px rgba(0,255,156,0.8), 0 0 24px rgba(0,255,156,0.4);
    }
    header.top .logo .accent { color: var(--cyan); text-shadow: 0 0 8px rgba(0,229,255,0.8); }
    header.top .sub {
        margin-top: 8px;
        color: var(--dim);
        font-size: 13px;
        letter-spacing: 1px;
    }
    header.top .sub .cursor {
        display: inline-block; width: 8px; height: 14px;
        background: var(--green); vertical-align: -2px;
        animation: blink 1s steps(1) infinite;
    }
    @keyframes blink { 50% { opacity: 0; } }

    /* 终端面板 */
    .term-panel {
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 8px;
        box-shadow: 0 0 0 1px rgba(0,255,156,0.06), 0 20px 60px rgba(0,0,0,0.55);
        overflow: hidden;
    }
    .term-bar {
        display: flex; align-items: center; gap: 8px;
        padding: 10px 14px;
        background: linear-gradient(180deg, rgba(0,255,156,0.08), rgba(0,0,0,0.2));
        border-bottom: 1px solid var(--line);
        color: var(--dim);
        font-size: 12px;
        letter-spacing: 1px;
    }
    .term-bar .dot { width: 12px; height: 12px; border-radius: 50%; }
    .dot.r { background: var(--pink); box-shadow: 0 0 8px var(--pink); }
    .dot.y { background: var(--amber); box-shadow: 0 0 8px var(--amber); }
    .dot.g { background: var(--green); box-shadow: 0 0 8px var(--green); }
    .term-bar .title { margin-left: 8px; }
    .term-body { padding: 20px 18px; }

    /* 表单 */
    .field { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 14px; }
    .field label.prompt {
        color: var(--green); white-space: nowrap; padding-top: 9px;
        font-size: 14px; letter-spacing: 1px;
        min-width: 96px; text-align: right;
    }
    .field input[type=text],
    .field input[type=number],
    .field textarea {
        flex: 1;
        background: rgba(0,0,0,0.45);
        border: 1px solid var(--line);
        border-radius: 4px;
        color: var(--cyan);
        font-family: inherit;
        font-size: 14px;
        padding: 9px 11px;
        outline: none;
        transition: border-color .15s, box-shadow .15s;
    }
    .field textarea { resize: vertical; min-height: 42px; }
    .field input:focus, .field textarea:focus {
        border-color: var(--green);
        box-shadow: 0 0 12px rgba(0,255,156,0.25);
    }
    .field .hint { color: var(--dim); font-size: 11px; margin-top: 4px; }

    .row2 { display: flex; gap: 14px; flex-wrap: wrap; }
    .row2 .field { flex: 1; min-width: 200px; }
    .row2 .field label.prompt { min-width: 80px; }

    .checks { display: flex; gap: 22px; flex-wrap: wrap; margin: 4px 0 16px 106px; }
    .checks label {
        display: flex; align-items: center; gap: 7px;
        color: var(--text); font-size: 13px; cursor: pointer; letter-spacing: 1px;
    }
    .checks input { accent-color: var(--green); width: 15px; height: 15px; cursor: pointer; }

    .actions { display: flex; gap: 14px; align-items: center; margin-top: 6px; }
    button.run {
        background: linear-gradient(90deg, rgba(0,255,156,0.18), rgba(0,229,255,0.18));
        border: 1px solid var(--green);
        color: var(--green);
        font-family: inherit; font-size: 15px; letter-spacing: 3px;
        padding: 12px 26px; border-radius: 4px; cursor: pointer;
        transition: all .15s;
        text-shadow: 0 0 6px rgba(0,255,156,0.5);
    }
    button.run:hover {
        background: var(--green); color: #03150c;
        box-shadow: 0 0 22px rgba(0,255,156,0.55);
    }
    button.run:disabled { opacity: 0.5; cursor: wait; }

    /* 双栏布局：左表单 / 右结果 */
    .layout {
        display: grid;
        grid-template-columns: minmax(340px, 5fr) minmax(380px, 6fr);
        gap: 20px;
        align-items: start;
    }
    /* 结果区（始终显示在右栏） */
    .result { }
    @keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

    .banner {
        padding: 14px 16px; border-radius: 6px; margin-bottom: 16px;
        font-size: 16px; letter-spacing: 2px; font-weight: bold;
        border: 1px solid;
    }
    .banner.vuln { color: var(--pink); border-color: var(--pink); background: rgba(255,46,99,0.08); text-shadow: 0 0 8px rgba(255,46,99,0.6); }
    .banner.clean { color: var(--green); border-color: var(--green); background: rgba(0,255,156,0.06); text-shadow: 0 0 8px rgba(0,255,156,0.5); }
    .banner.err  { color: var(--amber); border-color: var(--amber); background: rgba(255,176,32,0.08); text-shadow: 0 0 8px rgba(255,176,32,0.5); }

    .kv { display: grid; grid-template-columns: 160px 1fr; row-gap: 7px; column-gap: 12px; margin-bottom: 18px; font-size: 13px; }
    .kv .k { color: var(--dim); letter-spacing: 1px; }
    .kv .v { color: var(--text); word-break: break-all; }
    .kv .v strong { color: var(--cyan); }

    .sect { margin-bottom: 18px; }
    .sect .h {
        color: var(--cyan); font-size: 13px; letter-spacing: 2px;
        margin-bottom: 8px; padding-left: 10px;
        border-left: 3px solid var(--cyan);
    }
    .finding {
        border: 1px solid var(--line); border-radius: 6px;
        padding: 12px 14px; margin-bottom: 10px;
        background: rgba(0,0,0,0.25);
    }
    .finding .t { color: var(--green); font-weight: bold; letter-spacing: 1px; }
    .finding .meta { color: var(--dim); font-size: 12px; margin: 6px 0; }
    .finding .meta b { color: var(--text); }
    pre.code {
        background: rgba(0,0,0,0.5); border: 1px solid var(--line);
        border-radius: 4px; padding: 10px 12px; margin: 8px 0;
        font-family: inherit; font-size: 12px; color: #9fe8c0;
        overflow-x: auto; white-space: pre-wrap; word-break: break-all;
    }
    .tree {
        font-family: inherit; font-size: 13.5px; line-height: 2.05;
        color: var(--text); white-space: pre-wrap;
        background: rgba(0, 0, 0, 0.28);
        border: 1px solid var(--line);
        border-radius: 6px;
        padding: 12px 16px;
    }
    .tree .dim { color: var(--dim); }
    .tree .tbl-line { margin-top: 4px; }
    .tree .db-name {
        color: var(--cyan); font-weight: bold; letter-spacing: 1px;
        text-shadow: 0 0 8px rgba(0, 229, 255, 0.45);
    }
    .tree .tbl-name {
        color: var(--green); font-weight: bold;
        text-shadow: 0 0 8px rgba(0, 255, 156, 0.35);
    }
    .tree .col-name {
        color: #c5a3ff;
        text-shadow: 0 0 6px rgba(197, 163, 255, 0.25);
    }
    .placeholder {
        color: var(--dim); font-size: 13px; letter-spacing: 1px; line-height: 1.9; padding: 6px 0;
    }
    .placeholder::after { content: ' ▍'; color: var(--green); animation: blink 1s steps(1) infinite; }
    .warn { color: var(--amber); font-size: 12px; line-height: 1.7; }

    footer { margin-top: 30px; color: var(--dim); font-size: 12px; text-align: center; letter-spacing: 1px; }

    /* 加载遮罩 */
    .loading {
        position: fixed; inset: 0; z-index: 20;
        background: rgba(2,5,8,0.8); display: none;
        align-items: center; justify-content: center; flex-direction: column; gap: 18px;
    }
    .loading.on { display: flex; }
    .loading .ring {
        width: 54px; height: 54px; border-radius: 50%;
        border: 2px solid rgba(0,255,156,0.2); border-top-color: var(--green);
        animation: spin .8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .loading .msg { color: var(--green); letter-spacing: 3px; font-size: 14px; text-shadow: 0 0 8px rgba(0,255,156,0.5); }

    @media (max-width: 900px) {
        .layout { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
        .field { flex-direction: column; gap: 6px; }
        .field label.prompt { text-align: left; min-width: 0; padding-top: 0; }
        .checks { margin-left: 0; }
        .kv { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>
<canvas id="bg"></canvas>
<div class="grid"></div>
<div class="scanlines"></div>
<div class="vignette"></div>

<div class="wrap">
    <header class="top">
        <div class="logo">SQLi<span class="accent">_</span>SCANNER<span class="accent"> //</span></div>
        <div class="sub" id="subtitle">目标侦察 · 漏洞检测 · 元数据枚举<span class="cursor"></span></div>
    </header>

    <div class="layout">
    <div class="term-panel">
        <div class="term-bar">
            <span class="dot r"></span><span class="dot y"></span><span class="dot g"></span>
            <span class="title">root@scanner: ~/sqli</span>
        </div>
        <div class="term-body">
            <form id="scanForm" autocomplete="off">
                <div class="field">
                    <label class="prompt">$ target &gt;</label>
                    <div style="flex:1">
                        <input type="text" name="url" id="url" value="<?php echo sc_esc($DEFAULT_URL); ?>" spellcheck="false">
                        <div class="hint">目标 URL，必须包含 GET 参数</div>
                    </div>
                </div>
                <div class="field">
                    <label class="prompt">$ param &gt;</label>
                    <div style="flex:1">
                        <input type="text" name="param" id="param" value="id" spellcheck="false">
                        <div class="hint">只测试该参数，多个用英文逗号分隔，留空则测试全部</div>
                    </div>
                </div>
                <div class="field">
                    <label class="prompt">$ cookie &gt;</label>
                    <div style="flex:1">
                        <textarea name="cookie" id="cookie" rows="2"><?php echo sc_esc($DEFAULT_COOKIE); ?></textarea>
                        <div class="hint">登录 Cookie（用于登录后的页面）</div>
                    </div>
                </div>
                <div class="row2">
                    <div class="field">
                        <label class="prompt">$ timeout &gt;</label>
                        <input type="number" name="timeout" id="timeout" value="12" min="1" step="1">
                    </div>
                    <div class="field">
                        <label class="prompt">$ delay &gt;</label>
                        <input type="number" name="delay" id="delay" value="0.05" min="0" step="0.01">
                    </div>
                </div>
                <div class="checks">
                    <label><input type="checkbox" name="schema" id="schema" checked> 枚举库/表/列</label>
                    <label><input type="checkbox" name="no_time" id="no_time" checked> 跳过时间盲注（更快）</label>
                    <label><input type="checkbox" name="verbose" id="verbose"> 详细日志</label>
                </div>
                <div class="actions">
                    <button type="submit" class="run" id="runBtn">▸ 执行扫描</button>
                    <span style="color:var(--dim);font-size:12px;letter-spacing:1px">仅限获得授权的目标</span>
                </div>
            </form>
        </div>
    </div>

    <div class="term-panel result" id="resultPanel">
        <div class="term-bar">
            <span class="dot r"></span><span class="dot y"></span><span class="dot g"></span>
            <span class="title">// OUTPUT</span>
        </div>
        <div class="term-body" id="resultBody">
            <div class="placeholder">// 尚未执行扫描，等待输入…</div>
        </div>
    </div>
    </div>

    <footer>SQLi SCANNER · 授权测试专用 · 检测 + 复核 + 元数据枚举（不提取业务数据）</footer>
</div>

<div class="loading" id="loading">
    <div class="ring"></div>
    <div class="msg">EXECUTING SCAN…</div>
</div>

<script>
(function () {
    "use strict";

    /* ===== 粒子网络背景 ===== */
    var canvas = document.getElementById('bg');
    var ctx = canvas.getContext('2d');
    var W = 0, H = 0, particles = [];
    var mouse = { x: null, y: null };
    var COUNT = 90, DIST = 130;

    function resize() {
        W = canvas.width = window.innerWidth;
        H = canvas.height = window.innerHeight;
    }
    function initParticles() {
        resize();
        particles = [];
        for (var i = 0; i < COUNT; i++) {
            particles.push({
                x: Math.random() * W,
                y: Math.random() * H,
                vx: (Math.random() - 0.5) * 0.4,
                vy: (Math.random() - 0.5) * 0.4,
                r: Math.random() * 1.5 + 0.6,
                c: Math.random() > 0.5 ? '0,255,156' : '0,229,255'
            });
        }
    }
    window.addEventListener('resize', initParticles);
    window.addEventListener('mousemove', function (e) { mouse.x = e.clientX; mouse.y = e.clientY; });
    window.addEventListener('mouseleave', function () { mouse.x = null; mouse.y = null; });

    function tick() {
        ctx.clearRect(0, 0, W, H);
        var i, j, a, b, dx, dy, d2, alpha;

        for (i = 0; i < particles.length; i++) {
            var p = particles[i];
            p.x += p.vx; p.y += p.vy;
            if (p.x < 0 || p.x > W) p.vx *= -1;
            if (p.y < 0 || p.y > H) p.vy *= -1;
            if (mouse.x !== null) {
                dx = p.x - mouse.x; dy = p.y - mouse.y; d2 = dx * dx + dy * dy;
                if (d2 < 22500 && d2 > 0.5) {
                    var d = Math.sqrt(d2);
                    p.x -= dx / d * 0.7; p.y -= dy / d * 0.7;
                }
            }
        }

        for (i = 0; i < particles.length; i++) {
            a = particles[i];
            for (j = i + 1; j < particles.length; j++) {
                b = particles[j];
                dx = a.x - b.x; dy = a.y - b.y; d2 = dx * dx + dy * dy;
                if (d2 < DIST * DIST) {
                    alpha = (1 - d2 / (DIST * DIST)) * 0.45;
                    ctx.strokeStyle = 'rgba(0,255,156,' + alpha + ')';
                    ctx.lineWidth = 0.6;
                    ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
                }
            }
            if (mouse.x !== null) {
                dx = a.x - mouse.x; dy = a.y - mouse.y; d2 = dx * dx + dy * dy;
                if (d2 < DIST * DIST) {
                    alpha = (1 - d2 / (DIST * DIST)) * 0.7;
                    ctx.strokeStyle = 'rgba(0,229,255,' + alpha + ')';
                    ctx.lineWidth = 0.8;
                    ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(mouse.x, mouse.y); ctx.stroke();
                }
            }
        }

        for (i = 0; i < particles.length; i++) {
            a = particles[i];
            ctx.fillStyle = a.c.indexOf('156') > -1 ? '#00ff9c' : '#00e5ff';
            ctx.beginPath(); ctx.arc(a.x, a.y, a.r, 0, Math.PI * 2); ctx.fill();
        }
        requestAnimationFrame(tick);
    }
    initParticles();
    tick();

    /* ===== 结果渲染 ===== */
    function el(tag, text, cls) {
        var e = document.createElement(tag);
        if (text != null) e.textContent = text;
        if (cls) e.className = cls;
        return e;
    }
    function codeBlock(text) {
        var pre = el('pre', text, 'code');
        return pre;
    }
    function section(title) {
        var d = el('div', null, 'sect');
        d.appendChild(el('div', title, 'h'));
        return d;
    }
    function kv(k, v) {
        var d = el('div', null, 'kv');
        var kk = el('div', k, 'k');
        var vv = el('div', null, 'v');
        vv.appendChild(v);
        d.appendChild(kk); d.appendChild(vv);
        return d;
    }
    function renderSchema(schema) {
        var ss = section('数据库结构');
        var tree = el('div', null, 'tree');

        var dbLine = el('div', null, 'db-line');
        dbLine.appendChild(el('span', '▶ DATABASE  ', 'dim'));
        dbLine.appendChild(el('span', schema.database || 'unknown', 'db-name'));
        tree.appendChild(dbLine);

        var byTable = {};
        (schema.columns || []).forEach(function (c) {
            (byTable[c.table] = byTable[c.table] || []).push(c.column);
        });
        var tables = schema.tables || [];
        tables.forEach(function (t) { if (!byTable[t]) byTable[t] = []; });

        tables.forEach(function (t) {
            var tbl = el('div', null, 'tbl-line');
            tbl.appendChild(el('span', '└─ TABLE  ', 'dim'));
            tbl.appendChild(el('span', t, 'tbl-name'));
            tree.appendChild(tbl);

            var cols = byTable[t] || [];
            if (!cols.length) {
                tree.appendChild(el('div', '      · (无列)', 'col-line'));
            }
            cols.forEach(function (name, idx) {
                var branch = (idx === cols.length - 1) ? '└─' : '├─';
                var line = el('div', null, 'col-line');
                line.appendChild(el('span', '      ' + branch + ' ', 'dim'));
                line.appendChild(el('span', name, 'col-name'));
                tree.appendChild(line);
            });
        });

        ss.appendChild(tree);
        return ss;
    }

    function render(data) {
        var body = document.getElementById('resultBody');
        body.innerHTML = '';

        var sum = data.summary || {};
        var vuln = !!sum.vulnerable;
        var status = sum.status || 'clean';

        // 状态横幅
        var banner = el('div');
        if (status === 'error') {
            banner.textContent = '[!] 扫描未完成';
            banner.className = 'banner err';
        } else if (vuln) {
            banner.textContent = '[+] 已确认 SQL 注入漏洞';
            banner.className = 'banner vuln';
        } else {
            banner.textContent = '[-] 未确认 SQL 注入漏洞';
            banner.className = 'banner clean';
        }
        body.appendChild(banner);

        // 摘要
        var kvBox = el('div');
        kvBox.appendChild(kv('目标', el('span', data.target || '-')));
        kvBox.appendChild(kv('状态', el('strong', status)));
        kvBox.appendChild(kv('数据库', el('span', sum.dbms || 'unknown')));
        kvBox.appendChild(kv('请求数', el('span', String(sum.requests_sent != null ? sum.requests_sent : '-'))));
        kvBox.appendChild(kv('耗时', el('span', (sum.duration_seconds != null ? sum.duration_seconds.toFixed(2) + ' s' : '-'))));
        if (data.baseline) {
            kvBox.appendChild(kv('基线', el('span', 'HTTP ' + data.baseline.status + ' · ' + data.baseline.bytes + ' bytes · 稳定度 ' + data.baseline.stability)));
        }
        body.appendChild(kvBox);

        // 漏洞详情
        var findings = data.findings || [];
        if (findings.length) {
            var fs = section('漏洞详情');
            findings.forEach(function (f) {
                var card = el('div', null, 'finding');
                card.appendChild(el('div', '参数 ' + f.parameter, 't'));
                var meta = el('div', null, 'meta');
                meta.appendChild(el('b', f.technique + ' · ' + f.confidence + ' · ' + (f.dbms || 'unknown')));
                card.appendChild(meta);
                if (f.evidence) { card.appendChild(el('div', '证据：', 'meta')); card.appendChild(codeBlock(f.evidence)); }
                if (f.payload) { card.appendChild(el('div', '载荷：', 'meta')); card.appendChild(codeBlock(f.payload)); }
                fs.appendChild(card);
            });
            body.appendChild(fs);
        }

        // 数据库结构
        var schema = data.schema || {};
        if (schema.attempted) {
            if (schema.supported) {
                body.appendChild(renderSchema(schema));
            } else {
                var ss = section('数据库结构');
                ss.appendChild(el('div', schema.error ? ('枚举失败：' + schema.error) : '该 DBMS/技术暂不支持元数据枚举', 'meta'));
                body.appendChild(ss);
            }
        }

        // WAF
        var waf = data.waf || {};
        if (waf.detected) {
            var ws = section('WAF 检测');
            ws.appendChild(el('div', '检测到：' + ((waf.vendors || []).join(', ') || 'unknown'), 'meta'));
            ws.appendChild(el('div', '被拦截请求：' + waf.blocked_requests, 'meta'));
            (waf.evidence || []).forEach(function (ev) { ws.appendChild(el('div', '· ' + ev, 'warn')); });
            body.appendChild(ws);
        }

        // 警告
        var warns = data.warnings || [];
        if (warns.length) {
            var w = section('警告');
            warns.forEach(function (x) { w.appendChild(el('div', '· ' + x, 'warn')); });
            body.appendChild(w);
        }

        // 详细日志（勾选「详细日志」时返回）
        if (data.logs && data.logs.length) {
            var ls = section('详细日志');
            ls.appendChild(codeBlock(data.logs.join('\n')));
            body.appendChild(ls);
        }
    }

    function showError(msg) {
        var body = document.getElementById('resultBody');
        body.innerHTML = '';
        var banner = el('div', '[!] ' + msg, 'banner err');
        body.appendChild(banner);
    }

    /* ===== 表单提交（AJAX） ===== */
    var form = document.getElementById('scanForm');
    var loading = document.getElementById('loading');
    var runBtn = document.getElementById('runBtn');

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        loading.classList.add('on');
        runBtn.disabled = true;

        var fd = new FormData(form);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            loading.classList.remove('on');
            runBtn.disabled = false;
            if (xhr.status !== 200) {
                showError('HTTP ' + xhr.status + '：请求失败');
                return;
            }
            var res;
            try { res = JSON.parse(xhr.responseText); }
            catch (err) { showError('返回内容不是有效 JSON'); return; }
            if (!res.ok) { showError(res.error || '扫描失败'); }
            else { render(res.data); }
        };
        xhr.send(fd);
    });

    /* ===== 标题打字效果 ===== */
    (function typewrite() {
        var node = document.getElementById('subtitle');
        var full = '目标侦察 · 漏洞检测 · 元数据枚举 · 授权测试专用';
        node.innerHTML = '';
        var t = document.createTextNode('');
        var cursor = document.createElement('span');
        cursor.className = 'cursor';
        node.appendChild(t);
        node.appendChild(cursor);
        var i = 0;
        function step() {
            if (i <= full.length) {
                t.nodeValue = full.slice(0, i);
                i++;
                setTimeout(step, 55);
            }
        }
        step();
    })();
})();
</script>
</body>
</html>
