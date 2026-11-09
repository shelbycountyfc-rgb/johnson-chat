<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$roomId = strtolower(trim($input['room_id'] ?? ($_GET['room_id'] ?? '')));
$username = strtolower(trim($input['username'] ?? ($_GET['username'] ?? '')));
$token = trim($input['token'] ?? ($_GET['token'] ?? ''));

if ($roomId === '' || $username === '' || $token === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing fields']);
    exit;
}

$database = Database::getInstance();
$tokenRow = app_session_check($database, $token);
if (!$tokenRow) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    $database->close();
    exit;
}
if (strtolower((string) $tokenRow['username']) !== $username) {
    http_response_code(403);
    echo json_encode(['error' => 'Token mismatch']);
    $database->close();
    exit;
}

// Only allow players who are members of this private room.
$parts = explode('_', $roomId);
if (count($parts) !== 2 || !in_array($username, array_map('strtolower', $parts), true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not a room member']);
    $database->close();
    exit;
}
$database->close();

$gameDir = __DIR__ . '/game_data';
if (!is_dir($gameDir)) { @mkdir($gameDir, 0777, true); }
$gameFile = $gameDir . '/' . preg_replace('/[^a-z0-9_]/', '', $roomId) . '.json';

// Load state (or boot a fresh one the first time or on a version bump)
$state = file_exists($gameFile) ? (json_decode(file_get_contents($gameFile), true) ?? null) : null;
if (!$state || ($state['version'] ?? '') !== GAME_VERSION) {
    $state = boot_game($roomId);
}

$action = strtolower(trim($input['action'] ?? ($_GET['action'] ?? '')));
$result = handle_action($state, $action, $username, $input);

// Save unless action was read-only
if (!in_array($action, ['status', 'help', 'look'], true)) {
    file_put_contents($gameFile, json_encode($state));
}

http_response_code($result['status'] ?? 200);
echo json_encode([
    'narrative' => $result['narrative'] ?? [],
    'state' => public_state($state, $username),
    'error' => $result['error'] ?? null,
]);

function GAME_VERSION(): string { return '1.0.0'; }

function boot_game(string $roomId): array
{
    $p = explode('_', $roomId);
    return [
        'version' => GAME_VERSION(),
        'room_id' => $roomId,
        'players' => [],
        'enemy' => null,
        'last_active' => time(),
        'seed' => crc32($roomId) + time(),
        'log' => [],
        'adventure' => null,
        'won' => false,
    ];
}

function roll(int $sides, int $count = 1, int $mod = 0): array
{
    $results = [];
    for ($i = 0; $i < $count; $i++) { $results[] = random_int(1, $sides); }
    return ['rolls' => $results, 'total' => array_sum($results) + $mod, 'mod' => $mod];
}

function log_append(array &$state, string $text): void
{
    $state['log'][] = $text;
    if (count($state['log']) > 80) { $state['log'] = array_slice($state['log'], -80); }
    $state['last_active'] = time();
}

function public_state(array $state, string $username): array
{
    $players = [];
    foreach ($state['players'] as $p) {
        $players[] = [
            'username' => $p['username'],
            'name' => $p['name'],
            'class' => $p['class'],
            'level' => $p['level'],
            'hp' => $p['hp'],
            'max_hp' => $p['max_hp'],
            'ac' => $p['ac'],
            'xp' => $p['xp'],
            'is_you' => $p['username'] === $username,
            'turn' => $p['turn'],
        ];
    }
    return [
        'phase' => $state['adventure'] ? ($state['enemy'] ? 'combat' : ($state['won'] ? 'won' : 'exploring')) : 'lobby',
        'players' => $players,
        'enemy' => $state['enemy'],
        'adventure' => $state['adventure'],
        'log' => $state['log'],
        'won' => (bool) ($state['won'] ?? false),
    ];
}

function handle_action(array &$state, string $action, string $username, array &$input): array
{
    $me = null;
    foreach ($state['players'] as $i => $p) {
        if ($p['username'] === $username) { $me = &$state['players'][$i]; break; }
    }

    return match ($action) {
        'status' => status_action($state, $username, $me),
        'help' => help_action($state, $username, $me),
        'create' => create_action($state, $username, $me, $input),
        'start' => start_action($state, $username, $me),
        'look' => look_action($state, $username, $me),
        'move' => move_action($state, $username, $me, $input),
        'fight' => fight_action($state, $username, $me, $input),
        'cast' => cast_action($state, $username, $me, $input),
        'use' => use_action($state, $username, $me, $input),
        'rest' => rest_action($state, $username, $me),
        'search' => search_action($state, $username, $me),
        'quick' => quick_action($state, $username, $me),
        default => error_action($action),
    };
}

function error_action(string $action): array
{
    return ['status' => 400, 'narrative' => [], 'error' => "Unknown action '$action'. Type a command like: create, start, look, move, fight"];
}

function require_adventure(array &$state, $me): ?string
{
    if ($me === null) return 'Create a character first with action=create (name via name=, class via class=).';
    if (!$state['adventure']) return 'Adventurers must be ready. Use start when all players have characters.';
    return null;
}

function create_action(array &$state, string $username, &$me, array &$input): array
{
    if ($state['adventure']) {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => 'The adventure has begun. You cannot create another character now.']], 'error' => null];
    }
    $players = $state['players'];
    if (count($players) >= 2) {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => 'Two adventurers already stand ready. There is room for no more.']], 'error' => null];
    }
    $name = trim((string) ($input['name'] ?? ''));
    $class = strtolower(trim((string) ($input['class'] ?? '')));
    if ($name === '') { $name = $username; }
    $name = substr(preg_replace('/[^a-zA-Z0-9 _-]/', '', $name), 0, 20);

    $classes = [
        'fighter' => ['name' => 'Fighter', 'hp' => 12, 'ac' => 16, 'power' => 5, 'power_name' => 'Strength', 'desc' => 'A brave warrior. Battle-hardened and tough.', 'spells' => []],
        'mage' => ['name' => 'Mage', 'hp' => 8, 'ac' => 12, 'power' => 4, 'power_name' => 'Intellect', 'desc' => 'A wielder of arcane secrets and fire.', 'spells' => ['fireball', 'heal']],
        'rogue' => ['name' => 'Rogue', 'hp' => 10, 'ac' => 14, 'power' => 4, 'power_name' => 'Cunning', 'desc' => 'A shadowy trickster, quick with a blade.', 'spells' => []],
        'cleric' => ['name' => 'Cleric', 'hp' => 10, 'ac' => 15, 'power' => 3, 'power_name' => 'Faith', 'desc' => 'A healer of the sick, a bane of the undead.', 'spells' => ['heal', 'smite']],
    ];
    if (!isset($classes[$class])) {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => 'Classes: fighter, mage, rogue, cleric. Pick one with class=fighter (for example).']], 'error' => null];
    }
    $c = $classes[$class];
    $statRoll = roll(6, 3);
    $hpBonus = (int) floor($statRoll['total'] / 3);
    $maxHp = $c['hp'] + $hpBonus;
    $player = [
        'username' => $username,
        'name' => $name,
        'class' => $class,
        'level' => 1,
        'hp' => $maxHp,
        'max_hp' => $maxHp,
        'ac' => $c['ac'],
        'power' => $c['power'],
        'power_name' => $c['power_name'],
        'xp' => 0,
        'gold' => 0,
        'items' => ['health potion' => 2],
        'spells' => $c['spells'],
        'turn' => false,
    ];
    $state['players'][] = $player;
    log_append($state, "{$player['name']} the {$c['name']} joins the party!");
    $narr = [
        ['by' => 'system', 'text' => "{$player['name']} (you) has been created as a {$c['name']}."],
        ['by' => 'system', 'text' => "HP {$maxHp} · AC {$c['ac']} · {$c['power_name']} {$c['power']}"],
    ];
    return ['status' => 200, 'narrative' => $narr, 'error' => null];
}

