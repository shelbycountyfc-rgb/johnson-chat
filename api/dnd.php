<?php
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ---------- auth ----------
// The browser posts JSON; accept JSON, form fields and query parameters so
// every client path authenticates the same way.
$rawBody = file_get_contents('php://input');
$jsonInput = json_decode((string) $rawBody, true);
if (!is_array($jsonInput)) { $jsonInput = []; }

$token = trim($_GET['token'] ?? ($jsonInput['token'] ?? ($_POST['token'] ?? '')));
$roomId = trim($_GET['room_id'] ?? ($jsonInput['room_id'] ?? ($_POST['room_id'] ?? '')));
$username = trim($_GET['username'] ?? ($jsonInput['username'] ?? ($_POST['username'] ?? '')));

if ($token === '' || $roomId === '' || $username === '') {
    http_response_code(400);
    echo json_encode(['error' => 'token, room_id, username required']);
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
if (strtolower($tokenRow['username']) !== strtolower($username)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token mismatch']);
    $database->close();
    exit;
}

// room membership: only the two room owners may join this adventure
$parts = explode('_', $roomId);
$allowed = false;
if (count($parts) === 2) {
    $allowed = strtolower($parts[0]) === strtolower($username) || strtolower($parts[1]) === strtolower($username);
}
if (!$allowed) {
    http_response_code(403);
    echo json_encode(['error' => 'Not a room member']);
    $database->close();
    exit;
}
$database->close();

// ---------- state ----------
define('DND_DATA_DIR', __DIR__ . '/data/dnd');
if (!is_dir(DND_DATA_DIR)) { @mkdir(DND_DATA_DIR, 0777, true); }
$stateFile = DND_DATA_DIR . '/' . md5($roomId) . '.json';

function dnd_load_state(string $file, string $roomId, $fp = null): array
{
    // Read through the locked handle: on Windows a second handle cannot read
    // a region another handle has locked, which made every request see an
    // empty dungeon.
    $raw = false;
    if (is_resource($fp)) {
        rewind($fp);
        $raw = stream_get_contents($fp);
    } elseif (file_exists($file)) {
        $raw = file_get_contents($file);
    }
    if ($raw !== false && $raw !== '') {
        $s = json_decode((string) $raw, true);
        if (is_array($s)) {
            $s['room_id'] = $roomId;
            if (!isset($s['seq'])) $s['seq'] = 0;
            if (!isset($s['log'])) $s['log'] = [];
            return $s;
        }
    }
    return [
        'room_id' => $roomId,
        'seq' => 0,
        'status' => 'camp',
        'depth' => 1,
        'turn' => '',
        'turn_at' => '',
        'heroes' => [],
        'monster' => null,
        'defending' => '',
        'log' => [],
    ];

}

function dnd_set_turn(array &$state, string $u): void
{
    $state['turn'] = $u;
    $state['turn_at'] = microtime(true);
}

function dnd_auto_skip(array &$state, float $timeout = 30.0): bool
{
    if (($state['status'] ?? '') !== 'combat') return false;
    if (($state['turn'] ?? '') === '') return false;
    if (empty($state['turn_at'])) return false;
    if ((microtime(true) - (float) $state['turn_at']) < $timeout) return false;

    $alive = array_keys(array_filter($state['heroes'], fn($h) => $h['alive']));
    if (count($alive) < 2) {
        $state['turn_at'] = microtime(true);
        return false;
    }
    $idx = 0;
    foreach ($alive as $i => $u) {
        if (strtolower($u) === strtolower($state['turn'])) { $idx = $i; break; }
    }
    dnd_set_turn($state, $alive[($idx + 1) % count($alive)]);
    dnd_log($state, "⏭️ {$state['turn']}'s turn begins (skipped for inactivity).");
    return true;
}

function dnd_save_state(string $file, array $state, $fp = null): void
{
    // Write through the locked handle. Opening a second handle (or renaming
    // over the file) fails on Windows while the lock is held, which silently
    // discarded the dungeon state.
    $json = (string) json_encode($state);
    if (is_resource($fp)) {
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, $json);
        fflush($fp);
        return;
    }
    file_put_contents($file, $json);
}

