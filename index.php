<?php
/**
 * QUANTUM NEXUS — Moteur IA Quantique CoinGecko
 * PHP 8.3 — Hostinger Mutualisé — SQLite WAL — cURL only
 * Architecture : Single-file, AJAX steps, rotation 3 clés Mistral
 */

define('ROOT_PATH', dirname(__FILE__));
define('DB_PATH',   ROOT_PATH . '/data/nexus.db');
define('LOG_PATH',  ROOT_PATH . '/data/nexus.log');

define('MISTRAL_KEYS', [
    '5qaRTokbH8Rake',
    'o3rG1okHXRShytu',
    'vEzQokXkF',
]);
define('MISTRAL_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions');

define('COINGECKO_URL', 'https://api.coingecko.com/api/v3/coins/markets');

// Modèles Mistral par rôle
define('MODEL_ANALYSIS',    'mistral-medium-2508');   // Corporate Engine Pro
define('MODEL_AGENT_GEN',   'magistral-medium-2509'); // Agent Router Medium
define('MODEL_QUICK',       'mistral-small-2603');    // Fast Automate Turbo
define('MODEL_LARGE',       'mistral-large-2512');    // Mistral Brain Ultra

// ── Init dossier data ──
if (!is_dir(ROOT_PATH . '/data')) {
    mkdir(ROOT_PATH . '/data', 0755, true);
}

// ── Headers anti-JSON poison ──
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['action']) || !empty($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    ob_start();
    register_shutdown_function(function() {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR])) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'PHP fatal: ' . $err['message']]);
        }
    });
}

// ── Router ──
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'init_db':          echo json_encode(initDB());           break;
        case 'fetch_coingecko': echo json_encode(fetchCoinGecko());   break;
        case 'bulk_analyze':    echo json_encode(bulkAnalyze());      break;
        case 'generate_agents': echo json_encode(generateAgents());   break;
        case 'rl_step':         echo json_encode(rlStep());           break;
        case 'get_stats':       echo json_encode(getStats());         break;
        case 'get_coins':       echo json_encode(getCoins());         break;
        case 'get_agents':      echo json_encode(getAgents());        break;
        case 'get_analyses':    echo json_encode(getAnalyses());      break;
        case 'get_advice':      echo json_encode(getHumanAdvice());   break;
        case 'coin_detail':     echo json_encode(getCoinDetail());    break;
        default:
            // Serve HTML
            serveHTML();
    }
} catch (Throwable $e) {
    if ($action) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        serveHTML();
    }
}

// ════════════════════════════════════════════════════════════
// DATABASE
// ════════════════════════════════════════════════════════════

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA foreign_keys=ON;");
    }
    return $pdo;
}

function initDB(): array {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS coins (
            id              TEXT PRIMARY KEY,
            symbol          TEXT,
            name            TEXT,
            current_price   REAL,
            market_cap      REAL,
            market_cap_rank INTEGER,
            volume_24h      REAL,
            price_change_24h REAL,
            pct_change_24h  REAL,
            ath             REAL,
            ath_pct         REAL,
            sparkline       TEXT,
            raw_json        TEXT,
            fetched_at      INTEGER,
            updated_at      INTEGER
        );

        CREATE TABLE IF NOT EXISTS analyses (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_id     TEXT,
            agent_id    INTEGER,
            model       TEXT,
            score       REAL,
            signal      TEXT,
            summary     TEXT,
            rationale   TEXT,
            raw_output  TEXT,
            created_at  INTEGER
        );

        CREATE TABLE IF NOT EXISTS agents (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT,
            role        TEXT,
            specialty   TEXT,
            model       TEXT,
            system_prompt TEXT,
            score_history TEXT,
            generation  INTEGER DEFAULT 1,
            wins        INTEGER DEFAULT 0,
            losses      INTEGER DEFAULT 0,
            active      INTEGER DEFAULT 1,
            created_at  INTEGER
        );

        CREATE TABLE IF NOT EXISTS rl_state (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            episode     INTEGER,
            total_reward REAL,
            epsilon     REAL,
            best_score  REAL,
            market_regime TEXT,
            agent_mutations TEXT,
            created_at  INTEGER
        );

        CREATE TABLE IF NOT EXISTS advice_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            content     TEXT,
            context     TEXT,
            created_at  INTEGER
        );

        CREATE INDEX IF NOT EXISTS idx_analyses_coin ON analyses(coin_id);
        CREATE INDEX IF NOT EXISTS idx_analyses_created ON analyses(created_at DESC);
    ");

    // Seed agents par défaut si vide
    $cnt = $db->query("SELECT COUNT(*) FROM agents")->fetchColumn();
    if ($cnt == 0) {
        seedDefaultAgents($db);
    }

    return ['success' => true, 'message' => 'Base de données initialisée'];
}