function start_action(array &$state, string $username, &$me): array
{
    if ($me === null) { return create_action($state, $username, $me, []); }
    if (count($state['players']) < 2) {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => 'You need two players. Tell your partner to create a character.']], 'error' => null];
    }
    if ($state['won']) {
        $state['won'] = false;
        $state['adventure'] = dungeon_gen(100 + strlen($state['room_id']) + time() % 1000);
        $state['enemy'] = null;
        log_append($state, 'A new Dungeon of the Iron Crown is raised!');
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => 'A fresh Iron Crown dungeon rises before you. Use look to survey the entrance.']], 'error' => null];
    }
    if ($state['adventure']) {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => 'The adventure is already underway.']], 'error' => null];
    }
    $state['adventure'] = dungeon_gen(100 + strlen($state['room_id']) + $state['seed'] % 1000);
    $state['enemy'] = null;
    $state['current_node'] = $state['adventure']['start'];
    log_append($state, '⚔ The Dungeon of the Iron Crown awakens. Heroes enter!');
    $narr = [
        ['by' => 'system', 'text' => '⚔ The Dungeon of the Iron Crown awakens. You stand at the entrance.'],
        ['by' => 'system', 'text' => 'Type move (say move with a direction of n/s/e/w), search, fight (when enemies appear), cast, use, rest, or look.'],
    ];
    return ['status' => 200, 'narrative' => $narr, 'error' => null];
}

