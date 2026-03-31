<?php
require_once __DIR__ . '/db.php';

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $cols = $stmt ? $stmt->fetchAll() : [];
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

function addColumnIfMissing(PDO $pdo, string $table, string $columnDef): void
{
    $columnName = preg_split('/\s+/', trim($columnDef))[0] ?? '';
    if ($columnName !== '' && !hasColumn($pdo, $table, $columnName)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $columnDef");
    }
}

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS employees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            department TEXT,
            job_title TEXT,
            phone TEXT,
            username TEXT UNIQUE,
            password_hash TEXT,
            role TEXT NOT NULL DEFAULT 'User',
            is_active INTEGER NOT NULL DEFAULT 1,
            salary REAL NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );"
    );

    addColumnIfMissing($pdo, 'employees', "department TEXT");
    addColumnIfMissing($pdo, 'employees', "job_title TEXT");
    addColumnIfMissing($pdo, 'employees', "phone TEXT");
    addColumnIfMissing($pdo, 'employees', "username TEXT");
    addColumnIfMissing($pdo, 'employees', "password_hash TEXT");
    addColumnIfMissing($pdo, 'employees', "role TEXT NOT NULL DEFAULT 'User'");
    addColumnIfMissing($pdo, 'employees', "is_active INTEGER NOT NULL DEFAULT 1");
    addColumnIfMissing($pdo, 'employees', "salary REAL NOT NULL DEFAULT 0");
    addColumnIfMissing($pdo, 'employees', "created_at TEXT NOT NULL DEFAULT (datetime('now'))");

    if (hasColumn($pdo, 'employees', 'position')) {
        $pdo->exec("UPDATE employees SET job_title = COALESCE(job_title, position) WHERE (job_title IS NULL OR job_title = '')");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('حاضر','غائب','اجازة')),
            note TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
        );"
    );

    // عدم التكرار لنفس الموظف في نفس اليوم
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS UQ_Att ON attendance(employee_id, date);");

    $attendanceDefStmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='attendance'");
    $attendanceDef = strtolower((string)($attendanceDefStmt ? $attendanceDefStmt->fetchColumn() : ''));
    if ($attendanceDefStmt) {
        $attendanceDefStmt->closeCursor();
    }
    if ($attendanceDef !== '' && strpos($attendanceDef, 'متأخر') !== false && strpos($attendanceDef, 'اجازة') === false) {
        $pdo->exec("ALTER TABLE attendance RENAME TO attendance_legacy");
        $pdo->exec(
            "CREATE TABLE attendance (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL,
                date TEXT NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('حاضر','غائب','اجازة')),
                note TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
            );"
        );
        $pdo->exec(
            "INSERT INTO attendance(id, employee_id, date, status, note, created_at)
             SELECT id, employee_id, date,
                    CASE WHEN status = 'متأخر' THEN 'حاضر' ELSE status END,
                    note,
                    COALESCE(created_at, datetime('now'))
             FROM attendance_legacy"
        );
        $pdo->exec("DROP TABLE attendance_legacy");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS UQ_Att ON attendance(employee_id, date)");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS salaries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            month INTEGER NOT NULL,
            year INTEGER NOT NULL,
            base_salary REAL NOT NULL,
            deductions REAL NOT NULL,
            loans REAL NOT NULL,
            bonuses REAL NOT NULL,
            additions REAL NOT NULL,
            net_salary REAL NOT NULL,
            absence_days INTEGER NOT NULL DEFAULT 0,
            leave_days INTEGER NOT NULL DEFAULT 0,
            daily_salary REAL NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
        );"
    );

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS UQ_Salary_Period ON salaries(employee_id, month, year)");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS salary_adjustments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            month INTEGER NOT NULL,
            year INTEGER NOT NULL,
            type TEXT NOT NULL CHECK(type IN ('loan','deduction','bonus','addition')),
            amount REAL NOT NULL,
            note TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS restaurants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        );"
    );

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS UQ_Restaurant_Name ON restaurants(name)");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dailyfinance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            restaurant_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            sales REAL NOT NULL DEFAULT 0,
            expenses REAL NOT NULL DEFAULT 0,
            loans REAL NOT NULL DEFAULT 0,
            external_expenses REAL NOT NULL DEFAULT 0,
            net_profit REAL NOT NULL DEFAULT 0,
            FOREIGN KEY(restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
        );"
    );

    $pdo->exec("CREATE INDEX IF NOT EXISTS IX_DailyFinance_Date ON dailyfinance(date)");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS general_expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT NOT NULL,
            category TEXT NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            note TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS debts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            paid REAL NOT NULL DEFAULT 0,
            date TEXT NOT NULL,
            notes TEXT,
            status TEXT NOT NULL DEFAULT 'open'
        );"
    );

    addColumnIfMissing($pdo, 'debts', "status TEXT NOT NULL DEFAULT 'open'");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS debt_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            debt_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            payment_date TEXT NOT NULL,
            note TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY(debt_id) REFERENCES debts(id) ON DELETE CASCADE
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_name TEXT,
            admin_user TEXT,
            admin_pass_hash TEXT,
            whatsapp_enabled INTEGER DEFAULT 0,
            auto_backup_enabled INTEGER DEFAULT 1,
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );"
    );

    addColumnIfMissing($pdo, 'settings', "admin_pass_hash TEXT");
    addColumnIfMissing($pdo, 'settings', "auto_backup_enabled INTEGER DEFAULT 1");
    addColumnIfMissing($pdo, 'settings', "updated_at TEXT NOT NULL DEFAULT (datetime('now'))");

    if (hasColumn($pdo, 'settings', 'admin_pass') && hasColumn($pdo, 'settings', 'admin_pass_hash')) {
        $rows = $pdo->query("SELECT id, admin_pass, admin_pass_hash FROM settings")->fetchAll();
        $up = $pdo->prepare("UPDATE settings SET admin_pass_hash = :h WHERE id = :id");
        foreach ($rows as $r) {
            $legacy = (string)($r['admin_pass'] ?? '');
            $hashed = (string)($r['admin_pass_hash'] ?? '');
            if ($hashed === '' && $legacy !== '') {
                $up->execute(['h' => password_hash($legacy, PASSWORD_DEFAULT), 'id' => $r['id']]);
            }
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER,
            message TEXT,
            date TEXT NOT NULL DEFAULT (datetime('now')),
            seen INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS archive (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            month INTEGER NOT NULL,
            year INTEGER NOT NULL,
            data TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS collection_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entry_date TEXT NOT NULL,
            entry_day TEXT DEFAULT '',
            entry_month INTEGER NOT NULL,
            entry_year INTEGER NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            collection_name TEXT NOT NULL DEFAULT '',
            notes TEXT DEFAULT '',
            entry_type TEXT NOT NULL CHECK (entry_type IN ('collect','withdraw')),
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );"
    );

    $pdo->exec("CREATE INDEX IF NOT EXISTS IX_CollectionEntries_Period ON collection_entries(entry_year, entry_month, entry_date, id)");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS collection_month_state (
            id INTEGER PRIMARY KEY CHECK(id = 1),
            month INTEGER NOT NULL,
            year INTEGER NOT NULL,
            work_date TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS collection_month_archives (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            month INTEGER NOT NULL,
            year INTEGER NOT NULL,
            total_collect REAL NOT NULL DEFAULT 0,
            total_withdraw REAL NOT NULL DEFAULT 0,
            closing_balance REAL NOT NULL DEFAULT 0,
            snapshot_json TEXT NOT NULL DEFAULT '[]',
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(month, year)
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS collection_sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE COLLATE NOCASE,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );"
    );

    $insertCollectionSectionStmt = $pdo->prepare("INSERT OR IGNORE INTO collection_sections(name) VALUES (:name)");
    foreach (['جمع عام'] as $collectionSectionName) {
        $insertCollectionSectionStmt->execute(['name' => $collectionSectionName]);
    }

    $pdo->exec(
        'INSERT OR IGNORE INTO collection_month_state(id, month, year, work_date) VALUES (1, '
        . (int)date('n') . ', ' . (int)date('Y') . ', ' . $pdo->quote(date('Y-m-d')) . ')'
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS backup_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            backup_path TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );"
    );

    $insRestaurant = $pdo->prepare("INSERT OR IGNORE INTO restaurants(name) VALUES (:name)");
    $insRestaurant->execute(['name' => 'مطعم القلعة']);
    $insRestaurant->execute(['name' => 'مطعم حجاية']);

    $settingsCount = (int)$pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($settingsCount === 0) {
        $stmt = $pdo->prepare("INSERT INTO settings(site_name, admin_user, admin_pass_hash, whatsapp_enabled, auto_backup_enabled) VALUES (:site, :user, :pass, 0, 1)");
        $stmt->execute([
            'site' => 'AYOB قائمة الحضور',
            'user' => 'admin',
            'pass' => password_hash('admin123', PASSWORD_DEFAULT),
        ]);
    }

    $adminExists = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE username = 'admin'")->fetchColumn();
    if ($adminExists === 0) {
        if (hasColumn($pdo, 'employees', 'position')) {
            $stmt = $pdo->prepare(
                "INSERT INTO employees(name, position, department, job_title, phone, username, password_hash, role, salary, is_active)
                 VALUES (:name, :position, :dept, :job, :phone, :user, :pass, :role, :salary, 1)"
            );
            $stmt->execute([
                'name' => 'System Admin',
                'position' => 'Administrator',
                'dept' => 'Administration',
                'job' => 'Administrator',
                'phone' => '',
                'user' => 'admin',
                'pass' => password_hash('admin123', PASSWORD_DEFAULT),
                'role' => 'Admin',
                'salary' => 0,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO employees(name, department, job_title, phone, username, password_hash, role, salary, is_active)
                 VALUES (:name, :dept, :job, :phone, :user, :pass, :role, :salary, 1)"
            );
            $stmt->execute([
                'name' => 'System Admin',
                'dept' => 'Administration',
                'job' => 'Administrator',
                'phone' => '',
                'user' => 'admin',
                'pass' => password_hash('admin123', PASSWORD_DEFAULT),
                'role' => 'Admin',
                'salary' => 0,
            ]);
        }
    }

        echo '<p style="font-family: Arial, sans-serif; margin: 20px;">تمت التهيئة والترقية بنجاح. <a href="index.php">اذهب إلى النظام</a>.</p>';
} catch (PDOException $e) {
    die('فشل إنشاء الجداول: ' . htmlspecialchars($e->getMessage()));
}