function seedDefaultAgents(PDO $db): void {
    $agents = [
        ['ORACLE_MACRO',   'macro',     'Analyse macro-économique et cycles de marché',       MODEL_ANALYSIS],
        ['MOMENTUM_HAWK',  'momentum',  'Détection des tendances et signaux momentum',        MODEL_QUICK],
        ['VOLUME_SCANNER', 'volume',    'Analyse des volumes et liquidité on-chain',          MODEL_QUICK],
        ['RISK_SENTINEL',  'risk',      'Évaluation risque/récompense et drawdown',           MODEL_ANALYSIS],
        ['ALPHA_SEEKER',   'alpha',     'Recherche opportunités asymétriques à fort potentiel', MODEL_LARGE],
    ];
    $stmt = $db->prepare("INSERT INTO agents (name, role, specialty, model, system_prompt, score_history, created_at) VALUES (?,?,?,?,?,?,?)");
    foreach ($agents as $a) {
        $sys = buildAgentSystemPrompt($a[0], $a[1], $a[2]);
        $stmt->execute([$a[0], $a[1], $a[2], $a[3], $sys, '[]', time()]);
    }
}

function buildAgentSystemPrompt(string $name, string $role, string $specialty): string {
    return "Tu es l'agent quantique {$name}, spécialisé en {$specialty}. "
         . "Ton rôle : {$role}. "
         . "Tu analyses des cryptomonnaies avec une précision chirurgicale. "
         . "Réponds UNIQUEMENT en JSON valide : {\"score\":0-100, \"signal\":\"BUY|SELL|HOLD|WATCH\", \"summary\":\"...\", \"rationale\":\"...\"}. "
         . "score=0 à 100 (100=opportunité maximale). signal=BUY si score>70, SELL si score<30, HOLD sinon, WATCH si volatil. "
         . "summary : 1 phrase claire pour un humain. rationale : 2-3 facteurs clés. "
         . "Sois direct, sans jargon inutile, comme un trader senior qui parle à un ami.";
}

// ════════════════════════════════════════════════════════════
// COINGECKO FETCH
// ════════════════════════════════════════════════════════════

