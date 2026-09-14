<?php
/**
 * Seed script: 200 fake UK estate agent leads and 2 test setters.
 *
 * Run from the command line:   php seed.php
 * Or in the browser as admin:  https://your-domain/crm/seed.php
 *
 * Refuses to run if any leads already exist. Delete this file before real data goes in.
 * Phone numbers use Ofcom's reserved drama ranges so nobody real gets called.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    require_admin();
    if (!is_post()) {
        page_header('Seed demo data');
        echo '<div class="card card-auth"><h2>Create demo data?</h2>';
        echo '<p>This adds 2 test setters and 200 fake leads. It only runs while the leads table is empty.</p>';
        echo '<form method="post">' . csrf_field() . '<button class="btn btn-primary" type="submit">Create demo data</button> <a class="btn btn-ghost" href="leads.php">Cancel</a></form></div>';
        page_footer();
        exit;
    }
    csrf_verify();
    header('Content-Type: text/plain; charset=utf-8');
}
$say = static function (string $s): void {
    echo $s, "\n";
};

if ((int)q_val('SELECT COUNT(*) FROM leads') > 0) {
    $say('Refusing to seed: the leads table is not empty.');
    $say('Delete all leads first (Leads page > select all > Delete) if you really want demo data.');
    exit(1);
}

mt_srand(42);
$pick = static fn(array $a) => $a[mt_rand(0, count($a) - 1)];

// ---- Test users ----
$setters = [
    ['Alex Turner', 'setter1@example.com', 'setter-one-123'],
    ['Priya Shah',  'setter2@example.com', 'setter-two-123'],
];
$setterIds = [];
foreach ($setters as [$name, $email, $pass]) {
    $row = q_one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($row) {
        $setterIds[] = (int)$row['id'];
        $say("User $email already exists (id {$row['id']}).");
        continue;
    }
    q('INSERT INTO users (name, email, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)',
        [$name, $email, password_hash($pass, PASSWORD_BCRYPT), 'setter', now()]);
    $setterIds[] = (int)db()->lastInsertId();
    $say("Created setter $name  login: $email / $pass");
}

// ---- Fake leads ----
$prefixes = ['Oak', 'Elm', 'Maple', 'Willow', 'Riverside', 'Hillside', 'Parkside', 'Meadow', 'Bridge', 'Castle', 'Abbey', 'Harbour', 'Market', 'Station', 'Victoria', 'Albion', 'Crown', 'Regent', 'Kings', 'Queens', 'Beacon', 'Summit', 'Lakeside', 'Woodland', 'Fairway', 'Cornerstone', 'Keystone', 'Northgate', 'Southgate', 'Westfield'];
$suffixes = ['Estates', 'Property', 'Homes', 'Lettings', 'Sales & Lettings', 'Estate Agents', 'Residential', 'Properties', 'Property Services', '& Co'];
$firsts = ['James', 'Sarah', 'Tom', 'Emma', 'Dan', 'Laura', 'Chris', 'Hannah', 'Mike', 'Sophie', 'Ben', 'Lucy', 'Sam', 'Katie', 'Ryan', 'Amy', 'Jack', 'Grace', 'Olly', 'Megan', 'Aisha', 'Raj', 'Fatima', 'Kwame', 'Chloe', 'Liam', 'Isla', 'Noah', 'Ava', 'Ethan'];
$lasts = ['Smith', 'Jones', 'Taylor', 'Brown', 'Williams', 'Wilson', 'Johnson', 'Davies', 'Robinson', 'Wright', 'Thompson', 'Evans', 'Walker', 'White', 'Roberts', 'Green', 'Hall', 'Wood', 'Jackson', 'Clarke', 'Patel', 'Khan', 'Hussain', 'Begum', 'Ali', 'Murphy', 'Kelly', 'Campbell', 'Stewart', 'Fraser'];
$titles = ['Branch Manager', 'Director', 'Owner', 'Sales Manager', 'Lettings Manager', 'Senior Negotiator', 'Office Manager', 'Partner'];
$towns = [
    ['Leeds', 'LS', '0113 496'], ['Manchester', 'M', '0161 496'], ['London', 'SW', '020 7946'],
    ['Birmingham', 'B', '0121 496'], ['Bristol', 'BS', '0117 496'], ['Sheffield', 'S', '0114 496'],
    ['Liverpool', 'L', '0151 496'], ['Nottingham', 'NG', '0115 496'], ['Leicester', 'LE', '0116 496'],
    ['Newcastle', 'NE', '0191 498'], ['Reading', 'RG', '0118 496'],
    ['Cardiff', 'CF', '029 2018'], ['Edinburgh', 'EH', '0131 496'], ['Glasgow', 'G', '0141 496'],
];
$streets = ['High Street', 'Market Place', 'Church Road', 'Station Road', 'London Road', 'Victoria Street', 'The Parade', 'Mill Lane', 'Park Road', 'Queen Street'];
$batches = ['Demo batch North ' . date('Y-m-d'), 'Demo batch South ' . date('Y-m-d')];
$sources = ['Web scrape', 'Directory', 'Referral', 'Purchased list'];

$pdo = db();
$pdo->beginTransaction();
$ins = $pdo->prepare('INSERT INTO leads (company_name, contact_name, job_title, phone_raw, phone_e164, email, website, address, town, postcode, tier, source, batch_name, assigned_to, dial_status, do_not_dial, last_dial_at, callback_at, dial_attempts, notes, custom_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$log = $pdo->prepare('INSERT INTO call_logs (lead_id, user_id, outcome, note, callback_at, created_at) VALUES (?,?,?,?,?,?)');

$usedPhones = [];
$created = 0;
for ($i = 0; $i < 200; $i++) {
    [$town, $pcArea, $areaCode] = $pick($towns);
    $company = $pick($prefixes) . ' ' . $pick($suffixes);
    $first = $pick($firsts);
    $last = $pick($lasts);
    // Mostly landlines in the reserved 496 0xxx range, some 07700 900xxx mobiles, a few invalid.
    if ($i % 25 === 24) {
        $phoneRaw = $pick(['n/a', '0000', '12345', 'call reception']);
    } elseif ($i % 4 === 0) {
        $phoneRaw = '07700 900' . str_pad((string)mt_rand(0, 999), 3, '0', STR_PAD_LEFT);
    } else {
        $phoneRaw = $areaCode . ' 0' . str_pad((string)mt_rand(0, 999), 3, '0', STR_PAD_LEFT);
    }
    // Present some numbers the way spreadsheets mangle them.
    $e164 = normalise_phone($phoneRaw);
    if ($e164 !== null) {
        if (isset($usedPhones[$e164])) {
            $i--;
            continue;
        }
        $usedPhones[$e164] = true;
        if ($i % 7 === 0) {
            $phoneRaw = substr($e164, 1) . '.0';
        }
    }
    $slug = strtolower(preg_replace('/[^a-z]+/i', '', $company));
    $email = strtolower($first) . '@' . $slug . '.example';
    $website = 'www.' . $slug . '.example';
    $postcode = $pcArea . mt_rand(1, 20) . ' ' . mt_rand(1, 9) . chr(mt_rand(65, 90)) . chr(mt_rand(65, 90));
    $address = mt_rand(1, 120) . ' ' . $pick($streets);
    $tier = $pick(['A', 'A', 'B', 'B', 'B', 'C']);
    $assigned = $i < 100 ? $setterIds[0] : ($i < 180 ? $setterIds[1] : null);
    $createdAt = date('Y-m-d H:i:s', time() - mt_rand(1, 20) * 86400 - mt_rand(0, 86400));
    $status = $e164 === null ? 'Invalid' : 'New';
    $attempts = 0;
    $lastDial = null;
    $callback = null;
    $notes = null;
    $dnd = 0;
    $history = [];
    if ($e164 !== null && $assigned !== null && $i % 3 === 0) {
        // Give a third of assigned leads some history.
        $attempts = mt_rand(1, 3);
        $lastDial = date('Y-m-d H:i:s', time() - mt_rand(5, 72) * 3600);
        $status = $pick(['No-Answer', 'No-Answer', 'No-Answer', 'Follow-Up', 'Setter Callback', 'Demo Booked', 'DQ']);
        if (in_array($status, OUTCOMES_NEED_CALLBACK, true)) {
            $callback = date('Y-m-d H:i:00', time() + mt_rand(-48, 72) * 3600);
            $notes = $pick(['Spoke to reception, call back when manager is in.', 'Interested, wants a call next week.', 'Asked to call after 2pm.']);
        } elseif ($status === 'Demo Booked') {
            $notes = 'Demo booked for next Tuesday 10am.';
        } elseif ($status === 'DQ') {
            $notes = $pick(['Franchise, decisions made at head office.', 'Closing the branch.']);
            $dnd = $pick([0, 1]);
        }
        for ($a = 0; $a < $attempts; $a++) {
            $isLast = $a === $attempts - 1;
            $history[] = [
                $isLast ? $status : 'No-Answer',
                $isLast ? $notes : null,
                $isLast ? $callback : null,
                $isLast ? $lastDial : date('Y-m-d H:i:s', strtotime($lastDial) - ($attempts - $a) * 86400),
            ];
        }
    }
    $custom = $i % 5 === 0 ? json_encode(['Branches' => (string)mt_rand(1, 6), 'Rating' => (string)mt_rand(3, 5)]) : null;
    $ins->execute([
        $company, "$first $last", $pick($titles), $phoneRaw, $e164, $email, $website, $address, $town, $postcode,
        $tier, $pick($sources), $batches[$i % 2], $assigned, $status, $dnd, $lastDial, $callback, $attempts, $notes, $custom,
        $createdAt, $lastDial ?? $createdAt,
    ]);
    $leadId = (int)$pdo->lastInsertId();
    foreach ($history as [$outcome, $note, $cb, $at]) {
        $log->execute([$leadId, $assigned, $outcome, $note, $cb, $at]);
    }
    $created++;
}
$pdo->commit();

$say("Created $created leads: 100 for setter1, 80 for setter2, 20 unassigned.");
$say('Log in as setter1@example.com / setter-one-123 or setter2@example.com / setter-two-123 to try the dial screen.');
$say('Delete seed.php from the server before importing real data.');
