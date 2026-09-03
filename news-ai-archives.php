<?php
declare(strict_types=1);

// News IA — index des archives quotidiennes.
// Copyright (C) 2026 David Legoupil, licensed under the GNU GPL v3 or
// later (same terms as the sibling art-actu project).
//
// Lists the frozen daily snapshots written by lib.php's
// archive_current_page() into archives/ (served statically at
// /archives/news-ai-{date}.php — see the nginx location block). This page
// itself stays dynamic (rescans the directory on every request) since new
// archives appear daily and the list would otherwise need its own publish
// step to stay in sync.

require __DIR__ . '/lib.php';

// Two kinds of files live in archives/: the daily auto-generated snapshots
// (news-ai-YYYY-MM-DD.php) and occasional hand-written articles (anything
// else) — e.g. a French translation/explainer of an external piece,
// added by hand rather than by the n8n workflow. Articles are listed by
// their own <title> tag so adding a new one needs no edit here.
$dates = [];
$articles = [];
if (is_dir($ARCHIVE_DIR)) {
    foreach (scandir($ARCHIVE_DIR) ?: [] as $file) {
        if (preg_match('/^news-ai-(\d{4}-\d{2}-\d{2})\.php$/', $file, $m)) {
            $dates[] = $m[1];
        } elseif (preg_match('/\.php$/', $file)) {
            $title = $file;
            $contents = file_get_contents($ARCHIVE_DIR . '/' . $file);
            if ($contents !== false && preg_match('/<title>(.*?)<\/title>/is', $contents, $tm)) {
                $title = trim(html_entity_decode($tm[1], ENT_QUOTES, 'UTF-8'));
            }
            $articles[] = ['file' => $file, 'title' => $title];
        }
    }
}
rsort($dates);
usort($articles, fn($a, $b) => strcmp($a['title'], $b['title']));

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Archives — Actu IA</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, "Segoe UI", Arial, sans-serif; background: #0B0B0E; color: #F4F1EB; line-height: 1.5; }
  header { padding: 18px 22px; background: #15140F; border-bottom: 1px solid #262521; }
  header a { color: #E8C468; text-decoration: none; font-size: 0.85rem; }
  header h1 { font-size: 1.1rem; margin: 8px 0 0; font-weight: 600; }
  main { max-width: 560px; margin: 0 auto; padding: 24px 22px 60px; }
  h2 { font-size: 0.85rem; text-transform: uppercase; letter-spacing: .04em; color: #8C8577; margin: 26px 0 6px; }
  ul { list-style: none; margin: 0; padding: 0; }
  li { border-bottom: 1px solid #191813; }
  li a { display: block; padding: 12px 4px; color: #F4F1EB; text-decoration: none; font-size: 0.95rem; }
  li a:hover { color: #E8C468; }
  .empty { color: #6B6560; font-size: 0.9rem; font-style: italic; }
  footer { text-align: center; padding: 20px; color: #6B6560; font-size: 0.78rem; }
</style>
</head>
<body>
<header>
  <a href="/news-ai.php">← Retour à la page actuelle</a>
  <h1>Archives — Actu IA</h1>
</header>
<main>
<?php if (!empty($articles)): ?>
  <h2>Articles</h2>
  <ul>
    <?php foreach ($articles as $a): ?>
      <li><a href="/archives/<?= esc($a['file']) ?>"><?= esc($a['title']) ?></a></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if (!empty($dates)): ?>
  <h2>Éditions quotidiennes</h2>
<?php endif; ?>
<?php if (empty($dates) && empty($articles)): ?>
  <p class="empty">Aucune archive pour le moment — une page n'est archivée qu'une fois remplacée par celle du jour suivant.</p>
<?php elseif (!empty($dates)): ?>
  <ul>
    <?php foreach ($dates as $d): ?>
      <li><a href="/archives/news-ai-<?= esc($d) ?>.php"><?= esc(date('d/m/Y', strtotime($d))) ?></a></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
</main>
<footer>© <?= date('Y') ?> David Legoupil</footer>
</body>
</html>
