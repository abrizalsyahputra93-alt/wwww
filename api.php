<?php
/**
 * EmoteDrop API — backend PHP + file JSON (no MySQL needed)
 * Simpan di root public_html kamu, sama folder dengan index.html
 *
 * Endpoint:
 *   GET  api.php?action=list          → ambil semua drop aktif
 *   POST api.php?action=save          → simpan drop baru
 *   POST api.php?action=boost         → update boost drop
 *   POST api.php?action=expire        → hapus drop (expired manual)
 *   GET  api.php?action=cleanup       → hapus semua drop > 24 jam (bisa di-cron)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Lokasi file penyimpanan JSON ──────────────────────────────────
define('DATA_FILE', __DIR__ . '/emotedrop_data.json');
define('DROP_LIFETIME_MS', 24 * 60 * 60 * 1000); // 24 jam dalam ms

// ── Helpers ──────────────────────────────────────────────────────
function loadDrops(): array {
    if (!file_exists(DATA_FILE)) return [];
    $raw = file_get_contents(DATA_FILE);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function saveDrops(array $drops): void {
    file_put_contents(DATA_FILE, json_encode(array_values($drops), JSON_PRETTY_PRINT), LOCK_EX);
}

function nowMs(): int {
    return (int)(microtime(true) * 1000);
}

function cleanExpired(array $drops): array {
    $now = nowMs();
    return array_values(array_filter($drops, fn($d) => isset($d['expiresAt']) && $d['expiresAt'] > $now));
}

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function getBody(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ── Router ───────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

switch ($action) {

    // ─────────────────────────────────────────────────────────────
    // LIST — ambil semua drop aktif (expired dibuang otomatis)
    // ─────────────────────────────────────────────────────────────
    case 'list': {
        $drops = loadDrops();
        $drops = cleanExpired($drops);
        saveDrops($drops); // simpan hasil clean
        respond(['ok' => true, 'drops' => array_values($drops)]);
    }

    // ─────────────────────────────────────────────────────────────
    // SAVE — simpan drop baru
    // ─────────────────────────────────────────────────────────────
    case 'save': {
        $body = getBody();
        $required = ['id','handle','emote','boosted','solPaid','creatorPubkey','createdAt','expiresAt'];
        foreach ($required as $f) {
            if (!isset($body[$f])) respond(['ok' => false, 'error' => "Missing field: $f"], 400);
        }

        // Sanitize
        $drop = [
            'id'            => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$body['id']),
            'handle'        => substr(preg_replace('/[^a-zA-Z0-9_]/', '', (string)$body['handle']), 0, 30),
            'emote'         => mb_substr((string)$body['emote'], 0, 8),
            'boosted'       => (bool)$body['boosted'],
            'solPaid'       => (float)$body['solPaid'],
            'creatorPubkey' => substr(preg_replace('/[^a-zA-Z0-9]/', '', (string)$body['creatorPubkey']), 0, 88),
            'createdAt'     => (int)$body['createdAt'],
            'expiresAt'     => (int)$body['expiresAt'],
            'boostExpiresAt'=> isset($body['boostExpiresAt']) ? (int)$body['boostExpiresAt'] : null,
        ];

        // Validasi waktu — expiresAt harus dalam 25 jam ke depan
        $now = nowMs();
        if ($drop['expiresAt'] < $now || $drop['expiresAt'] > $now + 25 * 3600000) {
            $drop['expiresAt'] = $now + DROP_LIFETIME_MS;
        }

        $drops = loadDrops();
        $drops = cleanExpired($drops);

        // Cegah duplikat ID
        foreach ($drops as $d) {
            if ($d['id'] === $drop['id']) respond(['ok' => true, 'msg' => 'already exists']);
        }

        $drops[] = $drop;
        saveDrops($drops);
        respond(['ok' => true, 'drop' => $drop]);
    }

    // ─────────────────────────────────────────────────────────────
    // BOOST — update status boost drop yang sudah ada
    // ─────────────────────────────────────────────────────────────
    case 'boost': {
        $body = getBody();
        if (empty($body['id']) || empty($body['boostExpiresAt'])) {
            respond(['ok' => false, 'error' => 'Missing id or boostExpiresAt'], 400);
        }

        $drops = loadDrops();
        $drops = cleanExpired($drops);
        $found = false;

        foreach ($drops as &$d) {
            if ($d['id'] === $body['id']) {
                $d['boosted']        = true;
                $d['boostExpiresAt'] = (int)$body['boostExpiresAt'];
                $found = true;
                break;
            }
        }
        unset($d);

        if (!$found) respond(['ok' => false, 'error' => 'Drop not found'], 404);
        saveDrops($drops);
        respond(['ok' => true]);
    }

    // ─────────────────────────────────────────────────────────────
    // EXPIRE — hapus/tandai drop expired
    // ─────────────────────────────────────────────────────────────
    case 'expire': {
        $body = getBody();
        if (empty($body['id'])) respond(['ok' => false, 'error' => 'Missing id'], 400);

        $drops = loadDrops();
        $drops = array_values(array_filter($drops, fn($d) => $d['id'] !== $body['id']));
        $drops = cleanExpired($drops);
        saveDrops($drops);
        respond(['ok' => true]);
    }

    // ─────────────────────────────────────────────────────────────
    // CLEANUP — hapus semua expired (bisa dipanggil via cron atau manual)
    // ─────────────────────────────────────────────────────────────
    case 'cleanup': {
        $drops   = loadDrops();
        $before  = count($drops);
        $drops   = cleanExpired($drops);
        $after   = count($drops);
        saveDrops($drops);
        respond(['ok' => true, 'removed' => $before - $after, 'remaining' => $after]);
    }

    default:
        respond(['ok' => false, 'error' => 'Unknown action'], 400);
}
