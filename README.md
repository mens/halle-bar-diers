# Kantine POS — Installatie & Handleiding

## Vereisten

- Linux (Ubuntu/Debian aanbevolen)
- PHP 8.0+ met PDO/MySQL extensie
- MariaDB 10.5+
- Webserver (Apache of Nginx)

---

## 1. Database aanmaken

```sql
CREATE DATABASE kantine_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kantine'@'localhost' IDENTIFIED BY 'VERANDER_DIT_WACHTWOORD';
GRANT ALL PRIVILEGES ON kantine_pos.* TO 'kantine'@'localhost';
FLUSH PRIVILEGES;
```

---

## 2. Configuratie

Pas `db.php` aan met jouw gegevens:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'kantine_pos');
define('DB_USER', 'kantine');
define('DB_PASS', 'VERANDER_DIT_WACHTWOORD');
```

---

## 3. Bestanden uploaden

Kopieer alle bestanden naar je webroot, bv.:

```bash
/var/www/html/kantine/
```

Of in een Apache VirtualHost naar keuze.

---

## 4. Installatie uitvoeren

Surf naar:

```
https://jouwserver/kantine/install.php
```

Dit maakt alle tabellen aan en laadt voorbeelddranken in.
**Verwijder of hernoem `install.php` nadien!**

```bash
rm /var/www/html/kantine/install.php
```

---

## 5. Apache configuratie (voorbeeld)

```apache
<VirtualHost *:80>
    ServerName kantine.lokaal
    DocumentRoot /var/www/html/kantine
    <Directory /var/www/html/kantine>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

---

## Gebruik

### Kassa (`index.php`)
1. Start een shift: kies de verantwoordelijke en de prijslijst (Training of Evenement)
2. Maak tabs aan per persoon/tafel
3. Klik op een drank om ze toe te voegen aan de actieve tab
4. Pas aantallen aan met + / −
5. Betaal de tab: kies Cash of Payconiq
6. Sluit de shift af (met optionele opmerking)

### Beheer (`admin.php`)
- Voeg dranken toe met naam, categorie, volgorde en prijzen per prijslijst
- Bewerk bestaande dranken
- Deactiveer dranken (worden niet getoond in de kassa)

### Rapporten (`rapport.php`)
- Overzicht van alle shifts
- Per shift: omzet per betaalwijze (cash / Payconiq), verkoop per drank, opmerkingen

---

## Bestandsstructuur

```
kantine/
├── index.php        Kassa interface
├── admin.php        Drankenbeheer
├── rapport.php      Shift rapporten
├── api.php          AJAX backend
├── db.php           Databaseverbinding
├── install.php      Eenmalige installatie (daarna verwijderen)
├── css/
│   └── pos.css      Stylesheet
└── js/
    └── pos.js       Frontend logica
```

---

## Databaseschema

- **prijslijsten** — Training / Evenement
- **dranken** — Productcatalogus
- **prijzen** — Prijs per drank per prijslijst
- **shifts** — Diensten met verantwoordelijke en prijslijst
- **tabs** — Rekeningen per persoon/tafel per shift
- **tab_regels** — Individuele bestellingen per tab