function status_action(array &$state, string $username, $me): array
{
    $narr = [];
    if (empty($state['players'])) {
        $narr[] = ['by' => 'system', 'text' => 'No adventurers yet. Type a command like: create name=SirBob class=fighter'];
    } else {
        foreach ($state['players'] as $p) {
            $i = $p['username'] === $username ? ' (you)' : '';
            $inv = empty($p['items']) ? 'nothing' : implode(', ', array_map(fn($k, $v) => "$k x$v", array_keys($p['items']), array_values($p['items'])));
            $narr[] = ['by' => 'system', 'text' => "{$p['name']} the {$p['class']}{$i} · Lv{$p['level']} · HP {$p['hp']}/{$p['max_hp']} · AC {$p['ac']} · XP {$p['xp']} · Gold {$p['gold']} · Items: {$inv}"];
        }
    }
    return ['status' => 200, 'narrative' => $narr, 'error' => null];
}

function help_action(array &$state, string $username, $me): array
{
    $cmds = [
        'status – view all characters',
        'create name=NAME class=CLASS – make a character (fighter/mage/rogue/cleric)',
        'start – begin the adventure (2 players)',
        'look – examine the current room',
        'move dir=n|s|e|w – move through the dungeon',
        'search – hunt for treasure or secrets in the room',
        'fight – attack the enemy when in combat',
        'cast spell=fireball|heal|smite – use a spell',
        'use item=health potion – drink to restore HP',
        'rest – regain some HP (enemies may find you)',
        'quick – skip a turn',
    ];
    $narr = array_map(fn($c) => ['by' => 'system', 'text' => $c], $cmds);
    return ['status' => 200, 'narrative' => $narr, 'error' => null];
}

// ==== DUNGEON GENERATION ====
function dungeon_gen(int $seed): array
{
    mt_srand($seed);
    $nodeCount = 8;
    // Snake path across a small grid so each room has real compass neighbors.
    $path = [];
    $x = $y = 0;
    $dx = 1;
    $dy = 0;
    for ($i = 0; $i < $nodeCount; $i++) {
        $path[] = ['x' => $x, 'y' => $y];
        $nx = $x + $dx;
        if ($nx > 2) { $dx = 0; $dy = 1; }
        if ($dy === 1 && $y >= 1) { $dy = 0; $dx = -1; }
        if ($dx === -1 && $x <= 0) { $dx = 1; $dy = 0; }
        $x += $dx;
        $y += $dy;
        if ($y > 2) break;
    }

    $nodes = [];
    foreach ($path as $i => $pos) {
        $nodes[] = [
            'x' => $pos['x'],
            'y' => $pos['y'],
            'monsters' => [],
            'treasure' => [],
            'searched' => false,
            'name' => 'Chamber ' . ($i + 1),
        ];
    }
    $names = ['Giants Bounty Hall', 'The Weeping Crypt', 'Smoke-Filled Barracks', 'The Glass Treasury', 'Old Kings Concourse', 'The Singing Vault', 'Hall of Quiet Echoes', 'The Iron Basement'];
    shuffle($names);
    foreach ($nodes as $i => &$n) { $n['name'] = $names[$i % count($names)]; }
    unset($n);

    // Sprinkle monsters and treasure (skip entrance and boss room)
    $monsterPool = [
        ['name' => 'Goblin', 'hp' => 8, 'ac' => 12, 'dmg' => [2, 3], 'xp' => 5, 'gold' => [2, 8]],
        ['name' => 'Skeleton Warrior', 'hp' => 12, 'ac' => 13, 'dmg' => [3, 5], 'xp' => 8, 'gold' => [4, 12]],
        ['name' => 'Dire Rat', 'hp' => 6, 'ac' => 10, 'dmg' => [1, 3], 'xp' => 3, 'gold' => [1, 5]],
        ['name' => 'Orc Brute', 'hp' => 18, 'ac' => 14, 'dmg' => [4, 7], 'xp' => 14, 'gold' => [8, 20]],
        ['name' => 'Shadow Stalker', 'hp' => 10, 'ac' => 15, 'dmg' => [2, 4], 'xp' => 9, 'gold' => [3, 10]],
    ];
    $last = count($nodes) - 1;
    foreach ($nodes as $i => &$n) {
        if ($i === 0 || $i === $last) { continue; }
        if (mt_rand(0, 100) < 60) {
            $n['monsters'][] = $monsterPool[array_rand($monsterPool)];
        }
        if (mt_rand(0, 100) < 40) {
            $n['treasure'][] = mt_rand(1, 4) . ' gold';
        }
    }
    unset($n);

    // Boss on final node
    $last = count($nodes) - 1;
    $nodes[$last]['monsters'][] = [
        'name' => 'Iron Crown Guardian',
        'hp' => 32,
        'ac' => 15,
        'dmg' => [5, 9],
        'xp' => 30,
        'gold' => [20, 40],
    ];

    return [
        'nodes' => $nodes,
        'start' => 0,
        'current' => 0,
        'last' => $last,
    ];
}