function fetchCoinGecko(): array {
    $page    = (int)($_POST['page'] ?? 1);
    $perPage = min((int)($_POST['per_page'] ?? 100), 250);

    $url = COINGECKO_URL . '?' . http_build_query([
        'vs_currency' => 'usd',
        'order'       => 'market_cap_desc',
        'per_page'    => $perPage,
        'page'        => $page,
        'sparkline'   => 'true',
        'price_change_percentage' => '24h,7d',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'QuantumNexus/2.0 CryptoAnalytics',
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $httpCode !== 200) {
        return ['success' => false, 'error' => "CoinGecko HTTP {$httpCode}"];
    }

    // Anti-poison : vérifier que c'est bien du JSON tableau
    $trimmed = ltrim($raw);
    if ($trimmed[0] !== '[') {
        return ['success' => false, 'error' => 'Réponse CoinGecko invalide (non-JSON)'];
    }

    $coins = json_decode($raw, true);
    if (!is_array($coins)) {
        return ['success' => false, 'error' => 'JSON CoinGecko malformé'];
    }

    $db   = getDB();
    $now  = time();
    $stmt = $db->prepare("
        INSERT OR REPLACE INTO coins
        (id, symbol, name, current_price, market_cap, market_cap_rank,
         volume_24h, price_change_24h, pct_change_24h, ath, ath_pct, sparkline, raw_json, fetched_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $saved = 0;
    foreach ($coins as $c) {
        if (empty($c['id'])) continue;
        $spark = json_encode($c['sparkline_in_7d']['price'] ?? []);
        $stmt->execute([
            $c['id'],
            strtoupper($c['symbol'] ?? ''),
            $c['name'] ?? '',
            (float)($c['current_price'] ?? 0),
            (float)($c['market_cap'] ?? 0),
            (int)($c['market_cap_rank'] ?? 9999),
            (float)($c['total_volume'] ?? 0),
            (float)($c['price_change_24h'] ?? 0),
            (float)($c['price_change_percentage_24h'] ?? 0),
            (float)($c['ath'] ?? 0),
            (float)($c['ath_change_percentage'] ?? 0),
            $spark,
            json_encode($c),
            $now,
            $now,
        ]);
        $saved++;
        unset($c);
    }

    return [
        'success' => true,
        'saved'   => $saved,
        'page'    => $page,
        'message' => "{$saved} cryptos sauvegardées (page {$page})",
    ];
}

// ════════════════════════════════════════════════════════════
// BULK ANALYZE (AJAX step-by-step)
// ════════════════════════════════════════════════════════════

function bulkAnalyze(): array {
    $offset  = (int)($_POST['offset'] ?? 0);
    $batchSz = (int)($_POST['batch_size'] ?? 5);
    $agentId = (int)($_POST['agent_id'] ?? 0);

    $db = getDB();

    // Récupérer les coins à analyser
    $query = "SELECT id, symbol, name, current_price, market_cap, volume_24h,
                     pct_change_24h, ath_pct, sparkline
              FROM coins
              ORDER BY market_cap_rank ASC
              LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$batchSz, $offset]);
    $coins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($coins)) {
        return ['success' => true, 'done' => true, 'message' => 'Analyse terminée'];
    }

    // Récupérer l'agent
    $agent = false;
    if ($agentId > 0) {
        $stmtAgent = $db->prepare("SELECT * FROM agents WHERE id=? AND active=1");
        $stmtAgent->execute([$agentId]);
        $agent = $stmtAgent->fetch(PDO::FETCH_ASSOC);
    }
    if (empty($agent)) {
        $agent = $db->query("SELECT * FROM agents WHERE active=1 ORDER BY RANDOM() LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    if (!$agent) {
        return ['success' => false, 'error' => 'Aucun agent actif trouvé'];
    }

    $results = [];
    $keyIdx  = getKeyIndex();

    foreach ($coins as $coin) {
        $spark  = json_decode($coin['sparkline'] ?? '[]', true);
        $prices = array_slice(is_array($spark) ? $spark : [], -24);
        $trend  = computeTrend($prices);

        $prompt = buildAnalysisPrompt($coin, $trend);
        $resp   = callMistral($agent['model'], $agent['system_prompt'], $prompt, $keyIdx);

        if ($resp['success']) {
            $parsed = parseAgentJSON($resp['content']);
            $score  = $parsed['score'] ?? 50;
            $signal = $parsed['signal'] ?? 'HOLD';

            // Sauvegarder l'analyse
            $db->prepare("
                INSERT INTO analyses (coin_id, agent_id, model, score, signal, summary, rationale, raw_output, created_at)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $coin['id'],
                $agent['id'],
                $agent['model'],
                $score,
                $signal,
                $parsed['summary'] ?? '',
                $parsed['rationale'] ?? '',
                $resp['content'],
                time(),
            ]);

            // Update agent score
            $history = json_decode($agent['score_history'] ?? '[]', true);
            $history[] = $score;
            if (count($history) > 100) $history = array_slice($history, -100);
            $db->prepare("UPDATE agents SET score_history=? WHERE id=?")->execute([json_encode($history), $agent['id']]);

            $results[] = [
                'coin_id' => $coin['id'],
                'symbol'  => $coin['symbol'],
                'name'    => $coin['name'],
                'score'   => $score,
                'signal'  => $signal,
                'summary' => $parsed['summary'] ?? '',
            ];
        }

        $keyIdx = ($keyIdx + 1) % count(MISTRAL_KEYS);
        unset($resp, $parsed);
    }

    $total = (int)$db->query("SELECT COUNT(*) FROM coins")->fetchColumn();

    return [
        'success'    => true,
        'done'       => false,
        'results'    => $results,
        'offset'     => $offset + $batchSz,
        'total'      => $total,
        'progress'   => min(100, round(($offset + $batchSz) / max(1, $total) * 100)),
        'agent_name' => $agent['name'],
    ];
}

