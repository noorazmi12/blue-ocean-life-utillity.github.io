<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$DB_HOST = getenv('SMART_KJA_DB_HOST') ?: 'localhost';
$DB_PORT = getenv('SMART_KJA_DB_PORT') ?: '3306';
$DB_NAME = getenv('SMART_KJA_DB_NAME') ?: 'smart_kja_owc';
$DB_USER = getenv('SMART_KJA_DB_USER') ?: 'root';
$DB_PASS = getenv('SMART_KJA_DB_PASS');
if ($DB_PASS === false) {
    $DB_PASS = '';
}

function db_init_sqlite(PDO $pdo): void
{
    $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction('CURDATE', static fn (): string => date('Y-m-d'), 0);

    $hasUsers = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if ($hasUsers) {
        $cols = $pdo->query('PRAGMA table_info(water_measurements)')->fetchAll();
        $hasVoltPh = false;
        foreach ($cols as $c) {
            if (($c['name'] ?? '') === 'volt_ph_v') {
                $hasVoltPh = true;
                break;
            }
        }
        if (!$hasVoltPh) {
            $pdo->exec('ALTER TABLE water_measurements ADD COLUMN volt_ph_v REAL NULL');
            $pdo->exec('UPDATE water_measurements SET volt_ph_v = ROUND(1.5 + (7.0 - ph) * 0.17, 3) WHERE ph IS NOT NULL AND volt_ph_v IS NULL');
        }
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            full_name TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'viewer',
            is_active INTEGER NOT NULL DEFAULT 1,
            last_login TEXT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_code TEXT NOT NULL UNIQUE,
            device_name TEXT NOT NULL,
            device_type TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'offline',
            is_active INTEGER NOT NULL DEFAULT 1,
            api_token TEXT NULL,
            last_communication TEXT NULL,
            calibration_status TEXT NOT NULL DEFAULT 'none',
            calibration_date TEXT NULL,
            notes TEXT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS device_locations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id INTEGER NOT NULL,
            latitude REAL NOT NULL,
            longitude REAL NOT NULL,
            location_name TEXT NULL,
            installed_at TEXT NULL,
            notes TEXT NULL,
            updated_at TEXT NULL
        );
        CREATE TABLE IF NOT EXISTS thresholds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parameter TEXT NOT NULL,
            species TEXT NOT NULL DEFAULT 'Umum',
            min_value REAL NULL,
            max_value REAL NULL,
            warn_span REAL NULL,
            unit TEXT NULL,
            updated_by INTEGER NULL,
            updated_at TEXT NULL
        );
        CREATE TABLE IF NOT EXISTS system_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key TEXT NOT NULL UNIQUE,
            setting_value TEXT NULL,
            description TEXT NULL,
            updated_at TEXT NULL
        );
        CREATE TABLE IF NOT EXISTS fish_species (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            latin_name TEXT NULL,
            category TEXT NOT NULL DEFAULT 'laut',
            cycle_months_min INTEGER NULL,
            cycle_months_max INTEGER NULL,
            target_weight_kg REAL NULL,
            lw_a REAL NOT NULL DEFAULT 0.01060,
            lw_b REAL NOT NULL DEFAULT 3.050,
            optimal_temp_min REAL NULL,
            optimal_temp_max REAL NULL,
            optimal_ph_min REAL NULL,
            optimal_ph_max REAL NULL,
            optimal_salinity_min REAL NULL,
            optimal_salinity_max REAL NULL,
            description TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NULL
        );
        CREATE TABLE IF NOT EXISTS kja_sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            site_name TEXT NOT NULL,
            kabupaten TEXT NOT NULL,
            kecamatan TEXT NULL,
            latitude REAL NOT NULL,
            longitude REAL NOT NULL,
            depth_m REAL NULL,
            depth_source TEXT NULL,
            shelter TEXT NOT NULL DEFAULT 'semi',
            access_note TEXT NULL,
            recommended_species TEXT NULL,
            score_depth INTEGER NOT NULL DEFAULT 0,
            score_shelter INTEGER NOT NULL DEFAULT 0,
            score_access INTEGER NOT NULL DEFAULT 0,
            score_conflict INTEGER NOT NULL DEFAULT 0,
            suitability_score INTEGER NOT NULL DEFAULT 0,
            suitability_class TEXT NOT NULL DEFAULT 'S2',
            constraints_note TEXT NULL,
            notes TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NULL
        );
        CREATE TABLE IF NOT EXISTS water_measurements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id INTEGER NOT NULL,
            measured_at TEXT NOT NULL,
            temp_sensor_c REAL NULL,
            sst_c REAL NULL,
            salinity_psu REAL NULL,
            ph REAL NULL,
            volt_ph_v REAL NULL,
            turbidity_ntu REAL NULL,
            depth_m REAL NULL,
            is_simulated INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS owc_measurements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id INTEGER NOT NULL,
            measured_at TEXT NOT NULL,
            power_w REAL NOT NULL DEFAULT 0,
            voltage_v REAL NOT NULL DEFAULT 0,
            current_a REAL NOT NULL DEFAULT 0,
            energy_wh REAL NOT NULL DEFAULT 0,
            battery_pct REAL NULL,
            is_simulated INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS wave_measurements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id INTEGER NOT NULL,
            measured_at TEXT NOT NULL,
            wave_height_m REAL NOT NULL DEFAULT 0,
            wave_period_s REAL NOT NULL DEFAULT 0,
            wave_direction_deg INTEGER NULL,
            wind_speed_ms REAL NULL,
            wind_direction_deg INTEGER NULL,
            is_simulated INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS fishcount_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id INTEGER NULL,
            image_path TEXT NULL,
            media_type TEXT NOT NULL DEFAULT 'image',
            media_duration_s REAL NULL,
            frames_analyzed INTEGER NULL,
            thumb_path TEXT NULL,
            detected_count INTEGER NULL,
            avg_length_cm REAL NULL,
            biomass_kg REAL NULL,
            confidence_pct REAL NULL,
            processing_status TEXT NOT NULL DEFAULT 'pending',
            status_message TEXT NULL,
            is_simulated INTEGER NOT NULL DEFAULT 0,
            is_manual INTEGER NOT NULL DEFAULT 0,
            manual_count INTEGER NULL,
            validated_by INTEGER NULL,
            validated_at TEXT NULL,
            notes TEXT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id INTEGER NULL,
            parameter TEXT NOT NULL,
            severity TEXT NOT NULL DEFAULT 'warning',
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            actual_value REAL NULL,
            threshold_value REAL NULL,
            occurred_at TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            resolved_by INTEGER NULL,
            resolved_at TEXT NULL,
            is_simulated INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_water_measured ON water_measurements (measured_at);
        CREATE INDEX IF NOT EXISTS idx_owc_measured ON owc_measurements (measured_at);
        CREATE INDEX IF NOT EXISTS idx_wave_measured ON wave_measurements (measured_at);
    ");

    $seedFile = getenv('SMART_KJA_SEED_JSON') ?: (__DIR__ . '/../src/data/seedSql.json');
    if (!is_file($seedFile)) {
        return;
    }
    $seed = json_decode((string)file_get_contents($seedFile), true);
    if (!is_array($seed)) {
        return;
    }

    $maxTs = 0;
    foreach (['water_measurements', 'owc_measurements', 'wave_measurements'] as $tbl) {
        foreach (($seed[$tbl] ?? []) as $row) {
            if (!empty($row['measured_at'])) {
                $ts = strtotime((string)$row['measured_at']);
                if ($ts && $ts > $maxTs) {
                    $maxTs = $ts;
                }
            }
        }
    }
    $delta = $maxTs > 0 ? (time() - 120 - $maxTs) : 0;
    $shift = static function (?string $dt) use ($delta): ?string {
        if (!$dt) {
            return null;
        }
        $ts = strtotime($dt);
        return $ts ? date('Y-m-d H:i:s', $ts + $delta) : $dt;
    };

    $defaultPw = [
        'admin' => 'Admin@123',
        'operator' => 'Operator@123',
        'viewer' => 'Viewer@123',
    ];

    $pdo->beginTransaction();
    try {
        foreach (($seed['users'] ?? []) as $u) {
            $uname = (string)$u['username'];
            $u['password_hash'] = password_hash($defaultPw[$uname] ?? 'Bolu@12345', PASSWORD_BCRYPT);
            $u['last_login'] = $shift($u['last_login'] ?? null);
            $u['created_at'] = $shift($u['created_at'] ?? null) ?? date('Y-m-d H:i:s');
            $cols = array_keys($u);
            $sql = 'INSERT INTO users (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $pdo->prepare($sql)->execute(array_values($u));
        }

        $dateColsMap = [
            'devices' => ['last_communication', 'created_at'],
            'device_locations' => ['updated_at'],
            'thresholds' => ['updated_at'],
            'fish_species' => ['created_at', 'updated_at'],
            'water_measurements' => ['measured_at', 'created_at'],
            'owc_measurements' => ['measured_at', 'created_at'],
            'wave_measurements' => ['measured_at', 'created_at'],
            'fishcount_results' => ['validated_at', 'created_at'],
            'alerts' => ['occurred_at', 'resolved_at', 'created_at'],
        ];

        $tables = [
            'devices', 'device_locations', 'thresholds', 'system_settings',
            'fish_species', 'kja_sites', 'water_measurements',
            'owc_measurements', 'wave_measurements', 'fishcount_results', 'alerts',
        ];
        foreach ($tables as $tbl) {
            $rows = $seed[$tbl] ?? [];
            if (!$rows) {
                continue;
            }
            $cols = array_keys($rows[0]);
            $sql = "INSERT INTO {$tbl} (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $stmt = $pdo->prepare($sql);
            $dateCols = $dateColsMap[$tbl] ?? [];
            foreach ($rows as $row) {
                foreach ($dateCols as $dc) {
                    if (array_key_exists($dc, $row) && $row[$dc] !== null) {
                        $row[$dc] = $shift((string)$row[$dc]);
                    }
                }
                $stmt->execute(array_values($row));
            }
        }
        $pdo->exec('UPDATE water_measurements SET volt_ph_v = ROUND(1.5 + (7.0 - ph) * 0.17, 3) WHERE ph IS NOT NULL AND volt_ph_v IS NULL');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = getenv('SMART_KJA_DB_DRIVER') ?: '';
    if ($driver === 'sqlite') {
        $sqlitePath = getenv('SMART_KJA_SQLITE_PATH') ?: '/tmp/bolu_php.sqlite';
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        db_init_sqlite($pdo);
        return $pdo;
    }

    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $DB_HOST, $DB_PORT, $DB_NAME);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    return $pdo;
}
