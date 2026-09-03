<?php
declare(strict_types=1);

// News IA — actualités quotidiennes Google / Apple / OpenAI / Mistral AI /
// DeepSeek, Anthropic; classement des meilleurs LLM et des meilleurs
// harnesses (agents de code type Claude Code, Cursor...); cours de bourse.
// Copyright (C) 2026 David Legoupil, licensed under the GNU GPL v3 or
// later (same terms as the sibling art-actu project).
//
// Shared config/DB/rendering logic, required by news-ai.php. Kept in one
// place (rather than inline in news-ai.php, mirroring /srv/art-actu/lib.php)
// so the exact same render_news_ai_page() produces both the live page and
// the frozen daily archive snapshot in archives/ — one template, so an
// archived page can never drift from what the live page looked like that
// day.

$CONFIG_PATH  = __DIR__ . '/config.php';
$DB_PATH      = __DIR__ . '/news.db';
$ARCHIVE_DIR  = __DIR__ . '/archives';

$config = require $CONFIG_PATH;

// OpenAI/Mistral/DeepSeek are privately held — no public ticker exists,
// so 'ticker' stays null for them and the page shows "Non cotée en
// bourse" instead of inventing/borrowing a number (e.g. Microsoft's stock
// as a proxy for OpenAI would be misleading).
const COMPANIES = [
    'google'   => ['label' => 'Google',    'icon' => 'google',    'color' => '#4285F4', 'ticker' => 'GOOGL'],
    'apple'    => ['label' => 'Apple',     'icon' => 'apple',     'color' => '#A6A6A6', 'ticker' => 'AAPL'],
    'openai'   => ['label' => 'OpenAI',    'icon' => 'openai',    'color' => '#10A37F', 'ticker' => null],
    'anthropic'=> ['label' => 'Anthropic', 'icon' => 'anthropic', 'color' => '#CC785C', 'ticker' => null],
    'mistral'  => ['label' => 'Mistral AI','icon' => 'mistralai', 'color' => '#FA520F', 'ticker' => null],
    'deepseek' => ['label' => 'DeepSeek',  'icon' => 'deepseek',  'color' => '#4D6BFE', 'ticker' => null],
];

const NEWS_PER_COMPANY = 5;