function buildAnalysisPrompt(array $coin, array $trend): string {
    $athDist = $coin['ath_pct'] ? round($coin['ath_pct'], 1) : 'N/A';
    $vol_ratio = $coin['market_cap'] > 0 ? round($coin['volume_24h'] / $coin['market_cap'] * 100, 2) : 0;

    return "Analyse crypto : {$coin['name']} ({$coin['symbol']})
Prix actuel : \${$coin['current_price']}
Market Cap rank : #{$coin['market_cap_rank']} | MC : \${$coin['market_cap']}
Volume 24h : \${$coin['volume_24h']} (ratio vol/mc : {$vol_ratio}%)
Variation 24h : {$coin['pct_change_24h']}%
Distance ATH : {$athDist}%
Tendance 24h (prix horaires) : {$trend['direction']} | volatilité : {$trend['volatility']}% | momentum : {$trend['momentum']}

Donne ton analyse JSON maintenant.";
}

function computeTrend(array $prices): array {
    if (count($prices) < 2) {
        return ['direction' => 'neutre', 'volatility' => 0, 'momentum' => 'neutre'];
    }
    $first = $prices[0];
    $last  = end($prices);
    $pct   = $first > 0 ? (($last - $first) / $first) * 100 : 0;

    $returns = [];
    for ($i = 1; $i < count($prices); $i++) {
        if ($prices[$i-1] > 0) {
            $returns[] = ($prices[$i] - $prices[$i-1]) / $prices[$i-1] * 100;
        }
    }
    $vol = count($returns) > 0 ? round(sqrt(array_sum(array_map(fn($r) => $r*$r, $returns)) / count($returns)), 2) : 0;

    $dir = $pct > 1 ? 'haussière' : ($pct < -1 ? 'baissière' : 'neutre');
    $mom = $pct > 3 ? 'fort haussier' : ($pct > 0.5 ? 'léger haussier' : ($pct < -3 ? 'fort baissier' : ($pct < -0.5 ? 'léger baissier' : 'flat')));

    return ['direction' => $dir, 'volatility' => $vol, 'momentum' => $mom, 'pct' => round($pct, 2)];
}

// ════════════════════════════════════════════════════════════
// GENERATE AGENTS (auto-évolution IA)
// ════════════════════════════════════════════════════════════

