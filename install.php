<?php
// install.php — Eenmalige installatie van de database
require_once 'db.php';

$queries = [
    "CREATE TABLE IF NOT EXISTS prijslijsten (
        id INT AUTO_INCREMENT PRIMARY KEY,
        naam VARCHAR(100) NOT NULL,
        actief TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",

    "INSERT IGNORE INTO prijslijsten (id, naam) VALUES (1, 'Training'), (2, 'Evenement')",

    "CREATE TABLE IF NOT EXISTS dranken (
        id INT AUTO_INCREMENT PRIMARY KEY,
        naam VARCHAR(150) NOT NULL,
        categorie VARCHAR(100) DEFAULT 'Overig',
        actief TINYINT(1) DEFAULT 1,
        volgorde INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS prijzen (
        id INT AUTO_INCREMENT PRIMARY KEY,
        drank_id INT NOT NULL,
        prijslijst_id INT NOT NULL,
        prijs DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        UNIQUE KEY uniq_drank_lijst (drank_id, prijslijst_id),
        FOREIGN KEY (drank_id) REFERENCES dranken(id) ON DELETE CASCADE,
        FOREIGN KEY (prijslijst_id) REFERENCES prijslijsten(id) ON DELETE CASCADE
    )",

    "CREATE TABLE IF NOT EXISTS shifts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        verantwoordelijke VARCHAR(150) NOT NULL,
        prijslijst_id INT NOT NULL,
        begintijd DATETIME DEFAULT CURRENT_TIMESTAMP,
        eindtijd DATETIME NULL,
        opmerking TEXT NULL,
        password_hash VARCHAR(255) NULL,
        gesloten TINYINT(1) DEFAULT 0,
        FOREIGN KEY (prijslijst_id) REFERENCES prijslijsten(id)
    )",

    "CREATE TABLE IF NOT EXISTS tabs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shift_id INT NOT NULL,
        naam VARCHAR(150) NOT NULL,
        geopend DATETIME DEFAULT CURRENT_TIMESTAMP,
        gesloten DATETIME NULL,
        betaald TINYINT(1) DEFAULT 0,
        betaalwijze ENUM('cash','payconiq') NULL,
        totaal DECIMAL(8,2) DEFAULT 0.00,
        FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE
    )",

    "CREATE TABLE IF NOT EXISTS tab_regels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tab_id INT NOT NULL,
        drank_id INT NOT NULL,
        drank_naam VARCHAR(150) NOT NULL,
        prijs DECIMAL(6,2) NOT NULL,
        aantal INT NOT NULL DEFAULT 1,
        tijdstip DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tab_id) REFERENCES tabs(id) ON DELETE CASCADE
    )",

    "CREATE TABLE IF NOT EXISTS user_roles (
        email      VARCHAR(255) NOT NULL PRIMARY KEY,
        role       ENUM('read','write') NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
];

$errors = [];
foreach ($queries as $q) {
    if (!$pdo->exec($q) && $pdo->errorCode() !== '00000') {
        $errors[] = $pdo->errorInfo()[2];
    }
}

// Sample drinks
$samples = [
    ['Chips/Nootjes', 'Snack', 1, 1.50, 2.00],
    ['Koffie / Thee', 'Warme dranken', 2, 1.50, 2.00],
    ['Jupiler', 'Bier', 3, 1.80, 2.5],
    ['Kriek', 'Bier', 4, 2.50, 3],
    ['Kriek 0.0', 'Bier NA', 5, 2.5, 3.5],
    ['Brugse Zot', 'Bier', 6, 2.5, 3.5],
    ['Sportzot', 'Bier NA', 7, 2.5, 3.5],
    ['Omer', 'Bier', 8, 2.5, 3.5],
    ['Duvel', 'Bier', 9, 3, 3.5],
    ['Chimay', 'Bier', 10, 3, 3.5],
    ['Plugstreet', 'Bier', 11, 3, 3.5],
    ['Cola', 'Frisdrank', 12, 1.50, 2.00],
    ['Cola Zero', 'Frisdrank', 13, 1.50, 2.00],
    ['Fanta', 'Frisdrank', 14, 1.50, 2.00],
    ['Water', 'Frisdrank', 15, 1.50, 2.00],
    ['Ice-Tea', 'Frisdrank', 16, 2.00, 2.50],
    ['Aquarius', 'Frisdrank', 17, 2.00, 2.50],
    ['Glas Witte Wijn', 'Wijn', 18, 3.00, 4.00],
    ['Glas Rode Wijn', 'Wijn', 19, 3.00, 4.00],
    ['Fles Witte Wijn', 'Wijn', 20, 15.00, 20.00],
    ['Fles Rode Wijn', 'Wijn', 21, 15.00, 20.00],
    ['Fles Cava', 'Wijn', 22, 18.00, 20.00],
    ['Fles Champagne', 'Wijn', 22, 25.00, 35.00],
];

foreach ($samples as [$naam, $cat, $volgorde, $p_training, $p_event]) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO dranken (naam, categorie, volgorde) VALUES (?, ?, ?)");
    $stmt->execute([$naam, $cat, $volgorde]);
    $drank_id = $pdo->lastInsertId();
    if ($drank_id) {
        $pdo->prepare("INSERT IGNORE INTO prijzen (drank_id, prijslijst_id, prijs) VALUES (?, 1, ?), (?, 2, ?)")
            ->execute([$drank_id, $p_training, $drank_id, $p_event]);
    }
}

?>
<!DOCTYPE html>
<html lang="nl">
<head><meta charset="UTF-8"><title>Installatie</title>
<style>body{font-family:sans-serif;max-width:600px;margin:40px auto;padding:20px;}</style>
</head>
<body>
<h2>🍺 Kantine POS — Installatie</h2>
<?php if (empty($errors)): ?>
<p style="color:green;font-weight:bold;">✅ Database succesvol aangemaakt! Voorbeelddranken geladen.</p>
<p><a href="index.php">→ Naar de kassa</a> | <a href="admin.php">→ Beheer Prijslijsten</a></p>
<?php else: ?>
<p style="color:red;">❌ Fouten:</p>
<ul><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
</body>
</html>