function dnd_log(array &$state, string $msg): void
{
    $state['seq']++;
    $state['log'][] = ['seq' => $state['seq'], 't' => $msg, 'at' => microtime(true)];
    if (count($state['log']) > 200) {
        $state['log'] = array_slice($state['log'], -200);
    }
}

function dnd_roll(int $sides): int { return random_int(1, $sides); }
function dnd_stat_mod(int $stat): int { return intdiv($stat - 10, 2); }

const DND_CLASSES = [
    'fighter' => ['label' => 'Fighter',  'hp' => 10, 'hp_lvl' => 7, 'str' => 16, 'dex' => 12, 'con' => 14, 'int' => 10, 'wis' => 11, 'weapon' => [1, 8], 'hit' => 2, 'crit' => 20, 'spell' => ''],
    'mage'    => ['label' => 'Mage',     'hp' => 6,  'hp_lvl' => 4, 'str' => 9,  'dex' => 12, 'con' => 12, 'int' => 17, 'wis' => 12, 'weapon' => [1, 4], 'hit' => 0, 'crit' => 20, 'spell' => 'fire'],
    'rogue'   => ['label' => 'Rogue',    'hp' => 8,  'hp_lvl' => 5, 'str' => 12, 'dex' => 17, 'con' => 12, 'int' => 12, 'wis' => 10, 'weapon' => [1, 6], 'hit' => 1, 'crit' => 19, 'spell' => ''],
    'cleric'  => ['label' => 'Cleric',   'hp' => 8,  'hp_lvl' => 5, 'str' => 12, 'dex' => 10, 'con' => 14, 'int' => 10, 'wis' => 17, 'weapon' => [1, 6], 'hit' => 0, 'crit' => 20, 'spell' => 'heal'],
];

const DND_MONSTERS = [
    'Goblin'         => ['min' => 1,  'hp' => 2,  'atk' => 1,  'def' => 1,  'xp' => 10, 'gold' => [2, 6]],
    'Giant Rat'      => ['min' => 1,  'hp' => 2,  'atk' => 2,  'def' => 0,  'xp' => 8,  'gold' => [1, 5]],
    'Wolf'           => ['min' => 1,  'hp' => 3,  'atk' => 3,  'def' => 1,  'xp' => 14, 'gold' => [1, 4]],
    'Skeleton'       => ['min' => 2,  'hp' => 4,  'atk' => 2,  'def' => 2,  'xp' => 18, 'gold' => [3, 8]],
    'Orc'            => ['min' => 2,  'hp' => 5,  'atk' => 3,  'def' => 2,  'xp' => 24, 'gold' => [4, 12]],
    'Dark Elf'       => ['min' => 3,  'hp' => 5,  'atk' => 4,  'def' => 3,  'xp' => 30, 'gold' => [6, 16]],
    'Giant Spider'   => ['min' => 3,  'hp' => 6,  'atk' => 4,  'def' => 1,  'xp' => 26, 'gold' => [3, 10]],
    'Troll'          => ['min' => 4,  'hp' => 8,  'atk' => 5,  'def' => 2,  'xp' => 44, 'gold' => [10, 25]],
    'Wraith'         => ['min' => 5,  'hp' => 7,  'atk' => 6,  'def' => 4,  'xp' => 58, 'gold' => [8, 22]],
    'Ogre'           => ['min' => 6,  'hp' => 11, 'atk' => 6,  'def' => 3,  'xp' => 76, 'gold' => [16, 36]],
    'Mummy'          => ['min' => 7,  'hp' => 12, 'atk' => 6,  'def' => 5,  'xp' => 88, 'gold' => [12, 30]],
    'Basilisk'       => ['min' => 8,  'hp' => 13, 'atk' => 7,  'def' => 5,  'xp' => 105, 'gold' => [20, 50]],
    'Dragon Whelp'   => ['min' => 10, 'hp' => 16, 'atk' => 8,  'def' => 6,  'xp' => 140, 'gold' => [40, 90]],
    'Abyssal Horror' => ['min' => 12, 'hp' => 19, 'atk' => 9,  'def' => 7,  'xp' => 190, 'gold' => [60, 140]],
    'Dread Lich'     => ['min' => 15, 'hp' => 23, 'atk' => 11, 'def' => 8,  'xp' => 260, 'gold' => [90, 200]],
    'Ancient Dragon' => ['min' => 20, 'hp' => 30, 'atk' => 13, 'def' => 10, 'xp' => 400, 'gold' => [150, 350]],
];

