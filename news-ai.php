<?php
declare(strict_types=1);

// News IA — actualités quotidiennes Google / Apple / OpenAI / Mistral AI /
// DeepSeek, Anthropic; classement des meilleurs LLM et des meilleurs
// harnesses (agents de code type Claude Code, Cursor...); cours de bourse.
// Copyright (C) 2026 David Legoupil, licensed under the GNU GPL v3 or
// later (same terms as the sibling art-actu project).
//
// Deliberately public (no Authentik gate), same posture as art-actu.php.
// GET renders the page; POST (with the right X-News-Ai-Token header) is
// how the "News IA quotidien" n8n workflow publishes each day's data —
// see lib.php for the shared schema/rendering and the archiving of the
// previous day's page to /archives/news-ai-{date}.php before it's
// overwritten.

require __DIR__ . '/lib.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $token = $_SERVER['HTTP_X_NEWS_AI_TOKEN'] ?? '';
    if (!hash_equals((string) $config['publish_token'], $token)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid token']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $body['date'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'expected {"date": "YYYY-MM-DD", "news": {...}, "ranking": [...], "harness_ranking": [...], "stocks": {...}}']);
        exit;
    }

    $newDate = (string) $body['date'];
    $pdo = get_db($DB_PATH);

    $oldDate = meta_get($pdo, 'content_date');
    $archived = false;
    if ($oldDate !== null && $oldDate !== $newDate) {
        archive_current_page($pdo, $oldDate);
        $archived = true;
    }

    $pdo->beginTransaction();
    db_replace_news($pdo, is_array($body['news'] ?? null) ? $body['news'] : []);
    db_replace_ranking($pdo, is_array($body['ranking'] ?? null) ? $body['ranking'] : []);
    db_replace_harness_ranking($pdo, is_array($body['harness_ranking'] ?? null) ? $body['harness_ranking'] : []);
    db_replace_stocks($pdo, is_array($body['stocks'] ?? null) ? $body['stocks'] : []);
    meta_set($pdo, 'content_date', $newDate);
    meta_set($pdo, 'updated_at', date(DATE_ATOM));
    $pdo->commit();

    echo json_encode(['ok' => true, 'archived' => $archived, 'date' => $newDate]);
    exit;
}

// --- GET: render the live page ---

$pdo = get_db($DB_PATH);
$newsByCompany = db_get_news_by_company($pdo);
$ranking = db_get_ranking($pdo);
$harnessRanking = db_get_harness_ranking($pdo);
$stocks = db_get_stocks($pdo);
$updatedAt = meta_get($pdo, 'updated_at');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

echo render_news_ai_page($newsByCompany, $ranking, $harnessRanking, $stocks, $updatedAt, null);