function generateAgents(): array {
    $db = getDB();

    // Contexte marché actuel
    $topCoins = $db->query("
        SELECT symbol, name, pct_change_24h, market_cap_rank
        FROM coins ORDER BY market_cap_rank ASC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $marketCtx = implode(', ', array_map(fn($c) => "{$c['symbol']}({$c['pct_change_24h']}%)", $topCoins));

    $existingAgents = $db->query("SELECT name, role, specialty FROM agents WHERE active=1")->fetchAll(PDO::FETCH_ASSOC);
    $existingNames  = implode(', ', array_column($existingAgents, 'name'));

    $prompt = "Marché crypto actuel (top 10): {$marketCtx}

Agents existants: {$existingNames}

Crée 3 NOUVEAUX agents IA spécialisés pour analyser ce marché. Agents totalement différents des existants.
Réponds UNIQUEMENT en JSON valide :
{\"agents\":[
  {\"name\":\"NOM_UPPERCASE\",\"role\":\"role_court\",\"specialty\":\"description spécialité précise\",\"model\":\"mistral-small-2603\",\"persona\":\"prompt système en 2 phrases max\"},
  ...
]}
Modèles disponibles: mistral-small-2603, mistral-medium-2508, mistral-large-2512, magistral-medium-2509
Choisis le modèle selon la complexité du rôle.";

    $resp = callMistral(MODEL_AGENT_GEN,
        "Tu es un architecte d'agents IA quantiques pour l'analyse crypto. Tu crées des agents innovants avec des spécialités uniques. Réponds UNIQUEMENT en JSON valide, sans markdown.",
        $prompt, getKeyIndex());

    if (!$resp['success']) {
        return ['success' => false, 'error' => $resp['error']];
    }

    $data = parseAgentJSON($resp['content']);
    if (empty($data['agents'])) {
        return ['success' => false, 'error' => 'Format agents invalide: ' . substr($resp['content'], 0, 200)];
    }

    $stmt = $db->prepare("INSERT INTO agents (name, role, specialty, model, system_prompt, score_history, created_at) VALUES (?,?,?,?,?,?,?)");
    $created = [];

    foreach ($data['agents'] as $a) {
        if (empty($a['name'])) continue;
        $sys = !empty($a['persona'])
            ? $a['persona'] . " Réponds UNIQUEMENT en JSON: {\"score\":0-100,\"signal\":\"BUY|SELL|HOLD|WATCH\",\"summary\":\"...\",\"rationale\":\"...\"}"
            : buildAgentSystemPrompt($a['name'], $a['role'] ?? 'analyse', $a['specialty'] ?? '');
        $model = in_array($a['model'] ?? '', ['mistral-small-2603','mistral-medium-2508','mistral-large-2512','magistral-medium-2509']) ? $a['model'] : MODEL_QUICK;
        $stmt->execute([strtoupper($a['name']), $a['role'] ?? 'analyse', $a['specialty'] ?? '', $model, $sys, '[]', time()]);
        $created[] = ['name' => strtoupper($a['name']), 'role' => $a['role'] ?? '', 'specialty' => $a['specialty'] ?? ''];
    }

    return ['success' => true, 'agents' => $created, 'message' => count($created) . ' nouveaux agents créés'];
}

// ════════════════════════════════════════════════════════════
// RL STEP (Renforcement)
// ════════════════════════════════════════════════════════════

function rlStep(): array {
    $db = getDB();

    // Récupérer dernier état RL
    $last = $db->query("SELECT * FROM rl_state ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $episode = ($last['episode'] ?? 0) + 1;
    $epsilon = max(0.05, ($last['epsilon'] ?? 1.0) * 0.95); // décroissance epsilon-greedy

    // Évaluer performances des agents
    $agentPerf = $db->query("
        SELECT a.id, a.name, a.role,
               AVG(an.score) as avg_score,
               COUNT(an.id) as total_analyses,
               SUM(CASE WHEN an.signal='BUY' THEN 1 ELSE 0 END) as buys,
               SUM(CASE WHEN an.signal='SELL' THEN 1 ELSE 0 END) as sells
        FROM agents a
        LEFT JOIN analyses an ON a.id = an.agent_id
        WHERE a.active=1
        GROUP BY a.id
        ORDER BY avg_score DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Régime de marché
    $marketData = $db->query("
        SELECT AVG(pct_change_24h) as avg_change,
               COUNT(CASE WHEN pct_change_24h > 0 THEN 1 END) as positives,
               COUNT(*) as total
        FROM coins
    ")->fetch(PDO::FETCH_ASSOC);

    $avgChange = (float)($marketData['avg_change'] ?? 0);
    $bullRatio = $marketData['total'] > 0 ? $marketData['positives'] / $marketData['total'] : 0.5;
    $regime    = $avgChange > 2 ? 'BULL_STRONG' : ($avgChange > 0.5 ? 'BULL_MILD' : ($avgChange < -2 ? 'BEAR_STRONG' : ($avgChange < -0.5 ? 'BEAR_MILD' : 'SIDEWAYS')));

    // Récompenses et mutations
    $mutations  = [];
    $totalReward = 0;
    $bestScore   = 0;

    foreach ($agentPerf as $agent) {
        $score = (float)($agent['avg_score'] ?? 0);
        $totalReward += $score;
        if ($score > $bestScore) $bestScore = $score;

        // Epsilon-greedy : parfois muter un agent sous-performant
        if ($score < 40 && $agent['total_analyses'] > 3 && (mt_rand(0, 100) / 100) < $epsilon) {
            // Muter le modèle
            $models = ['mistral-small-2603', 'mistral-medium-2508', MODEL_ANALYSIS];
            $newModel = $models[array_rand($models)];
            $db->prepare("UPDATE agents SET model=?, generation=generation+1 WHERE id=?")->execute([$newModel, $agent['id']]);
            $mutations[] = "Agent {$agent['name']} muté → {$newModel}";
        }

        // Bonus aux top agents
        if ($score > 75) {
            $db->prepare("UPDATE agents SET wins=wins+1 WHERE id=?")->execute([$agent['id']]);
        }
    }

    // Appel Mistral pour conseil humain
    $topAgents = array_slice($agentPerf, 0, 3);
    $topStr    = implode(', ', array_map(fn($a) => "{$a['name']}(score: " . round($a['avg_score'] ?? 0) . ")", $topAgents));
    $mutatStr  = empty($mutations) ? 'aucune' : implode('; ', $mutations);

    $bullPct      = round($bullRatio * 100, 1);
    $advicePrompt = "Episode RL #{$episode} — Régime marché: {$regime} (variation moy: {$avgChange}%, {$bullPct}% de hausses)
Meilleurs agents: {$topStr}
Mutations: {$mutatStr}
Epsilon: " . round($epsilon, 3) . "

En 3 phrases max, langage simple et humain (pas de jargon technique), explique :
1. Ce que le marché fait en ce moment
2. Ce que tu recommandes de surveiller
3. Si c'est le bon moment pour agir ou attendre";

    $adviceResp = callMistral(MODEL_QUICK,
        "Tu es un conseiller financier bienveillant qui explique simplement la situation du marché crypto. Tu parles comme à un ami, sans jargon, en français.",
        $advicePrompt, getKeyIndex());

    $advice = $adviceResp['success'] ? $adviceResp['content'] : 'Analyse en cours...';

    // Sauvegarder état RL
    $db->prepare("
        INSERT INTO rl_state (episode, total_reward, epsilon, best_score, market_regime, agent_mutations, created_at)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([
        $episode,
        round($totalReward / max(1, count($agentPerf)), 2),
        round($epsilon, 4),
        round($bestScore, 2),
        $regime,
        json_encode($mutations),
        time(),
    ]);

    // Sauvegarder conseil
    $db->prepare("INSERT INTO advice_log (content, context, created_at) VALUES (?,?,?)")
       ->execute([$advice, json_encode(['regime' => $regime, 'episode' => $episode]), time()]);

    return [
        'success'      => true,
        'episode'      => $episode,
        'regime'       => $regime,
        'epsilon'      => round($epsilon, 3),
        'best_score'   => round($bestScore, 2),
        'total_reward' => round($totalReward / max(1, count($agentPerf)), 2),
        'mutations'    => $mutations,
        'advice'       => $advice,
        'agent_count'  => count($agentPerf),
    ];
}

// ════════════════════════════════════════════════════════════
// QUERIES
// ════════════════════════════════════════════════════════════

function getStats(): array {
    $db = getDB();
    $coins   = (int)$db->query("SELECT COUNT(*) FROM coins")->fetchColumn();
    $agents  = (int)$db->query("SELECT COUNT(*) FROM agents WHERE active=1")->fetchColumn();
    $analyses = (int)$db->query("SELECT COUNT(*) FROM analyses")->fetchColumn();
    $rl      = $db->query("SELECT * FROM rl_state ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $advice  = $db->query("SELECT content FROM advice_log ORDER BY id DESC LIMIT 1")->fetchColumn();

    $topSignals = $db->query("
        SELECT signal, COUNT(*) as cnt
        FROM analyses
        WHERE created_at > " . (time() - 3600) . "
        GROUP BY signal ORDER BY cnt DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $avgScore = (float)$db->query("SELECT AVG(score) FROM analyses WHERE created_at > " . (time() - 3600))->fetchColumn();

    return [
        'success'     => true,
        'coins'       => $coins,
        'agents'      => $agents,
        'analyses'    => $analyses,
        'episode'     => $rl['episode'] ?? 0,
        'epsilon'     => $rl['epsilon'] ?? 1.0,
        'regime'      => $rl['market_regime'] ?? 'UNKNOWN',
        'best_score'  => $rl['best_score'] ?? 0,
        'avg_score'   => round($avgScore, 1),
        'advice'      => $advice ?: '⟳ Lance une analyse pour obtenir des conseils.',
        'top_signals' => $topSignals,
    ];
}

function getCoins(): array {
    $db      = getDB();
    $limit   = min((int)($_POST['limit'] ?? $_GET['limit'] ?? 50), 200);
    $offset  = (int)($_POST['offset'] ?? $_GET['offset'] ?? 0);
    $filter  = $_POST['filter'] ?? $_GET['filter'] ?? '';
    $sort    = in_array($_POST['sort'] ?? '', ['market_cap_rank','pct_change_24h','volume_24h']) ? ($_POST['sort'] ?? 'market_cap_rank') : 'market_cap_rank';
    $order   = ($_POST['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

    $where = '';
    $params = [];
    if ($filter) {
        $where = "WHERE (UPPER(symbol) LIKE ? OR UPPER(name) LIKE ?)";
        $params = ["%{$filter}%", "%" . strtoupper($filter) . "%"];
    }

    $stmt = $db->prepare("
        SELECT c.id, c.symbol, c.name, c.current_price, c.market_cap_rank,
               c.pct_change_24h, c.volume_24h, c.ath_pct, c.sparkline,
               (SELECT AVG(score) FROM analyses WHERE coin_id=c.id ORDER BY created_at DESC LIMIT 5) as avg_score,
               (SELECT signal FROM analyses WHERE coin_id=c.id ORDER BY created_at DESC LIMIT 1) as last_signal
        FROM coins c {$where}
        ORDER BY {$sort} {$order}
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $coins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countParams = $filter ? ["%{$filter}%", "%" . strtoupper($filter) . "%"] : [];
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM coins {$where}");
    $stmtCount->execute($countParams);
    $total = (int)$stmtCount->fetchColumn();

    return ['success' => true, 'coins' => $coins, 'total' => $total];
}

function getAgents(): array {
    $db = getDB();
    $agents = $db->query("
        SELECT a.*,
               COUNT(an.id) as total_analyses,
               AVG(an.score) as avg_score,
               MAX(an.created_at) as last_active
        FROM agents a
        LEFT JOIN analyses an ON a.id = an.agent_id
        WHERE a.active=1
        GROUP BY a.id
        ORDER BY avg_score DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    return ['success' => true, 'agents' => $agents];
}

function getAnalyses(): array {
    $db     = getDB();
    $limit  = min((int)($_POST['limit'] ?? 20), 100);
    $coinId = $_POST['coin_id'] ?? '';

    $where  = $coinId ? "WHERE an.coin_id = '{$coinId}'" : '';
    $analyses = $db->query("
        SELECT an.*, a.name as agent_name, c.symbol, c.name as coin_name
        FROM analyses an
        LEFT JOIN agents a ON a.id = an.agent_id
        LEFT JOIN coins c ON c.id = an.coin_id
        {$where}
        ORDER BY an.created_at DESC
        LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_ASSOC);
    return ['success' => true, 'analyses' => $analyses];
}

function getHumanAdvice(): array {
    $db = getDB();

    $topBuy  = $db->query("
        SELECT c.symbol, c.name, an.score, an.summary
        FROM analyses an JOIN coins c ON c.id=an.coin_id
        WHERE an.signal='BUY'
        ORDER BY an.score DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $topSell = $db->query("
        SELECT c.symbol, c.name, an.score, an.summary
        FROM analyses an JOIN coins c ON c.id=an.coin_id
        WHERE an.signal='SELL'
        ORDER BY an.score ASC LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($topBuy) && empty($topSell)) {
        return ['success' => true, 'advice' => 'Lance une analyse pour obtenir des recommandations personnalisées.'];
    }

    $buyStr  = implode('; ', array_map(fn($c) => "{$c['symbol']}(score:{$c['score']}) - {$c['summary']}", $topBuy));
    $sellStr = implode('; ', array_map(fn($c) => "{$c['symbol']}({$c['summary']})", $topSell));

    $prompt = "Top opportunités d'achat détectées: {$buyStr}
Cryptos à surveiller à la baisse: {$sellStr}

Donne un conseil personnalisé en 4-5 phrases max, en français simple, comme un ami trader expérimenté.
Parle des meilleures opportunités concrètes. Inclus un avertissement sur le risque. Sois direct et utile.";

    $resp = callMistral(MODEL_QUICK,
        "Tu es un conseiller financier bienveillant et direct. Tu parles en français simple, sans jargon, comme à un ami. Tu mentionnes toujours le risque.",
        $prompt, getKeyIndex());

    $advice = $resp['success'] ? $resp['content'] : 'Analyse en cours...';
    $db->prepare("INSERT INTO advice_log (content, context, created_at) VALUES (?,?,?)")
       ->execute([$advice, 'human_request', time()]);

    return ['success' => true, 'advice' => $advice];
}

function getCoinDetail(): array {
    $db = getDB();
    $coinId = $_POST['coin_id'] ?? $_GET['coin_id'] ?? '';
    if (!$coinId) {
        return ['success' => false, 'error' => 'coin_id manquant'];
    }

    // Récupération du coin
    $stmt = $db->prepare("SELECT * FROM coins WHERE id = ?");
    $stmt->execute([$coinId]);
    $coin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$coin) {
        return ['success' => false, 'error' => 'Coin non trouvé'];
    }

    // Récupération des analyses
    $stmtAnalyses = $db->prepare("
        SELECT an.*, ag.name as agent_name
        FROM analyses an
        LEFT JOIN agents ag ON ag.id = an.agent_id
        WHERE an.coin_id = ?
        ORDER BY an.created_at DESC
        LIMIT 10
    ");
    $stmtAnalyses->execute([$coinId]);
    $coin['analyses'] = $stmtAnalyses->fetchAll(PDO::FETCH_ASSOC);
    $coin['sparkline'] = json_decode($coin['sparkline'] ?? '[]', true);

    return ['success' => true, 'coin' => $coin];
}
// ════════════════════════════════════════════════════════════
// MISTRAL API
// ════════════════════════════════════════════════════════════

function getKeyIndex(): int {
    static $idx = 0;
    $result = $idx;
    $idx = ($idx + 1) % count(MISTRAL_KEYS);
    return $result;
}

function callMistral(string $model, string $system, string $user, int $keyIdx = 0): array {
    $keys = MISTRAL_KEYS;
    $key  = $keys[$keyIdx % count($keys)];

    $payload = json_encode([
        'model'       => $model,
        'max_tokens'  => 600,
        'temperature' => 0.3,
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
    ]);

    $ch = curl_init(MISTRAL_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_USERAGENT      => 'QuantumNexus/2.0',
        CURLOPT_TIMEOUT        => 28,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
    ]);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw) return ['success' => false, 'error' => 'cURL échoué'];
    if ($httpCode === 429) {
        // Rate limit : essayer la clé suivante
        $nextKey = $keys[($keyIdx + 1) % count($keys)];
        $ch2 = curl_init(MISTRAL_ENDPOINT);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_USERAGENT      => 'QuantumNexus/2.0',
            CURLOPT_TIMEOUT        => 28,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $nextKey,
            ],
        ]);
        $raw      = curl_exec($ch2);
        $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
    }

    if ($httpCode !== 200) return ['success' => false, 'error' => "HTTP {$httpCode}"];

    // Anti-poison JSON
    $trimmed = ltrim($raw);
    if (empty($trimmed) || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
        return ['success' => false, 'error' => 'Réponse non-JSON Mistral'];
    }

    $data = json_decode($raw, true);
    if (!$data) return ['success' => false, 'error' => 'JSON Mistral invalide'];

    $content = $data['choices'][0]['message']['content'] ?? '';
    if (!$content) return ['success' => false, 'error' => 'Contenu vide Mistral'];

    return ['success' => true, 'content' => $content];
}

function parseAgentJSON(string $content): array {
    // Nettoyage markdown
    $content = preg_replace('/```json\s*/i', '', $content);
    $content = preg_replace('/```\s*/i', '', $content);
    $content = trim($content);

    // Tentative directe
    $data = json_decode($content, true);
    if ($data !== null) return $data;

    // Extraction JSON par regex
    if (preg_match('/\{[\s\S]*\}/U', $content, $m)) {
        $data = json_decode($m[0], true);
        if ($data !== null) return $data;
    }

    // Fallback : extraire valeurs
    $score  = 50;
    $signal = 'HOLD';
    $summary = $content;
    if (preg_match('/"score"\s*:\s*(\d+)/', $content, $m)) $score = (int)$m[1];
    if (preg_match('/"signal"\s*:\s*"(\w+)"/', $content, $m)) $signal = $m[1];
    if (preg_match('/"summary"\s*:\s*"([^"]+)"/', $content, $m)) $summary = $m[1];

    return ['score' => $score, 'signal' => $signal, 'summary' => $summary, 'rationale' => ''];
}

// ════════════════════════════════════════════════════════════
// HTML SERVE
// ════════════════════════════════════════════════════════════

function serveHTML(): void {
    header('Content-Type: text/html; charset=utf-8');
    $htmlFile = ROOT_PATH . '/interface.html';
    if (file_exists($htmlFile)) {
        readfile($htmlFile);
    } else {
        echo '<!DOCTYPE html><html><body>interface.html manquant</body></html>';
    }
}