function dnd_make_monster(int $depth): array
{
    $pool = [];
    foreach (DND_MONSTERS as $name => $m) {
        if ($depth >= $m['min']) {
            $pool[$name] = $m;
        }
    }
    if (empty($pool)) { $pool = [array_values(DND_MONSTERS)[0]]; }
    $name = array_rand($pool);
    $m = $pool[$name];
    $prefix = '';
    if ($depth >= 20 && random_int(1, 100) <= 30) { $prefix = 'Ancient '; }
    elseif ($depth >= 10 && random_int(1, 100) <= 30) { $prefix = 'Dire '; }
    elseif ($depth >= 4 && random_int(1, 100) <= 20) { $prefix = 'Elder '; }
    $hp = max(1, (int) round($m['hp'] * (1 + ($depth - 1) * 0.18)));
    $atk = $m['atk'] + intdiv($depth, 4);
    $def = $m['def'] + intdiv($depth, 6);
    $xp = (int) round($m['xp'] * (1 + ($depth - 1) * 0.06));
    $g1 = $m['gold'][0] + intdiv($depth, 2);
    $g2 = $m['gold'][1] + intdiv($depth, 2);
    return [
        'name' => $prefix . $name,
        'hp' => $hp,
        'max_hp' => $hp,
        'atk' => $atk,
        'def' => $def,
        'xp' => $xp,
        'gold' => random_int($g1, $g2) * (1 + intdiv($depth, 8)),
        'akind' => $name,
    ];
}

function dnd_xp_needed(int $level): int { return $level * 40; }

function dnd_new_hero(string $username, string $class): array
{
    $c = DND_CLASSES[$class];
    $conMod = dnd_stat_mod($c['con']);
    $hp = max(1, $c['hp'] + $conMod);
    return [
        'class' => $c['label'],
        'level' => 1,
        'xp' => 0,
        'hp' => $hp,
        'max_hp' => $hp,
        'str' => $c['str'],
        'dex' => $c['dex'],
        'con' => $c['con'],
        'int' => $c['int'],
        'wis' => $c['wis'],
        'weapon' => $c['weapon'],
        'hit' => $c['hit'],
        'crit' => $c['crit'],
        'spell' => $c['spell'],
        'cd' => 0,
        'gold' => 15,
        'potions' => 2,
        'alive' => true,
    ];
}

function dnd_handlers(array &$state): array
{
    $names = array_values(array_filter(array_keys(DND_CLASSES)));
    return $names;
}