function node_exits(array $node, array $nodes): array
{
    $exits = [];
    foreach (['n' => [0, -1], 's' => [0, 1], 'e' => [1, 0], 'w' => [-1, 0]] as $dir => [$dx, $dy]) {
        foreach ($nodes as $n) {
            if ($n['x'] === $node['x'] + $dx && $n['y'] === $node['y'] + $dy) {
                $exits[$dir] = true;
                break;
            }
        }
    }
    return $exits;
}

function current_node(array &$state): ?array
{
    $a = $state['adventure'];
    if (!$a) return null;
    return $a['nodes'][$a['current']] ?? null;
}

function enemy_spawn(array &$state): void
{
    $node = current_node($state);
    if (!$node || empty($node['monsters'])) return;
    $state['enemy'] = $node['monsters'][0];
    $state['enemy']['start_hp'] = $node['monsters'][0]['hp'];
    $state['enemy']['target'] = null;
}

function look_action(array &$state, string $username, $me): array
{
    $err = require_adventure($state, $me);
    if ($err) return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => $err]], 'error' => null];
    $node = current_node($state);
    $exits = node_exits($node, $state['adventure']['nodes']);
    $narr = [
        ['by' => 'system', 'text' => "📍 {$node['name']}"],
        ['by' => 'system', 'text' => 'Exits: ' . (empty($exits) ? 'none' : implode(', ', array_map('strtoupper', array_keys($exits))))],
    ];
    if (!empty($node['monsters'])) {
        $host = implode(', ', array_map(fn($m) => $m['name'] . " (HP{$m['hp']})", $node['monsters']));
        $narr[] = ['by' => 'system', 'text' => "🐉 Creatures lurk here: {$host}"];
    }
    if (!empty($node['treasure'])) {
        $narr[] = ['by' => 'system', 'text' => '💎 Something glints: ' . implode(', ', $node['treasure']) . ' (use search to claim)'];
    }
    if ($state['enemy']) {
        $narr[] = ['by' => 'system', 'text' => "🗡 A {$state['enemy']['name']} (HP {$state['enemy']['hp']}/{$state['enemy']['start_hp']}) attacks! Type: fight"];
    }
    return ['status' => 200, 'narrative' => $narr, 'error' => null];
}

