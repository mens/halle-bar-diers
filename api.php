<?php
// api.php — AJAX API endpoint
require_once 'db.php';
require_once 'auth.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function jsonOk($data = []) {
    echo json_encode(['ok' => true] + $data);
    exit;
}
function jsonErr($msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// Returns true if the caller may access the active shift's POS data:
// either a write-role user, or the session was unlocked for the current shift.
function hasShiftAuth(): bool {
    global $pdo;
    if (hasRole('write')) return true;
    $shift = $pdo->query("SELECT id FROM shifts WHERE gesloten = 0 LIMIT 1")->fetch();
    if (!$shift) return false;
    return (int)($_SESSION['shift_auth'] ?? 0) === (int)$shift['id'];
}

// Actions that require write role
$writeActions = ['save_drank', 'delete_drank', 'delete_shift'];
// Actions that require read role (implies login)
$readActions  = ['get_alle_dranken', 'get_shifts_lijst', 'get_shift_rapport'];
// Actions that require shift auth (write role OR session password)
$shiftActions = ['close_shift', 'open_tab', 'get_tab', 'add_to_tab', 'remove_tab_regel',
                 'update_regel_aantal', 'betaal_tab', 'delete_tab', 'get_dranken', 'direct_sale'];

if (in_array($action, $writeActions) && !hasRole('write'))   jsonErr('Geen schrijfrechten.', 403);
if (in_array($action, $readActions)  && !hasRole('read'))    jsonErr('Geen leesrechten.', 403);
if (in_array($action, $shiftActions) && !hasShiftAuth())     jsonErr('Geen toegang tot shift.', 403);

switch ($action) {

    // ── SHIFT ──────────────────────────────────────────────────────────────
    case 'start_shift':
        $open = $pdo->query("SELECT id FROM shifts WHERE gesloten = 0 LIMIT 1")->fetch();
        if ($open) jsonErr('Er is al een open shift (ID ' . $open['id'] . '). Sluit deze eerst.');

        $user = getUser();
        $lijst_id = (int)($_POST['prijslijst_id'] ?? 1);

        if ($user) {
            $naam = $user['name'];
            $hash = null;
        } else {
            $voornaam   = trim($_POST['voornaam']   ?? '');
            $achternaam = trim($_POST['achternaam'] ?? '');
            $password   = $_POST['password'] ?? '';
            if (!$voornaam || !$achternaam) jsonErr('Voornaam en achternaam zijn verplicht.');
            if (strlen($password) < 4) jsonErr('Wachtwoord moet minstens 4 tekens bevatten.');
            $naam = "$voornaam $achternaam";
            $hash = password_hash($password, PASSWORD_DEFAULT);
        }

        $pdo->prepare("INSERT INTO shifts (verantwoordelijke, prijslijst_id, password_hash) VALUES (?, ?, ?)")
            ->execute([$naam, $lijst_id, $hash]);
        $shift_id = (int)$pdo->lastInsertId();
        $_SESSION['shift_auth'] = $shift_id;
        jsonOk(['shift_id' => $shift_id]);

    case 'unlock_shift':
        $shift_id = (int)($_POST['shift_id'] ?? 0);
        $password  = $_POST['password'] ?? '';
        if (!$shift_id) jsonErr('Geen shift ID opgegeven.');
        $shift = $pdo->prepare("SELECT id, password_hash FROM shifts WHERE id = ? AND gesloten = 0");
        $shift->execute([$shift_id]);
        $shift = $shift->fetch();
        if (!$shift) jsonErr('Shift niet gevonden of al gesloten.');
        if (!$shift['password_hash'] || !password_verify($password, $shift['password_hash'])) {
            jsonErr('Incorrect wachtwoord.');
        }
        $_SESSION['shift_auth'] = (int)$shift['id'];
        jsonOk();

    case 'get_active_shift':
        $shift = $pdo->query(
            "SELECT s.*, p.naam AS prijslijst_naam
             FROM shifts s JOIN prijslijsten p ON p.id = s.prijslijst_id
             WHERE s.gesloten = 0 ORDER BY s.begintijd DESC LIMIT 1"
        )->fetch();
        if (!$shift) jsonOk(['shift' => null]);

        // Return only enough for the unlock screen if caller has no access
        if (!hasShiftAuth()) {
            jsonOk(['shift' => ['id' => (int)$shift['id'], 'needs_password' => true]]);
        }

        $tabs = $pdo->prepare(
            "SELECT t.*, COALESCE(SUM(r.prijs * r.aantal),0) AS subtotaal,
                    COUNT(r.id) AS aantal_regels
             FROM tabs t LEFT JOIN tab_regels r ON r.tab_id = t.id
             WHERE t.shift_id = ? AND t.betaald = 0
             GROUP BY t.id ORDER BY t.geopend"
        );
        $tabs->execute([$shift['id']]);
        $shift['tabs'] = $tabs->fetchAll();
        unset($shift['password_hash']); // never expose the hash
        jsonOk(['shift' => $shift]);

    case 'close_shift':
        $shift_id = (int)($_POST['shift_id'] ?? 0);
        $opmerking = trim($_POST['opmerking'] ?? '');
        if (!$shift_id) jsonErr('Geen shift ID opgegeven.');
        $open_tabs = $pdo->prepare("SELECT COUNT(*) FROM tabs WHERE shift_id = ? AND betaald = 0");
        $open_tabs->execute([$shift_id]);
        if ($open_tabs->fetchColumn() > 0) jsonErr('Er zijn nog onbetaalde tabs. Verwerk deze eerst.');
        $pdo->prepare("UPDATE shifts SET gesloten = 1, eindtijd = NOW(), opmerking = ? WHERE id = ?")
            ->execute([$opmerking ?: null, $shift_id]);
        unset($_SESSION['shift_auth']);
        jsonOk();

    case 'direct_sale':
        $shift_id    = (int)($_POST['shift_id'] ?? 0);
        $betaalwijze = $_POST['betaalwijze'] ?? '';
        $items       = json_decode($_POST['items'] ?? '[]', true);
        if (!$shift_id) jsonErr('Geen shift ID.');
        if (!in_array($betaalwijze, ['cash', 'payconiq'])) jsonErr('Ongeldige betaalwijze.');
        if (empty($items) || !is_array($items)) jsonErr('Voeg minstens één drank toe.');

        $shift = $pdo->prepare("SELECT id, prijslijst_id FROM shifts WHERE id = ? AND gesloten = 0");
        $shift->execute([$shift_id]);
        $shift = $shift->fetch();
        if (!$shift) jsonErr('Shift niet gevonden of al gesloten.');

        $pdo->prepare("INSERT INTO tabs (shift_id, naam) VALUES (?, 'Directe verkoop')")
            ->execute([$shift_id]);
        $tab_id = (int)$pdo->lastInsertId();

        $totaal = 0.0;
        foreach ($items as $item) {
            $drank_id = (int)($item['drank_id'] ?? 0);
            $aantal   = max(1, (int)($item['aantal'] ?? 1));
            if (!$drank_id) continue;
            $drank = $pdo->prepare(
                "SELECT d.naam, p.prijs FROM dranken d
                 JOIN prijzen p ON p.drank_id = d.id AND p.prijslijst_id = ?
                 WHERE d.id = ? AND d.actief = 1"
            );
            $drank->execute([$shift['prijslijst_id'], $drank_id]);
            $drank = $drank->fetch();
            if (!$drank) continue;
            $pdo->prepare("INSERT INTO tab_regels (tab_id, drank_id, drank_naam, prijs, aantal) VALUES (?,?,?,?,?)")
                ->execute([$tab_id, $drank_id, $drank['naam'], $drank['prijs'], $aantal]);
            $totaal += $drank['prijs'] * $aantal;
        }

        if ($totaal == 0) {
            $pdo->prepare("DELETE FROM tabs WHERE id = ?")->execute([$tab_id]);
            jsonErr('Geen geldige dranken opgegeven.');
        }

        $pdo->prepare("UPDATE tabs SET betaald=1, gesloten=NOW(), betaalwijze=?, totaal=? WHERE id=?")
            ->execute([$betaalwijze, $totaal, $tab_id]);
        jsonOk(['totaal' => $totaal]);

    case 'delete_shift':
        $shift_id = (int)($_POST['shift_id'] ?? 0);
        if (!$shift_id) jsonErr('Geen shift ID.');
        $pdo->prepare("DELETE FROM shifts WHERE id = ?")->execute([$shift_id]);
        jsonOk();

    // ── TABS ───────────────────────────────────────────────────────────────
    case 'open_tab':
        $shift_id = (int)($_POST['shift_id'] ?? 0);
        $naam = trim($_POST['naam'] ?? '');
        if (!$shift_id || !$naam) jsonErr('Shift ID en naam zijn verplicht.');
        $stmt = $pdo->prepare("INSERT INTO tabs (shift_id, naam) VALUES (?, ?)");
        $stmt->execute([$shift_id, $naam]);
        jsonOk(['tab_id' => $pdo->lastInsertId(), 'naam' => $naam]);

    case 'get_tab':
        $tab_id = (int)($_GET['tab_id'] ?? 0);
        if (!$tab_id) jsonErr('Geen tab ID.');
        $tab = $pdo->prepare("SELECT * FROM tabs WHERE id = ?");
        $tab->execute([$tab_id]);
        $tab = $tab->fetch();
        if (!$tab) jsonErr('Tab niet gevonden.');
        $regels = $pdo->prepare(
            "SELECT r.*, d.naam AS drank_naam_huidig FROM tab_regels r
             LEFT JOIN dranken d ON d.id = r.drank_id
             WHERE r.tab_id = ? ORDER BY r.tijdstip"
        );
        $regels->execute([$tab_id]);
        $tab['regels'] = $regels->fetchAll();
        $tab['totaal'] = array_sum(array_map(fn($r) => $r['prijs'] * $r['aantal'], $tab['regels']));
        jsonOk(['tab' => $tab]);

    case 'add_to_tab':
        $tab_id   = (int)($_POST['tab_id']   ?? 0);
        $drank_id = (int)($_POST['drank_id'] ?? 0);
        $aantal   = max(1, (int)($_POST['aantal'] ?? 1));
        if (!$tab_id || !$drank_id) jsonErr('Tab en drank zijn verplicht.');
        $info = $pdo->prepare(
            "SELECT d.naam, p.prijs
             FROM dranken d
             JOIN tabs t ON t.id = ?
             JOIN prijzen p ON p.drank_id = d.id AND p.prijslijst_id = (
                SELECT prijslijst_id FROM shifts WHERE id = t.shift_id
             )
             WHERE d.id = ? AND d.actief = 1"
        );
        $info->execute([$tab_id, $drank_id]);
        $drank = $info->fetch();
        if (!$drank) jsonErr('Drank niet beschikbaar voor deze prijslijst.');
        $existing = $pdo->prepare(
            "SELECT id, aantal FROM tab_regels WHERE tab_id = ? AND drank_id = ? AND prijs = ?"
        );
        $existing->execute([$tab_id, $drank_id, $drank['prijs']]);
        $row = $existing->fetch();
        if ($row) {
            $pdo->prepare("UPDATE tab_regels SET aantal = aantal + ? WHERE id = ?")
                ->execute([$aantal, $row['id']]);
        } else {
            $pdo->prepare("INSERT INTO tab_regels (tab_id, drank_id, drank_naam, prijs, aantal) VALUES (?,?,?,?,?)")
                ->execute([$tab_id, $drank_id, $drank['naam'], $drank['prijs'], $aantal]);
        }
        $pdo->prepare("UPDATE tabs SET totaal = (SELECT COALESCE(SUM(prijs*aantal),0) FROM tab_regels WHERE tab_id=?) WHERE id=?")
            ->execute([$tab_id, $tab_id]);
        jsonOk(['naam' => $drank['naam'], 'prijs' => $drank['prijs']]);

    case 'remove_tab_regel':
        $regel_id = (int)($_POST['regel_id'] ?? 0);
        $tab_id   = (int)($_POST['tab_id']   ?? 0);
        if (!$regel_id) jsonErr('Geen regel ID.');
        $pdo->prepare("DELETE FROM tab_regels WHERE id = ?")->execute([$regel_id]);
        $pdo->prepare("UPDATE tabs SET totaal = (SELECT COALESCE(SUM(prijs*aantal),0) FROM tab_regels WHERE tab_id=?) WHERE id=?")
            ->execute([$tab_id, $tab_id]);
        jsonOk();

    case 'update_regel_aantal':
        $regel_id = (int)($_POST['regel_id'] ?? 0);
        $aantal   = (int)($_POST['aantal']   ?? 0);
        $tab_id   = (int)($_POST['tab_id']   ?? 0);
        if ($aantal <= 0) {
            $pdo->prepare("DELETE FROM tab_regels WHERE id = ?")->execute([$regel_id]);
        } else {
            $pdo->prepare("UPDATE tab_regels SET aantal = ? WHERE id = ?")->execute([$aantal, $regel_id]);
        }
        $pdo->prepare("UPDATE tabs SET totaal = (SELECT COALESCE(SUM(prijs*aantal),0) FROM tab_regels WHERE tab_id=?) WHERE id=?")
            ->execute([$tab_id, $tab_id]);
        jsonOk();

    case 'betaal_tab':
        $tab_id      = (int)($_POST['tab_id']     ?? 0);
        $betaalwijze = $_POST['betaalwijze'] ?? '';
        if (!in_array($betaalwijze, ['cash', 'payconiq'])) jsonErr('Ongeldige betaalwijze.');
        $pdo->prepare("UPDATE tabs SET betaald=1, gesloten=NOW(), betaalwijze=? WHERE id=?")
            ->execute([$betaalwijze, $tab_id]);
        jsonOk();

    case 'delete_tab':
        $tab_id = (int)($_POST['tab_id'] ?? 0);
        $pdo->prepare("DELETE FROM tab_regels WHERE tab_id = ?")->execute([$tab_id]);
        $pdo->prepare("DELETE FROM tabs WHERE id = ?")->execute([$tab_id]);
        jsonOk();

    // ── DRANKEN ────────────────────────────────────────────────────────────
    case 'get_dranken':
        $prijslijst_id = (int)($_GET['prijslijst_id'] ?? 1);
        $dranken = $pdo->prepare(
            "SELECT d.id, d.naam, d.categorie, d.volgorde, COALESCE(p.prijs, 0) AS prijs
             FROM dranken d
             LEFT JOIN prijzen p ON p.drank_id = d.id AND p.prijslijst_id = ?
             WHERE d.actief = 1
             ORDER BY d.categorie, d.volgorde, d.naam"
        );
        $dranken->execute([$prijslijst_id]);
        jsonOk(['dranken' => $dranken->fetchAll()]);

    case 'get_alle_dranken':
        $dranken = $pdo->query(
            "SELECT d.id, d.naam, d.categorie, d.actief, d.volgorde,
                    MAX(CASE WHEN p.prijslijst_id=1 THEN p.prijs END) AS prijs_training,
                    MAX(CASE WHEN p.prijslijst_id=2 THEN p.prijs END) AS prijs_event
             FROM dranken d
             LEFT JOIN prijzen p ON p.drank_id = d.id
             GROUP BY d.id ORDER BY d.categorie, d.volgorde, d.naam"
        )->fetchAll();
        jsonOk(['dranken' => $dranken]);

    case 'save_drank':
        $id        = (int)($_POST['id'] ?? 0);
        $naam      = trim($_POST['naam']      ?? '');
        $categorie = trim($_POST['categorie'] ?? 'Overig');
        $volgorde  = (int)($_POST['volgorde'] ?? 0);
        $actief    = (int)($_POST['actief']   ?? 1);
        $p1        = (float)($_POST['prijs_1'] ?? 0);
        $p2        = (float)($_POST['prijs_2'] ?? 0);
        if (!$naam) jsonErr('Naam is verplicht.');
        if ($id) {
            $pdo->prepare("UPDATE dranken SET naam=?, categorie=?, volgorde=?, actief=? WHERE id=?")
                ->execute([$naam, $categorie, $volgorde, $actief, $id]);
        } else {
            $pdo->prepare("INSERT INTO dranken (naam, categorie, volgorde, actief) VALUES (?,?,?,?)")
                ->execute([$naam, $categorie, $volgorde, $actief]);
            $id = $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO prijzen (drank_id, prijslijst_id, prijs) VALUES (?,1,?),(?,2,?)
                       ON DUPLICATE KEY UPDATE prijs=VALUES(prijs)")
            ->execute([$id, $p1, $id, $p2]);
        jsonOk(['id' => $id]);

    case 'delete_drank':
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE dranken SET actief = 0 WHERE id = ?")->execute([$id]);
        jsonOk();

    // ── RAPPORT ────────────────────────────────────────────────────────────
    case 'get_shift_rapport':
        $shift_id = (int)($_GET['shift_id'] ?? 0);
        if (!$shift_id) jsonErr('Geen shift ID.');
        $shift = $pdo->prepare(
            "SELECT s.*, p.naam AS prijslijst_naam FROM shifts s
             JOIN prijslijsten p ON p.id = s.prijslijst_id WHERE s.id = ?"
        );
        $shift->execute([$shift_id]);
        $shift = $shift->fetch();
        if (!$shift) jsonErr('Shift niet gevonden.');
        unset($shift['password_hash']);

        $verkoop = $pdo->prepare(
            "SELECT r.drank_naam, SUM(r.aantal) AS totaal_stuks,
                    SUM(r.prijs * r.aantal) AS totaal_bedrag
             FROM tab_regels r
             JOIN tabs t ON t.id = r.tab_id
             WHERE t.shift_id = ?
             GROUP BY r.drank_naam ORDER BY totaal_stuks DESC"
        );
        $verkoop->execute([$shift_id]);

        $financieel = $pdo->prepare(
            "SELECT betaalwijze, SUM(totaal) AS bedrag, COUNT(*) AS tabs
             FROM tabs WHERE shift_id = ? AND betaald = 1
             GROUP BY betaalwijze"
        );
        $financieel->execute([$shift_id]);

        jsonOk([
            'shift'      => $shift,
            'verkoop'    => $verkoop->fetchAll(),
            'financieel' => $financieel->fetchAll(),
        ]);

    case 'get_shifts_lijst':
        $shifts = $pdo->query(
            "SELECT s.id, s.verantwoordelijke, s.begintijd, s.eindtijd, s.gesloten,
                    p.naam AS prijslijst,
                    COALESCE(SUM(t.totaal),0) AS omzet,
                    COUNT(DISTINCT t.id) AS aantal_tabs
             FROM shifts s
             JOIN prijslijsten p ON p.id = s.prijslijst_id
             LEFT JOIN tabs t ON t.shift_id = s.id AND t.betaald = 1
             GROUP BY s.id ORDER BY s.begintijd DESC LIMIT 50"
        )->fetchAll();
        jsonOk(['shifts' => $shifts]);

    default:
        jsonErr('Onbekende actie: ' . htmlspecialchars($action));
}