// ---------- GET ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $since = (int) ($_GET['since'] ?? 0);
    $fp = fopen($stateFile, 'c+');
    if ($fp) { flock($fp, LOCK_EX); }
    $state = dnd_load_state($stateFile, $roomId, $fp);
    if (dnd_auto_skip($state)) { dnd_save_state($stateFile, $state, $fp); }
    if ($fp) { flock($fp, LOCK_UN); fclose($fp); }

    $newLog = [];
    foreach ($state['log'] as $e) {
        if ((int) $e['seq'] > $since) { $newLog[] = $e; }
    }
    echo json_encode([
        'ok' => true,
        'seq' => $state['seq'],
        'status' => $state['status'],
        'depth' => $state['depth'],
        'turn' => $state['turn'],
        'heroes' => $state['heroes'],
        'monster' => $state['monster'],
        'defending' => $state['defending'] ?? '',
        'log' => $newLog,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_valid_origin();

$input = $jsonInput;
if (!is_array($input) || empty($input)) { $input = $_POST; }
$action = strtolower(trim($input['action'] ?? ''));
$arg = strtolower(trim($input['arg'] ?? ''));

if ($action === '') {
    http_response_code(400);
    echo json_encode(['error' => 'action required']);
    exit;
}

// exclusive lock for read-modify-write
$fp = fopen($stateFile, 'c+');
if ($fp) { flock($fp, LOCK_EX); }
$state = dnd_load_state($stateFile, $roomId, $fp);

$error = '';
$respond = true;

if ($action === 'create') {
    if (!isset(DND_CLASSES[$arg])) {
        $error = 'Choose a class: ' . implode(', ', dnd_handlers($state));
    } elseif (isset($state['heroes'][$username])) {
        $error = 'You already have a character. Use !reset to start over.';
    } else {
        $state['heroes'][$username] = dnd_new_hero($username, $arg);
        $label = ucfirst($arg);
        dnd_log($state, "⚔️ $username creates a $label and joins the party.");
        if (count($state['heroes']) === 1) {
            dnd_log($state, "🗡️ Say !explore when ready to enter the dungeon. Your partner can still join first.");
        }
        if ($state['turn'] === '') {
            dnd_set_turn($state, $username);
        }
    }
} elseif ($action === 'explore') {
    if (!isset($state['heroes'][$username])) {
        $error = 'Create a character first: !new fighter / mage / rogue / cleric';
    } elseif ($state['status'] !== 'camp') {
        $error = 'You can only explore while at camp.';
    } elseif (count($state['heroes']) === 0) {
        $error = 'At least one hero is needed to explore.';
    } else {
        $state['depth']++;
        $state['monster'] = dnd_make_monster($state['depth']);
        $state['status'] = 'combat';
        $state['defending'] = '';
        // starter hero whose turn it is
        $alive = array_keys(array_filter($state['heroes'], fn($h) => $h['alive']));
        dnd_set_turn($state, $alive[0] ?? $username);
        dnd_log($state, "⬇️ You descend to depth {$state['depth']}.");
        dnd_log($state, "🐾 {$state['monster']['name']} (hp {$state['monster']['hp']}) blocks the way! {$state['turn']}, your move.");
    }
} elseif ($action === 'status') {
    // no mutation
    dnd_log($state, "📜 Party status reviewed.");
} elseif ($action === 'attack' || $action === 'cast' || $action === 'defend' || $action === 'potion' || $action === 'flee') {
    if ($state['status'] !== 'combat') {
        $error = 'You are not in combat. Type !explore to enter the dungeon.';
    } elseif (($state['turn'] ?? '') !== $username) {
        $error = "It is {$state['turn']}'s turn.";
    } elseif (empty($state['heroes'][$username]['alive'])) {
        $error = 'You are fallen and cannot act.';
    } else {
        $hero = &$state['heroes'][$username];
        $monster = &$state['monster'];
        if ($monster == null) {
            $state['status'] = 'camp';
        } elseif ($action === 'attack') {
            if ($hero['cd'] > 0) { $hero['cd']--; }
            $roll = dnd_roll(20);
            $hits = $roll + dnd_stat_mod($hero['str']) + $hero['hit'] >= 10 + $monster['def'];
            $crit = $roll >= $hero['crit'];
            if ($hits) {
                $dmg = dnd_roll($hero['weapon'][1]) + max(1, dnd_stat_mod($hero['str']));
                if ($crit) {
                    $dmg += dnd_roll($hero['weapon'][1]);
                    dnd_log($state, "💥 CRITICAL! $username strikes the {$monster['name']} for $dmg damage.");
                } else {
                    dnd_log($state, "⚔️ $username attacks the {$monster['name']} for $dmg damage.");
                }
                $monster['hp'] -= $dmg;
            } else {
                $crit = false;
                dnd_log($state, "❌ $username swings and misses the {$monster['name']}.");
            }
            if (!$crit && random_int(1, 10) === 1) {
                // weapon mastery: minor bonus gold on future loot
                $hero['gold'] += 1;
                dnd_log($state, "🪙 $username finds a stray coin among the rubble. (+1 gold)");
            }
        } elseif ($action === 'cast') {
            $spell = $hero['spell'] ?? '';
            if ($spell === '') {
                $error = 'Your class has no spells.';
            } elseif ($hero['cd'] > 0) {
                $error = 'Your spell is recharging (' . $hero['cd'] . ' rounds left).';
            } else {
                if ($spell === 'fire') {
                    $dmg = dnd_roll(6) + dnd_roll(6) + dnd_stat_mod($hero['int']);
                    $monster['hp'] -= $dmg;
                    $hero['cd'] = 2;
                    dnd_log($state, "🔥 $username hurls a fireball! The {$monster['name']} takes $dmg damage.");
                } elseif ($spell === 'heal') {
                    $heal = dnd_roll(8) + dnd_stat_mod($hero['wis']);
                    $hero['hp'] = min($hero['max_hp'], $hero['hp'] + $heal);
                    $hero['cd'] = 2;
                    dnd_log($state, "✨ $username channels holy light, restoring $heal HP.");
                }
            }
        } elseif ($action === 'defend') {
            $state['defending'] = $username;
            dnd_log($state, "🛡️ $username raises their guard, bracing for the {$monster['name']}'s assault.");
        } elseif ($action === 'potion') {
            if ($hero['potions'] <= 0) {
                $error = 'No potions left. Buy some at camp: !buy';
            } else {
                $hero['potions']--;
                $heal = dnd_roll(8) + 2;
                $hero['hp'] = min($hero['max_hp'], $hero['hp'] + $heal);
                dnd_log($state, "🧪 $username drinks a potion, restoring $heal HP.");
            }
        } elseif ($action === 'flee') {
            if (dnd_roll(20) + dnd_stat_mod($hero['dex']) >= 12) {
                $state['monster'] = null;
                $state['status'] = 'camp';
                $state['defending'] = '';
                dnd_log($state, "💨 $username flees back to camp! The {$monster['name']} lets them go.");
            } else {
                dnd_log($state, "😰 $username tries to flee but the {$monster['name']} cuts them off!");
            }
        }

        if ($error === '' && $state['status'] === 'combat') {
            // monster still alive -> monster retaliates
            $monster = &$state['monster'];
            if ($monster != null && $monster['hp'] > 0) {
                $target = $username;
                $blocked = false;
                if (($state['defending'] ?? '') === $username) {
                    $blocked = true;
                    $state['defending'] = '';
                }
                $roll = dnd_roll(20);
                $hits = $roll + $monster['atk'] >= 10 + dnd_stat_mod($state['heroes'][$target]['dex']);
                if ($hits) {
                    $dmg = dnd_roll(6) + intdiv($monster['atk'], 2);
                    if ($blocked) { $dmg = max(1, intdiv($dmg, 2)); }
                    $state['heroes'][$target]['hp'] -= $dmg;
                    $state['heroes'][$target]['alive'] = $state['heroes'][$target]['hp'] > 0;
                    dnd_log($state, "🩸 The {$monster['name']} hits $target for $dmg damage" . ($blocked ? ' (blocked)' : '') . ".");
                    if (!$state['heroes'][$target]['alive']) {
                        dnd_log($state, "💀 $target has fallen!");
                    }
                } else {
                    dnd_log($state, "🕳️ The {$monster['name']} lunges at $target but misses.");
                }
                // pass turn to next alive hero
                $alive = array_keys(array_filter($state['heroes'], fn($h) => $h['alive']));
                if (count($alive) === 0) {
                    // party wipe -> retreat to camp, revives at 1 hp
                    foreach ($state['heroes'] as $u => &$h) {
                        $h['hp'] = $h['alive'] ? $h['hp'] : 1;
                        $h['alive'] = true;
                    }
                    unset($h);
                    $state['monster'] = null;
                    $state['status'] = 'camp';
                    $state['defending'] = '';
                    dnd_log($state, "🕊️ The party is overwhelmed and retreats to camp, battered but alive.");
                } else {
                    if (count($alive) === 1 && strtolower($alive[0]) === strtolower($username)) {
                        dnd_set_turn($state, $alive[0]);
                    } else {
                        $idx = 0;
                        foreach ($alive as $i => $u) {
                            if (strtolower($u) === strtolower($username)) { $idx = $i; break; }
                        }
                        dnd_set_turn($state, $alive[($idx + 1) % count($alive)]);
                        if (strtolower($state['turn']) === strtolower($username)) {
                            dnd_log($state, "🔁 $username acts again. ({$state['turn']} turn)");
                        }
                    }
                }
            } elseif ($monster == null || $monster['hp'] <= 0) {
                // victory
                $m = $monster;
                $monster = null;
                $state['status'] = 'camp';
                $state['defending'] = '';
                $xp = $m['xp'];
                $gold = $m['gold'];
                $gain = intdiv($xp, max(1, count($state['heroes'])));
                $lines = [];
                foreach ($state['heroes'] as $u => &$h) {
                    $h['xp'] += $gain;
                    $h['gold'] += $gold;
                    $lines[] = $u;
                    // level ups
                    while ($h['xp'] >= dnd_xp_needed($h['level'])) {
                        $h['xp'] -= dnd_xp_needed($h['level']);
                        $h['level']++;
                        $h['max_hp'] += ($h['class'] === 'Mage' ? 4 : ($h['class'] === 'Fighter' ? 7 : 5)) + dnd_stat_mod($h['con']);
                        $h['max_hp'] = max(1, $h['max_hp']);
                        dnd_log($state, "⬆️ $u reached level {$h['level']}! Max HP is now {$h['max_hp']}.");
                    }
                    // revive fallen when the party wins
                    if (!$h['alive']) {
                        $h['alive'] = true;
                        $h['hp'] = max(1, $h['hp']);
                        dnd_log($state, "🕊️ $u is revived by the party's victory (hp {$h['hp']}).");
                    }
                }
                unset($h);
                dnd_log($state, "🏆 The {$m['name']} is slain! Great victory!");
                dnd_log($state, "💰 Party gains {$gold} gold each and " . ($gain) . " XP (" . implode(', ', $lines) . ").");
                if (random_int(1, 100) <= 20) {
                    $u = $alive2 = array_keys($state['heroes'])[array_rand(array_keys($state['heroes']))];
                    $state['heroes'][$u]['potions']++;
                    dnd_log($state, "🧪 $u finds a healing potion in the monster's lair.");
                }
            }
        }
    }
} elseif ($action === 'rest') {
    if (!isset($state['heroes'][$username])) {
        $error = 'Create a character first: !new fighter / mage / rogue / cleric';
    } elseif ($state['status'] !== 'camp') {
        $error = 'You can only rest while at camp.';
    } else {
        foreach ($state['heroes'] as &$h) {
            $h['hp'] = $h['max_hp'];
            $h['alive'] = true;
            $h['cd'] = 0;
        }
        unset($h);
        dnd_log($state, "⛺ The party rests by the fire, fully healed and ready.");
    }
} elseif ($action === 'buy') {
    if (!isset($state['heroes'][$username])) {
        $error = 'Create a character first: !new fighter / mage / rogue / cleric';
    } elseif ($state['status'] !== 'camp') {
        $error = 'You can only shop while at camp.';
    } elseif ($state['heroes'][$username]['gold'] < 15) {
        $error = 'A potion costs 15 gold. You have ' . $state['heroes'][$username]['gold'] . '.';
    } else {
        $state['heroes'][$username]['gold'] -= 15;
        $state['heroes'][$username]['potions']++;
        dnd_log($state, "🧪 $username buys a potion (15 gold). Potions: {$state['heroes'][$username]['potions']}.");
    }
} elseif ($action === 'reset') {
    $state = [
        'room_id' => $roomId,
        'seq' => $state['seq'] + 1,
        'status' => 'camp',
        'depth' => 1,
        'turn' => '',
        'turn_at' => '',
        'heroes' => [],
        'monster' => null,
        'defending' => '',
        'log' => [['seq' => $state['seq'], 't' => '🌀 The dungeon has been reset. Start a fresh adventure!', 'at' => microtime(true)]],
    ];
} elseif ($action === 'help') {
    dnd_log($state, "🆘 Commands: !new <fighter|mage|rogue|cleric> · !explore · !attack · !cast · !defend · !potion · !flee · !rest · !buy · !status · !reset");
} else {
    $error = 'Unknown action: ' . $action . '. Type !help';
}

if ($error !== '') {
    // nothing was mutated for pure errors, but spells/defend partly mutate; log the error
    dnd_log($state, "⚠️ $username: $error");
}

if ($fp) { dnd_save_state($stateFile, $state, $fp); flock($fp, LOCK_UN); fclose($fp); }

echo json_encode([
    'ok' => $error === '',
    'error' => $error,
    'seq' => $state['seq'],
    'status' => $state['status'],
    'depth' => $state['depth'],
    'turn' => $state['turn'],
    'heroes' => $state['heroes'],
    'monster' => $state['monster'],
    'defending' => $state['defending'] ?? '',
    'log' => $state['log'],
    'your_turn' => ($state['turn'] ?? '') === $username,
]);