function move_action(array &$state, string $username, $me, array &$input): array
{
    $err = require_adventure($state, $me);
    if ($err) return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => $err]], 'error' => null];
    if ($state['enemy']) {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => "A {$state['enemy']['name']} blocks your path! Defeat it first (fight, cast, or rest)."]], 'error' => null];
    }
    $dir = strtolower(trim((string) ($input['dir'] ?? '')));
    if (!in_array($dir, ['n', 's', 'e', 'w'], true)) {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => 'Pick a direction: move dir=n, move dir=s, move dir=e, or move dir=w.']], 'error' => null];
    }
    $node = current_node($state);
    $exits = node_exits($node, $state['adventure']['nodes']);
    if (empty($exits[$dir])) {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => "There is no passage to the {$dir}."]], 'error' => null];
    }
    $delta = ['n' => [0, -1], 's' => [0, 1], 'e' => [1, 0], 'w' => [-1, 0]][$dir];
    $newIndex = $state['adventure']['current'];
    foreach ($state['adventure']['nodes'] as $i => $n) {
        if ($n['x'] === $node['x'] + $delta[0] && $n['y'] === $node['y'] + $delta[1]) {
            $newIndex = $i;
            break;
        }
    }
    $state['adventure']['current'] = $newIndex;
    $narr = [
        ['by' => 'system', 'text' => "You travel {$dir}."],
        ['by' => 'system', 'text' => "📍 " . current_node_label($state)],
    ];
    // chance encounter
    if (mt_rand(0, 100) < 75 && !empty(current_node($state)['monsters'])) {
        enemy_spawn($state);
        $narr[] = ['by' => 'system', 'text' => "🐉 A {$state['enemy']['name']} (HP {$state['enemy']['hp']}) lunges at you! Type: fight"];
    }
    log_append($state, "{$me['name']} moves {$dir} to the " . current_node_label($state));
    return ['status' => 200, 'narrative' => $narr, 'error' => null];
}

function current_node_label(array &$state): string
{
    $node = current_node($state);
    return $node ? $node['name'] : '?';
}

function fight_action(array &$state, string $username, $me, array &$input): array
{
    $err = require_adventure($state, $me);
    if ($err) return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => $err]], 'error' => null];
    if (!$state['enemy']) {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => 'There is nothing to fight here. Use move to explore.']], 'error' => null];
    }
    return combat_turn($state, $me, 'attack');
}

function cast_action(array &$state, string $username, $me, array &$input): array
{
    $err = require_adventure($state, $me);
    if ($err) return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => $err]], 'error' => null];
    $spell = strtolower(trim((string) ($input['spell'] ?? '')));
    if (!in_array($spell, $me['spells'] ?? [], true)) {
        $known = empty($me['spells']) ? 'You know no spells' : 'You know: ' . implode(', ', $me['spells']);
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => "You cannot cast the spell '$spell'. {$known}."]], 'error' => null];
    }
    if (!$state['enemy'] && $spell !== 'heal') {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => 'There is no enemy to cast at. Use heal outside of combat.']], 'error' => null];
    }
    return combat_turn($state, $me, 'spell', ['spell' => $spell]);
}

function use_action(array &$state, string $username, $me, array &$input): array
{
    $err = require_adventure($state, $me);
    if ($err) return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => $err]], 'error' => null];
    $item = strtolower(trim((string) ($input['item'] ?? '')));
    if (!isset($me['items'][$item]) || $me['items'][$item] <= 0) {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => "You have no such item. Type status to see your items."]], 'error' => null];
    }
    if ($item !== 'health potion') {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => "You can't use that here."]], 'error' => null];
    }
    $heal = roll(4, 1)[ 'total' ] + 2;
    $me['hp'] = min($me['max_hp'], $me['hp'] + $heal);
    $me['items']['health potion'] -= 1;
    if ($me['items']['health potion'] <= 0) unset($me['items']['health potion']);
    log_append($state, "{$me['name']} drinks a health potion and recovers {$heal} HP.");
    if ($state['enemy']) {
        return combat_turn($state, $me, 'potion');
    }
    return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => "🍶 You recover {$heal} HP. HP now {$me['hp']}/{$me['max_hp']}."]], 'error' => null];
}

function rest_action(array &$state, string $username, $me): array
{
    $err = require_adventure($state, $me);
    if ($err) return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => $err]], 'error' => null];
    if ($state['enemy']) {
        return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => 'You cannot rest while a monster attacks!']], 'error' => null];
    }
    $gain = roll(4, 1)['total'] + 2;
    $me['hp'] = min($me['max_hp'], $me['hp'] + $gain);
    $narr = [['by' => 'system', 'text' => "😴 You rest and recover {$gain} HP. HP now {$me['hp']}/{$me['max_hp']}."]];
    if (mt_rand(0, 100) < 30 && !empty(current_node($state)['monsters'])) {
        enemy_spawn($state);
        $narr[] = ['by' => 'system', 'text' => "The rest is disturbed! A {$state['enemy']['name']} attacks! Type: fight"];
    }
    log_append($state, "{$me['name']} rests.");
    return ['status' => 200, 'narrative' => $narr, 'error' => null];
}

