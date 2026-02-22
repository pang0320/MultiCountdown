```php
<?php
/**
 * Multi Countdown Manager (Modern Edition)
 * PHP 8.3+ | Tailwind CDN | Server-time authoritative countdowns
 * - Multiple countdowns (add/delete) stored in SESSION
 * - Default countdown: Sep 30 of current year; if passed => next year
 * - Countdown ticks from SERVER timestamp (not client clock)
 * - Auto re-sync server time via same file API (?api=now) every 60s
 */

session_start();
date_default_timezone_set('Asia/Bangkok');
error_reporting(E_ALL);
ini_set('display_errors', '0');

$TZ = new DateTimeZone('Asia/Bangkok');

function flash_set(string $type, string $msg): void {
    $_SESSION['_flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get(): ?array {
    if (!isset($_SESSION['_flash'])) return null;
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $f;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parse_target_datetime(string $date, string $time, DateTimeZone $tz): ?DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) return null;

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time, $tz);
    if (!$dt) return null;

    $errors = DateTimeImmutable::getLastErrors();
    if (!empty($errors['warning_count']) || !empty($errors['error_count'])) return null;

    return $dt;
}

function default_sep30(DateTimeZone $tz): DateTimeImmutable {
    $now = new DateTimeImmutable('now', $tz);
    $year = (int)$now->format('Y');
    $target = new DateTimeImmutable(sprintf('%04d-09-30 00:00:00', $year), $tz);
    if ($now->getTimestamp() > $target->getTimestamp()) {
        $target = $target->modify('+1 year');
    }
    return $target;
}

function ensure_default_countdown(DateTimeZone $tz): void {
    if (!isset($_SESSION['countdowns']) || !is_array($_SESSION['countdowns'])) {
        $_SESSION['countdowns'] = [];
    }
    if (count($_SESSION['countdowns']) > 0) return;

    $target = default_sep30($tz);
    $id = 'CD' . bin2hex(random_bytes(6));

    $_SESSION['countdowns'][$id] = [
        'id' => $id,
        'title' => 'กำหนดส่ง (30 กันยายน)',
        'target_iso' => $target->format('Y-m-d H:i:s'),
        'target_ts' => $target->getTimestamp(),
        'created_ts' => time(),
    ];
}

function sort_countdowns(): void {
    if (!isset($_SESSION['countdowns']) || !is_array($_SESSION['countdowns'])) return;
    uasort($_SESSION['countdowns'], fn($a, $b) => ($a['target_ts'] ?? 0) <=> ($b['target_ts'] ?? 0));
}

function format_thai_datetime(string $iso, DateTimeZone $tz): string {
    $thai_months = [
        1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
    ];
    $ts = strtotime($iso);
    if ($ts === false) return $iso;

    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    $day = (int)$dt->format('j');
    $month = $thai_months[(int)$dt->format('n')] ?? $dt->format('m');
    $year_be = (int)$dt->format('Y') + 543;
    $time = $dt->format('H:i');

    return "{$day} {$month} {$year_be} · {$time} น.";
}

/**
 * API: server time (authoritative)
 * GET ?api=now
 */
if (isset($_GET['api']) && $_GET['api'] === 'now') {
    header('Content-Type: application/json; charset=utf-8');
    $nowTs = time();
    echo json_encode([
        'ts' => $nowTs,
        'iso' => date('Y-m-d H:i:s', $nowTs),
        'tz' => 'Asia/Bangkok',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Actions: add / delete
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $title = trim((string)($_POST['title'] ?? ''));
    $date = (string)($_POST['date'] ?? '');
    $time = (string)($_POST['time'] ?? '00:00');

    $errors = [];

    if ($title === '') $errors[] = 'กรุณาระบุชื่อ Countdown';
    $dt = parse_target_datetime($date, $time, $TZ);
    if (!$dt) $errors[] = 'รูปแบบวันที่/เวลาไม่ถูกต้อง';

    if (!$errors) {
        $targetTs = $dt->getTimestamp();
        $id = 'CD' . bin2hex(random_bytes(6));

        $_SESSION['countdowns'][$id] = [
            'id' => $id,
            'title' => $title, // escape ตอนแสดงผล
            'target_iso' => $dt->format('Y-m-d H:i:s'),
            'target_ts' => $targetTs,
            'created_ts' => time(),
        ];

        sort_countdowns();
        flash_set('success', 'เพิ่ม Countdown เรียบร้อย');
    } else {
        flash_set('error', implode(' • ', $errors));
    }

    header('Location: index.php');
    exit;
}

if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
    $id = (string)$_GET['id'];
    if (isset($_SESSION['countdowns'][$id])) {
        unset($_SESSION['countdowns'][$id]);
        flash_set('success', 'ลบ Countdown เรียบร้อย');
    }
    header('Location: index.php');
    exit;
}

// Data init
ensure_default_countdown($TZ);
sort_countdowns();

$server_now = time();
$countdowns = array_values($_SESSION['countdowns'] ?? []);
$flash = flash_get();

?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Multi Countdown</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* subtle one-shot number animation */
        @keyframes pop {
            0% { transform: translateY(0); opacity: 1; }
            35% { transform: translateY(-2px); opacity: .95; }
            100% { transform: translateY(0); opacity: 1; }
        }
        .pop { animation: pop .22s ease-out; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <div class="pointer-events-none fixed inset-0 bg-[radial-gradient(circle_at_20%_10%,rgba(56,189,248,.10),transparent_35%),radial-gradient(circle_at_80%_20%,rgba(99,102,241,.12),transparent_40%),radial-gradient(circle_at_50%_90%,rgba(16,185,129,.08),transparent_35%)]"></div>

    <header class="relative border-b border-white/10 bg-slate-950/60 backdrop-blur">
        <div class="mx-auto max-w-7xl px-4 py-5">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Multi Countdown</h1>
                    <p class="text-sm text-slate-400">อ้างอิงเวลา Server เป็นหลัก (Asia/Bangkok) และรองรับหลายรายการ</p>
                </div>

                <div class="flex items-center gap-3">
                    <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-2">
                        <div class="text-[11px] uppercase tracking-wider text-slate-400">Server time</div>
                        <div id="serverTime" class="font-mono text-lg leading-tight">--:--:--</div>
                    </div>
                    <div class="hidden sm:block rounded-xl border border-white/10 bg-white/5 px-4 py-2">
                        <div class="text-[11px] uppercase tracking-wider text-slate-400">Sync</div>
                        <div id="syncState" class="text-sm text-emerald-300">กำลังทำงาน</div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="relative mx-auto max-w-7xl px-4 py-8">
        <?php if ($flash): ?>
            <div class="mb-6">
                <div class="rounded-xl border px-4 py-3 text-sm
                    <?= $flash['type'] === 'success'
                        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200'
                        : 'border-rose-500/30 bg-rose-500/10 text-rose-200' ?>">
                    <?= h($flash['msg']) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Add form -->
        <section class="mb-8">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-5 shadow-[0_0_0_1px_rgba(255,255,255,.03)]">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-base font-semibold">เพิ่ม Countdown</h2>
                    <div class="text-xs text-slate-400">ค่าเริ่มต้นระบบ: 30 กันยายน (ถ้าเลยแล้วจะเป็นปีถัดไป)</div>
                </div>

                <form method="post" action="index.php" class="grid gap-4 md:grid-cols-4">
                    <input type="hidden" name="action" value="add" />

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-300">ชื่อ</label>
                        <input name="title" required
                               placeholder="เช่น ปิดรับสมัครทุน / ส่งรายงาน / วันสำคัญ"
                               class="w-full rounded-xl border border-white/10 bg-slate-950/60 px-4 py-3 text-sm text-slate-100 placeholder:text-slate-500 outline-none focus:border-indigo-400/60 focus:ring-4 focus:ring-indigo-400/10" />
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-300">วันที่</label>
                        <input type="date" name="date" required
                               class="w-full rounded-xl border border-white/10 bg-slate-950/60 px-4 py-3 text-sm text-slate-100 outline-none focus:border-indigo-400/60 focus:ring-4 focus:ring-indigo-400/10 [color-scheme:dark]" />
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-300">เวลา</label>
                        <input type="time" name="time" value="00:00" required
                               class="w-full rounded-xl border border-white/10 bg-slate-950/60 px-4 py-3 text-sm text-slate-100 outline-none focus:border-indigo-400/60 focus:ring-4 focus:ring-indigo-400/10 [color-scheme:dark]" />
                    </div>

                    <div class="md:col-span-4">
                        <button type="submit"
                                class="w-full rounded-xl bg-indigo-500 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/15 transition hover:bg-indigo-400 active:scale-[0.99]">
                            เพิ่ม Countdown
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Grid -->
        <section class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($countdowns as $cd): ?>
                <?php
                    $id = (string)$cd['id'];
                    $title = (string)$cd['title'];
                    $target_iso = (string)$cd['target_iso'];
                    $target_ts = (int)$cd['target_ts'];
                    $target_th = format_thai_datetime($target_iso, $TZ);
                ?>
                <article class="group rounded-2xl border border-white/10 bg-white/5 p-5 shadow-[0_0_0_1px_rgba(255,255,255,.03)]">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span id="badge-<?= h($id) ?>" class="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-1 text-[11px] font-medium text-emerald-200">
                                    ACTIVE
                                </span>
                                <span class="text-xs text-slate-400">Target</span>
                            </div>
                            <h3 class="mt-2 truncate text-lg font-semibold tracking-tight" title="<?= h($title) ?>"><?= h($title) ?></h3>
                            <p class="mt-1 text-sm text-slate-400"><?= h($target_th) ?></p>
                        </div>

                        <a href="index.php?action=delete&id=<?= h($id) ?>"
                           onclick="return confirm('ลบ Countdown นี้?');"
                           class="rounded-xl border border-white/10 bg-slate-950/40 px-3 py-2 text-xs text-slate-300 transition hover:border-rose-400/40 hover:text-rose-200">
                            ลบ
                        </a>
                    </div>

                    <div class="mt-5 grid grid-cols-4 gap-2">
                        <div class="rounded-xl border border-white/10 bg-slate-950/45 p-3 text-center">
                            <div id="cd-<?= h($id) ?>-days" data-val="--" class="font-mono text-2xl font-bold">--</div>
                            <div class="mt-1 text-[11px] uppercase tracking-wider text-slate-400">Days</div>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-slate-950/45 p-3 text-center">
                            <div id="cd-<?= h($id) ?>-hours" data-val="--" class="font-mono text-2xl font-bold">--</div>
                            <div class="mt-1 text-[11px] uppercase tracking-wider text-slate-400">Hours</div>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-slate-950/45 p-3 text-center">
                            <div id="cd-<?= h($id) ?>-mins" data-val="--" class="font-mono text-2xl font-bold">--</div>
                            <div class="mt-1 text-[11px] uppercase tracking-wider text-slate-400">Mins</div>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-slate-950/45 p-3 text-center">
                            <div id="cd-<?= h($id) ?>-secs" data-val="--" class="font-mono text-2xl font-bold">--</div>
                            <div class="mt-1 text-[11px] uppercase tracking-wider text-slate-400">Secs</div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="h-2 w-full overflow-hidden rounded-full bg-white/5">
                            <div id="bar-<?= h($id) ?>" class="h-full w-0 rounded-full bg-gradient-to-r from-indigo-400 to-sky-300 transition-[width] duration-700"></div>
                        </div>
                        <div id="done-<?= h($id) ?>" class="mt-3 hidden rounded-xl border border-emerald-500/25 bg-emerald-500/10 px-3 py-2 text-xs text-emerald-200">
                            ถึงเวลาที่กำหนดแล้ว
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if (count($countdowns) === 0): ?>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-10 text-center text-slate-300">
                ยังไม่มี Countdown
            </div>
        <?php endif; ?>
    </main>

    <footer class="relative border-t border-white/10 bg-slate-950/60 py-6">
        <div class="mx-auto max-w-7xl px-4 text-xs text-slate-500">
            PHP 8.3 • Server-authoritative countdown • Session storage
        </div>
    </footer>

    <script>
        // Data from PHP (server-authoritative baseline)
        const SERVER_NOW = <?= (int)$server_now ?>;
        const COUNTDOWNS = <?= json_encode(array_map(function($c){
            return [
                'id' => (string)$c['id'],
                'target_ts' => (int)$c['target_ts'],
                'created_ts' => (int)($c['created_ts'] ?? time()),
            ];
        }, $countdowns), JSON_UNESCAPED_UNICODE) ?>;

        let currentServerTs = SERVER_NOW;

        const elServerTime = document.getElementById('serverTime');
        const elSyncState = document.getElementById('syncState');

        function pad2(n){ return String(n).padStart(2,'0'); }

        function setTextAnimated(el, nextVal){
            if (!el) return;
            const prev = el.dataset.val;
            const next = String(nextVal);
            if (prev === next) return;

            el.dataset.val = next;
            el.textContent = next;

            el.classList.remove('pop');
            void el.offsetWidth;
            el.classList.add('pop');
        }

        function updateServerClock(ts){
            const d = new Date(ts * 1000); // display only
            // toLocaleString uses client locale for formatting; base time is server ts
            elServerTime.textContent = d.toLocaleTimeString('th-TH', { hour12:false });
        }

        function updateOne(cd, nowTs){
            const diff = cd.target_ts - nowTs;

            const daysEl = document.getElementById(`cd-${cd.id}-days`);
            const hoursEl = document.getElementById(`cd-${cd.id}-hours`);
            const minsEl = document.getElementById(`cd-${cd.id}-mins`);
            const secsEl = document.getElementById(`cd-${cd.id}-secs`);

            const badge = document.getElementById(`badge-${cd.id}`);
            const done = document.getElementById(`done-${cd.id}`);
            const bar = document.getElementById(`bar-${cd.id}`);

            if (diff <= 0){
                setTextAnimated(daysEl, '0');
                setTextAnimated(hoursEl, '00');
                setTextAnimated(minsEl, '00');
                setTextAnimated(secsEl, '00');

                if (badge){
                    badge.textContent = 'DONE';
                    badge.className = 'inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-1 text-[11px] font-medium text-emerald-200';
                }
                if (done) done.classList.remove('hidden');
                if (bar) bar.style.width = '100%';
                return;
            }

            const days = Math.floor(diff / 86400);
            const hours = Math.floor((diff % 86400) / 3600);
            const mins = Math.floor((diff % 3600) / 60);
            const secs = Math.floor(diff % 60);

            setTextAnimated(daysEl, String(days));
            setTextAnimated(hoursEl, pad2(hours));
            setTextAnimated(minsEl, pad2(mins));
            setTextAnimated(secsEl, pad2(secs));

            if (badge){
                badge.textContent = 'ACTIVE';
                badge.className = 'inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-1 text-[11px] font-medium text-emerald-200';
            }
            if (done) done.classList.add('hidden');

            // Progress bar (second-based within minute for visual motion)
            if (bar){
                bar.style.width = `${((60 - secs) / 60) * 100}%`;
            }
        }

        function renderAll(){
            updateServerClock(currentServerTs);
            for (const cd of COUNTDOWNS){
                updateOne(cd, currentServerTs);
            }
        }

        // Tick from server baseline (no client clock dependency for "now")
        function tick(){
            currentServerTs += 1;
            renderAll();
        }

        // Periodic re-sync from server to avoid drift
        async function resync(){
            try{
                if (elSyncState) elSyncState.textContent = 'กำลังซิงก์...';
                const r = await fetch('index.php?api=now', { cache: 'no-store' });
                const j = await r.json();
                if (typeof j.ts === 'number'){
                    currentServerTs = j.ts;
                    if (elSyncState) elSyncState.textContent = 'ซิงก์แล้ว';
                } else {
                    if (elSyncState) elSyncState.textContent = 'ซิงก์ไม่สำเร็จ';
                }
            } catch(e){
                if (elSyncState) elSyncState.textContent = 'ซิงก์ไม่สำเร็จ';
            }
        }

        // Initial render (ensures numbers show immediately)
        renderAll();

        // Start ticking
        setInterval(tick, 1000);

        // Re-sync every 60s
        setInterval(resync, 60000);
        // Do one early resync after 2s
        setTimeout(resync, 2000);
    </script>
</body>
</html>
```