function get_db(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS news_items (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        company      TEXT NOT NULL,
        title        TEXT NOT NULL,
        url          TEXT NOT NULL,
        source       TEXT NOT NULL DEFAULT \'\',
        published_at TEXT NOT NULL DEFAULT \'\',
        summary      TEXT NOT NULL DEFAULT \'\',
        sort_order   INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS llm_ranking (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        rank    INTEGER NOT NULL,
        model   TEXT NOT NULL,
        company TEXT NOT NULL DEFAULT \'\',
        note    TEXT NOT NULL DEFAULT \'\'
    )');
    // "Harness" = coding agent tool (Claude Code, Cursor, GitHub Copilot,
    // Codex CLI...) as opposed to the underlying LLM itself — a separate
    // ranking next to llm_ranking, same shape.
    $pdo->exec('CREATE TABLE IF NOT EXISTS harness_ranking (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        rank    INTEGER NOT NULL,
        name    TEXT NOT NULL,
        company TEXT NOT NULL DEFAULT \'\',
        note    TEXT NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS stocks (
        company        TEXT PRIMARY KEY,
        ticker         TEXT NOT NULL DEFAULT \'\',
        price          REAL,
        currency       TEXT NOT NULL DEFAULT \'\',
        change_percent REAL,
        as_of          TEXT NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (mkey TEXT PRIMARY KEY, mvalue TEXT)');
    return $pdo;
}

function meta_get(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT mvalue FROM meta WHERE mkey = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
}

function meta_set(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO meta (mkey, mvalue) VALUES (?, ?) ON CONFLICT(mkey) DO UPDATE SET mvalue = excluded.mvalue');
    $stmt->execute([$key, $value]);
}

function db_replace_news(PDO $pdo, array $newsByCompany): void
{
    $pdo->exec('DELETE FROM news_items');
    $stmt = $pdo->prepare('INSERT INTO news_items (company, title, url, source, published_at, summary, sort_order)
        VALUES (:company, :title, :url, :source, :published_at, :summary, :sort_order)');
    foreach ($newsByCompany as $company => $items) {
        if (!array_key_exists($company, COMPANIES) || !is_array($items)) {
            continue;
        }
        $order = 0;
        foreach (array_slice($items, 0, NEWS_PER_COMPANY) as $item) {
            if (empty($item['title']) || empty($item['url'])) {
                continue;
            }
            $stmt->execute([
                ':company' => $company,
                ':title' => (string) $item['title'],
                ':url' => (string) $item['url'],
                ':source' => (string) ($item['source'] ?? ''),
                ':published_at' => (string) ($item['published_at'] ?? ''),
                ':summary' => (string) ($item['summary'] ?? ''),
                ':sort_order' => $order++,
            ]);
        }
    }
}

function db_replace_ranking(PDO $pdo, array $ranking): void
{
    $pdo->exec('DELETE FROM llm_ranking');
    $stmt = $pdo->prepare('INSERT INTO llm_ranking (rank, model, company, note) VALUES (:rank, :model, :company, :note)');
    foreach ($ranking as $row) {
        if (empty($row['model'])) {
            continue;
        }
        $stmt->execute([
            ':rank' => (int) ($row['rank'] ?? 0),
            ':model' => (string) $row['model'],
            ':company' => (string) ($row['company'] ?? ''),
            ':note' => (string) ($row['note'] ?? ''),
        ]);
    }
}

function db_replace_harness_ranking(PDO $pdo, array $ranking): void
{
    $pdo->exec('DELETE FROM harness_ranking');
    $stmt = $pdo->prepare('INSERT INTO harness_ranking (rank, name, company, note) VALUES (:rank, :name, :company, :note)');
    foreach ($ranking as $row) {
        if (empty($row['name'])) {
            continue;
        }
        $stmt->execute([
            ':rank' => (int) ($row['rank'] ?? 0),
            ':name' => (string) $row['name'],
            ':company' => (string) ($row['company'] ?? ''),
            ':note' => (string) ($row['note'] ?? ''),
        ]);
    }
}

function db_replace_stocks(PDO $pdo, array $stocks): void
{
    $pdo->exec('DELETE FROM stocks');
    $stmt = $pdo->prepare('INSERT INTO stocks (company, ticker, price, currency, change_percent, as_of)
        VALUES (:company, :ticker, :price, :currency, :change_percent, :as_of)');
    foreach (COMPANIES as $key => $info) {
        $s = $stocks[$key] ?? null;
        if (!is_array($s) || !isset($s['price'])) {
            continue;
        }
        $stmt->execute([
            ':company' => $key,
            ':ticker' => (string) ($info['ticker'] ?? ''),
            ':price' => (float) $s['price'],
            ':currency' => (string) ($s['currency'] ?? 'USD'),
            ':change_percent' => isset($s['change_percent']) ? (float) $s['change_percent'] : null,
            ':as_of' => (string) ($s['as_of'] ?? ''),
        ]);
    }
}

function db_get_news_by_company(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM news_items ORDER BY company, sort_order')->fetchAll(PDO::FETCH_ASSOC);
    $byCompany = array_fill_keys(array_keys(COMPANIES), []);
    foreach ($rows as $r) {
        if (isset($byCompany[$r['company']])) {
            $byCompany[$r['company']][] = $r;
        }
    }
    return $byCompany;
}

function db_get_ranking(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM llm_ranking ORDER BY rank')->fetchAll(PDO::FETCH_ASSOC);
}

function db_get_harness_ranking(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM harness_ranking ORDER BY rank')->fetchAll(PDO::FETCH_ASSOC);
}

function db_get_stocks(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM stocks')->fetchAll(PDO::FETCH_ASSOC);
    $byCompany = [];
    foreach ($rows as $r) {
        $byCompany[$r['company']] = $r;
    }
    return $byCompany;
}

// Reads a logo SVG and forces its fill to white (these simple-icons files
// have no fill attribute at all, i.e. default black; setting fill on the
// root <svg> is enough since none of their child <path> elements override
// it) so it reads as a white mark on the company's own coloured chip.
function render_logo_svg(string $iconKey): string
{
    $path = __DIR__ . '/logos/' . $iconKey . '.svg';
    if (!is_readable($path)) {
        return '';
    }
    $svg = file_get_contents($path);
    return preg_replace('/<svg /', '<svg fill="#fff" ', $svg, 1) ?? $svg;
}

function esc(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function format_price(array $stock): string
{
    $price = number_format((float) $stock['price'], 2, ',', ' ');
    $currency = $stock['currency'] === 'USD' ? '$' : ($stock['currency'] === 'EUR' ? '€' : $stock['currency'] . ' ');
    return $currency . $price;
}

function format_change(?float $pct): array
{
    if ($pct === null) {
        return ['', ''];
    }
    $sign = $pct >= 0 ? '+' : '';
    $cls = $pct >= 0 ? 'up' : 'down';
    return [$sign . number_format($pct, 2, ',', '.') . ' %', $cls];
}

// One template for both the live page and the frozen archive snapshots
// (see archive_current_page()) — $archivedDate is null for the live page,
// or the content date being frozen ("2026-09-02") for a snapshot.
function render_news_ai_page(array $newsByCompany, array $ranking, array $harnessRanking, array $stocks, ?string $updatedAt, ?string $archivedDate): string
{
    ob_start();
    $isArchive = $archivedDate !== null;
    $pageTitle = $isArchive
        ? 'Actu IA — archive du ' . date('d/m/Y', strtotime($archivedDate))
        : 'Actu IA — Google, Apple, OpenAI, Mistral AI, DeepSeek';
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($pageTitle) ?></title>
<?php if ($isArchive): ?><meta name="robots" content="noindex, follow"><?php endif; ?>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, "Segoe UI", Arial, sans-serif; background: #0B0B0E; color: #F4F1EB; line-height: 1.5; }
  a { color: #E8C468; }
  .hero { width: 100%; height: 140px; background: #0B0B0E; overflow: hidden; }
  .hero canvas { display: block; width: 100%; height: 100%; }
  .archive-banner { background: #1A1916; border-bottom: 1px solid #34322C; padding: 10px 22px; font-size: 0.85rem; color: #B7B2A8; text-align: center; }
  .archive-banner a { color: #E8C468; }
  .updated-below { margin: 0; padding: 10px 22px; font-size: 0.8rem; color: #B7B2A8; }
  main { max-width: 980px; margin: 0 auto; padding: 8px 22px 60px; }
  h2 { font-size: 1.05rem; margin: 30px 0 14px; font-weight: 600; color: #F4F1EB; border-bottom: 1px solid #262521; padding-bottom: 8px; }
  .companies { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
  .company-card { background: #15140F; border: 1px solid #262521; border-radius: 12px; padding: 16px; }
  .company-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
  .logo-chip { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex: none; }
  .logo-chip svg { width: 18px; height: 18px; }
  .company-name { font-weight: 600; font-size: 1rem; flex: 1; }
  .stock-pill { font-size: 0.78rem; padding: 3px 9px; border-radius: 20px; background: #1A1916; border: 1px solid #34322C; white-space: nowrap; }
  .stock-pill .chg.up { color: #7FB39A; }
  .stock-pill .chg.down { color: #D98B75; }
  .stock-pill.private { color: #8C8577; font-style: italic; }
  .news-item { padding: 9px 0; border-top: 1px solid #221F1A; }
  .news-item:first-child { border-top: none; padding-top: 0; }
  .news-item a { font-size: 0.9rem; font-weight: 600; color: #F4F1EB; text-decoration: none; }
  .news-item a:hover { color: #E8C468; }
  .news-meta { font-size: 0.75rem; color: #8C8577; margin: 2px 0 4px; }
  .news-summary { font-size: 0.82rem; color: #B7B2A8; }
  .empty { color: #6B6560; font-size: 0.85rem; font-style: italic; }
  table.ranking { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
  table.ranking th { text-align: left; color: #8C8577; font-size: 0.72rem; text-transform: uppercase; letter-spacing: .04em; padding: 6px 10px; border-bottom: 1px solid #262521; }
  table.ranking td { padding: 9px 10px; border-bottom: 1px solid #1C1B17; }
  table.ranking tr:last-child td { border-bottom: none; }
  .rank-badge { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #262521; color: #E8C468; font-weight: 700; font-size: 0.78rem; }
  .ranking-note { font-size: 0.78rem; color: #6B6560; margin-top: 10px; }
  .rankings-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px 32px; }
  .rankings-row h2 { margin-top: 30px; }
  .site-footer { text-align: center; padding: 20px 22px 30px; color: #6B6560; font-size: 0.78rem; border-top: 1px solid #191813; margin-top: 20px; }
  .site-footer a { color: #8C8577; }
</style>
</head>
<body>

<?php if ($isArchive): ?>
<div class="archive-banner">
  Archive du <?= esc(date('d/m/Y', strtotime($archivedDate))) ?> — <a href="/news-ai.php">voir la page actuelle →</a>
</div>
<?php endif; ?>

<div class="hero">
  <canvas id="ia-hero" aria-hidden="true"></canvas>
</div>

<p class="updated-below">
  <?= $updatedAt ? 'Mis à jour le ' . esc(date('d/m/Y à H:i', strtotime($updatedAt))) : 'Pas encore de données' ?>
</p>

<main>
  <h2>Actualités par entreprise</h2>
  <div class="companies">
    <?php foreach (COMPANIES as $key => $info): ?>
      <div class="company-card">
        <div class="company-head">
          <span class="logo-chip" style="background:<?= esc($info['color']) ?>"><?= render_logo_svg($info['icon']) ?></span>
          <span class="company-name"><?= esc($info['label']) ?></span>
          <?php if (isset($stocks[$key])): $s = $stocks[$key]; [$chgText, $chgCls] = format_change($s['change_percent'] !== null ? (float) $s['change_percent'] : null); ?>
            <span class="stock-pill"><?= esc($info['ticker']) ?> · <?= esc(format_price($s)) ?><?php if ($chgText): ?> <span class="chg <?= $chgCls ?>"><?= esc($chgText) ?></span><?php endif; ?></span>
          <?php else: ?>
            <span class="stock-pill private">Non cotée en bourse</span>
          <?php endif; ?>
        </div>
        <?php $items = $newsByCompany[$key] ?? []; ?>
        <?php if (empty($items)): ?>
          <p class="empty">Aucune actualité récente.</p>
        <?php else: ?>
          <?php foreach ($items as $n): ?>
            <div class="news-item">
              <a href="<?= esc($n['url']) ?>" target="_blank" rel="noopener"><?= esc($n['title']) ?></a>
              <div class="news-meta"><?= esc($n['source']) ?><?= $n['published_at'] ? ' — ' . esc($n['published_at']) : '' ?></div>
              <?php if ($n['summary']): ?><div class="news-summary"><?= esc($n['summary']) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="rankings-row">
    <div class="ranking-col">
      <h2>Classement des meilleurs LLM</h2>
      <?php if (empty($ranking)): ?>
        <p class="empty">Pas encore de classement.</p>
      <?php else: ?>
        <table class="ranking">
          <thead><tr><th></th><th>Modèle</th><th>Entreprise</th><th>Note</th></tr></thead>
          <tbody>
            <?php foreach ($ranking as $r): ?>
              <tr>
                <td><span class="rank-badge"><?= (int) $r['rank'] ?></span></td>
                <td><?= esc($r['model']) ?></td>
                <td><?= esc($r['company']) ?></td>
                <td><?= esc($r['note']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="ranking-note">Classement établi par recherche web automatisée à partir de benchmarks publics — à titre indicatif, susceptible d'évoluer chaque jour.</p>
      <?php endif; ?>
    </div>

    <div class="ranking-col">
      <h2>Classement des meilleurs harnesses</h2>
      <?php if (empty($harnessRanking)): ?>
        <p class="empty">Pas encore de classement.</p>
      <?php else: ?>
        <table class="ranking">
          <thead><tr><th></th><th>Outil</th><th>Entreprise</th><th>Note</th></tr></thead>
          <tbody>
            <?php foreach ($harnessRanking as $r): ?>
              <tr>
                <td><span class="rank-badge"><?= (int) $r['rank'] ?></span></td>
                <td><?= esc($r['name']) ?></td>
                <td><?= esc($r['company']) ?></td>
                <td><?= esc($r['note']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="ranking-note">Outils/agents de code en ligne de commande ou IDE (Claude Code, Codex CLI, Cursor, etc.) — classement établi par recherche web automatisée, à titre indicatif.</p>
      <?php endif; ?>
    </div>
  </div>

  <footer class="site-footer">
    © <?= date('Y') ?> David Legoupil<?php if (!$isArchive): ?> — <a href="/news-ai.php">page actuelle</a><?php endif; ?> — <a href="/news-ai-archives.php">archives</a>
  </footer>
</main>

<script>
// --- Pixel-art "IA" hero banner --- (same mechanism as art-actu.php's
// "ART" banner: pixels fly in from random positions, hold, then exit).
(function () {
  const canvasEls = ['ia-hero']
    .map(function (id) { return document.getElementById(id); })
    .filter(Boolean);
  if (!canvasEls.length) return;
  const surfaces = canvasEls.map(function (c) { return { canvas: c, ctx: c.getContext('2d') }; });
  const primary = surfaces[0].canvas;

  const WORD = 'IA';
  const GAP = 1;

  const BOLD_COLS = 9, BOLD_ROWS = 11;
  const BOLD_FONT = {
    I: ['XXXXXXXXX', 'XXXXXXXXX', '...XXX...', '...XXX...', '...XXX...',
        '...XXX...', '...XXX...', '...XXX...', '...XXX...', 'XXXXXXXXX', 'XXXXXXXXX'],
    A: ['...XXX...', '..XXXXX..', '.XX...XX.', 'XX.....XX', 'XX.....XX',
        'XXXXXXXXX', 'XXXXXXXXX', 'XX.....XX', 'XX.....XX', 'XX.....XX', 'XX.....XX'],
  };
  const THIN_COLS = 5, THIN_ROWS = 7;
  const THIN_FONT = {
    I: ['XXXXX', '..X..', '..X..', '..X..', '..X..', '..X..', 'XXXXX'],
    A: ['.XXX.', 'X...X', 'X...X', 'XXXXX', 'X...X', 'X...X', 'X...X'],
  };

  function buildCellsFromFont(font, letterCols, letterRows) {
    const cells = [];
    let colOffset = 0;
    for (const letter of WORD) {
      const rows = font[letter];
      for (let r = 0; r < letterRows; r++) {
        for (let c = 0; c < letterCols; c++) {
          if (rows[r][c] === 'X') cells.push({ col: colOffset + c, row: r });
        }
      }
      colOffset += letterCols + GAP;
    }
    const totalCols = WORD.length * letterCols + (WORD.length - 1) * GAP;
    return { cells: cells, totalCols: totalCols, totalRows: letterRows };
  }

  function upscale(base, factor) {
    const out = [];
    base.cells.forEach(function (c) {
      for (let dr = 0; dr < factor; dr++) {
        for (let dc = 0; dc < factor; dc++) out.push({ col: c.col * factor + dc, row: c.row * factor + dr });
      }
    });
    return { cells: out, totalCols: base.totalCols * factor, totalRows: base.totalRows * factor };
  }

  function outline(base) {
    const set = new Set(base.cells.map(function (c) { return c.col + ',' + c.row; }));
    const filled = function (c, r) { return set.has(c + ',' + r); };
    const out = base.cells.filter(function (c) {
      return !filled(c.col + 1, c.row) || !filled(c.col - 1, c.row) || !filled(c.col, c.row + 1) || !filled(c.col, c.row - 1);
    });
    return { cells: out, totalCols: base.totalCols, totalRows: base.totalRows };
  }

  function dotted(base) {
    const out = base.cells.filter(function (c) { return (c.col + c.row) % 2 === 0; });
    return { cells: out, totalCols: base.totalCols, totalRows: base.totalRows };
  }

  function sparse(base) {
    const out = base.cells.filter(function (c, i) { return (i * 7 + c.col * 3 + c.row * 5) % 9 < 5; });
    return { cells: out, totalCols: base.totalCols, totalRows: base.totalRows };
  }

  const RESOLUTION_FACTOR = 3;
  const THIN_RESOLUTION_FACTOR = 5;
  function getBoldBase() { return upscale(buildCellsFromFont(BOLD_FONT, BOLD_COLS, BOLD_ROWS), RESOLUTION_FACTOR); }
  function getThinBase() { return upscale(buildCellsFromFont(THIN_FONT, THIN_COLS, THIN_ROWS), THIN_RESOLUTION_FACTOR); }

  const FONT_VARIANTS = [
    { name: 'bold', build: function () { return getBoldBase(); } },
    { name: 'outline', build: function () { return outline(getBoldBase()); } },
    { name: 'thin', build: function () { return getThinBase(); } },
    { name: 'dotted', build: function () { return dotted(getBoldBase()); } },
    { name: 'sparse', build: function () { return sparse(getBoldBase()); } },
  ];

  const COLOR_PALETTES = [
    { name: 'neon', colors: ['#48d1ff', '#ff5ca8', '#b586ff'], exit: 'explode', bg: '#0B0B0E' },
    { name: 'sunset', colors: ['#ff8b3d', '#ff5c5c', '#ffd23f'], exit: 'confetti', bg: '#120A08' },
    { name: 'scandinave', colors: ['#C1694F', '#5D80A3', '#5F8B7A', '#D3A048', '#B97A94'], exit: 'fade', bg: '#0E0D0B' },
    { name: 'rainbow', rainbow: true, exit: 'explode', bg: '#050505' },
    { name: 'or', colors: ['#E8C468', '#FFFFFF', '#B97A1D'], exit: 'confetti', bg: '#0A0A0A' },
  ];

  const STYLES = [];
  FONT_VARIANTS.forEach(function (font) {
    COLOR_PALETTES.forEach(function (palette) {
      STYLES.push({
        name: font.name + '-' + palette.name, font: font,
        colors: palette.colors, rainbow: palette.rainbow, exit: palette.exit, bg: palette.bg,
      });
    });
  });

  function nextStyleIndex() {
    if (STYLES.length <= 1) return 0;
    let idx;
    do { idx = Math.floor(Math.random() * STYLES.length); } while (idx === styleIndex);
    return idx;
  }

  let styleIndex = Math.floor(Math.random() * STYLES.length);
  let phase = 'forming';
  let phaseStart = performance.now();
  const DURATIONS = { forming: 1600, hold: 1300, exiting: 1300 };
  let pixels = [];
  let currentTotalCols = 1, currentTotalRows = 1;

  function pickColor(style, i) {
    if (style.rainbow) return 'hsl(' + Math.round((i * 41) % 360) + ',85%,60%)';
    return style.colors[i % style.colors.length];
  }

  const MARGIN_FRAC = 0.12;
  function computeLayout() {
    const innerW = primary.width * (1 - MARGIN_FRAC * 2);
    const innerH = primary.height * (1 - MARGIN_FRAC * 2);
    const cellW = innerW / currentTotalCols;
    const cellH = innerH / currentTotalRows;
    const totalW = currentTotalCols * cellW;
    const totalH = currentTotalRows * cellH;
    return {
      cellW: cellW, cellH: cellH,
      offsetX: (primary.width - totalW) / 2,
      offsetY: (primary.height - totalH) / 2,
      size: Math.min(cellW, cellH) * 0.88,
    };
  }

  function targetFor(layout, cell) {
    const tx = layout.offsetX + cell.col * layout.cellW + layout.cellW / 2;
    const ty = layout.offsetY + cell.row * layout.cellH + layout.cellH / 2;
    return { tx: tx, ty: ty };
  }

  function resizeAll() {
    surfaces.forEach(function (s) {
      const rect = s.canvas.parentElement.getBoundingClientRect();
      const dpr = window.devicePixelRatio || 1;
      s.canvas.width = Math.max(1, Math.round(rect.width * dpr));
      s.canvas.height = Math.max(1, Math.round(rect.height * dpr));
    });
    if (pixels.length) {
      const layout = computeLayout();
      pixels.forEach(function (p) {
        const t = targetFor(layout, { col: p.col, row: p.row });
        p.tx = t.tx; p.ty = t.ty; p.size = layout.size;
      });
    }
  }
  window.addEventListener('resize', resizeAll);

  function setupForming() {
    const style = STYLES[styleIndex];
    const built = style.font.build();
    currentTotalCols = built.totalCols;
    currentTotalRows = built.totalRows;
    const layout = computeLayout();
    pixels = built.cells.map(function (cell, i) {
      const t = targetFor(layout, cell);
      const startX = Math.random() * primary.width;
      const startY = Math.random() * primary.height;
      return {
        tx: t.tx, ty: t.ty, x: startX, y: startY, startX: startX, startY: startY,
        size: layout.size, color: pickColor(style, i),
        delay: Math.random() * 0.4, alpha: 1, scale: 1, col: cell.col, row: cell.row,
      };
    });
  }

  function setupExit() {
    const style = STYLES[styleIndex];
    const cx = primary.width / 2, cy = primary.height / 2;
    pixels.forEach(function (p) {
      p.startX = p.tx; p.startY = p.ty;
      if (style.exit === 'explode') {
        const angle = Math.atan2(p.ty - cy, p.tx - cx) + (Math.random() - 0.5) * 0.6;
        const dist = 220 + Math.random() * 220;
        p.exitDX = Math.cos(angle) * dist;
        p.exitDY = Math.sin(angle) * dist;
        p.exitRot = (Math.random() - 0.5) * 8;
      } else if (style.exit === 'confetti') {
        p.exitDX = (Math.random() - 0.5) * 260;
        p.exitDY = primary.height * 1.1 + Math.random() * 180;
        p.exitRot = (Math.random() - 0.5) * 12;
      } else {
        p.exitDX = 0; p.exitDY = 0; p.exitRot = 0;
      }
    });
  }

  function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }
  function easeInCubic(t) { return t * t * t; }

  function drawPixelOn(ctx, p, rotation) {
    ctx.save();
    ctx.globalAlpha = Math.max(0, Math.min(1, p.alpha));
    ctx.translate(p.x, p.y);
    if (rotation) ctx.rotate(rotation);
    const s = p.size * (p.scale != null ? p.scale : 1);
    ctx.fillStyle = p.color;
    ctx.fillRect(-s / 2, -s / 2, s, s);
    ctx.restore();
  }

  function drawPixel(p, rotation) {
    surfaces.forEach(function (s) { drawPixelOn(s.ctx, p, rotation); });
  }

  function paintBackground(bg) {
    surfaces.forEach(function (s) {
      s.ctx.fillStyle = bg;
      s.ctx.fillRect(0, 0, s.canvas.width, s.canvas.height);
    });
  }

  function draw(now) {
    const style = STYLES[styleIndex];
    const elapsed = now - phaseStart;
    const dur = DURATIONS[phase];
    const t = Math.min(1, elapsed / dur);

    paintBackground(style.bg);

    if (phase === 'forming') {
      pixels.forEach(function (p) {
        const pt = Math.max(0, Math.min(1, (t - p.delay) / (1 - p.delay)));
        const e = easeOutCubic(pt);
        p.x = p.startX + (p.tx - p.startX) * e;
        p.y = p.startY + (p.ty - p.startY) * e;
        p.alpha = 0.25 + 0.75 * e;
        drawPixel(p, 0);
      });
      if (t >= 1) { phase = 'hold'; phaseStart = now; }
    } else if (phase === 'hold') {
      pixels.forEach(function (p) { p.x = p.tx; p.y = p.ty; p.alpha = 1; p.scale = 1; drawPixel(p, 0); });
      if (t >= 1) { phase = 'exiting'; phaseStart = now; setupExit(); }
    } else {
      if (style.exit === 'fade') {
        pixels.forEach(function (p) {
          const colFrac = p.col / currentTotalCols;
          const local = Math.max(0, Math.min(1, (t - colFrac * 0.5) / 0.5));
          const e = easeInCubic(local);
          p.alpha = 1 - e;
          p.scale = 1 - e * 0.6;
          p.x = p.tx; p.y = p.ty;
          drawPixel(p, 0);
        });
      } else {
        const e = easeInCubic(t);
        pixels.forEach(function (p) {
          p.x = p.tx + p.exitDX * e;
          p.y = p.ty + (style.exit === 'confetti' ? p.exitDY * e * e : p.exitDY * e);
          p.alpha = 1 - e;
          p.scale = 1;
          drawPixel(p, p.exitRot * e);
        });
      }
      if (t >= 1) {
        styleIndex = nextStyleIndex();
        phase = 'forming'; phaseStart = now; setupForming();
      }
    }

    requestAnimationFrame(draw);
  }

  resizeAll();
  setupForming();
  requestAnimationFrame(draw);
})();
</script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Freezes the currently-live page (before it gets overwritten by a new
// day's publish) to archives/news-ai-{$oldDate}.php as plain static HTML
// — served directly by nginx (see the /archives/ location block), so an
// old day's page keeps rendering exactly as it looked forever, even after
// future schema/template changes. No-ops if that date was already
// archived (safe to call on a same-day retry).
function archive_current_page(PDO $pdo, string $oldDate): void
{
    global $ARCHIVE_DIR;
    if (!is_dir($ARCHIVE_DIR)) {
        mkdir($ARCHIVE_DIR, 0755, true);
    }
    $file = $ARCHIVE_DIR . '/news-ai-' . $oldDate . '.php';
    if (file_exists($file)) {
        return;
    }
    $html = render_news_ai_page(
        db_get_news_by_company($pdo),
        db_get_ranking($pdo),
        db_get_harness_ranking($pdo),
        db_get_stocks($pdo),
        meta_get($pdo, 'updated_at'),
        $oldDate
    );
    file_put_contents($file, $html);
}