function search_action(array &$state, string $username, $me): array
{
    $err = require_adventure($state, $me);
    if ($err) return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => $err]], 'error' => null];
    $node = &$state['adventure']['nodes'][$state['adventure']['current']];
    $narr = [];
    if (empty($node['treasure'])) {
        $narr[] = ['by' => 'system', 'text' => 'You search the chamber but find nothing of value.'];
    } else {
        $found = [];
        foreach ($node['treasure'] as $t) {
            $found[] = $t;
            if (preg_match('/(\d+) gold/', $t, $m)) {
                $me['gold'] += (int) $m[1];
            }
        }
        $node['treasure'] = [];
        log_append($state, "{$me['name']} searches and finds: " . implode(', ', $found) . '.');
        $narr[] = ['by' => 'system', 'text' => '💎 You found: ' . implode(', ', $found) . '! Gold added to your pouch.'];
    }
    return ['status' => 200, 'narrative' => $narr, 'error' => null];
}

function quick_action(array &$state, string $username, $me): array
{
    $err = require_adventure($state, $me);
    if ($err) return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => $err]], 'error' => null];
    return ['status' => 200, 'narrative' => [['by' => 'system', 'text' => '⏭ You hold your position.']], 'error' => null];
}

// ==== COMBAT ====
function combat_turn(array &$state, array &$me, string $action, array $extra = []): array
{
    $enemy = &$state['enemy'];
    $narr = [];
    $hit = roll(20, 1);

    if ($action === 'attack' || $action === 'spell' || $action === 'potion') {
        $damageDealt = 0;
        $verb = 'attacks';
        if ($action === 'attack') {
            $atk = $me['power'] + max(0, (int) floor(($me['level'] - 1) / 2));
            $verb = "swings";
            if ($hit['total'] >= $enemy['ac']) {
                $damageDealt = roll(8, 1)['total'] + $atk;
                $narr[] = ['by' => $me['name'], 'text' => "You strike the {$enemy['name']}, dealing {$damageDealt} damage!"];
            } else {
                $narr[] = ['by' => $me['name'], 'text' => "Your blow misses the {$enemy['name']}!"];
            }
        } elseif ($action === 'spell') {
            if ($extra['spell'] === 'fireball') {
                $damageDealt = roll(10, 1)['total'] + $me['power'];
                $narr[] = ['by' => $me['name'], 'text' => "🔥 You hurl a roaring fireball, dealing {$damageDealt} damage to the {$enemy['name']}!"];
            } elseif ($extra['spell'] === 'smite') {
                $damageDealt = roll(8, 1)['total'] + 2;
                $narr[] = ['by' => $me['name'], 'text' => "✨ Divine light sears the {$enemy['name']} for {$damageDealt} damage!"];
            } else {
                $heal = roll(6, 1)['total'] + 3;
                $me['hp'] = min($me['max_hp'], $me['hp'] + $heal);
                $narr[] = ['by' => $me['name'], 'text' => "✨ You channel healing energy and recover {$heal} HP."];
            }
        } elseif ($action === 'potion') {
            $narr[] = ['by' => $me['name'], 'text' => "🥤 Potion chug succeeds. You feel stronger."];
        }

        if ($damageDealt > 0) {
            $enemy['hp'] -= $damageDealt;
            if ($enemy['hp'] <= 0) {
                $victory = enemy_defeat($state, $me);
                $narr[] = ['by' => 'system', 'text' => $victory];
                // Reward the whole party
                if (!$state['enemy']) {
                    $narr[] = ['by' => 'system', 'text' => 'The room is clear. Use search to probe for treasure, or move to continue.'];
                }
                return ['status' => 200, 'narrative' => $narr, 'error' => null];
            }
            $narr[] = ['by' => 'system', 'text' => "The {$enemy['name']} has {$enemy['hp']} HP left."];
        }

        // Enemy counterattack
        if ($state['enemy']) {
            $narr = array_merge($narr, enemy_attack($state, $me));
        }
        if (hp_check_party_down($state)) {
            foreach ($state['players'] as $i => &$p) {
                $p['hp'] = (int) ceil($p['max_hp'] / 2);
            }
            unset($p);
            $state['enemy'] = null;
            $narr[] = ['by' => 'system', 'text' => 'The party has fallen... but the dungeon offers mercy. You awaken, restored to half HP. 💀 Type: status to see everyone and look to find your bearings.'];
        }
    }

    return ['status' => 200, 'narrative' => $narr, 'error' => null];
}

function enemy_defeat(array &$state, array &$me): string
{
    $enemy = $state['enemy'];
    $xp = (int) ($enemy['xp'] ?? 5);
    $goldRange = $enemy['gold'] ?? [2, 8];
    $gold = random_int((int)$goldRange[0], (int)$goldRange[1]);
    $me['xp'] += $xp;
    $me['gold'] += $gold;
    $state['enemy'] = null;
    // remove from node
    $node = &$state['adventure']['nodes'][$state['adventure']['current']];
    if (!empty($node['monsters'])) { array_shift($node['monsters']); }

    $msg = "💥 You fell the {$enemy['name']}! (+{$xp} XP, +{$gold} gold)";
    log_append($state, "{$me['name']} slays the {$enemy['name']}.");

    // Boss defeated = victory
    $a = $state['adventure'];
    if ($state['adventure']['current'] === ($state['adventure']['last'] ?? -1) && empty($state['adventure']['nodes'][$state['adventure']['current']]['monsters'])) {
        $state['won'] = true;
        $state['enemy'] = null;
        $msg .= " 🏆 THE IRON CROWN IS YOURS! The dungeon is conquered! Type: start for a new adventure.";
        log_append($state, '🏆 The Iron Crown has been conquered by ' . $me['name'] . '!');
    }
    check_level_up($state, $me, $msg);
    return $msg;
}

function check_level_up(array &$state, array &$me, string &$msg): void
{
    $needed = 15 * $me['level'] * $me['level'];
    if ($me['xp'] >= $needed) {
        $me['level'] += 1;
        $maxHpGain = roll(6, 1)['total'] + 1;
        $me['max_hp'] += $maxHpGain;
        $me['hp'] = min($me['max_hp'], $me['hp'] + $maxHpGain);
        $me['ac'] += 1;
        $msg .= " ⬆ Level up! Level {$me['level']} (+{$maxHpGain} max HP, +1 AC)!";
        log_append($state, "{$me['name']} levels up to {$me['level']}!");
    }
}

function enemy_attack(array &$state, array &$me): array
{
    $enemy = &$state['enemy'];
    $narr = [];
    $roll = roll(20, 1);
    // Target the lowest HP alive player (excluding dead)
    $alive = array_filter($state['players'], fn($p) => ($p['hp'] ?? 0) > 0);
    if (empty($alive)) { return [['by' => 'system', 'text' => 'The enemy has no one left to fight.']]; }
    usort($alive, fn($a, $b) => $a['hp'] <=> $b['hp']);
    $targetName = $alive[0]['name'];

    if ($roll['total'] >= $alive[0]['ac']) {
        $dmg = random_int((int)$enemy['dmg'][0], (int)$enemy['dmg'][1]);
        foreach ($state['players'] as $i => &$tp) {
            if ($tp['name'] === $targetName && $tp['hp'] > 0) {
                $tp['hp'] -= $dmg;
                $narr[] = ['by' => 'system', 'text' => "👹 The {$enemy['name']} slashes {$tp['name']} for {$dmg} damage (roll {$roll['total']})."];
                if ($tp['hp'] <= 0) {
                    $tp['hp'] = 0;
                    $narr[] = ['by' => 'system', 'text' => "💀 {$tp['name']} has fallen! Their allies must carry on."];
                }
                break;
            }
        }
        unset($tp);
    } else {
        $narr[] = ['by' => 'system', 'text' => "👹 The {$enemy['name']} swipes at {$targetName} but misses (roll {$roll['total']})."];
    }
    return $narr;
}

function hp_check_party_down(array &$state): bool
{
    $alive = array_filter($state['players'], fn($p) => ($p['hp'] ?? 0) > 0);
    if (empty($alive)) {
        return true;
    }
    return false;
}