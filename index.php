<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function toFloat($value): float
{
    return round((float)$value, 2);
}

function normalizeWhatsAppPhone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', trim($phone));
    if ($digits === null) {
        return '';
    }
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    if (str_starts_with($digits, '0')) {
        $digits = '964' . substr($digits, 1);
    }
    return $digits;
}

function buildWhatsAppUrl(string $phone, string $message): string
{
    $normalized = normalizeWhatsAppPhone($phone);
    if ($normalized === '') {
        return '';
    }
    return 'https://wa.me/' . $normalized . '?text=' . rawurlencode($message);
}

function isValidIsoDate(string $date): bool
{
    $ts = strtotime($date);
    return $ts !== false && date('Y-m-d', $ts) === $date;
}

function arabicWeekdayName(string $date): string
{
    static $days = [
        'Sunday' => 'الأحد',
        'Monday' => 'الإثنين',
        'Tuesday' => 'الثلاثاء',
        'Wednesday' => 'الأربعاء',
        'Thursday' => 'الخميس',
        'Friday' => 'الجمعة',
        'Saturday' => 'السبت',
    ];
    $ts = strtotime($date);
    if ($ts === false) {
        return '';
    }
    $en = date('l', $ts);
    return $days[$en] ?? $en;
}

function nextDateSameMonth(string $date): string
{
    $ts = strtotime($date);
    if ($ts === false) {
        return date('Y-m-d');
    }
    $nextTs = strtotime('+1 day', $ts);
    if ($nextTs === false) {
        return $date;
    }
    return date('Y-m-d', $nextTs);
}

function nextMonthPeriod(int $month, int $year): array
{
    if ($month === 12) {
        return ['month' => 1, 'year' => $year + 1];
    }
    return ['month' => $month + 1, 'year' => $year];
}

function payrollPeriodKey(int $month, int $year): string
{
    return $year . '-' . sprintf('%02d', $month);
}

function collectionMonthHasEntries(PDO $pdo, int $month, int $year): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM collection_entries WHERE entry_month = :month AND entry_year = :year');
    $stmt->execute(['month' => $month, 'year' => $year]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    // SQLite does not support binding identifiers, so validate before building PRAGMA query.
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
        return false;
    }

    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    if ($stmt === false) {
        return false;
    }

    foreach ($stmt->fetchAll() as $col) {
        if (isset($col['name']) && strcasecmp((string)$col['name'], $column) === 0) {
            return true;
        }
    }

    return false;
}

function redirectSelf(string $page = '', array $params = []): void
{
    $target = 'index.php';
    $query = [];

    if ($page !== '') {
        $query['page'] = $page;
    }

    foreach ($params as $key => $value) {
        if (!is_scalar($value) && $value !== null) {
            continue;
        }
        $query[(string)$key] = $value;
    }

    if (!empty($query)) {
        $target .= '?' . http_build_query($query);
    }

    header('Location: ' . $target);
    exit;
}

function jsonResponse(bool $ok, string $message, array $extra = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array_merge([
        'ok' => $ok,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function registerEmployeeDepartment(PDO $pdo, string $department): void
{
    $name = trim($department);
    if ($name === '') {
        return;
    }

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO employee_departments(name) VALUES (:name)');
    $stmt->execute(['name' => $name]);
}

function registerExpenseCategory(PDO $pdo, string $category): void
{
    $name = trim($category);
    if ($name === '') {
        return;
    }

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO expense_categories(name) VALUES (:name)');
    $stmt->execute(['name' => $name]);
}

/**
 * Send a UTF-8 CSV file with BOM to the browser.
 * @param string   $filename    Suggested download filename
 * @param string[] $headers     Column header labels
 * @param array[]  $rows        2-D array of row values
 */
function csvExport(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel recognises the encoding
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}

/**
 * Send a SpreadsheetML (Excel XML / .xls) file to the browser with full
 * Arabic RTL support.  No external library required.
 *
 * @param string   $filename    Suggested download filename (should end in .xls)
 * @param string[] $headers     Column header labels (Arabic supported)
 * @param array[]  $rows        2-D array of row values
 * @param string   $sheetName   Worksheet tab label
 * @param bool     $rtl         Enable right-to-left worksheet direction
 */
function excelTableExport(string $filename, array $headers, array $rows, string $sheetName = 'Sheet1', bool $rtl = true): void
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $esc = static function (string $v): string {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };

    $cellType = static function ($val): string {
        return is_numeric($val) ? 'Number' : 'String';
    };

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title><?= $esc($sheetName) ?></Title>
  <Author>Ayob System</Author>
 </DocumentProperties>
 <Styles>
  <Style ss:ID="sHdr">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" <?= $rtl ? 'ss:ReadingOrder="RightToLeft"' : '' ?>/>
   <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="11"/>
   <Interior ss:Color="#1e40af" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>
   </Borders>
  </Style>
  <Style ss:ID="sNum">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" <?= $rtl ? 'ss:ReadingOrder="RightToLeft"' : '' ?>/>
   <NumberFormat ss:Format="#,##0.00"/>
  </Style>
  <Style ss:ID="sTxt">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center" <?= $rtl ? 'ss:ReadingOrder="RightToLeft"' : '' ?>/>
  </Style>
  <Style ss:ID="sCtr">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" <?= $rtl ? 'ss:ReadingOrder="RightToLeft"' : '' ?>/>
  </Style>
 </Styles>
 <Worksheet ss:Name="<?= $esc($sheetName) ?>">
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <?= $rtl ? '<DisplayRightToLeft/>' : '' ?>
   <FreezePanes/>
   <FrozenNoSplit/>
   <SplitHorizontal>1</SplitHorizontal>
   <TopRowBottomPane>1</TopRowBottomPane>
   <ActivePane>2</ActivePane>
  </WorksheetOptions>
  <Table>
<?php
    // Header row
    echo '   <Row ss:Height="20">' . "\n";
    foreach ($headers as $h) {
        echo '    <Cell ss:StyleID="sHdr"><Data ss:Type="String">' . $esc((string)$h) . '</Data></Cell>' . "\n";
    }
    echo '   </Row>' . "\n";

    // Data rows
    foreach ($rows as $row) {
        echo '   <Row>' . "\n";
        foreach (array_values($row) as $val) {
            $type  = $cellType($val);
            $style = ($type === 'Number') ? 'sNum' : 'sTxt';
            echo '    <Cell ss:StyleID="' . $style . '"><Data ss:Type="' . $type . '">' . $esc((string)($val ?? '')) . '</Data></Cell>' . "\n";
        }
        echo '   </Row>' . "\n";
    }
?>
  </Table>
 </Worksheet>
</Workbook>
<?php
    exit;
}

function pageUrl(string $page = '', array $params = []): string
{
    $target = 'index.php';
    $query = [];

    if ($page !== '') {
        $query['page'] = $page;
    }

    foreach ($params as $key => $value) {
        if (!is_scalar($value) && $value !== null) {
            continue;
        }
        $query[(string)$key] = $value;
    }

    if (!empty($query)) {
        return $target . '?' . http_build_query($query);
    }

    return $target;
}

function setFlashMessage(string $type, string $text): void
{
    if ($type !== 'message' && $type !== 'error') {
        return;
    }
    $_SESSION['flash_' . $type] = $text;
}

function payrollMonthHasSavedWork(PDO $pdo, int $month, int $year): bool
{
    $m = sprintf('%02d', $month);
    $y = (string)$year;

    $checks = [
        ['sql' => 'SELECT COUNT(*) FROM salaries WHERE month = :month AND year = :year', 'params' => ['month' => $month, 'year' => $year]],
        ['sql' => 'SELECT COUNT(*) FROM salary_adjustments WHERE month = :month AND year = :year', 'params' => ['month' => $month, 'year' => $year]],
        ['sql' => 'SELECT COUNT(*) FROM employee_daily_entries WHERE strftime("%m", date) = :m AND strftime("%Y", date) = :y', 'params' => ['m' => $m, 'y' => $y]],
        ['sql' => 'SELECT COUNT(*) FROM attendance WHERE strftime("%m", date) = :m AND strftime("%Y", date) = :y', 'params' => ['m' => $m, 'y' => $y]],
    ];

    foreach ($checks as $check) {
        $stmt = $pdo->prepare($check['sql']);
        $stmt->execute($check['params']);
        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }
    }

    return false;
}

function clearPayrollMonthData(PDO $pdo, int $month, int $year): array
{
    if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
        return [
            'salaries' => 0,
            'salary_adjustments' => 0,
            'salary_archives' => 0,
            'employee_daily_entries' => 0,
            'attendance' => 0,
        ];
    }

    $m = sprintf('%02d', $month);
    $y = (string)$year;
    $deleted = [];

    $salaryStmt = $pdo->prepare('DELETE FROM salaries WHERE month = :month AND year = :year');
    $salaryStmt->execute(['month' => $month, 'year' => $year]);
    $deleted['salaries'] = $salaryStmt->rowCount();

    $adjustmentStmt = $pdo->prepare('DELETE FROM salary_adjustments WHERE month = :month AND year = :year');
    $adjustmentStmt->execute(['month' => $month, 'year' => $year]);
    $deleted['salary_adjustments'] = $adjustmentStmt->rowCount();

    $archiveStmt = $pdo->prepare('DELETE FROM salary_archives WHERE month = :month AND year = :year');
    $archiveStmt->execute(['month' => $month, 'year' => $year]);
    $deleted['salary_archives'] = $archiveStmt->rowCount();

    $dailyEntriesStmt = $pdo->prepare('DELETE FROM employee_daily_entries WHERE strftime("%m", date) = :m AND strftime("%Y", date) = :y');
    $dailyEntriesStmt->execute(['m' => $m, 'y' => $y]);
    $deleted['employee_daily_entries'] = $dailyEntriesStmt->rowCount();

    $attendanceStmt = $pdo->prepare('DELETE FROM attendance WHERE strftime("%m", date) = :m AND strftime("%Y", date) = :y');
    $attendanceStmt->execute(['m' => $m, 'y' => $y]);
    $deleted['attendance'] = $attendanceStmt->rowCount();

    return $deleted;
}

function normalizeHexColor(string $color, string $fallback = '#2563eb'): string
{
    $c = trim($color);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
        return strtolower($c);
    }
    return $fallback;
}

function attendanceStatusSupportsTermination(PDO $pdo): bool
{
    $schemaStmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'attendance' LIMIT 1");
    $schemaStmt->execute();
    $attendanceSql = (string)($schemaStmt->fetchColumn() ?: '');
    return $attendanceSql !== '' && strpos($attendanceSql, 'انهاء خدمات') !== false;
}

function migrateAttendanceStatusConstraint(PDO $pdo): void
{
    if (attendanceStatusSupportsTermination($pdo)) {
        return;
    }

    try {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $oldMigrationTableExists = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'attendance_old_migration' LIMIT 1")->fetchColumn();
        if ($oldMigrationTableExists) {
            $pdo->exec('DROP TABLE attendance_old_migration');
        }

        $pdo->beginTransaction();
        $pdo->exec('ALTER TABLE attendance RENAME TO attendance_old_migration');
        $pdo->exec(
            "CREATE TABLE attendance (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL,
                date TEXT NOT NULL,
                status TEXT NOT NULL CHECK (status IN ('حاضر','غائب','اجازة','انهاء خدمات')),
                note TEXT,
                UNIQUE(employee_id, date)
            )"
        );
        $pdo->exec(
            "INSERT INTO attendance(id, employee_id, date, status, note)
             SELECT
                id,
                employee_id,
                date,
                CASE
                    WHEN status IN ('حاضر','غائب','اجازة','انهاء خدمات') THEN status
                    ELSE 'حاضر'
                END,
                note
             FROM attendance_old_migration"
        );
        $pdo->exec('DROP TABLE attendance_old_migration');
        $pdo->commit();
        $pdo->exec('PRAGMA foreign_keys = ON');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

migrateAttendanceStatusConstraint($pdo);
$attendanceSupportsTermination = attendanceStatusSupportsTermination($pdo);

function recalculateSalaryForMonth(PDO $pdo, int $employeeId, int $month, int $year): ?array
{
    if ($employeeId <= 0 || $month < 1 || $month > 12 || $year < 2000) {
        return null;
    }

    $empStmt = $pdo->prepare('SELECT id, name, salary, start_date, service_end_date FROM employees WHERE id = :id LIMIT 1');
    $empStmt->execute(['id' => $employeeId]);
    $emp = $empStmt->fetch();
    if (!$emp) {
        return null;
    }

    $monthlySalary = toFloat($emp['salary'] ?? 0);
    $startDate = trim((string)($emp['start_date'] ?? ''));
    $serviceEndDate = trim((string)($emp['service_end_date'] ?? ''));

    $periodStartDay = 1;
    $periodEndDay = 30;
    $calendarDaysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));

    if (isValidIsoDate($startDate)) {
        $startYear = (int)date('Y', strtotime($startDate));
        $startMonth = (int)date('n', strtotime($startDate));
        $startDay = (int)date('j', strtotime($startDate));

        if ($year < $startYear || ($year === $startYear && $month < $startMonth)) {
            $periodEndDay = 0;
        } elseif ($year === $startYear && $month === $startMonth) {
            $periodStartDay = max(1, min(30, $startDay));
        }
    }

    if (isValidIsoDate($serviceEndDate)) {
        $endYear = (int)date('Y', strtotime($serviceEndDate));
        $endMonth = (int)date('n', strtotime($serviceEndDate));
        $endDay = (int)date('j', strtotime($serviceEndDate));

        if ($year > $endYear || ($year === $endYear && $month > $endMonth)) {
            $periodEndDay = 0;
        } elseif ($year === $endYear && $month === $endMonth) {
            $lastPaidDay = max(0, min(30, $endDay - 1));
            $periodEndDay = min($periodEndDay, $lastPaidDay);
        }
    }

    $payableDays = $periodEndDay >= $periodStartDay ? ($periodEndDay - $periodStartDay + 1) : 0;
    $periodStartDate = sprintf('%04d-%02d-%02d', $year, $month, max(1, min($periodStartDay, $calendarDaysInMonth)));
    $periodEndDate = sprintf('%04d-%02d-%02d', $year, $month, max(1, min(max($periodEndDay, 1), $calendarDaysInMonth)));

    $dailySalary = toFloat($monthlySalary / 30);
    $baseSalary = toFloat($dailySalary * $payableDays);

    $absenceDays = 0;
    $leaveDays = 0;
    if ($payableDays > 0) {
        $absStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = :id AND status = 'غائب' AND date >= :start_date AND date <= :end_date");
        $absStmt->execute(['id' => $employeeId, 'start_date' => $periodStartDate, 'end_date' => $periodEndDate]);
        $absenceDays = (int)$absStmt->fetchColumn();

        $leaveStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = :id AND status = 'اجازة' AND date >= :start_date AND date <= :end_date");
        $leaveStmt->execute(['id' => $employeeId, 'start_date' => $periodStartDate, 'end_date' => $periodEndDate]);
        $leaveDays = (int)$leaveStmt->fetchColumn();
    }

    $sumStmt = $pdo->prepare("SELECT type, COALESCE(SUM(amount), 0) AS total FROM salary_adjustments WHERE employee_id = :id AND month = :m AND year = :y GROUP BY type");
    $sumStmt->execute(['id' => $employeeId, 'm' => $month, 'y' => $year]);
    $adjustmentMap = ['loan' => 0, 'deduction' => 0, 'bonus' => 0, 'addition' => 0];
    foreach ($sumStmt->fetchAll() as $row) {
        $adjustmentMap[$row['type']] = toFloat($row['total']);
    }
    if ($payableDays <= 0) {
        $adjustmentMap = ['loan' => 0, 'deduction' => 0, 'bonus' => 0, 'addition' => 0];
    }

    $listTotal = 0.0;
    $loanTotal = 0.0;
    $deductionTotalDaily = 0.0;
    $extraTotal = 0.0;
    if ($payableDays > 0) {
        $dailyMoneyStmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(list_amount), 0) AS list_total,
                COALESCE(SUM(loan_amount), 0) AS loan_total,
                COALESCE(SUM(deduction_amount), 0) AS deduction_total,
                COALESCE(SUM(extra_credit), 0) AS extra_total
             FROM employee_daily_entries
             WHERE employee_id = :id
                AND date >= :start_date
                AND date <= :end_date"
        );
        $dailyMoneyStmt->execute(['id' => $employeeId, 'start_date' => $periodStartDate, 'end_date' => $periodEndDate]);
        $dailyMoney = $dailyMoneyStmt->fetch() ?: [];
        $listTotal = toFloat($dailyMoney['list_total'] ?? 0);
        $loanTotal = toFloat($dailyMoney['loan_total'] ?? 0);
        $deductionTotalDaily = toFloat($dailyMoney['deduction_total'] ?? 0);
        $extraTotal = toFloat($dailyMoney['extra_total'] ?? 0);
    }

    $deduction = toFloat(($absenceDays * $dailySalary * 2) + (max(0, $leaveDays - 2) * $dailySalary) + $adjustmentMap['deduction'] + $listTotal + $deductionTotalDaily);
    $loans = toFloat($adjustmentMap['loan'] + $loanTotal);
    $bonuses = toFloat($adjustmentMap['bonus']);
    $additions = toFloat($adjustmentMap['addition'] + $extraTotal);
    $netSalary = toFloat($baseSalary - $deduction - $loans + $bonuses + $additions);

    $stmt = $pdo->prepare(
        "INSERT INTO salaries(employee_id, month, year, base_salary, deductions, loans, bonuses, additions, net_salary, absence_days, leave_days, daily_salary)
         VALUES (:employee_id,:month,:year,:base_salary,:deductions,:loans,:bonuses,:additions,:net_salary,:absence_days,:leave_days,:daily_salary)
         ON CONFLICT(employee_id, month, year)
         DO UPDATE SET
            base_salary = excluded.base_salary,
            deductions = excluded.deductions,
            loans = excluded.loans,
            bonuses = excluded.bonuses,
            additions = excluded.additions,
            net_salary = excluded.net_salary,
            absence_days = excluded.absence_days,
            leave_days = excluded.leave_days,
            daily_salary = excluded.daily_salary"
    );
    $stmt->execute([
        'employee_id' => $employeeId,
        'month' => $month,
        'year' => $year,
        'base_salary' => $baseSalary,
        'deductions' => $deduction,
        'loans' => $loans,
        'bonuses' => $bonuses,
        'additions' => $additions,
        'net_salary' => $netSalary,
        'absence_days' => $absenceDays,
        'leave_days' => $leaveDays,
        'daily_salary' => $dailySalary,
    ]);

    return [
        'employee_name' => (string)$emp['name'],
        'month' => $month,
        'year' => $year,
        'base_salary' => $baseSalary,
        'daily_salary' => $dailySalary,
        'absence_days' => $absenceDays,
        'leave_days' => $leaveDays,
        'deductions' => $deduction,
        'loans' => $loans,
        'bonuses' => $bonuses,
        'additions' => $additions,
        'net_salary' => $netSalary,
    ];
}

$message = '';
$error = '';
$salaryData = null;

if (!empty($_SESSION['flash_message'])) {
    $message = (string)$_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$settingsRow = $pdo->query('SELECT * FROM settings ORDER BY id DESC LIMIT 1')->fetch();
$appTitle = $settingsRow['site_name'] ?? APP_TITLE;

$currentUser = $_SESSION['user'] ?? null;
$isLoggedIn = is_array($currentUser);
$currentRole = $currentUser['role'] ?? 'User';
$isAdmin = strcasecmp((string)$currentRole, 'Admin') === 0;
$isManager = strcasecmp((string)$currentRole, 'Manager') === 0;

$allowedPages = [
    'dashboard', 'employees', 'add_employee', 'edit_employee', 'employee_profile', 'attendance', 'salaries', 'adjustments',
    'restaurants', 'expenses', 'expense_categories', 'collections', 'daily_closing', 'daily_closing_record', 'debts', 'debt_sheet', 'reports', 'stock', 'stock_categories', 'backup', 'settings', 'myportal', 'salary_slip'
];
$currentPage = (string)($_GET['page'] ?? 'dashboard');
if (!in_array($currentPage, $allowedPages, true)) {
    $currentPage = 'dashboard';
}
if ($currentPage === 'salaries') {
    $currentPage = 'employees';
}

$defaultDebtCategories = [
    'الصرفيات',
    'الجمع',
    'الديون الخارجية',
    'الديون الداخلية',
    'دكتور علي',
    'امير ابوعبدالله',
    'مصاريف المسواك',
    'مصاريف اخرى',
    'مصاريف الكاز',
    'مصاريف الاراكيل',
    'الجباية',
    'الكهرباء',
    'الايجارات',
];

$defaultEmployeeDepartments = [
    'مطبخ',
    'كاشير',
    'صالة',
    'ادارة',
    'تنظيف',
    'حسابات',
    'اراكيل',
];

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS employee_daily_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        date TEXT NOT NULL,
        list_amount REAL NOT NULL DEFAULT 0,
        list_number TEXT,
        loan_amount REAL NOT NULL DEFAULT 0,
        deduction_amount REAL NOT NULL DEFAULT 0,
        extra_credit REAL NOT NULL DEFAULT 0,
        UNIQUE(employee_id, date)
    )"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS payroll_period_state (
        id INTEGER PRIMARY KEY CHECK(id = 1),
        month INTEGER NOT NULL,
        year INTEGER NOT NULL,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )"
);
$pdo->exec('INSERT OR IGNORE INTO payroll_period_state(id, month, year) VALUES (1, ' . (int)date('n') . ', ' . (int)date('Y') . ')');
$payrollPeriodRow = $pdo->query('SELECT month, year FROM payroll_period_state WHERE id = 1 LIMIT 1')->fetch();
$activePayrollMonth = (int)($payrollPeriodRow['month'] ?? date('n'));
$activePayrollYear = (int)($payrollPeriodRow['year'] ?? date('Y'));
if ($activePayrollMonth < 1 || $activePayrollMonth > 12) {
    $activePayrollMonth = (int)date('n');
}
if ($activePayrollYear < 2000 || $activePayrollYear > 2100) {
    $activePayrollYear = (int)date('Y');
}

$allowedPayrollPeriods = [];
$allowedPayrollPeriodKeys = [];
$activePeriodKey = payrollPeriodKey($activePayrollMonth, $activePayrollYear);
$allowedPayrollPeriods[] = [
    'month' => $activePayrollMonth,
    'year' => $activePayrollYear,
    'key' => $activePeriodKey,
    'label' => $activePayrollMonth . '/' . $activePayrollYear . ' (فعّال)',
];
$allowedPayrollPeriodKeys[$activePeriodKey] = true;

$archivePeriods = $pdo->query('SELECT DISTINCT month, year FROM archive ORDER BY year DESC, month DESC')->fetchAll();
foreach ($archivePeriods as $p) {
    $m = (int)($p['month'] ?? 0);
    $y = (int)($p['year'] ?? 0);
    if ($m < 1 || $m > 12 || $y < 2000 || $y > 2100) {
        continue;
    }
    if (!payrollMonthHasSavedWork($pdo, $m, $y)) {
        continue;
    }
    $key = payrollPeriodKey($m, $y);
    if (isset($allowedPayrollPeriodKeys[$key])) {
        continue;
    }
    $allowedPayrollPeriods[] = [
        'month' => $m,
        'year' => $y,
        'key' => $key,
        'label' => $m . '/' . $y . ' (مؤرشف)',
    ];
    $allowedPayrollPeriodKeys[$key] = true;
}

if (!columnExists($pdo, 'debts', 'debt_category')) {
    $pdo->exec("ALTER TABLE debts ADD COLUMN debt_category TEXT DEFAULT 'الديون الداخلية'");
}
if (!columnExists($pdo, 'employees', 'start_date')) {
    $pdo->exec("ALTER TABLE employees ADD COLUMN start_date TEXT DEFAULT ''");
}
if (!columnExists($pdo, 'employees', 'service_end_date')) {
    $pdo->exec("ALTER TABLE employees ADD COLUMN service_end_date TEXT DEFAULT ''");
}
if (!columnExists($pdo, 'salaries', 'is_paid')) {
    $pdo->exec('ALTER TABLE salaries ADD COLUMN is_paid INTEGER DEFAULT 0');
}
if (!columnExists($pdo, 'salaries', 'paid_at')) {
    $pdo->exec("ALTER TABLE salaries ADD COLUMN paid_at TEXT DEFAULT ''");
}
if (!columnExists($pdo, 'salaries', 'settled_at')) {
    $pdo->exec("ALTER TABLE salaries ADD COLUMN settled_at TEXT DEFAULT ''");
}
if (!columnExists($pdo, 'salaries', 'payment_note')) {
    $pdo->exec("ALTER TABLE salaries ADD COLUMN payment_note TEXT DEFAULT ''");
}
if (!columnExists($pdo, 'salaries', 'month_note')) {
    $pdo->exec("ALTER TABLE salaries ADD COLUMN month_note TEXT DEFAULT ''");
}
if (!columnExists($pdo, 'employee_daily_entries', 'day_note')) {
    $pdo->exec("ALTER TABLE employee_daily_entries ADD COLUMN day_note TEXT DEFAULT ''");
}
if (!columnExists($pdo, 'general_expenses', 'paid')) {
    $pdo->exec('ALTER TABLE general_expenses ADD COLUMN paid REAL NOT NULL DEFAULT 0');
}
if (!columnExists($pdo, 'daily_closings', 'additions_amount')) {
    $pdo->exec('ALTER TABLE daily_closings ADD COLUMN additions_amount REAL DEFAULT 0');
}
if (!columnExists($pdo, 'daily_closings', 'additions_notes')) {
    $pdo->exec("ALTER TABLE daily_closings ADD COLUMN additions_notes TEXT DEFAULT ''");
}
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS salary_archives (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        month INTEGER NOT NULL,
        year INTEGER NOT NULL,
        payload TEXT NOT NULL,
        delivered_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(employee_id, month, year)
    )'
);

$todayIso = date('Y-m-d');
$deactivateEndedStmt = $pdo->prepare(
    "UPDATE employees
     SET is_active = 0
     WHERE is_active = 1
       AND TRIM(COALESCE(service_end_date, '')) <> ''
       AND service_end_date <= :today"
);
$deactivateEndedStmt->execute(['today' => $todayIso]);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS debt_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )'
);
$existingDebtCategoriesCount = (int)$pdo->query('SELECT COUNT(*) FROM debt_categories')->fetchColumn();
if ($existingDebtCategoriesCount === 0) {
    $insertDebtCategoryStmt = $pdo->prepare('INSERT OR IGNORE INTO debt_categories(name) VALUES (:name)');
    $debtCategoriesFromDebts = $pdo->query("SELECT DISTINCT TRIM(COALESCE(debt_category,'')) AS cat FROM debts WHERE TRIM(COALESCE(debt_category,'')) <> ''")->fetchAll();
    foreach ($debtCategoriesFromDebts as $debtCatRow) {
        $catName = trim((string)($debtCatRow['cat'] ?? ''));
        if ($catName !== '') {
            $insertDebtCategoryStmt->execute(['name' => $catName]);
        }
    }

    $settingsDebtCategory = trim((string)($settingsRow['default_debt_category'] ?? ''));
    if ($settingsDebtCategory !== '') {
        $insertDebtCategoryStmt->execute(['name' => $settingsDebtCategory]);
    }

    foreach ($defaultDebtCategories as $catName) {
        $insertDebtCategoryStmt->execute(['name' => $catName]);
    }
}
$debtCategoryRows = $pdo->query('SELECT id, name FROM debt_categories ORDER BY id ASC')->fetchAll();
$debtCategories = [];
$debtCategoryIdByName = [];
foreach ($debtCategoryRows as $debtCategoryRow) {
    $catId = (int)($debtCategoryRow['id'] ?? 0);
    $catName = trim((string)($debtCategoryRow['name'] ?? ''));
    if ($catName !== '') {
        $debtCategories[] = $catName;
        if ($catId > 0) {
            $debtCategoryIdByName[$catName] = $catId;
        }
    }
}
if (empty($debtCategories)) {
    $debtCategories[] = 'الديون الداخلية';
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS employee_departments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )'
);
$insertEmployeeDepartmentStmt = $pdo->prepare('INSERT OR IGNORE INTO employee_departments(name) VALUES (:name)');
foreach ($defaultEmployeeDepartments as $deptName) {
    $insertEmployeeDepartmentStmt->execute(['name' => $deptName]);
}
$settingsEmployeeDepartment = trim((string)($settingsRow['default_employee_department'] ?? ''));
if ($settingsEmployeeDepartment !== '') {
    $insertEmployeeDepartmentStmt->execute(['name' => $settingsEmployeeDepartment]);
}
$employeeDepartmentsFromEmployees = $pdo->query("SELECT DISTINCT TRIM(COALESCE(department,'')) AS dept FROM employees WHERE TRIM(COALESCE(department,'')) <> ''")->fetchAll();
foreach ($employeeDepartmentsFromEmployees as $deptRow) {
    $deptName = trim((string)($deptRow['dept'] ?? ''));
    if ($deptName !== '') {
        $insertEmployeeDepartmentStmt->execute(['name' => $deptName]);
    }
}
$employeeDepartmentRows = $pdo->query('SELECT id, name FROM employee_departments ORDER BY id ASC')->fetchAll();
$employeeDepartments = [];
foreach ($employeeDepartmentRows as $employeeDepartmentRow) {
    $deptName = trim((string)($employeeDepartmentRow['name'] ?? ''));
    if ($deptName !== '') {
        $employeeDepartments[] = $deptName;
    }
}
if (empty($employeeDepartments)) {
    $employeeDepartments = $defaultEmployeeDepartments;
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS expense_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )'
);
$insertExpenseCategoryStmt = $pdo->prepare('INSERT OR IGNORE INTO expense_categories(name) VALUES (:name)');
$settingsExpenseCategory = trim((string)($settingsRow['default_expense_category'] ?? ''));
if ($settingsExpenseCategory !== '') {
    $insertExpenseCategoryStmt->execute(['name' => $settingsExpenseCategory]);
}
$expenseCategoriesFromExpenses = $pdo->query("SELECT DISTINCT TRIM(COALESCE(category,'')) AS cat FROM general_expenses WHERE TRIM(COALESCE(category,'')) <> ''")->fetchAll();
foreach ($expenseCategoriesFromExpenses as $expenseCategoryRow) {
    $expenseCategoryName = trim((string)($expenseCategoryRow['cat'] ?? ''));
    if ($expenseCategoryName !== '') {
        $insertExpenseCategoryStmt->execute(['name' => $expenseCategoryName]);
    }
}
$expenseCategoryRows = $pdo->query('SELECT id, name FROM expense_categories ORDER BY id ASC')->fetchAll();
$expenseCategories = [];
foreach ($expenseCategoryRows as $expenseCategoryRow) {
    $expenseCategoryName = trim((string)($expenseCategoryRow['name'] ?? ''));
    if ($expenseCategoryName !== '') {
        $expenseCategories[] = $expenseCategoryName;
    }
}

if (!columnExists($pdo, 'settings', 'dark_mode_enabled')) {
    $pdo->exec('ALTER TABLE settings ADD COLUMN dark_mode_enabled INTEGER DEFAULT 0');
}
if (!columnExists($pdo, 'settings', 'default_employee_department')) {
    $pdo->exec("ALTER TABLE settings ADD COLUMN default_employee_department TEXT DEFAULT ''");
}
if (!columnExists($pdo, 'settings', 'default_expense_category')) {
    $pdo->exec("ALTER TABLE settings ADD COLUMN default_expense_category TEXT DEFAULT 'عام'");
}
if (!columnExists($pdo, 'settings', 'default_debt_category')) {
    $pdo->exec("ALTER TABLE settings ADD COLUMN default_debt_category TEXT DEFAULT 'الديون الداخلية'");
}
if (!columnExists($pdo, 'settings', 'primary_color')) {
    $pdo->exec("ALTER TABLE settings ADD COLUMN primary_color TEXT DEFAULT '#2563eb'");
}
if (!columnExists($pdo, 'settings', 'show_profit_card')) {
    $pdo->exec('ALTER TABLE settings ADD COLUMN show_profit_card INTEGER DEFAULT 1');
}
if (!columnExists($pdo, 'settings', 'show_deduction_card')) {
    $pdo->exec('ALTER TABLE settings ADD COLUMN show_deduction_card INTEGER DEFAULT 1');
}
if (!columnExists($pdo, 'settings', 'show_total_salary_card')) {
    $pdo->exec('ALTER TABLE settings ADD COLUMN show_total_salary_card INTEGER DEFAULT 1');
}
if (!columnExists($pdo, 'settings', 'manager_reports_enabled')) {
    $pdo->exec('ALTER TABLE settings ADD COLUMN manager_reports_enabled INTEGER DEFAULT 1');
}
if (!columnExists($pdo, 'settings', 'manager_backup_enabled')) {
    $pdo->exec('ALTER TABLE settings ADD COLUMN manager_backup_enabled INTEGER DEFAULT 0');
}
if (!columnExists($pdo, 'settings', 'manager_finance_enabled')) {
    $pdo->exec('ALTER TABLE settings ADD COLUMN manager_finance_enabled INTEGER DEFAULT 1');
}

// ============================================
// جداول نظام المخزن (Stock Management System)
// ============================================

// جدول فئات المخزن
$pdo->exec('CREATE TABLE IF NOT EXISTS stock_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT DEFAULT "",
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)');

// جدول المواد المخزنة
$pdo->exec('CREATE TABLE IF NOT EXISTS stock_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    category_id INTEGER,
    description TEXT DEFAULT "",
    quantity REAL DEFAULT 0,
    unit TEXT DEFAULT "وحدة",
    min_quantity REAL DEFAULT 0,
    unit_price REAL DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(category_id) REFERENCES stock_categories(id)
)');

// جدول حركات المخزن
$pdo->exec('CREATE TABLE IF NOT EXISTS stock_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER NOT NULL,
    movement_type TEXT NOT NULL,
    quantity REAL DEFAULT 0,
    unit_price REAL DEFAULT 0,
    notes TEXT DEFAULT "",
    movement_date TEXT DEFAULT CURRENT_TIMESTAMP,
    created_by TEXT DEFAULT "",
    FOREIGN KEY(item_id) REFERENCES stock_items(id)
)');

// جدول التقفيل اليومي الموحد
$pdo->exec("CREATE TABLE IF NOT EXISTS daily_closings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    closing_date TEXT NOT NULL UNIQUE,
    closing_day TEXT DEFAULT '',
    closing_month INTEGER DEFAULT 0,
    closing_year INTEGER DEFAULT 0,
    hajaya_sales REAL DEFAULT 0,
    hajaya_expenses REAL DEFAULT 0,
    hajaya_net REAL DEFAULT 0,
    qalaa_sales REAL DEFAULT 0,
    qalaa_expenses REAL DEFAULT 0,
    qalaa_net REAL DEFAULT 0,
    additions_amount REAL DEFAULT 0,
    additions_notes TEXT DEFAULT '',
    total_sales REAL DEFAULT 0,
    total_restaurant_expenses REAL DEFAULT 0,
    total_withdrawals REAL DEFAULT 0,
    total_all_expenses REAL DEFAULT 0,
    final_net REAL DEFAULT 0,
    withdrawals_json TEXT DEFAULT '[]',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

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
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )"
);
$pdo->exec('CREATE INDEX IF NOT EXISTS IX_CollectionEntries_Period ON collection_entries(entry_year, entry_month, entry_date, id)');

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS collection_month_state (
        id INTEGER PRIMARY KEY CHECK(id = 1),
        month INTEGER NOT NULL,
        year INTEGER NOT NULL,
        work_date TEXT NOT NULL,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )"
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
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(month, year)
    )"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS collection_sections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE COLLATE NOCASE,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )"
);

$defaultCollectionSectionNames = ['جمع عام'];
$insertCollectionSectionStmt = $pdo->prepare('INSERT OR IGNORE INTO collection_sections(name) VALUES (:name)');
foreach ($defaultCollectionSectionNames as $defaultCollectionSectionName) {
    $insertCollectionSectionStmt->execute(['name' => $defaultCollectionSectionName]);
}
$deprecatedCollectionSectionNames = ['جمع الإيجار', 'جمع يومي', 'جمع الخدمات'];
$replaceDeprecatedSectionStmt = $pdo->prepare('UPDATE collection_entries SET collection_name = :fallback WHERE LOWER(collection_name) = LOWER(:deprecated_name)');
$deleteDeprecatedSectionStmt = $pdo->prepare('DELETE FROM collection_sections WHERE LOWER(name) = LOWER(:deprecated_name)');
foreach ($deprecatedCollectionSectionNames as $deprecatedCollectionSectionName) {
    $replaceDeprecatedSectionStmt->execute(['fallback' => 'جمع عام', 'deprecated_name' => $deprecatedCollectionSectionName]);
    $deleteDeprecatedSectionStmt->execute(['deprecated_name' => $deprecatedCollectionSectionName]);
}
$existingCollectionNames = $pdo->query("SELECT DISTINCT TRIM(COALESCE(collection_name, '')) AS name FROM collection_entries WHERE TRIM(COALESCE(collection_name, '')) <> ''")->fetchAll();
foreach ($existingCollectionNames as $existingCollectionNameRow) {
    $existingCollectionName = trim((string)($existingCollectionNameRow['name'] ?? ''));
    if ($existingCollectionName !== '') {
        $insertCollectionSectionStmt->execute(['name' => $existingCollectionName]);
    }
}
$collectionSectionMasterRows = $pdo->query("SELECT name FROM collection_sections ORDER BY CASE WHEN name = 'جمع عام' THEN 0 ELSE 1 END, name COLLATE NOCASE ASC")->fetchAll();
$collectionSectionMasterOptions = [];
foreach ($collectionSectionMasterRows as $collectionSectionMasterRow) {
    $collectionSectionName = trim((string)($collectionSectionMasterRow['name'] ?? ''));
    if ($collectionSectionName !== '') {
        $collectionSectionMasterOptions[] = $collectionSectionName;
    }
}
if (empty($collectionSectionMasterOptions)) {
    $collectionSectionMasterOptions = ['جمع عام'];
}

$pdo->exec(
    'INSERT OR IGNORE INTO collection_month_state(id, month, year, work_date) VALUES (1, '
    . (int)date('n') . ', ' . (int)date('Y') . ', ' . $pdo->quote(date('Y-m-d')) . ')'
);

$collectionStateRow = $pdo->query('SELECT month, year, work_date FROM collection_month_state WHERE id = 1 LIMIT 1')->fetch();
$activeCollectionMonth = (int)($collectionStateRow['month'] ?? date('n'));
$activeCollectionYear = (int)($collectionStateRow['year'] ?? date('Y'));
$collectionWorkDate = trim((string)($collectionStateRow['work_date'] ?? date('Y-m-d')));
if ($activeCollectionMonth < 1 || $activeCollectionMonth > 12) {
    $activeCollectionMonth = (int)date('n');
}
if ($activeCollectionYear < 2000 || $activeCollectionYear > 2100) {
    $activeCollectionYear = (int)date('Y');
}
if (!isValidIsoDate($collectionWorkDate)
    || (int)date('n', strtotime($collectionWorkDate)) !== $activeCollectionMonth
    || (int)date('Y', strtotime($collectionWorkDate)) !== $activeCollectionYear) {
    if ($activeCollectionMonth === (int)date('n') && $activeCollectionYear === (int)date('Y')) {
        $collectionWorkDate = date('Y-m-d');
    } else {
        $collectionWorkDate = sprintf('%04d-%02d-01', $activeCollectionYear, $activeCollectionMonth);
    }
    $pdo->prepare('UPDATE collection_month_state SET month = :month, year = :year, work_date = :work_date, updated_at = datetime("now") WHERE id = 1')
        ->execute(['month' => $activeCollectionMonth, 'year' => $activeCollectionYear, 'work_date' => $collectionWorkDate]);
}

$isDarkMode = !empty($settingsRow['dark_mode_enabled']);
$defaultEmployeeDepartment = trim((string)($settingsRow['default_employee_department'] ?? ''));
$defaultExpenseCategory = trim((string)($settingsRow['default_expense_category'] ?? 'عام'));
$defaultDebtCategory = trim((string)($settingsRow['default_debt_category'] ?? 'الديون الداخلية'));
$primaryColor = normalizeHexColor((string)($settingsRow['primary_color'] ?? '#2563eb'));
$showProfitCard = !isset($settingsRow['show_profit_card']) || !empty($settingsRow['show_profit_card']);
$showDeductionCard = !isset($settingsRow['show_deduction_card']) || !empty($settingsRow['show_deduction_card']);
$showTotalSalaryCard = !isset($settingsRow['show_total_salary_card']) || !empty($settingsRow['show_total_salary_card']);
$managerReportsEnabled = !isset($settingsRow['manager_reports_enabled']) || !empty($settingsRow['manager_reports_enabled']);
$managerBackupEnabled = !empty($settingsRow['manager_backup_enabled']);
$managerFinanceEnabled = !isset($settingsRow['manager_finance_enabled']) || !empty($settingsRow['manager_finance_enabled']);
if (!in_array($defaultDebtCategory, $debtCategories, true)) {
    $defaultDebtCategory = $debtCategories[0] ?? 'الديون الداخلية';
}

if ($isLoggedIn && $isManager) {
    $blockedPage = false;
    if (in_array($currentPage, ['reports'], true) && !$managerReportsEnabled) {
        $blockedPage = true;
    }
    if (in_array($currentPage, ['backup'], true) && !$managerBackupEnabled) {
        $blockedPage = true;
    }
    if (in_array($currentPage, ['restaurants', 'expenses', 'expense_categories', 'collections', 'daily_closing', 'daily_closing_record', 'debts', 'debt_sheet'], true) && !$managerFinanceEnabled) {
        $blockedPage = true;
    }
    if ($currentPage === 'settings') {
        $blockedPage = true;
    }
    if ($blockedPage) {
        $currentPage = 'dashboard';
        $error = 'لا تملك صلاحية الوصول لهذا القسم حسب إعدادات النظام.';
    }
}

if ($isLoggedIn && !empty($settingsRow['auto_backup_enabled'])) {
    $today = date('Y-m-d');
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM backup_logs WHERE status='success' AND substr(created_at,1,10)=:today");
    $checkStmt->execute(['today' => $today]);
    $existsToday = (int)$checkStmt->fetchColumn() > 0;
    if (!$existsToday && file_exists(DB_FILE)) {
        $backupDir = __DIR__ . '/data/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        $target = $backupDir . '/auto_' . date('Ymd_His') . '.db';
        $ok = copy(DB_FILE, $target);
        $ins = $pdo->prepare('INSERT INTO backup_logs(backup_path, status) VALUES (:backup_path,:status)');
        $ins->execute([
            'backup_path' => $target,
            'status' => $ok ? 'success' : 'failed',
        ]);
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    redirectSelf();
}

if (isset($_GET['export']) && $isLoggedIn) {
    $exportType = $_GET['export'];
    if ($exportType === 'attendance') {
        $rows = $pdo->query("SELECT a.date, e.name, a.status, COALESCE(a.note, '') AS note FROM attendance a JOIN employees e ON e.id = a.employee_id ORDER BY a.date DESC")->fetchAll();
        $csv = [];
        foreach ($rows as $r) {
            $csv[] = [$r['date'], $r['name'], $r['status'], $r['note']];
        }
        csvExport('attendance_report.csv', ['Date', 'Employee', 'Status', 'Note'], $csv);
    }
    if ($exportType === 'salaries') {
        // Export the active payroll month (or a specific month/year passed via URL)
        $exportMonth = isset($_GET['month']) ? (int)$_GET['month'] : $activePayrollMonth;
        $exportYear  = isset($_GET['year'])  ? (int)$_GET['year']  : $activePayrollYear;
        if ($exportMonth < 1 || $exportMonth > 12) { $exportMonth = $activePayrollMonth; }
        if ($exportYear  < 2000 || $exportYear > 2100) { $exportYear  = $activePayrollYear; }

        // Arabic month names
        $arabicMonths = ['','يناير','فبراير','مارس','أبريل','مايو','يونيو',
                         'يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
        $monthLabel = ($arabicMonths[$exportMonth] ?? $exportMonth) . ' ' . $exportYear;

        // Fetch salary rows for the requested month, including all adjustments
        $exportSalaryStmt = $pdo->prepare(
            'SELECT e.name AS emp_name, e.department, e.job_title,
                    s.base_salary, s.bonuses, s.additions, s.deductions, s.loans, s.net_salary,
                    CASE WHEN s.is_paid = 1 THEN \'نعم\' WHEN TRIM(COALESCE(s.settled_at,\'\')) <> \'\' THEN \'تسوية\' ELSE \'لا\' END AS is_paid_label
             FROM salaries s
             JOIN employees e ON e.id = s.employee_id
             WHERE s.month = :month AND s.year = :year
             ORDER BY e.name ASC'
        );
        $exportSalaryStmt->execute(['month' => $exportMonth, 'year' => $exportYear]);
        $exportSalaryRows = $exportSalaryStmt->fetchAll();

        $excelRows = [];
        $rowNum = 1;
        foreach ($exportSalaryRows as $r) {
            $excelRows[] = [
                $rowNum++,
                (string)($r['emp_name']    ?? ''),
                (string)($r['department']  ?? ''),
                (string)($r['job_title']   ?? ''),
                (float)($r['base_salary']  ?? 0),
                (float)($r['bonuses']      ?? 0),
                (float)($r['additions']    ?? 0),
                (float)($r['deductions']   ?? 0),
                (float)($r['loans']        ?? 0),
                (float)($r['net_salary']   ?? 0),
                (string)($r['is_paid_label'] ?? ''),
            ];
        }

        $arabicHeaders = [
            '#',
            'اسم الموظف',
            'القسم',
            'المسمى الوظيفي',
            'الراتب الأساسي',
            'المكافآت',
            'الإضافات',
            'الاستقطاعات',
            'السلف',
            'صافي الراتب',
            'تم الصرف',
        ];

        $safeMonth = sprintf('%02d', $exportMonth);
        excelTableExport(
            "كشف_رواتب_{$safeMonth}_{$exportYear}.xls",
            $arabicHeaders,
            $excelRows,
            $monthLabel,
            true
        );
    }
    if ($exportType === 'finance') {
        $rows = $pdo->query("SELECT r.name, d.date, d.sales, d.expenses, d.loans, d.external_expenses, d.net_profit FROM dailyfinance d JOIN restaurants r ON r.id = d.restaurant_id ORDER BY d.date DESC")->fetchAll();
        $csv = [];
        foreach ($rows as $r) {
            $csv[] = [$r['name'], $r['date'], $r['sales'], $r['expenses'], $r['loans'], $r['external_expenses'], $r['net_profit']];
        }
        csvExport('profit_report.csv', ['Restaurant', 'Date', 'Sales', 'Expenses', 'Loans', 'ExternalExpenses', 'NetProfit'], $csv);
    }
    if ($exportType === 'expenses') {
        $fromDate = trim((string)($_GET['expense_from'] ?? ''));
        $toDate = trim((string)($_GET['expense_to'] ?? ''));
        $fromDate = isValidIsoDate($fromDate) ? $fromDate : '';
        $toDate = isValidIsoDate($toDate) ? $toDate : '';

        $where = '';
        $params = [];
        if ($fromDate !== '' && $toDate !== '') {
            $where = ' WHERE date >= :from_date AND date <= :to_date';
            $params['from_date'] = $fromDate;
            $params['to_date'] = $toDate;
        } elseif ($fromDate !== '') {
            $where = ' WHERE date >= :from_date';
            $params['from_date'] = $fromDate;
        } elseif ($toDate !== '') {
            $where = ' WHERE date <= :to_date';
            $params['to_date'] = $toDate;
        }

        $expStmt = $pdo->prepare('SELECT id, date, category, amount, COALESCE(paid, 0) AS paid, note FROM general_expenses' . $where . ' ORDER BY date DESC, id DESC');
        $expStmt->execute($params);
        $expRows = $expStmt->fetchAll();

        $excelRows = [];
        $i = 1;
        foreach ($expRows as $r) {
            $amount = toFloat($r['amount'] ?? 0);
            $paid = toFloat($r['paid'] ?? 0);
            $remain = toFloat($amount - $paid);
            $excelRows[] = [
                $i++,
                (string)($r['date'] ?? ''),
                (string)($r['category'] ?? ''),
                (float)$amount,
                (float)$paid,
                (float)$remain,
                (string)($r['note'] ?? ''),
            ];
        }

        $sheetLabel = 'المصاريف';
        if ($fromDate !== '' || $toDate !== '') {
            $sheetLabel .= ' (' . ($fromDate !== '' ? $fromDate : '...') . ' - ' . ($toDate !== '' ? $toDate : '...') . ')';
        }

        excelTableExport(
            'expenses_report.xls',
            ['#', 'التاريخ', 'المادة', 'المبلغ', 'المدفوع', 'الباقي', 'الملاحظات'],
            $excelRows,
            $sheetLabel,
            true
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    if ($action === 'login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM employees WHERE username = :u AND is_active = 1 AND (TRIM(COALESCE(service_end_date, '')) = '' OR service_end_date > :today) LIMIT 1");
        $stmt->execute(['u' => $username, 'today' => $todayIso]);
        $emp = $stmt->fetch();

        $ok = false;
        $userPayload = null;
        if ($emp && !empty($emp['password_hash']) && password_verify($password, $emp['password_hash'])) {
            $ok = true;
            $userPayload = [
                'id' => $emp['id'],
                'name' => $emp['name'],
                'role' => $emp['role'] ?? 'User',
            ];
        } elseif ($settingsRow && ($settingsRow['admin_user'] ?? '') === $username && !empty($settingsRow['admin_pass_hash']) && password_verify($password, $settingsRow['admin_pass_hash'])) {
            $ok = true;
            $userPayload = [
                'id' => 0,
                'name' => 'Administrator',
                'role' => 'Admin',
            ];
        }

        if ($ok) {
            $_SESSION['user'] = $userPayload;
            redirectSelf(($userPayload['role'] ?? 'User') === 'User' ? 'myportal' : '');
        }
        $error = 'بيانات الدخول غير صحيحة.';
    }

    if ($action !== 'login' && !$isLoggedIn) {
        $error = 'يجب تسجيل الدخول أولاً.';
    } elseif ($action === 'add_employee') {
        $name = trim((string)($_POST['name'] ?? ''));
        $department = trim((string)($_POST['department'] ?? ''));
        $departmentCustom = trim((string)($_POST['department_custom'] ?? ''));
        if ($department === '__custom_department__') {
            $department = $departmentCustom;
        }
        $jobTitle = trim((string)($_POST['job_title'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $salary = toFloat($_POST['salary'] ?? 0);
        $role = trim((string)($_POST['role'] ?? 'User'));
        $startDate = trim((string)($_POST['start_date'] ?? date('Y-m-d')));
        if (!isValidIsoDate($startDate)) {
            $startDate = date('Y-m-d');
        }

        if ($name === '' || $username === '' || $pass === '') {
            $error = 'الاسم واسم المستخدم وكلمة المرور مطلوبة.';
        } elseif ($department === '') {
            $error = 'القسم مطلوب.';
        } else {
            try {
                registerEmployeeDepartment($pdo, $department);
                if (columnExists($pdo, 'employees', 'position')) {
                    $stmt = $pdo->prepare('INSERT INTO employees(name, position, department, job_title, phone, username, password_hash, salary, role, start_date, is_active) VALUES (:name,:position,:department,:job_title,:phone,:username,:password_hash,:salary,:role,:start_date,1)');
                    $stmt->execute([
                        'name' => $name,
                        'position' => $jobTitle !== '' ? $jobTitle : 'Employee',
                        'department' => $department,
                        'job_title' => $jobTitle,
                        'phone' => $phone,
                        'username' => $username,
                        'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                        'salary' => $salary,
                        'start_date' => $startDate,
                        'role' => in_array($role, ['Admin', 'Manager', 'User'], true) ? $role : 'User',
                    ]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO employees(name, department, job_title, phone, username, password_hash, salary, role, start_date, is_active) VALUES (:name,:department,:job_title,:phone,:username,:password_hash,:salary,:role,:start_date,1)');
                    $stmt->execute([
                        'name' => $name,
                        'department' => $department,
                        'job_title' => $jobTitle,
                        'phone' => $phone,
                        'username' => $username,
                        'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                        'salary' => $salary,
                        'start_date' => $startDate,
                        'role' => in_array($role, ['Admin', 'Manager', 'User'], true) ? $role : 'User',
                    ]);
                }
                redirectSelf('employees');
            } catch (PDOException $e) {
                $error = 'اسم المستخدم مستخدم مسبقاً.';
            }
        }
    } elseif ($action === 'update_employee') {
        if (!$isAdmin) {
            $error = 'هذه العملية متاحة للمشرف فقط.';
        } else {
            $empId    = (int)($_POST['employee_id'] ?? 0);
            $name     = trim((string)($_POST['name'] ?? ''));
            $dept     = trim((string)($_POST['department'] ?? ''));
            $jobTitle = trim((string)($_POST['job_title'] ?? ''));
            $phone    = trim((string)($_POST['phone'] ?? ''));
            $salary   = toFloat($_POST['salary'] ?? 0);
            $role     = trim((string)($_POST['role'] ?? 'User'));
            $username = trim((string)($_POST['username'] ?? ''));
            $newPass  = (string)($_POST['password'] ?? '');
            $startDate = trim((string)($_POST['start_date'] ?? date('Y-m-d')));
            if (!isValidIsoDate($startDate)) {
                $startDate = date('Y-m-d');
            }
            if ($name === '' || $username === '') {
                $error = 'الاسم واسم المستخدم مطلوبان.';
            } else {
                try {
                    registerEmployeeDepartment($pdo, $dept);
                    if ($newPass !== '') {
                        $stmt = $pdo->prepare('UPDATE employees SET name=:name, department=:department, job_title=:job_title, phone=:phone, salary=:salary, role=:role, username=:username, start_date=:start_date, password_hash=:password_hash WHERE id=:id');
                        $stmt->execute(['name'=>$name,'department'=>$dept,'job_title'=>$jobTitle,'phone'=>$phone,'salary'=>$salary,'role'=>in_array($role,['Admin','Manager','User'],true)?$role:'User','username'=>$username,'start_date'=>$startDate,'password_hash'=>password_hash($newPass,PASSWORD_DEFAULT),'id'=>$empId]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE employees SET name=:name, department=:department, job_title=:job_title, phone=:phone, salary=:salary, role=:role, username=:username, start_date=:start_date WHERE id=:id');
                        $stmt->execute(['name'=>$name,'department'=>$dept,'job_title'=>$jobTitle,'phone'=>$phone,'salary'=>$salary,'role'=>in_array($role,['Admin','Manager','User'],true)?$role:'User','username'=>$username,'start_date'=>$startDate,'id'=>$empId]);
                    }

                    // Keep existing salary slips in sync with the edited base salary.
                    $syncSalaryStmt = $pdo->prepare(
                        'UPDATE salaries
                         SET base_salary = :base_salary,
                             daily_salary = :daily_salary,
                             net_salary = :base_salary - deductions - loans + bonuses + additions
                         WHERE employee_id = :employee_id'
                    );
                    $syncSalaryStmt->execute([
                        'base_salary' => $salary,
                        'daily_salary' => toFloat($salary / 30),
                        'employee_id' => $empId,
                    ]);

                    recalculateSalaryForMonth($pdo, $empId, $activePayrollMonth, $activePayrollYear);
                    redirectSelf('employees');
                } catch (PDOException $e) {
                    $error = 'اسم المستخدم مستخدم مسبقاً.';
                }
            }
        }
    } elseif ($action === 'delete_employee') {
        if (!$isAdmin) {
            $error = 'هذه العملية متاحة للمشرف فقط.';
            if ($isAjaxRequest) {
                jsonResponse(false, $error, [], 403);
            }
        } else {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                $error = 'الموظف غير صالح للحذف.';
                if ($isAjaxRequest) {
                    jsonResponse(false, $error, [], 422);
                }
            } else {
                try {
                    $pdo->beginTransaction();

                    // Remove all payroll/attendance history linked to the employee
                    // so summary cards are recalculated without stale values.
                    $pdo->prepare('DELETE FROM salaries WHERE employee_id = :id')->execute(['id' => $employeeId]);
                    $pdo->prepare('DELETE FROM salary_adjustments WHERE employee_id = :id')->execute(['id' => $employeeId]);
                    $pdo->prepare('DELETE FROM employee_daily_entries WHERE employee_id = :id')->execute(['id' => $employeeId]);
                    $pdo->prepare('DELETE FROM attendance WHERE employee_id = :id')->execute(['id' => $employeeId]);
                    $pdo->prepare('DELETE FROM employees WHERE id = :id')->execute(['id' => $employeeId]);

                    $pdo->commit();
                    $message = 'تم حذف الموظف وتحديث الإحصائيات.';
                    if ($isAjaxRequest) {
                        jsonResponse(true, $message, ['employee_id' => $employeeId]);
                    }
                    redirectSelf('employees');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'فشل حذف الموظف. حاول مرة أخرى.';
                    if ($isAjaxRequest) {
                        jsonResponse(false, $error, [], 500);
                    }
                }
            }
        }
    } elseif ($action === 'set_employee_service_end') {
        if (!$isAdmin) {
            $error = 'هذه العملية متاحة للمشرف فقط.';
        } else {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $serviceEndDate = trim((string)($_POST['service_end_date'] ?? ''));
            if ($employeeId <= 0) {
                $error = 'الموظف غير صالح.';
            } elseif ($serviceEndDate !== '' && !isValidIsoDate($serviceEndDate)) {
                $error = 'تاريخ إنهاء الخدمة غير صالح.';
            } else {
                $activateFlag = 1;
                if ($serviceEndDate !== '' && $serviceEndDate <= $todayIso) {
                    $activateFlag = 0;
                }
                $stmt = $pdo->prepare('UPDATE employees SET service_end_date = :service_end_date, is_active = :is_active WHERE id = :id');
                $stmt->execute([
                    'service_end_date' => $serviceEndDate,
                    'is_active' => $activateFlag,
                    'id' => $employeeId,
                ]);

                $monthsToRecalculate = [];
                $existingSalaryMonthsStmt = $pdo->prepare('SELECT DISTINCT month, year FROM salaries WHERE employee_id = :employee_id');
                $existingSalaryMonthsStmt->execute(['employee_id' => $employeeId]);
                foreach ($existingSalaryMonthsStmt->fetchAll() as $mRow) {
                    $m = (int)($mRow['month'] ?? 0);
                    $y = (int)($mRow['year'] ?? 0);
                    if ($m >= 1 && $m <= 12 && $y >= 2000 && $y <= 2100) {
                        $monthsToRecalculate[$y . '-' . $m] = ['month' => $m, 'year' => $y];
                    }
                }

                $monthsToRecalculate[$activePayrollYear . '-' . $activePayrollMonth] = [
                    'month' => $activePayrollMonth,
                    'year' => $activePayrollYear,
                ];

                if ($serviceEndDate !== '' && isValidIsoDate($serviceEndDate)) {
                    $endMonth = (int)date('n', strtotime($serviceEndDate));
                    $endYear = (int)date('Y', strtotime($serviceEndDate));
                    $monthsToRecalculate[$endYear . '-' . $endMonth] = [
                        'month' => $endMonth,
                        'year' => $endYear,
                    ];
                }

                foreach ($monthsToRecalculate as $period) {
                    recalculateSalaryForMonth($pdo, $employeeId, (int)$period['month'], (int)$period['year']);
                }
                redirectSelf('employees');
            }
        }
    } elseif ($action === 'record_attendance') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $date = trim((string)($_POST['date'] ?? date('Y-m-d')));
        $status = trim((string)($_POST['status'] ?? 'حاضر'));
        if ($status === 'انهاء خدمات' && !$attendanceSupportsTermination) {
            $status = 'غائب';
        }
        $note = trim((string)($_POST['note'] ?? ''));

        try {
            $stmt = $pdo->prepare('INSERT INTO attendance(employee_id, date, status, note) VALUES (:employee_id,:date,:status,:note)');
            $stmt->execute(['employee_id' => $employeeId, 'date' => $date, 'status' => $status, 'note' => $note]);
            $ts = strtotime($date);
            if ($employeeId > 0 && $ts !== false) {
                recalculateSalaryForMonth($pdo, $employeeId, (int)date('n', $ts), (int)date('Y', $ts));
            }
            $message = 'تم تسجيل الحضور.';
        } catch (PDOException $e) {
            $error = 'هذا اليوم مسجل مسبقاً لهذا الموظف.';
        }
    } elseif ($action === 'save_employee_month_attendance') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $month = (int)($_POST['month'] ?? $activePayrollMonth);
        $year = (int)($_POST['year'] ?? $activePayrollYear);
        $statuses = $_POST['status_by_date'] ?? [];
        $listAmountByDate = $_POST['list_amount_by_date'] ?? [];
        $listNumberByDate = $_POST['list_number_by_date'] ?? [];
        $loanAmountByDate = $_POST['loan_amount_by_date'] ?? [];
        $deductionAmountByDate = $_POST['deduction_amount_by_date'] ?? [];
        $extraCreditByDate = $_POST['extra_credit_by_date'] ?? [];
        $dayNoteByDate = $_POST['day_note_by_date'] ?? [];

        if ($employeeId <= 0 || $month < 1 || $month > 12 || $year < 2000) {
            $error = 'بيانات الدوام الشهرية غير صحيحة.';
        } else {
            $empCheck = $pdo->prepare('SELECT id FROM employees WHERE id = :id LIMIT 1');
            $empCheck->execute(['id' => $employeeId]);
            $targetEmployee = $empCheck->fetch();
            $loggedEmployeeId = (int)($currentUser['id'] ?? 0);
            $canEdit = $isAdmin || strcasecmp((string)$currentRole, 'Manager') === 0 || $loggedEmployeeId === $employeeId;
            if (!$targetEmployee || !$canEdit) {
                $error = 'لا تملك صلاحية تعديل دوام هذا الموظف.';
                redirectSelf('employee_profile', ['emp_id' => $employeeId, 'month' => $month, 'year' => $year]);
            }

            $startDate = sprintf('%04d-%02d-01', $year, $month);
            $daysInMonth = (int)date('t', strtotime($startDate));
            $allowedStatuses = ['حاضر', 'غائب', 'اجازة', 'مباشرة عمل', 'انهاء خدمات'];
            $upsert = $pdo->prepare(
                "INSERT INTO attendance(employee_id, date, status, note)
                 VALUES (:employee_id, :date, :status, :note)
                 ON CONFLICT(employee_id, date)
                 DO UPDATE SET status = excluded.status, note = excluded.note"
            );
            $upsertDailyEntry = $pdo->prepare(
                "INSERT INTO employee_daily_entries(employee_id, date, list_amount, list_number, loan_amount, deduction_amount, extra_credit, day_note)
                 VALUES (:employee_id, :date, :list_amount, :list_number, :loan_amount, :deduction_amount, :extra_credit, :day_note)
                 ON CONFLICT(employee_id, date)
                 DO UPDATE SET
                    list_amount = excluded.list_amount,
                    list_number = excluded.list_number,
                    loan_amount = excluded.loan_amount,
                    deduction_amount = excluded.deduction_amount,
                    extra_credit = excluded.extra_credit,
                    day_note = excluded.day_note"
            );

            $terminationDate = '';
            $startWorkDate = '';
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $status = trim((string)($statuses[$date] ?? 'حاضر'));
                if (!in_array($status, $allowedStatuses, true)) {
                    $status = 'حاضر';
                }
                if ($status === 'مباشرة عمل' && ($startWorkDate === '' || strcmp($date, $startWorkDate) < 0)) {
                    $startWorkDate = $date;
                }
                if ($status === 'انهاء خدمات' && ($terminationDate === '' || strcmp($date, $terminationDate) < 0)) {
                    $terminationDate = $date;
                }
                $statusToStore = $status;
                if ($status === 'مباشرة عمل') {
                    $statusToStore = 'حاضر';
                } elseif ($status === 'انهاء خدمات' && !$attendanceSupportsTermination) {
                    $statusToStore = 'غائب';
                }
                $upsert->execute([
                    'employee_id' => $employeeId,
                    'date' => $date,
                    'status' => $statusToStore,
                    'note' => 'من جدول دوام الموظف',
                ]);

                $upsertDailyEntry->execute([
                    'employee_id' => $employeeId,
                    'date' => $date,
                    'list_amount' => toFloat($listAmountByDate[$date] ?? 0),
                    'list_number' => trim((string)($listNumberByDate[$date] ?? '')),
                    'loan_amount' => toFloat($loanAmountByDate[$date] ?? 0),
                    'deduction_amount' => toFloat($deductionAmountByDate[$date] ?? 0),
                    'extra_credit' => toFloat($extraCreditByDate[$date] ?? 0),
                    'day_note' => trim((string)($dayNoteByDate[$date] ?? '')),
                ]);
            }

            if ($startWorkDate !== '' && $terminationDate !== '' && strcmp($startWorkDate, $terminationDate) > 0) {
                $error = 'لا يمكن أن يكون تاريخ المباشرة بعد تاريخ إنهاء الخدمة في نفس الحفظ.';
                redirectSelf('employee_profile', ['emp_id' => $employeeId, 'month' => $month, 'year' => $year]);
            }

            if ($startWorkDate !== '' && isValidIsoDate($startWorkDate)) {
                $pdo->prepare('UPDATE employees SET start_date = :start_date, service_end_date = NULL, is_active = 1 WHERE id = :id')
                    ->execute([
                        'start_date' => $startWorkDate,
                        'id' => $employeeId,
                    ]);

                $pdo->prepare('DELETE FROM attendance WHERE employee_id = :employee_id AND date < :start_date')
                    ->execute([
                        'employee_id' => $employeeId,
                        'start_date' => $startWorkDate,
                    ]);
                $pdo->prepare('DELETE FROM employee_daily_entries WHERE employee_id = :employee_id AND date < :start_date')
                    ->execute([
                        'employee_id' => $employeeId,
                        'start_date' => $startWorkDate,
                    ]);

                $startMonth = (int)date('n', strtotime($startWorkDate));
                $startYear = (int)date('Y', strtotime($startWorkDate));
                $pdo->prepare('DELETE FROM salaries WHERE employee_id = :employee_id AND (year < :start_year OR (year = :start_year AND month < :start_month))')
                    ->execute([
                        'employee_id' => $employeeId,
                        'start_year' => $startYear,
                        'start_month' => $startMonth,
                    ]);
            }

            if ($terminationDate !== '' && isValidIsoDate($terminationDate)) {
                $activateFlag = $terminationDate <= $todayIso ? 0 : 1;
                $pdo->prepare('UPDATE employees SET service_end_date = :service_end_date, is_active = :is_active WHERE id = :id')
                    ->execute([
                        'service_end_date' => $terminationDate,
                        'is_active' => $activateFlag,
                        'id' => $employeeId,
                    ]);

                $pdo->prepare('DELETE FROM attendance WHERE employee_id = :employee_id AND date > :termination_date')
                    ->execute([
                        'employee_id' => $employeeId,
                        'termination_date' => $terminationDate,
                    ]);
                $pdo->prepare('DELETE FROM employee_daily_entries WHERE employee_id = :employee_id AND date > :termination_date')
                    ->execute([
                        'employee_id' => $employeeId,
                        'termination_date' => $terminationDate,
                    ]);

                $terminationMonth = (int)date('n', strtotime($terminationDate));
                $terminationYear = (int)date('Y', strtotime($terminationDate));
                $pdo->prepare('DELETE FROM salaries WHERE employee_id = :employee_id AND (year > :termination_year OR (year = :termination_year AND month > :termination_month))')
                    ->execute([
                        'employee_id' => $employeeId,
                        'termination_year' => $terminationYear,
                        'termination_month' => $terminationMonth,
                    ]);

                $monthsToRecalculate = [];
                $existingSalaryMonthsStmt = $pdo->prepare('SELECT DISTINCT month, year FROM salaries WHERE employee_id = :employee_id');
                $existingSalaryMonthsStmt->execute(['employee_id' => $employeeId]);
                foreach ($existingSalaryMonthsStmt->fetchAll() as $mRow) {
                    $m = (int)($mRow['month'] ?? 0);
                    $y = (int)($mRow['year'] ?? 0);
                    if ($m >= 1 && $m <= 12 && $y >= 2000 && $y <= 2100) {
                        $monthsToRecalculate[$y . '-' . $m] = ['month' => $m, 'year' => $y];
                    }
                }
                $monthsToRecalculate[$year . '-' . $month] = ['month' => $month, 'year' => $year];
                $monthsToRecalculate[$terminationYear . '-' . $terminationMonth] = ['month' => $terminationMonth, 'year' => $terminationYear];

                if ($startWorkDate !== '' && isValidIsoDate($startWorkDate)) {
                    $startMonth = (int)date('n', strtotime($startWorkDate));
                    $startYear = (int)date('Y', strtotime($startWorkDate));
                    $monthsToRecalculate[$startYear . '-' . $startMonth] = ['month' => $startMonth, 'year' => $startYear];
                }

                foreach ($monthsToRecalculate as $period) {
                    recalculateSalaryForMonth($pdo, $employeeId, (int)$period['month'], (int)$period['year']);
                }
            } elseif ($startWorkDate !== '' && isValidIsoDate($startWorkDate)) {
                $monthsToRecalculate = [];
                $existingSalaryMonthsStmt = $pdo->prepare('SELECT DISTINCT month, year FROM salaries WHERE employee_id = :employee_id');
                $existingSalaryMonthsStmt->execute(['employee_id' => $employeeId]);
                foreach ($existingSalaryMonthsStmt->fetchAll() as $mRow) {
                    $m = (int)($mRow['month'] ?? 0);
                    $y = (int)($mRow['year'] ?? 0);
                    if ($m >= 1 && $m <= 12 && $y >= 2000 && $y <= 2100) {
                        $monthsToRecalculate[$y . '-' . $m] = ['month' => $m, 'year' => $y];
                    }
                }

                $startMonth = (int)date('n', strtotime($startWorkDate));
                $startYear = (int)date('Y', strtotime($startWorkDate));
                $monthsToRecalculate[$year . '-' . $month] = ['month' => $month, 'year' => $year];
                $monthsToRecalculate[$startYear . '-' . $startMonth] = ['month' => $startMonth, 'year' => $startYear];

                foreach ($monthsToRecalculate as $period) {
                    recalculateSalaryForMonth($pdo, $employeeId, (int)$period['month'], (int)$period['year']);
                }
            }

            recalculateSalaryForMonth($pdo, $employeeId, $month, $year);

            $message = 'تم حفظ جدول دوام الموظف للشهر بالكامل.';
            redirectSelf('employee_profile', ['emp_id' => $employeeId, 'month' => $month, 'year' => $year]);
        }
    } elseif ($action === 'add_adjustment') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $month = (int)($_POST['month'] ?? $activePayrollMonth);
        $year = (int)($_POST['year'] ?? $activePayrollYear);
        $type = trim((string)($_POST['type'] ?? 'deduction'));
        $amount = toFloat($_POST['amount'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        if (!in_array($type, ['loan', 'deduction', 'bonus', 'addition'], true)) {
            $type = 'deduction';
        }

        $stmt = $pdo->prepare('INSERT INTO salary_adjustments(employee_id, month, year, type, amount, note) VALUES (:employee_id,:month,:year,:type,:amount,:note)');
        $stmt->execute([
            'employee_id' => $employeeId,
            'month' => $month,
            'year' => $year,
            'type' => $type,
            'amount' => $amount,
            'note' => $note,
        ]);
        recalculateSalaryForMonth($pdo, $employeeId, $month, $year);
        $message = 'تم حفظ السلف/الخصومات/المكافآت/الإضافات.';
    } elseif ($action === 'bulk_deduct_all_employees') {
        if (!$isAdmin) {
            $error = 'هذه العملية متاحة للمشرف فقط.';
        } else {
            $amount = toFloat($_POST['amount'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            $month = (int)($_POST['month'] ?? $activePayrollMonth);
            $year = (int)($_POST['year'] ?? $activePayrollYear);
            $excludedIdsInput = $_POST['exclude_employee_ids'] ?? [];
            if (!is_array($excludedIdsInput)) {
                $excludedIdsInput = [];
            }
            $excludedIds = [];
            foreach ($excludedIdsInput as $excludedIdRaw) {
                $excludedId = (int)$excludedIdRaw;
                if ($excludedId > 0) {
                    $excludedIds[$excludedId] = true;
                }
            }
            $excludedIds = array_keys($excludedIds);

            if ($amount <= 0) {
                $error = 'مبلغ الخصم يجب أن يكون أكبر من صفر.';
            } elseif ($reason === '') {
                $error = 'سبب الخصم مطلوب.';
            } else {
                $empStmt = $pdo->query("SELECT id FROM employees WHERE is_active = 1 AND (TRIM(COALESCE(service_end_date,'')) = '' OR service_end_date > '" . $todayIso . "') ORDER BY id ASC");
                $targetEmployees = $empStmt->fetchAll();
                if (empty($targetEmployees)) {
                    $error = 'لا يوجد موظفون نشطون لتطبيق الخصم الجماعي.';
                } else {
                    $insertStmt = $pdo->prepare('INSERT INTO salary_adjustments(employee_id, month, year, type, amount, note) VALUES (:employee_id,:month,:year,:type,:amount,:note)');
                    $appliedCount = 0;
                    $skippedCount = 0;
                    $appliedEmployeeIds = [];
                    try {
                        $pdo->beginTransaction();
                        foreach ($targetEmployees as $empRow) {
                            $empId = (int)($empRow['id'] ?? 0);
                            if ($empId <= 0) {
                                continue;
                            }
                            if (in_array($empId, $excludedIds, true)) {
                                $skippedCount++;
                                continue;
                            }
                            $insertStmt->execute([
                                'employee_id' => $empId,
                                'month' => $month,
                                'year' => $year,
                                'type' => 'deduction',
                                'amount' => $amount,
                                'note' => 'خصم جماعي: ' . $reason,
                            ]);
                            $appliedCount++;
                            $appliedEmployeeIds[] = $empId;
                        }
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = 'فشل تطبيق الخصم الجماعي.';
                    }

                    if ($error === '') {
                        foreach ($appliedEmployeeIds as $empId) {
                            recalculateSalaryForMonth($pdo, (int)$empId, $month, $year);
                        }
                        if ($appliedCount <= 0) {
                            $error = 'لم يتم تطبيق الخصم لأن جميع الموظفين كانوا ضمن الاستثناء.';
                        } else {
                            $message = 'تم تطبيق خصم جماعي بقيمة ' . number_format($amount, 2) . ' د.ع على ' . $appliedCount . ' موظف.';
                            if ($skippedCount > 0) {
                                $message .= ' تم استثناء ' . $skippedCount . ' موظف.';
                            }
                        }
                    }
                    if ($error === '') {
                        redirectSelf('employees');
                    }
                }
            }
        }
    } elseif ($action === 'save_month_note') {
        if (!($isAdmin || $isManager)) {
            $error = 'هذه العملية متاحة للإدارة فقط.';
        } else {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $month      = (int)($_POST['month'] ?? $activePayrollMonth);
            $year       = (int)($_POST['year']  ?? $activePayrollYear);
            $monthNote  = trim((string)($_POST['month_note'] ?? ''));
            if ($employeeId > 0 && $month >= 1 && $month <= 12 && $year >= 2000) {
                recalculateSalaryForMonth($pdo, $employeeId, $month, $year);
                $pdo->prepare(
                    'UPDATE salaries SET month_note = :month_note WHERE employee_id = :employee_id AND month = :month AND year = :year'
                )->execute([
                    'month_note'  => $monthNote,
                    'employee_id' => $employeeId,
                    'month'       => $month,
                    'year'        => $year,
                ]);
            }
            redirectSelf('employees');
        }
    } elseif ($action === 'mark_salary_delivered') {
        if (!($isAdmin || $isManager)) {
            $error = 'هذه العملية متاحة للإدارة فقط.';
        } else {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $month = (int)($_POST['month'] ?? $activePayrollMonth);
            $year = (int)($_POST['year'] ?? $activePayrollYear);
            $note = trim((string)($_POST['payment_note'] ?? ''));
            if ($employeeId <= 0 || $month < 1 || $month > 12 || $year < 2000) {
                $error = 'بيانات تسليم الراتب غير صالحة.';
            } else {
                recalculateSalaryForMonth($pdo, $employeeId, $month, $year);
                $upStmt = $pdo->prepare('UPDATE salaries SET is_paid = 1, paid_at = datetime("now"), payment_note = :payment_note WHERE employee_id = :employee_id AND month = :month AND year = :year');
                $upStmt->execute([
                    'payment_note' => $note,
                    'employee_id' => $employeeId,
                    'month' => $month,
                    'year' => $year,
                ]);

                $salaryRowStmt = $pdo->prepare('SELECT * FROM salaries WHERE employee_id = :employee_id AND month = :month AND year = :year LIMIT 1');
                $salaryRowStmt->execute([
                    'employee_id' => $employeeId,
                    'month' => $month,
                    'year' => $year,
                ]);
                $salaryRow = $salaryRowStmt->fetch() ?: null;

                if (is_array($salaryRow)) {
                    $employeeInfoStmt = $pdo->prepare('SELECT id, name, department, job_title, role, start_date, service_end_date FROM employees WHERE id = :id LIMIT 1');
                    $employeeInfoStmt->execute(['id' => $employeeId]);
                    $employeeInfo = $employeeInfoStmt->fetch() ?: [];

                    $adjustStmt = $pdo->prepare('SELECT type, amount, note FROM salary_adjustments WHERE employee_id = :employee_id AND month = :month AND year = :year ORDER BY id ASC');
                    $adjustStmt->execute([
                        'employee_id' => $employeeId,
                        'month' => $month,
                        'year' => $year,
                    ]);
                    $adjustments = $adjustStmt->fetchAll();

                    $dailyStmt = $pdo->prepare("SELECT date, list_amount, list_number, loan_amount, deduction_amount, extra_credit
                                               FROM employee_daily_entries
                                               WHERE employee_id = :employee_id
                                                 AND strftime('%m', date) = :m
                                                 AND strftime('%Y', date) = :y
                                               ORDER BY date ASC");
                    $dailyStmt->execute([
                        'employee_id' => $employeeId,
                        'm' => sprintf('%02d', $month),
                        'y' => (string)$year,
                    ]);
                    $dailyEntries = $dailyStmt->fetchAll();

                    $archivePayload = [
                        'employee' => $employeeInfo,
                        'salary' => $salaryRow,
                        'adjustments' => $adjustments,
                        'daily_entries' => $dailyEntries,
                        'delivered_note' => $note,
                        'delivered_at' => date('Y-m-d H:i:s'),
                    ];

                    $salaryArchiveStmt = $pdo->prepare(
                        'INSERT INTO salary_archives(employee_id, month, year, payload, delivered_at)
                         VALUES (:employee_id, :month, :year, :payload, datetime("now"))
                         ON CONFLICT(employee_id, month, year)
                         DO UPDATE SET payload = excluded.payload, delivered_at = datetime("now")'
                    );
                    $salaryArchiveStmt->execute([
                        'employee_id' => $employeeId,
                        'month' => $month,
                        'year' => $year,
                        'payload' => json_encode($archivePayload, JSON_UNESCAPED_UNICODE),
                    ]);

                    $monthArchiveCheck = $pdo->prepare('SELECT id FROM archive WHERE month = :month AND year = :year LIMIT 1');
                    $monthArchiveCheck->execute(['month' => $month, 'year' => $year]);
                    $monthArchiveId = (int)$monthArchiveCheck->fetchColumn();
                    if ($monthArchiveId <= 0) {
                        $monthArchivePayload = [
                            'month' => $month,
                            'year' => $year,
                            'employees' => (int)$pdo->query('SELECT COUNT(*) FROM employees WHERE is_active = 1')->fetchColumn(),
                            'salary_total' => toFloat($pdo->query('SELECT COALESCE(SUM(net_salary),0) FROM salaries WHERE month = ' . $month . ' AND year = ' . $year)->fetchColumn()),
                            'profit_total' => toFloat($pdo->query('SELECT COALESCE(SUM(net_profit),0) FROM dailyfinance WHERE strftime("%m", date) = "' . sprintf('%02d', $month) . '" AND strftime("%Y", date) = "' . $year . '"')->fetchColumn()),
                            'archived_by' => 'salary_delivery',
                        ];
                        $pdo->prepare('INSERT INTO archive(month, year, data) VALUES (:month,:year,:data)')->execute([
                            'month' => $month,
                            'year' => $year,
                            'data' => json_encode($monthArchivePayload, JSON_UNESCAPED_UNICODE),
                        ]);
                    }
                }

                $nextMonth = $month === 12 ? 1 : $month + 1;
                $nextYear = $month === 12 ? $year + 1 : $year;
                recalculateSalaryForMonth($pdo, $employeeId, $nextMonth, $nextYear);

                $returnPage = trim((string)($_POST['return_page'] ?? 'salaries'));
                if ($returnPage === 'employee_profile') {
                    redirectSelf('employee_profile', ['emp_id' => $employeeId, 'month' => $nextMonth, 'year' => $nextYear]);
                } elseif ($returnPage === 'employees') {
                    redirectSelf('employees');
                }
                redirectSelf('salaries', ['salary_period' => $month . '-' . $year]);
            }
        }
    } elseif ($action === 'settle_reset_salary') {
        if (!($isAdmin || $isManager)) {
            $error = 'هذه العملية متاحة للإدارة فقط.';
        } else {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $month = (int)($_POST['month'] ?? $activePayrollMonth);
            $year = (int)($_POST['year'] ?? $activePayrollYear);
            $note = trim((string)($_POST['payment_note'] ?? ''));
            if ($employeeId <= 0 || $month < 1 || $month > 12 || $year < 2000) {
                $error = 'بيانات تصفية الراتب غير صالحة.';
            } else {
                recalculateSalaryForMonth($pdo, $employeeId, $month, $year);
                try {
                    $pdo->beginTransaction();

                    $pdo->prepare('DELETE FROM salary_adjustments WHERE employee_id = :employee_id AND month = :month AND year = :year')
                        ->execute([
                            'employee_id' => $employeeId,
                            'month' => $month,
                            'year' => $year,
                        ]);

                    $pdo->prepare("UPDATE employee_daily_entries
                                   SET list_amount = 0,
                                       list_number = '',
                                       loan_amount = 0,
                                       deduction_amount = 0,
                                       extra_credit = 0
                                   WHERE employee_id = :employee_id
                                     AND strftime('%m', date) = :m
                                     AND strftime('%Y', date) = :y")
                        ->execute([
                            'employee_id' => $employeeId,
                            'm' => sprintf('%02d', $month),
                            'y' => (string)$year,
                        ]);

                    $pdo->prepare('UPDATE salaries
                                   SET base_salary = 0,
                                       deductions = 0,
                                       loans = 0,
                                       bonuses = 0,
                                       additions = 0,
                                       net_salary = 0,
                                       absence_days = 0,
                                       leave_days = 0,
                                       daily_salary = 0,
                                       is_paid = 1,
                                       paid_at = CASE WHEN TRIM(COALESCE(paid_at, "")) = "" THEN datetime("now") ELSE paid_at END,
                                       settled_at = datetime("now"),
                                       payment_note = :payment_note
                                   WHERE employee_id = :employee_id
                                     AND month = :month
                                     AND year = :year')
                        ->execute([
                            'payment_note' => $note,
                            'employee_id' => $employeeId,
                            'month' => $month,
                            'year' => $year,
                        ]);

                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'فشلت عملية التصفية والتصفير.';
                }

                if ($error === '') {
                    $returnPage = trim((string)($_POST['return_page'] ?? 'salaries'));
                    if ($returnPage === 'employee_profile') {
                        redirectSelf('employee_profile', ['emp_id' => $employeeId, 'month' => $month, 'year' => $year]);
                    } elseif ($returnPage === 'employees') {
                        redirectSelf('employees');
                    }
                    redirectSelf('salaries', ['salary_period' => $month . '-' . $year]);
                }
            }
        }
    } elseif ($action === 'calculate_salary') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $month = (int)($_POST['month'] ?? $activePayrollMonth);
        $year = (int)($_POST['year'] ?? $activePayrollYear);

        $calculatedSalary = recalculateSalaryForMonth($pdo, $employeeId, $month, $year);
        if ($calculatedSalary === null) {
            $error = 'الموظف غير موجود.';
        } else {
            $salaryData = $calculatedSalary;
            $message = 'تم احتساب صافي الراتب وتخزينه.';
            if (!empty($_POST['redirect_to_slip'])) {
                redirectSelf('salary_slip', ['emp_id' => $employeeId, 'month' => $month, 'year' => $year]);
            }
        }
    } elseif ($action === 'save_daily_closing') {
        $recordId       = (int)($_POST['record_id'] ?? 0);
        $closingDate    = trim((string)($_POST['closing_date'] ?? date('Y-m-d')));
        if (!isValidIsoDate($closingDate)) {
            $closingDate = date('Y-m-d');
        }

        // For new daily-closing entries, force the date to the current work date only.
        if ($recordId <= 0) {
            $workDate = trim((string)($_SESSION['daily_closing_work_date'] ?? date('Y-m-d')));
            if (!isValidIsoDate($workDate)) {
                $workDate = date('Y-m-d');
            }
            $closingDate = $workDate;
        }

        $closingDay     = trim((string)($_POST['closing_day'] ?? ''));
        $closingMonth   = (int)($_POST['closing_month'] ?? date('n'));
        $closingYear    = (int)($_POST['closing_year'] ?? date('Y'));
        $closingMonth   = $closingMonth >= 1 && $closingMonth <= 12 ? $closingMonth : (int)date('n', strtotime($closingDate));
        $closingYear    = $closingYear >= 2000 && $closingYear <= 2100 ? $closingYear : (int)date('Y', strtotime($closingDate));
        $closingDay     = $closingDay !== '' ? $closingDay : arabicWeekdayName($closingDate);
        $hajayaSales    = toFloat($_POST['hajaya_sales'] ?? 0);
        $hajayaExp      = toFloat($_POST['hajaya_expenses'] ?? 0);
        $hajayaNet      = toFloat($hajayaSales - $hajayaExp);
        $qalaaSales     = toFloat($_POST['qalaa_sales'] ?? 0);
        $qalaaExp       = toFloat($_POST['qalaa_expenses'] ?? 0);
        $qalaaNet       = toFloat($qalaaSales - $qalaaExp);
        $additionsAmount = toFloat($_POST['additions_amount'] ?? 0);
        $additionsNotes  = trim((string)($_POST['additions_notes'] ?? ''));
        $restaurantSales = toFloat($hajayaSales + $qalaaSales);
        $totalSales     = toFloat($restaurantSales + $additionsAmount);
        $totalRestExp   = toFloat($hajayaExp + $qalaaExp);
        $amounts        = array_map('trim', (array)($_POST['withdrawal_amount'] ?? []));
        $descs          = array_map('trim', (array)($_POST['withdrawal_desc'] ?? []));
        $withdrawals    = [];
        $totalWithdraw  = 0.0;
        foreach ($amounts as $i => $amt) {
            $a = toFloat($amt);
            $d = $descs[$i] ?? '';
            if ($a > 0 || $d !== '') {
                $withdrawals[] = ['amount' => $a, 'desc' => $d];
                $totalWithdraw += $a;
            }
        }
        $totalAllExp    = toFloat($totalRestExp + $totalWithdraw);
        $finalNet       = toFloat($totalSales - $totalAllExp);
        $withdrawJson   = json_encode($withdrawals, JSON_UNESCAPED_UNICODE);

        if ($recordId > 0) {
            $conflictStmt = $pdo->prepare('SELECT id FROM daily_closings WHERE closing_date = :cd AND id <> :id LIMIT 1');
            $conflictStmt->execute(['cd' => $closingDate, 'id' => $recordId]);
            $conflictId = (int)$conflictStmt->fetchColumn();
            if ($conflictId > 0) {
                redirectSelf('daily_closing_record', ['record_id' => $conflictId, 'conflict' => 1]);
            }
        } else {
            $existingStmt = $pdo->prepare('SELECT id FROM daily_closings WHERE closing_date = :cd LIMIT 1');
            $existingStmt->execute(['cd' => $closingDate]);
            $existingId = (int)$existingStmt->fetchColumn();
            if ($existingId > 0) {
                redirectSelf('daily_closing_record', ['record_id' => $existingId, 'duplicate' => 1]);
            }
        }

        if ($recordId > 0) {
            $pdo->prepare('UPDATE daily_closings SET closing_date=:cd,closing_day=:cday,closing_month=:cm,closing_year=:cy,
                hajaya_sales=:hs,hajaya_expenses=:he,hajaya_net=:hn,qalaa_sales=:qs,qalaa_expenses=:qe,qalaa_net=:qn,
                additions_amount=:aa,additions_notes=:an,
                total_sales=:ts,total_restaurant_expenses=:tre,total_withdrawals=:tw,total_all_expenses=:tae,
                final_net=:fn,withdrawals_json=:wj WHERE id=:id')
            ->execute(['cd'=>$closingDate,'cday'=>$closingDay,'cm'=>$closingMonth,'cy'=>$closingYear,
                'hs'=>$hajayaSales,'he'=>$hajayaExp,'hn'=>$hajayaNet,'qs'=>$qalaaSales,'qe'=>$qalaaExp,'qn'=>$qalaaNet,
                'aa'=>$additionsAmount,'an'=>$additionsNotes,
                'ts'=>$totalSales,'tre'=>$totalRestExp,'tw'=>$totalWithdraw,'tae'=>$totalAllExp,'fn'=>$finalNet,'wj'=>$withdrawJson,'id'=>$recordId]);
            $message = 'تم تحديث التقفيل اليومي.';
            $_SESSION['daily_closing_work_date'] = $closingDate;
            redirectSelf('daily_closing_record', ['record_id' => $recordId, 'updated' => 1]);
        } else {
            $pdo->prepare('INSERT INTO daily_closings(closing_date,closing_day,closing_month,closing_year,
                hajaya_sales,hajaya_expenses,hajaya_net,qalaa_sales,qalaa_expenses,qalaa_net,
                additions_amount,additions_notes,
                total_sales,total_restaurant_expenses,total_withdrawals,total_all_expenses,final_net,withdrawals_json)
                VALUES(:cd,:cday,:cm,:cy,:hs,:he,:hn,:qs,:qe,:qn,:aa,:an,:ts,:tre,:tw,:tae,:fn,:wj)')
            ->execute(['cd'=>$closingDate,'cday'=>$closingDay,'cm'=>$closingMonth,'cy'=>$closingYear,
                'hs'=>$hajayaSales,'he'=>$hajayaExp,'hn'=>$hajayaNet,'qs'=>$qalaaSales,'qe'=>$qalaaExp,'qn'=>$qalaaNet,
                'aa'=>$additionsAmount,'an'=>$additionsNotes,
                'ts'=>$totalSales,'tre'=>$totalRestExp,'tw'=>$totalWithdraw,'tae'=>$totalAllExp,'fn'=>$finalNet,'wj'=>$withdrawJson]);
            $message = 'تم حفظ التقفيل اليومي.';
            $_SESSION['daily_closing_work_date'] = nextDateSameMonth($closingDate);
            redirectSelf('daily_closing', ['saved' => 1]);
        }
    } elseif ($action === 'delete_daily_closing') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        if ($recordId > 0) {
            $pdo->prepare('DELETE FROM daily_closings WHERE id = :id')->execute(['id' => $recordId]);
        }
        $message = 'تم حذف سجل التقفيل.';
        redirectSelf('daily_closing');
    } elseif ($action === 'rewind_daily_closing_work_date') {
        $workDate = trim((string)($_SESSION['daily_closing_work_date'] ?? date('Y-m-d')));
        if (!isValidIsoDate($workDate)) {
            $workDate = date('Y-m-d');
        }
        $prevTs = strtotime('-1 day', strtotime($workDate));
        if ($prevTs === false) {
            $prevDate = $workDate;
        } else {
            $prevDate = date('Y-m-d', $prevTs);
        }
        $_SESSION['daily_closing_work_date'] = $prevDate;
        redirectSelf('daily_closing', ['rewind' => 1]);
    } elseif ($action === 'forward_daily_closing_work_date') {
        $workDate = trim((string)($_SESSION['daily_closing_work_date'] ?? date('Y-m-d')));
        if (!isValidIsoDate($workDate)) {
            $workDate = date('Y-m-d');
        }
        $nextTs = strtotime('+1 day', strtotime($workDate));
        if ($nextTs === false) {
            $nextDate = $workDate;
        } else {
            $nextDate = date('Y-m-d', $nextTs);
        }
        $_SESSION['daily_closing_work_date'] = $nextDate;
        redirectSelf('daily_closing', ['forward' => 1]);
    } elseif ($action === 'add_daily_finance') {
        $restaurantId = (int)($_POST['restaurant_id'] ?? 0);
        $date = trim((string)($_POST['date'] ?? date('Y-m-d')));
        $sales = toFloat($_POST['sales'] ?? 0);
        $expenses = toFloat($_POST['expenses'] ?? 0);
        $loans = toFloat($_POST['loans'] ?? 0);
        $external = toFloat($_POST['external_expenses'] ?? 0);
        $net = toFloat($sales - $expenses - $loans - $external);

        $stmt = $pdo->prepare('INSERT INTO dailyfinance(restaurant_id, date, sales, expenses, loans, external_expenses, net_profit) VALUES (:restaurant_id,:date,:sales,:expenses,:loans,:external_expenses,:net_profit)');
        $stmt->execute([
            'restaurant_id' => $restaurantId,
            'date' => $date,
            'sales' => $sales,
            'expenses' => $expenses,
            'loans' => $loans,
            'external_expenses' => $external,
            'net_profit' => $net,
        ]);
        $message = 'تم حفظ حساب المطعم اليومي.';
    } elseif ($action === 'add_general_expense') {
        $expenseCategoryInput = trim((string)($_POST['category'] ?? $_POST['description'] ?? $defaultExpenseCategory));
        if ($expenseCategoryInput === '__custom_expense_category__') {
            $expenseCategoryInput = trim((string)($_POST['category_custom'] ?? ''));
        }
        if ($expenseCategoryInput === '') {
            $expenseCategoryInput = $defaultExpenseCategory;
        }
        registerExpenseCategory($pdo, $expenseCategoryInput);

        $stmt = $pdo->prepare('INSERT INTO general_expenses(date, category, amount, paid, note) VALUES (:date,:category,:amount,:paid,:note)');
        $stmt->execute([
            'date' => trim((string)($_POST['date'] ?? date('Y-m-d'))),
            'category' => $expenseCategoryInput,
            'amount' => toFloat($_POST['amount'] ?? 0),
            'paid' => toFloat($_POST['paid'] ?? 0),
            'note' => trim((string)($_POST['note'] ?? '')),
        ]);
        $message = 'تم حفظ المصروف العام.';
    } elseif ($action === 'update_general_expense') {
        $expenseId = (int)($_POST['expense_id'] ?? 0);
        if ($expenseId <= 0) {
            $error = 'معرّف المصروف غير صالح.';
        } else {
            $expenseCategoryInput = trim((string)($_POST['category'] ?? $_POST['description'] ?? $defaultExpenseCategory));
            if ($expenseCategoryInput === '__custom_expense_category__') {
                $expenseCategoryInput = trim((string)($_POST['category_custom'] ?? ''));
            }
            if ($expenseCategoryInput === '') {
                $expenseCategoryInput = $defaultExpenseCategory;
            }
            registerExpenseCategory($pdo, $expenseCategoryInput);

            $stmt = $pdo->prepare('UPDATE general_expenses SET date = :date, category = :category, amount = :amount, paid = :paid, note = :note WHERE id = :id');
            $stmt->execute([
                'id' => $expenseId,
                'date' => trim((string)($_POST['date'] ?? date('Y-m-d'))),
                'category' => $expenseCategoryInput,
                'amount' => toFloat($_POST['amount'] ?? 0),
                'paid' => toFloat($_POST['paid'] ?? 0),
                'note' => trim((string)($_POST['note'] ?? '')),
            ]);
            $message = 'تم تحديث المصروف.';
        }
    } elseif ($action === 'delete_general_expense') {
        $expenseId = (int)($_POST['expense_id'] ?? 0);
        if ($expenseId > 0) {
            $pdo->prepare('DELETE FROM general_expenses WHERE id = :id')->execute(['id' => $expenseId]);
            $message = 'تم حذف المصروف.';
        }
    } elseif ($action === 'add_expense_category') {
        $newCategoryName = trim((string)($_POST['new_expense_category_name'] ?? ''));
        $expenseReturnPage = trim((string)($_POST['return_page'] ?? 'expenses'));
        if (!in_array($expenseReturnPage, ['expenses', 'expense_categories'], true)) {
            $expenseReturnPage = 'expenses';
        }
        $expenseRedirectParams = ['page' => $expenseReturnPage];
        $keepFrom = trim((string)($_POST['expense_from'] ?? ''));
        $keepTo = trim((string)($_POST['expense_to'] ?? ''));
        $keepCategory = trim((string)($_POST['expense_category'] ?? 'all'));
        if ($keepFrom !== '') { $expenseRedirectParams['expense_from'] = $keepFrom; }
        if ($keepTo !== '') { $expenseRedirectParams['expense_to'] = $keepTo; }
        if ($keepCategory !== '') { $expenseRedirectParams['expense_category'] = $keepCategory; }

        if ($newCategoryName === '') {
            setFlashMessage('error', 'أدخل اسم قسم المصروف الجديد.');
        } else {
            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE LOWER(name) = LOWER(:name)');
            $existsStmt->execute(['name' => $newCategoryName]);
            if ((int)$existsStmt->fetchColumn() > 0) {
                setFlashMessage('error', 'اسم قسم المصروف موجود مسبقًا.');
            } else {
                registerExpenseCategory($pdo, $newCategoryName);
                setFlashMessage('message', 'تمت إضافة قسم مصروف جديد.');
                $expenseRedirectParams['expense_category'] = $newCategoryName;
            }
        }
        redirectSelf($expenseReturnPage, $expenseRedirectParams);
    } elseif ($action === 'rename_expense_category') {
        $oldCategoryName = trim((string)($_POST['old_expense_category_name'] ?? ''));
        $newCategoryName = trim((string)($_POST['new_expense_category_name'] ?? ''));
        $expenseReturnPage = trim((string)($_POST['return_page'] ?? 'expenses'));
        if (!in_array($expenseReturnPage, ['expenses', 'expense_categories'], true)) {
            $expenseReturnPage = 'expenses';
        }
        $expenseRedirectParams = ['page' => $expenseReturnPage];
        $keepFrom = trim((string)($_POST['expense_from'] ?? ''));
        $keepTo = trim((string)($_POST['expense_to'] ?? ''));
        $keepCategory = trim((string)($_POST['expense_category'] ?? 'all'));
        if ($keepFrom !== '') { $expenseRedirectParams['expense_from'] = $keepFrom; }
        if ($keepTo !== '') { $expenseRedirectParams['expense_to'] = $keepTo; }
        if ($keepCategory !== '') { $expenseRedirectParams['expense_category'] = $keepCategory; }

        if ($oldCategoryName === '' || $newCategoryName === '') {
            setFlashMessage('error', 'يجب تحديد القسم القديم والاسم الجديد.');
        } else {
            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE LOWER(name) = LOWER(:name)');
            $existsStmt->execute(['name' => $oldCategoryName]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                setFlashMessage('error', 'قسم المصروف المطلوب تعديله غير موجود.');
            } else {
                $duplicateStmt = $pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE LOWER(name) = LOWER(:name) AND LOWER(name) <> LOWER(:old_name)');
                $duplicateStmt->execute(['name' => $newCategoryName, 'old_name' => $oldCategoryName]);
                if ((int)$duplicateStmt->fetchColumn() > 0) {
                    setFlashMessage('error', 'الاسم الجديد مستخدم مسبقًا.');
                } else {
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare('UPDATE expense_categories SET name = :new_name WHERE LOWER(name) = LOWER(:old_name)')
                            ->execute(['new_name' => $newCategoryName, 'old_name' => $oldCategoryName]);
                        $pdo->prepare('UPDATE general_expenses SET category = :new_name WHERE LOWER(category) = LOWER(:old_name)')
                            ->execute(['new_name' => $newCategoryName, 'old_name' => $oldCategoryName]);
                        $pdo->prepare('UPDATE settings SET default_expense_category = :new_name WHERE LOWER(default_expense_category) = LOWER(:old_name)')
                            ->execute(['new_name' => $newCategoryName, 'old_name' => $oldCategoryName]);
                        $pdo->commit();
                        setFlashMessage('message', 'تم تعديل اسم قسم المصروف بنجاح.');
                        $expenseRedirectParams['expense_category'] = $newCategoryName;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        setFlashMessage('error', 'تعذر تعديل قسم المصروف حاليًا.');
                    }
                }
            }
        }
        redirectSelf($expenseReturnPage, $expenseRedirectParams);
    } elseif ($action === 'delete_expense_category') {
        $categoryName = trim((string)($_POST['expense_category_name'] ?? ''));
        $expenseReturnPage = trim((string)($_POST['return_page'] ?? 'expenses'));
        if (!in_array($expenseReturnPage, ['expenses', 'expense_categories'], true)) {
            $expenseReturnPage = 'expenses';
        }
        $expenseRedirectParams = ['page' => $expenseReturnPage];
        $keepFrom = trim((string)($_POST['expense_from'] ?? ''));
        $keepTo = trim((string)($_POST['expense_to'] ?? ''));
        if ($keepFrom !== '') { $expenseRedirectParams['expense_from'] = $keepFrom; }
        if ($keepTo !== '') { $expenseRedirectParams['expense_to'] = $keepTo; }

        if ($categoryName === '') {
            setFlashMessage('error', 'القسم المطلوب حذفه غير صالح.');
            $expenseRedirectParams['expense_category'] = 'all';
        } else {
            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE LOWER(name) = LOWER(:name)');
            $existsStmt->execute(['name' => $categoryName]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                setFlashMessage('error', 'قسم المصروف المطلوب حذفه غير موجود.');
                $expenseRedirectParams['expense_category'] = 'all';
            } else {
                $usedStmt = $pdo->prepare('SELECT COUNT(*) FROM general_expenses WHERE LOWER(category) = LOWER(:name)');
                $usedStmt->execute(['name' => $categoryName]);
                if ((int)$usedStmt->fetchColumn() > 0) {
                    setFlashMessage('error', 'لا يمكن حذف قسم مرتبط بسجلات مصاريف.');
                    $expenseRedirectParams['expense_category'] = $categoryName;
                } else {
                    $remainingCategoriesStmt = $pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE LOWER(name) <> LOWER(:name)');
                    $remainingCategoriesStmt->execute(['name' => $categoryName]);
                    if ((int)$remainingCategoriesStmt->fetchColumn() <= 0) {
                        setFlashMessage('error', 'لا يمكن حذف آخر قسم مصاريف متبقي.');
                        $expenseRedirectParams['expense_category'] = $categoryName;
                    } else {
                        $pdo->prepare('DELETE FROM expense_categories WHERE LOWER(name) = LOWER(:name)')->execute(['name' => $categoryName]);
                        $fallbackCategory = (string)$pdo->query('SELECT name FROM expense_categories ORDER BY id ASC LIMIT 1')->fetchColumn();
                        if ($fallbackCategory !== '') {
                            $pdo->prepare('UPDATE settings SET default_expense_category = :fallback WHERE LOWER(default_expense_category) = LOWER(:old_name)')
                                ->execute(['fallback' => $fallbackCategory, 'old_name' => $categoryName]);
                        }
                        setFlashMessage('message', 'تم حذف قسم المصروف بنجاح.');
                        $expenseRedirectParams['expense_category'] = 'all';
                    }
                }
            }
        }
        redirectSelf($expenseReturnPage, $expenseRedirectParams);
    }

    // ============================================
    // عمليات إدارة المخزن (Stock Management Operations)
    // ============================================
    
    elseif ($action === 'add_stock_category') {
        $categoryName = trim((string)($_POST['category_name'] ?? ''));
        $categoryDescription = trim((string)($_POST['category_description'] ?? ''));
        
        if ($categoryName === '') {
            setFlashMessage('error', 'أدخل اسم فئة المخزن.');
        } else {
            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_categories WHERE LOWER(name) = LOWER(:name)');
            $existsStmt->execute(['name' => $categoryName]);
            if ((int)$existsStmt->fetchColumn() > 0) {
                setFlashMessage('error', 'فئة المخزن موجودة مسبقًا.');
            } else {
                $insertStmt = $pdo->prepare('INSERT INTO stock_categories(name, description) VALUES(:name, :desc)');
                $insertStmt->execute(['name' => $categoryName, 'desc' => $categoryDescription]);
                setFlashMessage('message', 'تمت إضافة فئة المخزن بنجاح.');
            }
        }
        redirectSelf('stock_categories', []);
    } elseif ($action === 'add_stock_item') {
        $itemName = trim((string)($_POST['item_name'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $description = trim((string)($_POST['item_description'] ?? ''));
        $quantity = toFloat($_POST['quantity'] ?? 0);
        $unit = trim((string)($_POST['unit'] ?? 'وحدة'));
        $minQuantity = toFloat($_POST['min_quantity'] ?? 0);
        $unitPrice = toFloat($_POST['unit_price'] ?? 0);
        
        if ($itemName === '') {
            setFlashMessage('error', 'أدخل اسم المادة.');
        } elseif ($quantity < 0 || $minQuantity < 0 || $unitPrice < 0) {
            setFlashMessage('error', 'تأكد من صحة القيم المدخلة.');
        } else {
            $insertStmt = $pdo->prepare('INSERT INTO stock_items(name, category_id, description, quantity, unit, min_quantity, unit_price) VALUES(:name, :cat, :desc, :qty, :unit, :min, :price)');
            $insertStmt->execute([
                'name' => $itemName,
                'cat' => $categoryId > 0 ? $categoryId : null,
                'desc' => $description,
                'qty' => $quantity,
                'unit' => $unit,
                'min' => $minQuantity,
                'price' => $unitPrice
            ]);
            $itemId = (int)$pdo->lastInsertId();
            
            // تسجيل حركة المخزن الأولية
            if ($quantity > 0) {
                $movementStmt = $pdo->prepare('INSERT INTO stock_movements(item_id, movement_type, quantity, unit_price, notes, created_by) VALUES(:item, :type, :qty, :price, :notes, :user)');
                $movementStmt->execute([
                    'item' => $itemId,
                    'type' => 'in',
                    'qty' => $quantity,
                    'price' => $unitPrice,
                    'notes' => 'رصيد أولي',
                    'user' => $currentUser['username'] ?? 'نظام'
                ]);
            }
            
            setFlashMessage('message', 'تمت إضافة المادة بنجاح.');
        }
        redirectSelf('stock', []);
    } elseif ($action === 'edit_stock_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $itemName = trim((string)($_POST['item_name'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $description = trim((string)($_POST['item_description'] ?? ''));
        $unit = trim((string)($_POST['unit'] ?? 'وحدة'));
        $minQuantity = toFloat($_POST['min_quantity'] ?? 0);
        $unitPrice = toFloat($_POST['unit_price'] ?? 0);
        
        if ($itemId <= 0 || $itemName === '') {
            setFlashMessage('error', 'بيانات غير صحيحة.');
        } else {
            $updateStmt = $pdo->prepare('UPDATE stock_items SET name=:name, category_id=:cat, description=:desc, unit=:unit, min_quantity=:min, unit_price=:price, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $updateStmt->execute([
                'name' => $itemName,
                'cat' => $categoryId > 0 ? $categoryId : null,
                'desc' => $description,
                'unit' => $unit,
                'min' => $minQuantity,
                'price' => $unitPrice,
                'id' => $itemId
            ]);
            setFlashMessage('message', 'تم تحديث بيانات المادة بنجاح.');
        }
        redirectSelf('stock', []);
    } elseif ($action === 'delete_stock_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        
        if ($itemId <= 0) {
            setFlashMessage('error', 'المادة المطلوب حذفها غير صالحة.');
        } else {
            $pdo->prepare('DELETE FROM stock_movements WHERE item_id = :id')->execute(['id' => $itemId]);
            $pdo->prepare('DELETE FROM stock_items WHERE id = :id')->execute(['id' => $itemId]);
            setFlashMessage('message', 'تم حذف المادة بنجاح.');
        }
        redirectSelf('stock', []);
    } elseif ($action === 'adjust_stock_quantity') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $movementType = trim((string)($_POST['movement_type'] ?? 'in'));
        $quantity = toFloat($_POST['adjustment_quantity'] ?? 0);
        $notes = trim((string)($_POST['movement_notes'] ?? ''));
        
        if ($itemId <= 0 || $quantity <= 0) {
            setFlashMessage('error', 'بيانات الكمية غير صحيحة.');
        } elseif (!in_array($movementType, ['in', 'out'], true)) {
            setFlashMessage('error', 'نوع الحركة غير صحيح.');
        } else {
            // الحصول على كمية المادة الحالية
            $itemStmt = $pdo->prepare('SELECT quantity FROM stock_items WHERE id = :id');
            $itemStmt->execute(['id' => $itemId]);
            $currentQty = toFloat($itemStmt->fetchColumn() ?? 0);
            
            if ($movementType === 'out' && $quantity > $currentQty) {
                setFlashMessage('error', 'الكمية المطلوبة أكبر من الكمية المتاحة.');
            } else {
                $newQuantity = $movementType === 'in' ? $currentQty + $quantity : $currentQty - $quantity;
                
                // تحديث كمية المادة
                $updateStmt = $pdo->prepare('UPDATE stock_items SET quantity = :qty, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $updateStmt->execute(['qty' => $newQuantity, 'id' => $itemId]);
                
                // تسجيل حركة المخزن
                $movementStmt = $pdo->prepare('INSERT INTO stock_movements(item_id, movement_type, quantity, notes, created_by) VALUES(:item, :type, :qty, :notes, :user)');
                $movementStmt->execute([
                    'item' => $itemId,
                    'type' => $movementType,
                    'qty' => $quantity,
                    'notes' => $notes,
                    'user' => $currentUser['username'] ?? 'نظام'
                ]);
                
                setFlashMessage('message', 'تم تحديث كمية المادة بنجاح.');
            }
        }
        redirectSelf('stock', []);
    } elseif ($action === 'rename_stock_category') {
        $oldCategoryId = (int)($_POST['old_category_id'] ?? 0);
        $newCategoryName = trim((string)($_POST['new_category_name'] ?? ''));
        
        if ($oldCategoryId <= 0 || $newCategoryName === '') {
            setFlashMessage('error', 'بيانات غير صحيحة.');
        } else {
            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_categories WHERE LOWER(name) = LOWER(:name) AND id <> :id');
            $existsStmt->execute(['name' => $newCategoryName, 'id' => $oldCategoryId]);
            if ((int)$existsStmt->fetchColumn() > 0) {
                setFlashMessage('error', 'اسم الفئة الجديد موجود مسبقًا.');
            } else {
                $updateStmt = $pdo->prepare('UPDATE stock_categories SET name = :name WHERE id = :id');
                $updateStmt->execute(['name' => $newCategoryName, 'id' => $oldCategoryId]);
                setFlashMessage('message', 'تم تحديث اسم الفئة بنجاح.');
            }
        }
        redirectSelf('stock_categories', []);
    } elseif ($action === 'delete_stock_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        
        if ($categoryId <= 0) {
            setFlashMessage('error', 'الفئة المطلوبة غير صالحة.');
        } else {
            // التحقق من وجود مواد في الفئة
            $itemsStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_items WHERE category_id = :id');
            $itemsStmt->execute(['id' => $categoryId]);
            if ((int)$itemsStmt->fetchColumn() > 0) {
                setFlashMessage('error', 'لا يمكن حذف فئة تحتوي على مواد.');
            } else {
                $deleteStmt = $pdo->prepare('DELETE FROM stock_categories WHERE id = :id');
                $deleteStmt->execute(['id' => $categoryId]);
                setFlashMessage('message', 'تم حذف الفئة بنجاح.');
            }
        }
        redirectSelf('stock_categories', []);
    } elseif ($action === 'save_collection_entry') {
        $entryType = (string)($_POST['entry_type'] ?? 'collect');
        $entryType = $entryType === 'withdraw' ? 'withdraw' : 'collect';
        $amount = toFloat($_POST['amount'] ?? 0);
        $collectionName = trim((string)($_POST['collection_name'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $collectionRedirectParams = ['collection_period' => payrollPeriodKey($activeCollectionMonth, $activeCollectionYear)];

        if ($amount <= 0) {
            setFlashMessage('error', 'أدخل مبلغًا صحيحًا لعملية الجمع أو السحب.');
            redirectSelf('collections', $collectionRedirectParams);
        } else {
            if ($collectionName === '' || !in_array($collectionName, $collectionSectionMasterOptions, true)) {
                $collectionName = 'جمع عام';
            }

            $entryDate = $collectionWorkDate;
            $entryDay = arabicWeekdayName($entryDate);
            $stmt = $pdo->prepare(
                'INSERT INTO collection_entries(entry_date, entry_day, entry_month, entry_year, amount, collection_name, notes, entry_type)
                 VALUES (:entry_date,:entry_day,:entry_month,:entry_year,:amount,:collection_name,:notes,:entry_type)'
            );
            $stmt->execute([
                'entry_date' => $entryDate,
                'entry_day' => $entryDay,
                'entry_month' => $activeCollectionMonth,
                'entry_year' => $activeCollectionYear,
                'amount' => $amount,
                'collection_name' => $collectionName,
                'notes' => $notes,
                'entry_type' => $entryType,
            ]);
            setFlashMessage('message', $entryType === 'withdraw' ? 'تم تسجيل السحب من الجمع.' : 'تم حفظ عملية الجمع.');
            $collectionRedirectParams['collection_section'] = $collectionName;
            redirectSelf('collections', $collectionRedirectParams);
        }
    } elseif ($action === 'update_collection_entry') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $entryType = (string)($_POST['entry_type'] ?? 'collect');
        $entryType = $entryType === 'withdraw' ? 'withdraw' : 'collect';
        $amount = toFloat($_POST['amount'] ?? 0);
        $collectionName = trim((string)($_POST['collection_name'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $returnCollectionPeriod = trim((string)($_POST['return_collection_period'] ?? ''));
        $returnCollectionSection = trim((string)($_POST['return_collection_section'] ?? 'all'));
        $confirmArchivedEdit = (int)($_POST['confirm_archived_edit'] ?? 0) === 1;

        $collectionRedirectParams = [
            'collection_period' => $returnCollectionPeriod !== '' ? $returnCollectionPeriod : payrollPeriodKey($activeCollectionMonth, $activeCollectionYear),
            'collection_section' => $returnCollectionSection !== '' ? $returnCollectionSection : 'all',
        ];

        if ($entryId <= 0) {
            setFlashMessage('error', 'عملية الجمع المطلوبة للتعديل غير صالحة.');
            $collectionRedirectParams['collection_modal'] = 'edit_entry';
            redirectSelf('collections', $collectionRedirectParams);
        }

        $activeCollectionPeriodKey = payrollPeriodKey($activeCollectionMonth, $activeCollectionYear);
        $isArchivedPeriodEdit = $returnCollectionPeriod !== '' && $returnCollectionPeriod !== $activeCollectionPeriodKey;
        if ($isArchivedPeriodEdit && !$confirmArchivedEdit) {
            setFlashMessage('error', 'تعديل البيانات المؤرشفة يتطلب تأكيدًا قبل الحفظ.');
            $collectionRedirectParams['collection_modal'] = 'edit_entry';
            $collectionRedirectParams['collection_entry_id'] = $entryId;
            redirectSelf('collections', $collectionRedirectParams);
        }

        if ($amount <= 0) {
            setFlashMessage('error', 'قيمة المبلغ بعد التعديل يجب أن تكون أكبر من صفر.');
            $collectionRedirectParams['collection_modal'] = 'edit_entry';
            $collectionRedirectParams['collection_entry_id'] = $entryId;
            redirectSelf('collections', $collectionRedirectParams);
        }
        if ($collectionName === '' || !in_array($collectionName, $collectionSectionMasterOptions, true)) {
            $collectionName = 'جمع عام';
        }

        $existsEntryStmt = $pdo->prepare('SELECT id FROM collection_entries WHERE id = :id LIMIT 1');
        $existsEntryStmt->execute(['id' => $entryId]);
        if (!$existsEntryStmt->fetch()) {
            setFlashMessage('error', 'العملية المطلوب تعديلها غير موجودة.');
            redirectSelf('collections', $collectionRedirectParams);
        }

        $updateEntryStmt = $pdo->prepare(
            'UPDATE collection_entries
             SET amount = :amount,
                 collection_name = :collection_name,
                 notes = :notes,
                 entry_type = :entry_type
             WHERE id = :id'
        );
        $updateEntryStmt->execute([
            'amount' => $amount,
            'collection_name' => $collectionName,
            'notes' => $notes,
            'entry_type' => $entryType,
            'id' => $entryId,
        ]);

        setFlashMessage('message', 'تم تعديل قيم العملية بنجاح.');
        $collectionRedirectParams['collection_section'] = $collectionName;
        redirectSelf('collections', $collectionRedirectParams);
    } elseif ($action === 'add_collection_section') {
        $newSectionName = trim((string)($_POST['section_name'] ?? ''));
        $collectionRedirectParams = ['collection_period' => payrollPeriodKey($activeCollectionMonth, $activeCollectionYear)];
        if ($newSectionName === '') {
            setFlashMessage('error', 'أدخل اسم القسم الجديد.');
            $collectionRedirectParams['collection_modal'] = 'add';
        } else {
            $sectionExistsStmt = $pdo->prepare('SELECT COUNT(*) FROM collection_sections WHERE LOWER(name) = LOWER(:name)');
            $sectionExistsStmt->execute(['name' => $newSectionName]);
            if ((int)$sectionExistsStmt->fetchColumn() > 0) {
                setFlashMessage('error', 'اسم هذا القسم موجود مسبقًا.');
                $collectionRedirectParams['collection_modal'] = 'add';
            } else {
                $pdo->prepare('INSERT INTO collection_sections(name) VALUES (:name)')->execute(['name' => $newSectionName]);
                setFlashMessage('message', 'تمت إضافة قسم جمع جديد.');
                $collectionRedirectParams['collection_section'] = $newSectionName;
            }
        }
        redirectSelf('collections', $collectionRedirectParams);
    } elseif ($action === 'rename_collection_section') {
        $oldSectionName = trim((string)($_POST['old_section_name'] ?? ''));
        $newSectionName = trim((string)($_POST['new_section_name'] ?? ''));
        $collectionRedirectParams = ['collection_period' => payrollPeriodKey($activeCollectionMonth, $activeCollectionYear)];
        if ($oldSectionName === '' || $newSectionName === '') {
            setFlashMessage('error', 'يجب تحديد القسم القديم والاسم الجديد.');
            $collectionRedirectParams['collection_modal'] = 'rename';
            $collectionRedirectParams['collection_section'] = $oldSectionName !== '' ? $oldSectionName : 'all';
        } elseif ($oldSectionName === 'جمع عام') {
            setFlashMessage('error', 'لا يمكن تعديل اسم قسم جمع عام لأنه القسم الاحتياطي الرئيسي.');
            $collectionRedirectParams['collection_section'] = 'all';
        } else {
            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM collection_sections WHERE LOWER(name) = LOWER(:name)');
            $existsStmt->execute(['name' => $oldSectionName]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                setFlashMessage('error', 'القسم المطلوب تعديله غير موجود.');
                $collectionRedirectParams['collection_section'] = 'all';
            } else {
                $duplicateStmt = $pdo->prepare('SELECT COUNT(*) FROM collection_sections WHERE LOWER(name) = LOWER(:name) AND LOWER(name) <> LOWER(:old_name)');
                $duplicateStmt->execute(['name' => $newSectionName, 'old_name' => $oldSectionName]);
                if ((int)$duplicateStmt->fetchColumn() > 0) {
                    setFlashMessage('error', 'الاسم الجديد مستخدم بالفعل في قسم آخر.');
                    $collectionRedirectParams['collection_modal'] = 'rename';
                    $collectionRedirectParams['collection_section'] = $oldSectionName;
                } else {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('UPDATE collection_sections SET name = :new_name WHERE LOWER(name) = LOWER(:old_name)')
                            ->execute(['new_name' => $newSectionName, 'old_name' => $oldSectionName]);
                        $pdo->prepare('UPDATE collection_entries SET collection_name = :new_name WHERE LOWER(collection_name) = LOWER(:old_name)')
                            ->execute(['new_name' => $newSectionName, 'old_name' => $oldSectionName]);
                        $pdo->commit();
                        setFlashMessage('message', 'تم تعديل اسم القسم بنجاح.');
                        $collectionRedirectParams['collection_section'] = $newSectionName;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        setFlashMessage('error', 'تعذر تعديل القسم حاليًا.');
                        $collectionRedirectParams['collection_modal'] = 'rename';
                        $collectionRedirectParams['collection_section'] = $oldSectionName;
                    }
                }
            }
        }
        redirectSelf('collections', $collectionRedirectParams);
    } elseif ($action === 'delete_collection_section') {
        $sectionName = trim((string)($_POST['section_name'] ?? ''));
        $collectionRedirectParams = ['collection_period' => payrollPeriodKey($activeCollectionMonth, $activeCollectionYear)];
        if ($sectionName === '') {
            setFlashMessage('error', 'القسم المطلوب حذفه غير صالح.');
        } elseif ($sectionName === 'جمع عام') {
            setFlashMessage('error', 'لا يمكن حذف قسم جمع عام لأنه القسم الاحتياطي الرئيسي.');
        } else {
            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM collection_sections WHERE LOWER(name) = LOWER(:name)');
            $existsStmt->execute(['name' => $sectionName]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                setFlashMessage('error', 'القسم المطلوب حذفه غير موجود.');
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE collection_entries SET collection_name = :fallback_name WHERE LOWER(collection_name) = LOWER(:section_name)')
                        ->execute(['fallback_name' => 'جمع عام', 'section_name' => $sectionName]);
                    $pdo->prepare('DELETE FROM collection_sections WHERE LOWER(name) = LOWER(:name)')
                        ->execute(['name' => $sectionName]);
                    $pdo->commit();
                    setFlashMessage('message', 'تم حذف القسم وتحويل عملياته إلى جمع عام.');
                    $collectionRedirectParams['collection_section'] = 'all';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    setFlashMessage('error', 'تعذر حذف القسم حاليًا.');
                }
            }
        }
        redirectSelf('collections', $collectionRedirectParams);
    } elseif ($action === 'advance_collection_day') {
        $currentWorkTs = strtotime($collectionWorkDate);
        $nextWorkTs = $currentWorkTs === false ? false : strtotime('+1 day', $currentWorkTs);
        if ($nextWorkTs === false) {
            $error = 'تعذر فتح يوم جديد في قسم الجمع.';
        } else {
            $nextMonth = (int)date('n', $nextWorkTs);
            $nextYear = (int)date('Y', $nextWorkTs);
            if ($nextMonth !== $activeCollectionMonth || $nextYear !== $activeCollectionYear) {
                $error = 'لا يمكن فتح شهر جديد من قسم الجمع قبل أرشفة الشهر الحالي.';
            } else {
                $collectionWorkDate = date('Y-m-d', $nextWorkTs);
                $pdo->prepare('UPDATE collection_month_state SET work_date = :work_date, updated_at = datetime("now") WHERE id = 1')
                    ->execute(['work_date' => $collectionWorkDate]);
                setFlashMessage('message', 'تم تثبيت اليوم الجديد لقسم الجمع.');
                redirectSelf('collections', ['collection_period' => payrollPeriodKey($activeCollectionMonth, $activeCollectionYear)]);
            }
        }
    } elseif ($action === 'archive_collection_month') {
        if (!collectionMonthHasEntries($pdo, $activeCollectionMonth, $activeCollectionYear)) {
            $error = 'لا يمكن أرشفة شهر الجمع قبل تسجيل عمليات فعلية.';
        } else {
            $archiveExistsStmt = $pdo->prepare('SELECT COUNT(*) FROM collection_month_archives WHERE month = :month AND year = :year');
            $archiveExistsStmt->execute(['month' => $activeCollectionMonth, 'year' => $activeCollectionYear]);
            if ((int)$archiveExistsStmt->fetchColumn() > 0) {
                $error = 'هذا الشهر مؤرشف مسبقًا في قسم الجمع.';
            } else {
                $entryStmt = $pdo->prepare('SELECT entry_date, entry_day, amount, collection_name, notes, entry_type, created_at FROM collection_entries WHERE entry_month = :month AND entry_year = :year ORDER BY entry_date ASC, id ASC');
                $entryStmt->execute(['month' => $activeCollectionMonth, 'year' => $activeCollectionYear]);
                $archiveEntries = $entryStmt->fetchAll();

                $collectionTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM collection_entries WHERE entry_month = :month AND entry_year = :year AND entry_type = "collect"');
                $collectionTotalStmt->execute(['month' => $activeCollectionMonth, 'year' => $activeCollectionYear]);
                $archiveCollectTotal = toFloat($collectionTotalStmt->fetchColumn());

                $withdrawTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM collection_entries WHERE entry_month = :month AND entry_year = :year AND entry_type = "withdraw"');
                $withdrawTotalStmt->execute(['month' => $activeCollectionMonth, 'year' => $activeCollectionYear]);
                $archiveWithdrawTotal = toFloat($withdrawTotalStmt->fetchColumn());
                $archiveBalance = toFloat($archiveCollectTotal - $archiveWithdrawTotal);

                $insArchive = $pdo->prepare(
                    'INSERT INTO collection_month_archives(month, year, total_collect, total_withdraw, closing_balance, snapshot_json)
                     VALUES (:month,:year,:total_collect,:total_withdraw,:closing_balance,:snapshot_json)'
                );
                $insArchive->execute([
                    'month' => $activeCollectionMonth,
                    'year' => $activeCollectionYear,
                    'total_collect' => $archiveCollectTotal,
                    'total_withdraw' => $archiveWithdrawTotal,
                    'closing_balance' => $archiveBalance,
                    'snapshot_json' => json_encode($archiveEntries, JSON_UNESCAPED_UNICODE),
                ]);

                $nextCollectionPeriod = nextMonthPeriod($activeCollectionMonth, $activeCollectionYear);
                $activeCollectionMonth = (int)$nextCollectionPeriod['month'];
                $activeCollectionYear = (int)$nextCollectionPeriod['year'];
                $collectionWorkDate = sprintf('%04d-%02d-01', $activeCollectionYear, $activeCollectionMonth);
                $pdo->prepare('UPDATE collection_month_state SET month = :month, year = :year, work_date = :work_date, updated_at = datetime("now") WHERE id = 1')
                    ->execute([
                        'month' => $activeCollectionMonth,
                        'year' => $activeCollectionYear,
                        'work_date' => $collectionWorkDate,
                    ]);
                setFlashMessage('message', 'تمت أرشفة شهر الجمع وفتح الشهر التالي مباشرة.');
                redirectSelf('collections', ['collection_period' => payrollPeriodKey($activeCollectionMonth, $activeCollectionYear)]);
            }
        }
    } elseif ($action === 'add_debt') {
        $debtCategory = trim((string)($_POST['debt_category'] ?? $defaultDebtCategory));
        if (!in_array($debtCategory, $debtCategories, true)) {
            $debtCategory = $defaultDebtCategory;
        }
        if (!in_array($debtCategory, $debtCategories, true)) {
            $debtCategory = $debtCategories[0] ?? 'الديون الداخلية';
        }
        $stmt = $pdo->prepare('INSERT INTO debts(name, amount, paid, date, notes, status, debt_category) VALUES (:name,:amount,0,:date,:notes,:status,:debt_category)');
        $stmt->execute([
            'name' => trim((string)($_POST['name'] ?? '')),
            'amount' => toFloat($_POST['amount'] ?? 0),
            'date' => trim((string)($_POST['date'] ?? date('Y-m-d'))),
            'notes' => trim((string)($_POST['notes'] ?? '')),
            'status' => 'open',
            'debt_category' => $debtCategory,
        ]);
        redirectSelf('debt_sheet', ['cat' => $debtCategory]);
    } elseif ($action === 'pay_debt') {
        $debtId = (int)($_POST['debt_id'] ?? 0);
        $payAmount = toFloat($_POST['pay_amount'] ?? 0);
        $returnCat = trim((string)($_POST['return_cat'] ?? ''));
        $stmt = $pdo->prepare('SELECT amount, paid, debt_category FROM debts WHERE id = :id');
        $stmt->execute(['id' => $debtId]);
        $debt = $stmt->fetch();
        if (!$debt) {
            $error = 'الدين غير موجود.';
        } else {
            $newPaid = toFloat($debt['paid'] + $payAmount);
            $status = $newPaid >= toFloat($debt['amount']) ? 'closed' : 'open';
            $up = $pdo->prepare('UPDATE debts SET paid = :paid, status = :status WHERE id = :id');
            $up->execute(['paid' => $newPaid, 'status' => $status, 'id' => $debtId]);

            $ins = $pdo->prepare('INSERT INTO debt_payments(debt_id, amount, payment_date, note) VALUES (:debt_id,:amount,:payment_date,:note)');
            $ins->execute([
                'debt_id' => $debtId,
                'amount' => $payAmount,
                'payment_date' => date('Y-m-d'),
                'note' => trim((string)($_POST['note'] ?? '')),
            ]);
            $catBack = $returnCat !== '' ? $returnCat : (string)($debt['debt_category'] ?? '');
            redirectSelf('debt_sheet', ['cat' => $catBack]);
        }
    } elseif ($action === 'delete_debt') {
        $debtId = (int)($_POST['debt_id'] ?? 0);
        $returnCat = trim((string)($_POST['return_cat'] ?? ''));
        if ($debtId > 0 && $isAdmin) {
            $stmt = $pdo->prepare('SELECT debt_category FROM debts WHERE id = :id');
            $stmt->execute(['id' => $debtId]);
            $dRow = $stmt->fetch();
            $catBack = $returnCat !== '' ? $returnCat : (string)($dRow['debt_category'] ?? '');
            $pdo->prepare('DELETE FROM debts WHERE id = :id')->execute(['id' => $debtId]);
            redirectSelf('debt_sheet', ['cat' => $catBack]);
        }
    } elseif ($action === 'add_debt_category') {
        if (!$isAdmin) {
            $error = 'هذه العملية متاحة للمشرف فقط.';
        } else {
            $categoryName = trim((string)($_POST['category_name'] ?? ''));
            if ($categoryName === '') {
                $error = 'اسم القسم مطلوب.';
            } elseif (strlen($categoryName) > 80) {
                $error = 'اسم القسم طويل جداً.';
            } else {
                try {
                    $pdo->prepare('INSERT INTO debt_categories(name) VALUES (:name)')->execute(['name' => $categoryName]);
                    redirectSelf('debts');
                } catch (Throwable $e) {
                    $error = 'القسم موجود مسبقاً أو غير صالح.';
                }
            }
        }
    } elseif ($action === 'rename_debt_category') {
        if (!$isAdmin) {
            $error = 'هذه العملية متاحة للمشرف فقط.';
        } else {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $newCategoryName = trim((string)($_POST['new_name'] ?? ''));
            if ($categoryId <= 0 || $newCategoryName === '') {
                $error = 'بيانات التعديل غير مكتملة.';
            } else {
                $findStmt = $pdo->prepare('SELECT name FROM debt_categories WHERE id = :id LIMIT 1');
                $findStmt->execute(['id' => $categoryId]);
                $oldCategoryName = trim((string)($findStmt->fetchColumn() ?: ''));
                if ($oldCategoryName === '') {
                    $error = 'القسم غير موجود.';
                } elseif ($oldCategoryName === $newCategoryName) {
                    $message = 'لم يتم تعديل الاسم لأنه مطابق للاسم الحالي.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare('UPDATE debt_categories SET name = :new_name WHERE id = :id')
                            ->execute(['new_name' => $newCategoryName, 'id' => $categoryId]);
                        $pdo->prepare('UPDATE debts SET debt_category = :new_name WHERE debt_category = :old_name')
                            ->execute(['new_name' => $newCategoryName, 'old_name' => $oldCategoryName]);
                        $pdo->prepare('UPDATE settings SET default_debt_category = :new_name WHERE default_debt_category = :old_name')
                            ->execute(['new_name' => $newCategoryName, 'old_name' => $oldCategoryName]);
                        $pdo->commit();
                        redirectSelf('debts');
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = 'تعذر تعديل القسم، تأكد أن الاسم الجديد غير مكرر.';
                    }
                }
            }
        }
    } elseif ($action === 'delete_debt_category') {
        if (!$isAdmin) {
            $error = 'هذه العملية متاحة للمشرف فقط.';
        } else {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            if ($categoryId <= 0) {
                $error = 'القسم غير صالح.';
            } else {
                $findStmt = $pdo->prepare('SELECT name FROM debt_categories WHERE id = :id LIMIT 1');
                $findStmt->execute(['id' => $categoryId]);
                $categoryName = trim((string)($findStmt->fetchColumn() ?: ''));
                if ($categoryName === '') {
                    $error = 'القسم غير موجود.';
                } else {
                    $categoriesCount = (int)$pdo->query('SELECT COUNT(*) FROM debt_categories')->fetchColumn();
                    if ($categoriesCount <= 1) {
                        $error = 'لا يمكن حذف آخر قسم ديون.';
                    } else {
                        $debtCountStmt = $pdo->prepare('SELECT COUNT(*) FROM debts WHERE debt_category = :cat');
                        $debtCountStmt->execute(['cat' => $categoryName]);
                        $relatedDebtCount = (int)$debtCountStmt->fetchColumn();
                        if ($relatedDebtCount > 0) {
                            $error = 'لا يمكن حذف هذا القسم لأنه يحتوي على ديون. قم بحذف الديون أو نقلها أولاً.';
                        } else {
                            $pdo->prepare('DELETE FROM debt_categories WHERE id = :id')->execute(['id' => $categoryId]);
                            $newDefaultCategory = (string)$pdo->query('SELECT name FROM debt_categories ORDER BY id ASC LIMIT 1')->fetchColumn();
                            $pdo->prepare('UPDATE settings SET default_debt_category = :new_default WHERE default_debt_category = :old_default')
                                ->execute(['new_default' => $newDefaultCategory, 'old_default' => $categoryName]);
                            redirectSelf('debts');
                        }
                    }
                }
            }
        }
    } elseif ($action === 'delete_debt_categories_bulk') {
        if (!$isAdmin) {
            $error = 'هذه العملية متاحة للمشرف فقط.';
        } else {
            $categoryIdsInput = $_POST['category_ids'] ?? [];
            if (!is_array($categoryIdsInput)) {
                $categoryIdsInput = [];
            }
            if (empty($categoryIdsInput)) {
                $bulkIdsRaw = trim((string)($_POST['bulk_category_ids'] ?? ''));
                if ($bulkIdsRaw !== '') {
                    $categoryIdsInput = explode(',', $bulkIdsRaw);
                }
            }
            $categoryIds = [];
            foreach ($categoryIdsInput as $cid) {
                $id = (int)$cid;
                if ($id > 0) {
                    $categoryIds[$id] = true;
                }
            }
            $categoryIds = array_keys($categoryIds);
            if (empty($categoryIds)) {
                $error = 'حدد الأقسام المراد حذفها أولاً.';
            } else {
                $idPlaceholders = implode(',', array_fill(0, count($categoryIds), '?'));
                $selectedStmt = $pdo->prepare('SELECT id, name FROM debt_categories WHERE id IN (' . $idPlaceholders . ')');
                $selectedStmt->execute($categoryIds);
                $selectedRows = $selectedStmt->fetchAll();

                if (empty($selectedRows)) {
                    $error = 'لم يتم العثور على الأقسام المحددة.';
                } else {
                    $totalCategories = (int)$pdo->query('SELECT COUNT(*) FROM debt_categories')->fetchColumn();
                    $selectedNames = [];
                    $selectedIds = [];
                    foreach ($selectedRows as $sRow) {
                        $selectedIds[] = (int)$sRow['id'];
                        $selectedNames[] = trim((string)($sRow['name'] ?? ''));
                    }

                    $namePlaceholders = implode(',', array_fill(0, count($selectedNames), '?'));
                    $usedStmt = $pdo->prepare('SELECT debt_category, COUNT(*) AS cnt FROM debts WHERE debt_category IN (' . $namePlaceholders . ') GROUP BY debt_category');
                    $usedStmt->execute($selectedNames);
                    $usedMap = [];
                    foreach ($usedStmt->fetchAll() as $uRow) {
                        $usedMap[(string)($uRow['debt_category'] ?? '')] = (int)($uRow['cnt'] ?? 0);
                    }

                    $deletableIds = [];
                    $deletableNames = [];
                    $blockedNames = [];
                    foreach ($selectedRows as $sRow) {
                        $catId = (int)($sRow['id'] ?? 0);
                        $catName = trim((string)($sRow['name'] ?? ''));
                        if ($catName === '') {
                            continue;
                        }
                        if ((int)($usedMap[$catName] ?? 0) > 0) {
                            $blockedNames[] = $catName;
                        } else {
                            $deletableIds[] = $catId;
                            $deletableNames[] = $catName;
                        }
                    }

                    if (empty($deletableIds)) {
                        $error = 'لا يمكن حذف الأقسام المحددة لأنها تحتوي على ديون.';
                    } elseif ($totalCategories - count($deletableIds) <= 0) {
                        $error = 'لا يمكن حذف جميع الأقسام. يجب الإبقاء على قسم واحد على الأقل.';
                    } else {
                        try {
                            $pdo->beginTransaction();
                            $deletePlaceholders = implode(',', array_fill(0, count($deletableIds), '?'));
                            $deleteStmt = $pdo->prepare('DELETE FROM debt_categories WHERE id IN (' . $deletePlaceholders . ')');
                            $deleteStmt->execute($deletableIds);

                            $currentDefaultDebtCategory = trim((string)($settingsRow['default_debt_category'] ?? ''));
                            if ($currentDefaultDebtCategory !== '' && in_array($currentDefaultDebtCategory, $deletableNames, true)) {
                                $newDefaultCategory = (string)$pdo->query('SELECT name FROM debt_categories ORDER BY id ASC LIMIT 1')->fetchColumn();
                                if ($newDefaultCategory !== '') {
                                    $pdo->prepare('UPDATE settings SET default_debt_category = :new_default')->execute(['new_default' => $newDefaultCategory]);
                                }
                            }
                            $pdo->commit();
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $error = 'فشل حذف الأقسام المحددة.';
                        }

                        if ($error === '') {
                            redirectSelf('debts');
                        }
                    }
                }
            }
        }
    } elseif ($action === 'archive_month') {
        $month = (int)($_POST['month'] ?? $activePayrollMonth);
        $year = (int)($_POST['year'] ?? $activePayrollYear);
        $payload = [
            'month' => $month,
            'year' => $year,
            'employees' => (int)$pdo->query('SELECT COUNT(*) FROM employees WHERE is_active = 1')->fetchColumn(),
            'salary_total' => toFloat($pdo->query('SELECT COALESCE(SUM(net_salary),0) FROM salaries WHERE month = ' . $month . ' AND year = ' . $year)->fetchColumn()),
            'profit_total' => toFloat($pdo->query('SELECT COALESCE(SUM(net_profit),0) FROM dailyfinance WHERE strftime("%m", date) = "' . sprintf('%02d', $month) . '" AND strftime("%Y", date) = "' . $year . '"')->fetchColumn()),
        ];
        $stmt = $pdo->prepare('INSERT INTO archive(month, year, data) VALUES (:month,:year,:data)');
        $stmt->execute(['month' => $month, 'year' => $year, 'data' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        $message = 'تمت الأرشفة الشهرية.';
    } elseif ($action === 'open_new_payroll_month') {
        if (!$isAdmin) {
            $error = 'هذه العملية متاحة للمشرف فقط.';
        } else {
            $newMonth = (int)($_POST['new_month'] ?? 0);
            $newYear = (int)($_POST['new_year'] ?? 0);
            $expectedMonth = $activePayrollMonth === 12 ? 1 : $activePayrollMonth + 1;
            $expectedYear = $activePayrollMonth === 12 ? $activePayrollYear + 1 : $activePayrollYear;
            if ($newMonth < 1 || $newMonth > 12 || $newYear < 2000 || $newYear > 2100) {
                $error = 'الشهر أو السنة غير صالحين.';
            } elseif ($newMonth === $activePayrollMonth && $newYear === $activePayrollYear) {
                $message = 'هذا الشهر هو الشهر الفعّال حالياً.';
            } elseif ($newMonth !== $expectedMonth || $newYear !== $expectedYear) {
                $error = 'مسموح فقط بفتح الشهر التالي مباشرة: ' . $expectedMonth . '/' . $expectedYear;
            } elseif (!payrollMonthHasSavedWork($pdo, $activePayrollMonth, $activePayrollYear)) {
                $error = 'لا يمكن فتح شهر جديد قبل حفظ بيانات الشهر الحالي ثم أرشفته.';
            } else {
                $archivePayload = [
                    'month' => $activePayrollMonth,
                    'year' => $activePayrollYear,
                    'employees' => (int)$pdo->query('SELECT COUNT(*) FROM employees WHERE is_active = 1')->fetchColumn(),
                    'salary_total' => toFloat($pdo->query('SELECT COALESCE(SUM(net_salary),0) FROM salaries WHERE month = ' . $activePayrollMonth . ' AND year = ' . $activePayrollYear)->fetchColumn()),
                    'profit_total' => toFloat($pdo->query('SELECT COALESCE(SUM(net_profit),0) FROM dailyfinance WHERE strftime("%m", date) = "' . sprintf('%02d', $activePayrollMonth) . '" AND strftime("%Y", date) = "' . $activePayrollYear . '"')->fetchColumn()),
                ];

                try {
                    $pdo->beginTransaction();

                    $checkArchive = $pdo->prepare('SELECT COUNT(*) FROM archive WHERE month = :month AND year = :year');
                    $checkArchive->execute(['month' => $activePayrollMonth, 'year' => $activePayrollYear]);
                    if ((int)$checkArchive->fetchColumn() === 0) {
                        $insArchive = $pdo->prepare('INSERT INTO archive(month, year, data) VALUES (:month,:year,:data)');
                        $insArchive->execute([
                            'month' => $activePayrollMonth,
                            'year' => $activePayrollYear,
                            'data' => json_encode($archivePayload, JSON_UNESCAPED_UNICODE),
                        ]);
                    }

                    clearPayrollMonthData($pdo, $newMonth, $newYear);

                    $upPeriod = $pdo->prepare('UPDATE payroll_period_state SET month = :month, year = :year, updated_at = datetime("now") WHERE id = 1');
                    $upPeriod->execute(['month' => $newMonth, 'year' => $newYear]);

                    $pdo->commit();
                    $activePayrollMonth = $newMonth;
                    $activePayrollYear = $newYear;
                    $message = 'تم أرشفة الشهر السابق وفتح الشهر الجديد بعد تصفير السلف والخصومات والقوائم الخاصة به.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'تعذر فتح الشهر الجديد وتصفير بياناته.';
                }
            }
        }
    } elseif ($action === 'backup_now') {
        $backupDir = __DIR__ . '/data/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        $target = $backupDir . '/restaurant_' . date('Ymd_His') . '.db';
        $ok = copy(DB_FILE, $target);
        $stmt = $pdo->prepare('INSERT INTO backup_logs(backup_path, status) VALUES (:backup_path,:status)');
        $stmt->execute([
            'backup_path' => $target,
            'status' => $ok ? 'success' : 'failed',
        ]);
        $message = $ok ? 'تم إنشاء النسخة الاحتياطية.' : 'فشل إنشاء النسخة الاحتياطية.';
    } elseif ($action === 'restore_backup') {
        $backupPath = trim((string)($_POST['backup_path'] ?? ''));
        if ($backupPath === '' || !file_exists($backupPath)) {
            $error = 'مسار النسخة غير صالح.';
        } else {
            $ok = copy($backupPath, DB_FILE);
            $message = $ok ? 'تمت استعادة النسخة بنجاح.' : 'فشلت عملية الاستعادة.';
        }
    } elseif ($action === 'save_settings') {
        if (!$isAdmin) {
            $error = 'هذه العملية متاحة للمشرف فقط.';
        } else {
            $siteName = trim((string)($_POST['site_name'] ?? APP_TITLE));
            $adminUser = trim((string)($_POST['admin_user'] ?? 'admin'));
            $newPassword = (string)($_POST['admin_password'] ?? '');
            $waEnabled = isset($_POST['whatsapp_enabled']) ? 1 : 0;
            $autoBackup = isset($_POST['auto_backup_enabled']) ? 1 : 0;
            $darkModeEnabled = isset($_POST['dark_mode_enabled']) ? (int)$_POST['dark_mode_enabled'] : (int)($settingsRow['dark_mode_enabled'] ?? 0);
            $defaultEmployeeDeptInput = trim((string)($_POST['default_employee_department'] ?? ''));
            $defaultExpenseCategoryInput = trim((string)($_POST['default_expense_category'] ?? 'عام'));
            $defaultDebtCategoryInput = trim((string)($_POST['default_debt_category'] ?? 'الديون الداخلية'));
            $primaryColorInput = normalizeHexColor((string)($_POST['primary_color'] ?? ($settingsRow['primary_color'] ?? '#2563eb')));
            $showProfitCardInput = isset($_POST['show_profit_card']) ? 1 : 0;
            $showDeductionCardInput = isset($_POST['show_deduction_card']) ? 1 : 0;
            $showTotalSalaryCardInput = isset($_POST['show_total_salary_card']) ? 1 : 0;
            $managerReportsInput = isset($_POST['manager_reports_enabled']) ? 1 : 0;
            $managerBackupInput = isset($_POST['manager_backup_enabled']) ? 1 : 0;
            $managerFinanceInput = isset($_POST['manager_finance_enabled']) ? 1 : 0;

            $darkModeEnabled = $darkModeEnabled === 1 ? 1 : 0;
            if ($defaultExpenseCategoryInput === '') {
                $defaultExpenseCategoryInput = 'عام';
            }
            registerExpenseCategory($pdo, $defaultExpenseCategoryInput);
            if (!in_array($defaultDebtCategoryInput, $debtCategories, true)) {
                $defaultDebtCategoryInput = $debtCategories[0] ?? 'الديون الداخلية';
            }
            registerEmployeeDepartment($pdo, $defaultEmployeeDeptInput);

            $hash = $settingsRow['admin_pass_hash'] ?? password_hash('admin123', PASSWORD_DEFAULT);
            if ($newPassword !== '') {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            if ($settingsRow) {
                $stmt = $pdo->prepare('UPDATE settings SET site_name=:site_name, admin_user=:admin_user, admin_pass_hash=:admin_pass_hash, whatsapp_enabled=:whatsapp_enabled, auto_backup_enabled=:auto_backup_enabled, dark_mode_enabled=:dark_mode_enabled, default_employee_department=:default_employee_department, default_expense_category=:default_expense_category, default_debt_category=:default_debt_category, primary_color=:primary_color, show_profit_card=:show_profit_card, show_deduction_card=:show_deduction_card, show_total_salary_card=:show_total_salary_card, manager_reports_enabled=:manager_reports_enabled, manager_backup_enabled=:manager_backup_enabled, manager_finance_enabled=:manager_finance_enabled, updated_at=datetime("now") WHERE id = :id');
                $stmt->execute([
                    'site_name' => $siteName,
                    'admin_user' => $adminUser,
                    'admin_pass_hash' => $hash,
                    'whatsapp_enabled' => $waEnabled,
                    'auto_backup_enabled' => $autoBackup,
                    'dark_mode_enabled' => $darkModeEnabled,
                    'default_employee_department' => $defaultEmployeeDeptInput,
                    'default_expense_category' => $defaultExpenseCategoryInput,
                    'default_debt_category' => $defaultDebtCategoryInput,
                    'primary_color' => $primaryColorInput,
                    'show_profit_card' => $showProfitCardInput,
                    'show_deduction_card' => $showDeductionCardInput,
                    'show_total_salary_card' => $showTotalSalaryCardInput,
                    'manager_reports_enabled' => $managerReportsInput,
                    'manager_backup_enabled' => $managerBackupInput,
                    'manager_finance_enabled' => $managerFinanceInput,
                    'id' => $settingsRow['id'],
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO settings(site_name, admin_user, admin_pass_hash, whatsapp_enabled, auto_backup_enabled, dark_mode_enabled, default_employee_department, default_expense_category, default_debt_category, primary_color, show_profit_card, show_deduction_card, show_total_salary_card, manager_reports_enabled, manager_backup_enabled, manager_finance_enabled) VALUES (:site_name,:admin_user,:admin_pass_hash,:whatsapp_enabled,:auto_backup_enabled,:dark_mode_enabled,:default_employee_department,:default_expense_category,:default_debt_category,:primary_color,:show_profit_card,:show_deduction_card,:show_total_salary_card,:manager_reports_enabled,:manager_backup_enabled,:manager_finance_enabled)');
                $stmt->execute([
                    'site_name' => $siteName,
                    'admin_user' => $adminUser,
                    'admin_pass_hash' => $hash,
                    'whatsapp_enabled' => $waEnabled,
                    'auto_backup_enabled' => $autoBackup,
                    'dark_mode_enabled' => $darkModeEnabled,
                    'default_employee_department' => $defaultEmployeeDeptInput,
                    'default_expense_category' => $defaultExpenseCategoryInput,
                    'default_debt_category' => $defaultDebtCategoryInput,
                    'primary_color' => $primaryColorInput,
                    'show_profit_card' => $showProfitCardInput,
                    'show_deduction_card' => $showDeductionCardInput,
                    'show_total_salary_card' => $showTotalSalaryCardInput,
                    'manager_reports_enabled' => $managerReportsInput,
                    'manager_backup_enabled' => $managerBackupInput,
                    'manager_finance_enabled' => $managerFinanceInput,
                ]);
            }
            $message = 'تم حفظ الإعدادات.';
            $settingsRow = $pdo->query('SELECT * FROM settings ORDER BY id DESC LIMIT 1')->fetch();
            $appTitle = $settingsRow['site_name'] ?? APP_TITLE;
            $isDarkMode = !empty($settingsRow['dark_mode_enabled']);
            $defaultEmployeeDepartment = trim((string)($settingsRow['default_employee_department'] ?? ''));
            $defaultExpenseCategory = trim((string)($settingsRow['default_expense_category'] ?? 'عام'));
            $defaultDebtCategory = trim((string)($settingsRow['default_debt_category'] ?? 'الديون الداخلية'));
            $primaryColor = normalizeHexColor((string)($settingsRow['primary_color'] ?? '#2563eb'));
            $showProfitCard = !isset($settingsRow['show_profit_card']) || !empty($settingsRow['show_profit_card']);
            $showDeductionCard = !isset($settingsRow['show_deduction_card']) || !empty($settingsRow['show_deduction_card']);
            $showTotalSalaryCard = !isset($settingsRow['show_total_salary_card']) || !empty($settingsRow['show_total_salary_card']);
            $managerReportsEnabled = !isset($settingsRow['manager_reports_enabled']) || !empty($settingsRow['manager_reports_enabled']);
            $managerBackupEnabled = !empty($settingsRow['manager_backup_enabled']);
            $managerFinanceEnabled = !isset($settingsRow['manager_finance_enabled']) || !empty($settingsRow['manager_finance_enabled']);
        }
    }
}

$employees = $pdo->query('SELECT * FROM employees ORDER BY id DESC')->fetchAll();
$deptStats = $pdo->query("SELECT department, COUNT(*) AS cnt FROM employees WHERE is_active = 1 GROUP BY department ORDER BY cnt DESC")->fetchAll();
$editEmployee = null;
if ($currentPage === 'edit_employee' && isset($_GET['emp_id'])) {
    $eid = (int)$_GET['emp_id'];
    $stmt = $pdo->prepare('SELECT * FROM employees WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $eid]);
    $editEmployee = $stmt->fetch();
}
$restaurants = $pdo->query('SELECT * FROM restaurants ORDER BY id ASC')->fetchAll();
$attendanceRows = $pdo->query('SELECT a.id, a.date, a.status, COALESCE(a.note, "") AS note, e.name FROM attendance a JOIN employees e ON e.id = a.employee_id ORDER BY a.date DESC LIMIT 50')->fetchAll();
$salaryFilterPeriod = trim((string)($_GET['salary_period'] ?? ''));
$salaryFilterMonth = (int)($_GET['salary_month'] ?? $activePayrollMonth);
$salaryFilterYear = (int)($_GET['salary_year'] ?? $activePayrollYear);
if ($salaryFilterPeriod !== '') {
    $periodParts = explode('-', $salaryFilterPeriod, 2);
    if (count($periodParts) === 2) {
        $salaryFilterMonth = (int)$periodParts[0];
        $salaryFilterYear = (int)$periodParts[1];
    }
}
if ($salaryFilterMonth < 1 || $salaryFilterMonth > 12) {
    $salaryFilterMonth = $activePayrollMonth;
}
if ($salaryFilterYear < 2000 || $salaryFilterYear > 2100) {
    $salaryFilterYear = $activePayrollYear;
}
$salaryFilterKey = payrollPeriodKey($salaryFilterMonth, $salaryFilterYear);
if (!isset($allowedPayrollPeriodKeys[$salaryFilterKey])) {
    $salaryFilterMonth = $activePayrollMonth;
    $salaryFilterYear = $activePayrollYear;
    $salaryFilterKey = payrollPeriodKey($salaryFilterMonth, $salaryFilterYear);
    if ($currentPage === 'salaries') {
        $error = 'لا يمكن فتح شهر غير معتمد. المسموح فقط: الشهر الفعّال أو شهر مؤرشف.';
    }
}

$shouldAutoRecalculateSalaryLists =
    $isLoggedIn &&
    ($currentPage === 'employees' || $currentPage === 'salaries') &&
    $salaryFilterMonth === $activePayrollMonth &&
    $salaryFilterYear === $activePayrollYear;

if ($shouldAutoRecalculateSalaryLists) {
    $employeeIdsForAutoCalc = $pdo->query('SELECT id FROM employees ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($employeeIdsForAutoCalc as $employeeIdForAutoCalc) {
        recalculateSalaryForMonth($pdo, (int)$employeeIdForAutoCalc, $activePayrollMonth, $activePayrollYear);
    }
}

$salaryRowsStmt = $pdo->prepare('SELECT s.*, e.name, e.department, e.job_title, e.role, e.salary AS employee_salary, e.phone FROM salaries s JOIN employees e ON e.id = s.employee_id WHERE s.month = :month AND s.year = :year ORDER BY e.name ASC');
$salaryRowsStmt->execute(['month' => $salaryFilterMonth, 'year' => $salaryFilterYear]);
$salaryRows = $salaryRowsStmt->fetchAll();
$activeMonthSalaryMap = [];
$activeMonthSalaryStmt = $pdo->prepare('SELECT * FROM salaries WHERE month = :month AND year = :year');
$activeMonthSalaryStmt->execute(['month' => $activePayrollMonth, 'year' => $activePayrollYear]);
foreach ($activeMonthSalaryStmt->fetchAll() as $salaryRow) {
    $activeMonthSalaryMap[(int)($salaryRow['employee_id'] ?? 0)] = $salaryRow;
}
$financeRows = $pdo->query('SELECT d.*, r.name AS restaurant_name FROM dailyfinance d JOIN restaurants r ON r.id = d.restaurant_id ORDER BY d.date DESC LIMIT 50')->fetchAll();
$dailyClosingRecords = $pdo->query('SELECT * FROM daily_closings ORDER BY closing_date DESC LIMIT 60')->fetchAll();
$dailyClosingWorkDate = trim((string)($_SESSION['daily_closing_work_date'] ?? date('Y-m-d')));
if (!isValidIsoDate($dailyClosingWorkDate)) {
    $dailyClosingWorkDate = date('Y-m-d');
}
$dailyClosingWorkMonth = (int)date('n', strtotime($dailyClosingWorkDate));
$dailyClosingWorkYear = (int)date('Y', strtotime($dailyClosingWorkDate));
$dailyClosingSelected = null;
if ($currentPage === 'daily_closing_record' && isset($_GET['record_id'])) {
    $dailyClosingRecordId = (int)$_GET['record_id'];
    if ($dailyClosingRecordId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM daily_closings WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $dailyClosingRecordId]);
        $dailyClosingSelected = $stmt->fetch() ?: null;
    }
}
$expenseFromDate = trim((string)($_GET['expense_from'] ?? ''));
$expenseToDate = trim((string)($_GET['expense_to'] ?? ''));
$expenseCategoryFilter = trim((string)($_GET['expense_category'] ?? 'all'));
$expenseEditId = (int)($_GET['expense_edit_id'] ?? 0);
$expenseFromDate = isValidIsoDate($expenseFromDate) ? $expenseFromDate : '';
$expenseToDate = isValidIsoDate($expenseToDate) ? $expenseToDate : '';
if ($expenseCategoryFilter !== 'all' && !in_array($expenseCategoryFilter, $expenseCategories, true)) {
    $expenseCategoryFilter = 'all';
}

$generalExpenseWhere = '';
$generalExpenseParams = [];
if ($expenseFromDate !== '' && $expenseToDate !== '') {
    $generalExpenseWhere = ' WHERE date >= :from_date AND date <= :to_date';
    $generalExpenseParams['from_date'] = $expenseFromDate;
    $generalExpenseParams['to_date'] = $expenseToDate;
} elseif ($expenseFromDate !== '') {
    $generalExpenseWhere = ' WHERE date >= :from_date';
    $generalExpenseParams['from_date'] = $expenseFromDate;
} elseif ($expenseToDate !== '') {
    $generalExpenseWhere = ' WHERE date <= :to_date';
    $generalExpenseParams['to_date'] = $expenseToDate;
}
if ($expenseCategoryFilter !== 'all') {
    $generalExpenseWhere .= ($generalExpenseWhere === '' ? ' WHERE ' : ' AND ') . 'category = :category';
    $generalExpenseParams['category'] = $expenseCategoryFilter;
}

$generalExpenseRowsStmt = $pdo->prepare('SELECT * FROM general_expenses' . $generalExpenseWhere . ' ORDER BY date DESC, id DESC LIMIT 500');
$generalExpenseRowsStmt->execute($generalExpenseParams);
$generalExpenseRows = $generalExpenseRowsStmt->fetchAll();

$generalExpenseSummaryStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS total_amount, COALESCE(SUM(COALESCE(paid,0)),0) AS total_paid FROM general_expenses' . $generalExpenseWhere);
$generalExpenseSummaryStmt->execute($generalExpenseParams);
$generalExpenseSummary = $generalExpenseSummaryStmt->fetch() ?: ['total_amount' => 0, 'total_paid' => 0];
$generalExpenseTotalAmountFiltered = toFloat($generalExpenseSummary['total_amount'] ?? 0);
$generalExpensePaidFiltered = toFloat($generalExpenseSummary['total_paid'] ?? 0);
$generalExpenseRemainFiltered = toFloat($generalExpenseTotalAmountFiltered - $generalExpensePaidFiltered);

$expenseEditRow = null;
if ($expenseEditId > 0) {
    $expenseEditStmt = $pdo->prepare('SELECT * FROM general_expenses WHERE id = :id LIMIT 1');
    $expenseEditStmt->execute(['id' => $expenseEditId]);
    $expenseEditRow = $expenseEditStmt->fetch() ?: null;
}

$expenseFormId = (int)($expenseEditRow['id'] ?? 0);
$expenseFormDate = trim((string)($expenseEditRow['date'] ?? date('Y-m-d')));
$expenseFormCategory = trim((string)($expenseEditRow['category'] ?? $defaultExpenseCategory));
$expenseFormAmount = toFloat($expenseEditRow['amount'] ?? 0);
$expenseFormPaid = toFloat($expenseEditRow['paid'] ?? 0);
$expenseFormNote = trim((string)($expenseEditRow['note'] ?? ''));
$expenseFormCategoryOptions = $expenseCategories;
if ($expenseFormCategory !== '' && !in_array($expenseFormCategory, $expenseFormCategoryOptions, true)) {
    $expenseFormCategoryOptions[] = $expenseFormCategory;
}
$allowedCollectionPeriods = [];
$allowedCollectionPeriodKeys = [];
$activeCollectionKey = payrollPeriodKey($activeCollectionMonth, $activeCollectionYear);
$allowedCollectionPeriods[] = [
    'month' => $activeCollectionMonth,
    'year' => $activeCollectionYear,
    'key' => $activeCollectionKey,
    'label' => $activeCollectionMonth . '/' . $activeCollectionYear . ' (فعّال)',
];
$allowedCollectionPeriodKeys[$activeCollectionKey] = true;
$collectionArchivePeriods = $pdo->query('SELECT month, year FROM collection_month_archives ORDER BY year DESC, month DESC')->fetchAll();
foreach ($collectionArchivePeriods as $archivePeriod) {
    $m = (int)($archivePeriod['month'] ?? 0);
    $y = (int)($archivePeriod['year'] ?? 0);
    if ($m < 1 || $m > 12 || $y < 2000 || $y > 2100) {
        continue;
    }
    $key = payrollPeriodKey($m, $y);
    if (isset($allowedCollectionPeriodKeys[$key])) {
        continue;
    }
    $allowedCollectionPeriods[] = [
        'month' => $m,
        'year' => $y,
        'key' => $key,
        'label' => $m . '/' . $y . ' (مؤرشف)',
    ];
    $allowedCollectionPeriodKeys[$key] = true;
}

$activeCollectionTotalsStmt = $pdo->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN entry_type = "collect" THEN amount ELSE 0 END), 0) AS collect_total,
        COALESCE(SUM(CASE WHEN entry_type = "withdraw" THEN amount ELSE 0 END), 0) AS withdraw_total
     FROM collection_entries
     WHERE entry_month = :month AND entry_year = :year'
);
$activeCollectionTotalsStmt->execute(['month' => $activeCollectionMonth, 'year' => $activeCollectionYear]);
$activeCollectionTotals = $activeCollectionTotalsStmt->fetch() ?: ['collect_total' => 0, 'withdraw_total' => 0];
$activeCollectionBalanceCard = toFloat(($activeCollectionTotals['collect_total'] ?? 0) - ($activeCollectionTotals['withdraw_total'] ?? 0));

$collectionPeriodParam = trim((string)($_GET['collection_period'] ?? ''));
$collectionSectionParam = trim((string)($_GET['collection_section'] ?? 'all'));
$collectionFilterMonth = (int)($_GET['collection_month'] ?? $activeCollectionMonth);
$collectionFilterYear = (int)($_GET['collection_year'] ?? $activeCollectionYear);
if ($collectionPeriodParam !== '') {
    $periodParts = explode('-', $collectionPeriodParam, 2);
    if (count($periodParts) === 2) {
        $collectionFilterYear = (int)$periodParts[0];
        $collectionFilterMonth = (int)$periodParts[1];
    }
}
if ($collectionFilterMonth < 1 || $collectionFilterMonth > 12) {
    $collectionFilterMonth = $activeCollectionMonth;
}
if ($collectionFilterYear < 2000 || $collectionFilterYear > 2100) {
    $collectionFilterYear = $activeCollectionYear;
}
$collectionFilterKey = payrollPeriodKey($collectionFilterMonth, $collectionFilterYear);
if (!isset($allowedCollectionPeriodKeys[$collectionFilterKey])) {
    $collectionFilterMonth = $activeCollectionMonth;
    $collectionFilterYear = $activeCollectionYear;
    $collectionFilterKey = payrollPeriodKey($collectionFilterMonth, $collectionFilterYear);
    if ($currentPage === 'collections') {
        $error = 'لا يمكن فتح شهر جمع غير مستخدم. المسموح فقط الشهر الفعّال أو الأشهر المؤرشفة.';
    }
}
$isCollectionActivePeriod = $collectionFilterMonth === $activeCollectionMonth && $collectionFilterYear === $activeCollectionYear;
$collectionRowsStmt = $pdo->prepare('SELECT * FROM collection_entries WHERE entry_month = :month AND entry_year = :year ORDER BY entry_date ASC, id ASC');
$collectionRowsStmt->execute(['month' => $collectionFilterMonth, 'year' => $collectionFilterYear]);
$collectionRowsRaw = $collectionRowsStmt->fetchAll();
$collectionSectionOptions = $collectionSectionMasterOptions;
$collectionSectionSummaries = [];
$collectionRowsPrepared = [];
$collectionMonthlyTotal = 0.0;
$collectionMonthlyWithdrawTotal = 0.0;
$collectionBalance = 0.0;
$collectionDays = [];
foreach ($collectionRowsRaw as $collectionRow) {
    $sectionName = trim((string)($collectionRow['collection_name'] ?? ''));
    if ($sectionName === '') {
        $sectionName = 'جمع عام';
    }
    if (!in_array($sectionName, $collectionSectionOptions, true)) {
        $collectionSectionOptions[] = $sectionName;
    }
    if (!isset($collectionSectionSummaries[$sectionName])) {
        $collectionSectionSummaries[$sectionName] = [
            'name' => $sectionName,
            'collect_total' => 0.0,
            'withdraw_total' => 0.0,
            'balance' => 0.0,
            'entry_count' => 0,
            'last_date' => '',
        ];
    }

    $rowAmount = toFloat($collectionRow['amount'] ?? 0);
    $rowType = (string)($collectionRow['entry_type'] ?? 'collect');
    if ($rowType === 'withdraw') {
        $collectionMonthlyWithdrawTotal += $rowAmount;
        $collectionBalance -= $rowAmount;
        $collectionSectionSummaries[$sectionName]['withdraw_total'] = toFloat($collectionSectionSummaries[$sectionName]['withdraw_total'] + $rowAmount);
        $collectionSectionSummaries[$sectionName]['balance'] = toFloat($collectionSectionSummaries[$sectionName]['balance'] - $rowAmount);
    } else {
        $collectionMonthlyTotal += $rowAmount;
        $collectionBalance += $rowAmount;
        $collectionSectionSummaries[$sectionName]['collect_total'] = toFloat($collectionSectionSummaries[$sectionName]['collect_total'] + $rowAmount);
        $collectionSectionSummaries[$sectionName]['balance'] = toFloat($collectionSectionSummaries[$sectionName]['balance'] + $rowAmount);
    }
    $collectionSectionSummaries[$sectionName]['entry_count']++;
    $collectionSectionSummaries[$sectionName]['last_date'] = (string)($collectionRow['entry_date'] ?? '');
    $collectionRow['collection_name'] = $sectionName;
    $collectionRowsPrepared[] = $collectionRow;
    $collectionDays[(string)($collectionRow['entry_date'] ?? '')] = true;
}
$collectionMonthlyTotal = toFloat($collectionMonthlyTotal);
$collectionMonthlyWithdrawTotal = toFloat($collectionMonthlyWithdrawTotal);
$collectionBalance = toFloat($collectionBalance);
$collectionDayCount = count($collectionDays);

foreach ($collectionSectionOptions as $sectionOption) {
    if (!isset($collectionSectionSummaries[$sectionOption])) {
        $collectionSectionSummaries[$sectionOption] = [
            'name' => $sectionOption,
            'collect_total' => 0.0,
            'withdraw_total' => 0.0,
            'balance' => 0.0,
            'entry_count' => 0,
            'last_date' => '',
        ];
    }
}

$collectionSectionFilter = $collectionSectionParam === '' ? 'all' : $collectionSectionParam;
if ($collectionSectionFilter !== 'all' && !in_array($collectionSectionFilter, $collectionSectionOptions, true)) {
    $collectionSectionFilter = 'all';
}
$collectionRows = [];
$collectionFilteredCollectTotal = 0.0;
$collectionFilteredWithdrawTotal = 0.0;
$collectionFilteredBalance = 0.0;
foreach ($collectionRowsPrepared as $collectionRow) {
    $sectionName = (string)($collectionRow['collection_name'] ?? 'جمع عام');
    if ($collectionSectionFilter !== 'all' && $sectionName !== $collectionSectionFilter) {
        continue;
    }
    $rowAmount = toFloat($collectionRow['amount'] ?? 0);
    $rowType = (string)($collectionRow['entry_type'] ?? 'collect');
    if ($rowType === 'withdraw') {
        $collectionFilteredWithdrawTotal += $rowAmount;
        $collectionFilteredBalance -= $rowAmount;
    } else {
        $collectionFilteredCollectTotal += $rowAmount;
        $collectionFilteredBalance += $rowAmount;
    }
    $collectionRow['running_balance'] = toFloat($collectionFilteredBalance);
    $collectionRows[] = $collectionRow;
}
$collectionFilteredCollectTotal = toFloat($collectionFilteredCollectTotal);
$collectionFilteredWithdrawTotal = toFloat($collectionFilteredWithdrawTotal);
$collectionFilteredBalance = toFloat($collectionFilteredBalance);
$collectionSelectedSectionSummary = $collectionSectionFilter === 'all'
    ? null
    : ($collectionSectionSummaries[$collectionSectionFilter] ?? null);

$nextCollectionDayLabel = '';
if ($isCollectionActivePeriod) {
    $collectionNextTs = strtotime('+1 day', strtotime($collectionWorkDate));
    if ($collectionNextTs !== false && (int)date('n', $collectionNextTs) === $activeCollectionMonth && (int)date('Y', $collectionNextTs) === $activeCollectionYear) {
        $nextCollectionDayLabel = date('Y-m-d', $collectionNextTs);
    }
}
$debtCategoryTotals = [];
$debtCategoryRemaining = [];
$debtCategoryCounts = [];
$totalsStmt = $pdo->query('SELECT debt_category, COUNT(*) AS debt_count, COALESCE(SUM(amount),0) AS total_amount, COALESCE(SUM(amount - paid),0) AS total_remaining FROM debts GROUP BY debt_category');
foreach ($totalsStmt->fetchAll() as $row) {
    $debtCategoryTotals[(string)($row['debt_category'] ?? '')] = toFloat($row['total_amount'] ?? 0);
    $debtCategoryRemaining[(string)($row['debt_category'] ?? '')] = toFloat($row['total_remaining'] ?? 0);
    $debtCategoryCounts[(string)($row['debt_category'] ?? '')] = (int)($row['debt_count'] ?? 0);
}

// debt_sheet page: load specific category data
$sheetCategory = '';
$sheetCategoryId = 0;
$sheetDebts = [];
$sheetTotal = 0.0;
$sheetPaid = 0.0;
$sheetRemaining = 0.0;
if ($currentPage === 'debt_sheet') {
    $sheetCategory = trim((string)($_GET['cat'] ?? ''));
    if (empty($debtCategories)) {
        $sheetCategory = '';
    } elseif ($sheetCategory === '' || !in_array($sheetCategory, $debtCategories, true)) {
        $sheetCategory = $debtCategories[0];
    }
    $sheetCategoryId = (int)($debtCategoryIdByName[$sheetCategory] ?? 0);
    $stmt = $pdo->prepare('SELECT *, (amount - paid) AS remaining FROM debts WHERE debt_category = :cat ORDER BY date DESC');
    $stmt->execute(['cat' => $sheetCategory]);
    $sheetDebts = $stmt->fetchAll();
    foreach ($sheetDebts as $r) {
        $sheetTotal     = toFloat($sheetTotal + (float)($r['amount'] ?? 0));
        $sheetPaid      = toFloat($sheetPaid + (float)($r['paid'] ?? 0));
        $sheetRemaining = toFloat($sheetRemaining + (float)($r['remaining'] ?? 0));
    }
}
$backupRows = $pdo->query('SELECT * FROM backup_logs ORDER BY id DESC LIMIT 20')->fetchAll();

$employeeCountStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE is_active = 1 AND (TRIM(COALESCE(service_end_date, '')) = '' OR service_end_date > :today)");
$employeeCountStmt->execute(['today' => $todayIso]);
$employeeCount = (int)$employeeCountStmt->fetchColumn();
$salaryTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(s.net_salary),0) FROM salaries s JOIN employees e ON e.id = s.employee_id AND e.is_active = 1 WHERE s.month = :month AND s.year = :year');
$salaryTotalStmt->execute(['month' => $activePayrollMonth, 'year' => $activePayrollYear]);
$salaryTotal = toFloat($salaryTotalStmt->fetchColumn());
$restaurantSalaryTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(s.base_salary),0) FROM salaries s JOIN employees e ON e.id = s.employee_id AND e.is_active = 1 WHERE s.month = :month AND s.year = :year');
$restaurantSalaryTotalStmt->execute(['month' => $activePayrollMonth, 'year' => $activePayrollYear]);
$restaurantSalaryTotal = toFloat($restaurantSalaryTotalStmt->fetchColumn());
$deductionTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(s.deductions + s.loans),0) FROM salaries s JOIN employees e ON e.id = s.employee_id AND e.is_active = 1 WHERE s.month = :month AND s.year = :year');
$deductionTotalStmt->execute(['month' => $activePayrollMonth, 'year' => $activePayrollYear]);
$deductionTotal = toFloat($deductionTotalStmt->fetchColumn());
$profitTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(net_profit),0) FROM dailyfinance WHERE strftime("%m", date) = :m AND strftime("%Y", date) = :y');
$profitTotalStmt->execute(['m' => sprintf('%02d', $activePayrollMonth), 'y' => (string)$activePayrollYear]);
$profitTotal = toFloat($profitTotalStmt->fetchColumn());
$generalExpenseTotal = toFloat($pdo->query('SELECT COALESCE(SUM(amount),0) FROM general_expenses')->fetchColumn());
$debtRemainingTotal = toFloat($pdo->query('SELECT COALESCE(SUM(amount - paid),0) FROM debts')->fetchColumn());
$finalBalance = toFloat($profitTotal - $salaryTotal - $generalExpenseTotal - $debtRemainingTotal);

// Employee self-portal data
$selfEmployeeId = (int)($currentUser['id'] ?? 0);
$selfEmployee   = null;
$selfAttendanceRows  = [];
$selfSalaryRows      = [];
$selfAttendanceStats = ['حاضر' => 0, 'غائب' => 0, 'اجازة' => 0];
if ($isLoggedIn && $selfEmployeeId > 0 && $currentPage === 'myportal') {
    $stmt = $pdo->prepare('SELECT * FROM employees WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $selfEmployeeId]);
    $selfEmployee = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = :id ORDER BY date DESC');
    $stmt->execute(['id' => $selfEmployeeId]);
    $selfAttendanceRows = $stmt->fetchAll();
    foreach ($selfAttendanceRows as $row) {
        $s = $row['status'];
        if (isset($selfAttendanceStats[$s])) $selfAttendanceStats[$s]++;
    }

    $stmt = $pdo->prepare('SELECT * FROM salaries WHERE employee_id = :id ORDER BY year DESC, month DESC');
    $stmt->execute(['id' => $selfEmployeeId]);
    $selfSalaryRows = $stmt->fetchAll();
}

// Per-employee monthly attendance sheet data
$profileEmployee = null;
$profileDays = [];
$profileMonth = (int)($_GET['month'] ?? $activePayrollMonth);
$profileYear = (int)($_GET['year'] ?? $activePayrollYear);
$profileTotals = [
    'base_salary' => 0.0,
    'list_total' => 0.0,
    'loan_total' => 0.0,
    'deduction_total' => 0.0,
    'extra_total' => 0.0,
    'net_remaining' => 0.0,
];
$profileAttendanceCounts = ['حاضر' => 0, 'غائب' => 0, 'اجازة' => 0, 'انهاء خدمات' => 0];
$profileSalaryRecord = null;
$profileWhatsAppUrl = '';
$profileWhatsAppError = '';
if ($profileMonth < 1 || $profileMonth > 12) {
    $profileMonth = $activePayrollMonth;
}
if ($profileYear < 2000 || $profileYear > 2100) {
    $profileYear = $activePayrollYear;
}
$profilePeriodKey = payrollPeriodKey($profileMonth, $profileYear);
if (!isset($allowedPayrollPeriodKeys[$profilePeriodKey])) {
    $profileMonth = $activePayrollMonth;
    $profileYear = $activePayrollYear;
}
if ($isLoggedIn && $currentPage === 'employee_profile' && isset($_GET['emp_id'])) {
    $profileEmpId = (int)$_GET['emp_id'];
    if ($profileEmpId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM employees WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $profileEmpId]);
        $profileEmployee = $stmt->fetch();
    }

    if ($profileEmployee) {
        $canViewProfile = $isAdmin || strcasecmp((string)$currentRole, 'Manager') === 0 || $selfEmployeeId === (int)$profileEmployee['id'];
        if (!$canViewProfile) {
            $profileEmployee = null;
            $error = 'لا تملك صلاحية عرض ملف هذا الموظف.';
        } else {
            try {
                recalculateSalaryForMonth($pdo, (int)$profileEmployee['id'], $profileMonth, $profileYear);
            } catch (Throwable $e) {
                // Keep profile view available even if salary recalculation fails.
            }

            $monthStart = sprintf('%04d-%02d-01', $profileYear, $profileMonth);
            $daysInMonth = (int)date('t', strtotime($monthStart));
            $monthEnd = sprintf('%04d-%02d-%02d', $profileYear, $profileMonth, $daysInMonth);
            $attStmt = $pdo->prepare('SELECT date, status FROM attendance WHERE employee_id = :id AND date BETWEEN :start_date AND :end_date');
            $attStmt->execute([
                'id' => (int)$profileEmployee['id'],
                'start_date' => $monthStart,
                'end_date' => $monthEnd,
            ]);
            $attendanceMap = [];
            foreach ($attStmt->fetchAll() as $row) {
                $attendanceMap[$row['date']] = $row['status'];
            }

            $dailyEntryStmt = $pdo->prepare('SELECT date, list_amount, list_number, loan_amount, deduction_amount, extra_credit, day_note FROM employee_daily_entries WHERE employee_id = :id AND date BETWEEN :start_date AND :end_date');
            $dailyEntryStmt->execute([
                'id' => (int)$profileEmployee['id'],
                'start_date' => $monthStart,
                'end_date' => $monthEnd,
            ]);
            $dailyEntryMap = [];
            foreach ($dailyEntryStmt->fetchAll() as $entryRow) {
                $dailyEntryMap[$entryRow['date']] = $entryRow;
            }

            $profileTotals['base_salary'] = toFloat($profileEmployee['salary'] ?? 0);

            $salaryStmt = $pdo->prepare('SELECT * FROM salaries WHERE employee_id = :id AND month = :month AND year = :year LIMIT 1');
            $salaryStmt->execute([
                'id' => (int)$profileEmployee['id'],
                'month' => $profileMonth,
                'year' => $profileYear,
            ]);
            $profileSalaryRecord = $salaryStmt->fetch() ?: null;

            $arDays = [
                'Sunday' => 'الأحد',
                'Monday' => 'الاثنين',
                'Tuesday' => 'الثلاثاء',
                'Wednesday' => 'الأربعاء',
                'Thursday' => 'الخميس',
                'Friday' => 'الجمعة',
                'Saturday' => 'السبت',
            ];
            $profileStartDate = trim((string)($profileEmployee['start_date'] ?? ''));
            $profileServiceEndDate = trim((string)($profileEmployee['service_end_date'] ?? ''));
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = sprintf('%04d-%02d-%02d', $profileYear, $profileMonth, $day);
                if ($profileStartDate !== '' && isValidIsoDate($profileStartDate) && strcmp($date, $profileStartDate) < 0) {
                    continue;
                }
                if ($profileServiceEndDate !== '' && isValidIsoDate($profileServiceEndDate) && strcmp($date, $profileServiceEndDate) > 0) {
                    continue;
                }
                $dayNameEn = date('l', strtotime($date));
                $dayStatus = $attendanceMap[$date] ?? 'حاضر';
                if ($profileStartDate !== '' && isValidIsoDate($profileStartDate) && $date === $profileStartDate) {
                    $dayStatus = 'مباشرة عمل';
                }
                if ($profileServiceEndDate !== '' && isValidIsoDate($profileServiceEndDate) && $date === $profileServiceEndDate) {
                    $dayStatus = 'انهاء خدمات';
                }
                $entry = $dailyEntryMap[$date] ?? [];
                $listAmount = toFloat($entry['list_amount'] ?? 0);
                $loanAmount = toFloat($entry['loan_amount'] ?? 0);
                $deductionAmount = toFloat($entry['deduction_amount'] ?? 0);
                $extraCredit = toFloat($entry['extra_credit'] ?? 0);

                $profileTotals['list_total'] = toFloat($profileTotals['list_total'] + $listAmount);
                $profileTotals['loan_total'] = toFloat($profileTotals['loan_total'] + $loanAmount);
                $profileTotals['deduction_total'] = toFloat($profileTotals['deduction_total'] + $deductionAmount);
                $profileTotals['extra_total'] = toFloat($profileTotals['extra_total'] + $extraCredit);

                $profileDays[] = [
                    'date' => $date,
                    'day_name' => $arDays[$dayNameEn] ?? $dayNameEn,
                    'status' => $dayStatus,
                    'list_amount' => $listAmount,
                    'list_number' => (string)($entry['list_number'] ?? ''),
                    'loan_amount' => $loanAmount,
                    'deduction_amount' => $deductionAmount,
                    'extra_credit' => $extraCredit,
                    'day_note' => (string)($entry['day_note'] ?? ''),
                ];
            }

            $absentCount = 0;
            $leaveCount = 0;
            foreach ($profileDays as $d) {
                if ($d['status'] === 'حاضر') {
                    $profileAttendanceCounts['حاضر']++;
                } elseif ($d['status'] === 'غائب') {
                    $absentCount++;
                    $profileAttendanceCounts['غائب']++;
                } elseif ($d['status'] === 'اجازة') {
                    $leaveCount++;
                    $profileAttendanceCounts['اجازة']++;
                } elseif ($d['status'] === 'انهاء خدمات') {
                    $profileAttendanceCounts['انهاء خدمات']++;
                }
            }
            $dailySalaryProfile = toFloat($profileTotals['base_salary'] / 30);
            $attendanceDeduction = toFloat(($absentCount * $dailySalaryProfile * 2) + (max(0, $leaveCount - 2) * $dailySalaryProfile));
            $profileTotals['net_remaining'] = toFloat(
                $profileTotals['base_salary']
                - $attendanceDeduction
                - $profileTotals['list_total']
                - $profileTotals['loan_total']
                - $profileTotals['deduction_total']
                + $profileTotals['extra_total']
            );
            if (is_array($profileSalaryRecord)) {
                $profileTotals['loan_total'] = toFloat($profileSalaryRecord['loans'] ?? $profileTotals['loan_total']);
                // deduction_total stays as sum from daily entries table only
                $profileTotals['extra_total'] = toFloat(($profileSalaryRecord['bonuses'] ?? 0) + ($profileSalaryRecord['additions'] ?? 0));
                $profileTotals['net_remaining'] = toFloat($profileSalaryRecord['net_salary'] ?? $profileTotals['net_remaining']);
            }

            $salaryBase = $profileTotals['base_salary'];
            $salaryDeductions = toFloat($profileTotals['list_total'] + $profileTotals['deduction_total']);
            $salaryLoans = $profileTotals['loan_total'];
            $salaryAdditions = $profileTotals['extra_total'];
            $salaryNet = $profileTotals['net_remaining'];
            if (is_array($profileSalaryRecord)) {
                $salaryBase = toFloat($profileSalaryRecord['base_salary'] ?? $salaryBase);
                $salaryDeductions = toFloat($profileSalaryRecord['deductions'] ?? $salaryDeductions);
                $salaryLoans = toFloat($profileSalaryRecord['loans'] ?? $salaryLoans);
                $salaryAdditions = toFloat(($profileSalaryRecord['bonuses'] ?? 0) + ($profileSalaryRecord['additions'] ?? 0));
                $salaryNet = toFloat($profileSalaryRecord['net_salary'] ?? $salaryNet);
            }

            $waMessage = "تفاصيل الموظف الشهرية" . "\n"
                . "الاسم: " . (string)$profileEmployee['name'] . "\n"
                . "القسم: " . (string)($profileEmployee['department'] ?? '-') . "\n"
                . "الفترة: " . $profileMonth . "/" . $profileYear . "\n"
                . "الحضور: " . $profileAttendanceCounts['حاضر'] . " يوم" . "\n"
                . "الغياب: " . $profileAttendanceCounts['غائب'] . " يوم" . "\n"
                . "الإجازة: " . $profileAttendanceCounts['اجازة'] . " يوم" . "\n"
                . "الراتب الأساسي: " . number_format($salaryBase, 0) . " د.ع" . "\n"
                . "الخصومات: " . number_format($salaryDeductions, 0) . " د.ع" . "\n"
                . "السلف: " . number_format($salaryLoans, 0) . " د.ع" . "\n"
                . "الإضافات: " . number_format($salaryAdditions, 0) . " د.ع" . "\n"
                . "صافي الراتب: " . number_format($salaryNet, 0) . " د.ع";
            $profileWhatsAppUrl = buildWhatsAppUrl((string)($profileEmployee['phone'] ?? ''), $waMessage);
            if ($profileWhatsAppUrl === '') {
                $profileWhatsAppError = 'رقم هاتف الموظف غير صالح لواتساب.';
            }
        }
    }
}

$salarySlip = null;
if ($isLoggedIn && $currentPage === 'salary_slip' && isset($_GET['emp_id'], $_GET['month'], $_GET['year'])) {
    $slipEmpId = (int)$_GET['emp_id'];
    $slipMonth = (int)$_GET['month'];
    $slipYear = (int)$_GET['year'];

    if ($slipEmpId > 0 && $slipMonth >= 1 && $slipMonth <= 12 && $slipYear >= 2000) {
        $slipStmt = $pdo->prepare(
            'SELECT s.*, e.name AS employee_name, e.department, e.job_title
             FROM salaries s
             JOIN employees e ON e.id = s.employee_id
             WHERE s.employee_id = :id AND s.month = :month AND s.year = :year
             LIMIT 1'
        );
        $slipStmt->execute([
            'id' => $slipEmpId,
            'month' => $slipMonth,
            'year' => $slipYear,
        ]);
        $salarySlip = $slipStmt->fetch();

        if ($salarySlip) {
            $canViewSlip = $isAdmin || strcasecmp((string)$currentRole, 'Manager') === 0 || $selfEmployeeId === (int)$salarySlip['employee_id'];
            if (!$canViewSlip) {
                $salarySlip = null;
                $error = 'لا تملك صلاحية عرض هذا الوصل.';
            } else {
                $dailyMoneyStmt = $pdo->prepare(
                    "SELECT
                        COALESCE(SUM(list_amount), 0) AS list_total,
                        COALESCE(SUM(loan_amount), 0) AS loan_total,
                        COALESCE(SUM(deduction_amount), 0) AS deduction_total,
                        COALESCE(SUM(extra_credit), 0) AS extra_total
                     FROM employee_daily_entries
                     WHERE employee_id = :id
                       AND strftime('%m', date) = :m
                       AND strftime('%Y', date) = :y"
                );
                $dailyMoneyStmt->execute([
                    'id' => $slipEmpId,
                    'm' => sprintf('%02d', $slipMonth),
                    'y' => (string)$slipYear,
                ]);
                $dailyMoney = $dailyMoneyStmt->fetch() ?: [];
                $salarySlip['list_total'] = toFloat($dailyMoney['list_total'] ?? 0);
                $salarySlip['daily_loan_total'] = toFloat($dailyMoney['loan_total'] ?? 0);
                $salarySlip['daily_deduction_total'] = toFloat($dailyMoney['deduction_total'] ?? 0);
                $salarySlip['daily_extra_total'] = toFloat($dailyMoney['extra_total'] ?? 0);
                $salarySlip['issued_at'] = date('Y-m-d H:i');
                $salarySlip['receipt_no'] = 'SAL-' . $slipYear . sprintf('%02d', $slipMonth) . '-' . (int)$salarySlip['employee_id'];
            }
        }
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($appTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        :root { --brand-color: <?= h($primaryColor) ?>; }
        body { background: #f5f7fb; margin: 0; }
        /* ===== TOP NAVBAR ===== */
        .sidebar {
            background: linear-gradient(135deg, #0a0e1a 0%, #111827 60%, #0d1520 100%);
            border-radius: 0 0 20px 20px;
            border-bottom: 2px solid rgba(255,255,255,0.06);
            box-shadow: 0 8px 32px rgba(0,0,0,0.45), inset 0 1px 0 rgba(255,255,255,0.05);
            position: sticky; top: 0; z-index: 100;
            padding: 0 !important;
        }
        .navbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            padding: 12px 20px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .navbar-brand-area {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .navbar-brand-icon {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--brand-color), #f59e0b);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
            flex-shrink: 0;
        }
        .navbar-brand-text {
            color: #fff;
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-shadow: 0 1px 4px rgba(0,0,0,0.5);
        }
        .navbar-brand-sub {
            color: #94a3b8;
            font-size: 0.7rem;
            margin-top: -2px;
        }
        .navbar-logout {
            display: inline-flex; align-items: center; gap: 5px;
            color: #fbbf24;
            text-decoration: none;
            font-size: 0.82rem;
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid rgba(251,191,36,0.25);
            background: rgba(251,191,36,0.08);
            transition: all 0.2s;
        }
        .navbar-logout:hover { background: rgba(251,191,36,0.2); color: #fef3c7; border-color: rgba(251,191,36,0.5); }
        .sidebar .menu-grid {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px;
            padding: 10px 16px 12px;
        }
        .sidebar a, .menu-toggle {
            color: #cbd5e1;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 9px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.07);
            font-size: 0.87rem;
            font-weight: 500;
            transition: all 0.18s ease;
            cursor: pointer;
            white-space: nowrap;
        }
        .sidebar a:hover, .menu-toggle:hover {
            background: rgba(255,255,255,0.10);
            color: #fff;
            border-color: rgba(255,255,255,0.15);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .sidebar a.active {
            background: var(--brand-color);
            border-color: var(--brand-color);
            color: #fff;
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(0,0,0,0.35);
        }
        .menu-group { position: relative; }
        .menu-toggle.active {
            background: var(--brand-color);
            border-color: var(--brand-color);
            color: #fff;
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(0,0,0,0.35);
        }
        .menu-toggle .caret { font-size: 0.65rem; opacity: 0.7; transition: transform 0.2s; }
        .menu-group:hover .caret, .menu-group:focus-within .caret { transform: rotate(180deg); }
        .menu-sub {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            background: #0f172a;
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 12px;
            padding: 8px;
            min-width: 200px;
            z-index: 200;
            box-shadow: 0 16px 40px rgba(0,0,0,0.6), inset 0 1px 0 rgba(255,255,255,0.05);
        }
        .menu-sub::before {
            content: '';
            position: absolute;
            top: -6px; right: 14px;
            width: 10px; height: 10px;
            background: #0f172a;
            border-right: 1px solid rgba(255,255,255,0.10);
            border-top: 1px solid rgba(255,255,255,0.10);
            transform: rotate(-45deg);
        }
        .menu-sub a {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.84rem;
            margin-bottom: 3px;
            border: none;
            background: transparent;
            justify-content: flex-start;
        }
        .menu-sub a:last-child { margin-bottom: 0; }
        .menu-sub a:hover { background: rgba(255,255,255,0.08); transform: none; box-shadow: none; }
        .menu-sub a.active { background: var(--brand-color); }
        .menu-sub-divider { height: 1px; background: rgba(255,255,255,0.07); margin: 5px 4px; }
        .menu-group:hover .menu-sub,
        .menu-group:focus-within .menu-sub { display: block; animation: fadeSlide 0.15s ease; }
        @keyframes fadeSlide {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .nav-badge {
            display: inline-block;
            font-size: 0.62rem;
            background: #ef4444;
            color: #fff;
            border-radius: 50px;
            padding: 1px 5px;
            margin-right: 2px;
        }
        .card-stat { border: 0; border-radius: 14px; color: #fff; }
        .small-muted { color: #64748b; font-size: .9rem; }
        .employee-sheet tr.status-leave td { background: #fff8db !important; }
        .employee-sheet tr.status-absent td { background: #ffe3e3 !important; }
        .employee-sheet tr.status-start td { background: #dcfce7 !important; color: #166534; }
        .employee-sheet tr.status-ended td { background: #ffd6d6 !important; color: #7f1d1d; }
        .btn-primary { background-color: var(--brand-color); border-color: var(--brand-color); }
        .btn-outline-primary { color: var(--brand-color); border-color: var(--brand-color); }
        .btn-outline-primary:hover { background-color: var(--brand-color); border-color: var(--brand-color); color: #fff; }

        body.theme-dark { background: #0b1220; color: #e5e7eb; }
        body.theme-dark,
        body.theme-dark h1,
        body.theme-dark h2,
        body.theme-dark h3,
        body.theme-dark h4,
        body.theme-dark h5,
        body.theme-dark h6,
        body.theme-dark p,
        body.theme-dark span,
        body.theme-dark label,
        body.theme-dark th,
        body.theme-dark td,
        body.theme-dark div,
        body.theme-dark li,
        body.theme-dark a {
            color: #e2e8f0;
        }
        body.theme-dark .card { background: #111827; color: #e5e7eb; border-color: #1f2937 !important; }
        body.theme-dark .table { color: #e5e7eb; }
        body.theme-dark .table-striped > tbody > tr:nth-of-type(odd) > * { color: #e5e7eb; background-color: #0f172a; }
        body.theme-dark .table-hover > tbody > tr:hover > * { color: #fff; background-color: #1f2937; }
        body.theme-dark .table-dark { --bs-table-bg: #0f172a; --bs-table-color: #e5e7eb; }
        body.theme-dark .form-control,
        body.theme-dark .form-select { background: #0f172a; color: #e5e7eb; border-color: #334155; }
        body.theme-dark .form-control::placeholder { color: #94a3b8; }
        body.theme-dark .small-muted,
        body.theme-dark .text-muted { color: #cbd5e1 !important; }
        body.theme-dark .alert-info { background: #0f172a; color: #bae6fd; border-color: #155e75; }
        body.theme-dark .alert-success { background: #052e16; color: #bbf7d0; border-color: #166534; }
        body.theme-dark .alert-danger { background: #450a0a; color: #fecaca; border-color: #991b1b; }
    </style>
</head>
    <body class="<?= $isDarkMode ? 'theme-dark' : '' ?>">
<?php if (!$isLoggedIn): ?>
    <div class="container py-5" style="max-width:460px;">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h4 class="mb-3">تسجيل الدخول</h4>
                <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3"><label class="form-label">اسم المستخدم</label><input class="form-control" name="username" required></div>
                    <div class="mb-3"><label class="form-label">كلمة المرور</label><input class="form-control" type="password" name="password" required></div>
                    <button class="btn btn-primary w-100">دخول</button>
                </form>
            </div>
        </div>
    </div>
<?php else: ?>
    <aside class="sidebar">

                <!-- Brand + Logout Row -->
                <div class="navbar-inner">
                    <div class="navbar-brand-area">
                        <div class="navbar-brand-icon">&#127859;</div>
                        <div>
                            <div class="navbar-brand-text"><?= h($appTitle) ?></div>
                            <div class="navbar-brand-sub">نظام إدارة المطعم</div>
                        </div>
                    </div>
                    <a href="?logout=1" class="navbar-logout">&#10148; تسجيل خروج</a>
                </div>
                <!-- Navigation Menu -->
                <div class="menu-grid">
                    <?php if ($isAdmin || $currentRole === 'Manager'): ?>
                        <a class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>" href="<?= h(pageUrl('dashboard')) ?>">&#9776; لوحة التحكم</a>

                        <div class="menu-group">
                            <a class="menu-toggle <?= in_array($currentPage, ['employees', 'attendance', 'salaries', 'adjustments'], true) ? 'active' : '' ?>" href="<?= h(pageUrl('employees')) ?>">&#128101; الموظفين <span class="caret">&#9660;</span></a>
                            <div class="menu-sub">
                                <a class="<?= $currentPage === 'employees' ? 'active' : '' ?>" href="<?= h(pageUrl('employees')) ?>">&#128203; قائمة الموظفين</a>
                            </div>
                        </div>

                        <?php if ($isAdmin || $managerFinanceEnabled): ?>
                            <div class="menu-group">
                                <a class="menu-toggle <?= in_array($currentPage, ['daily_closing', 'daily_closing_record', 'expenses', 'expense_categories', 'collections', 'debts', 'debt_sheet', 'reports'], true) ? 'active' : '' ?>" href="<?= h(pageUrl('daily_closing')) ?>">&#128200; الحسابات <span class="caret">&#9660;</span></a>
                                <div class="menu-sub">
                                    <a class="<?= in_array($currentPage, ['daily_closing', 'daily_closing_record'], true) ? 'active' : '' ?>" href="<?= h(pageUrl('daily_closing')) ?>">&#128197; التقفيل اليومي</a>
                                    <a class="<?= in_array($currentPage, ['expenses', 'expense_categories'], true) ? 'active' : '' ?>" href="<?= h(pageUrl('expenses')) ?>">&#128176; المصاريف</a>
                                    <a class="<?= $currentPage === 'collections' ? 'active' : '' ?>" href="<?= h(pageUrl('collections')) ?>">&#128181; الجمع</a>
                                    <a class="<?= in_array($currentPage, ['debts','debt_sheet'], true) ? 'active' : '' ?>" href="<?= h(pageUrl('debts')) ?>">&#128179; الديون</a>
                                    <?php if ($isAdmin || $managerReportsEnabled): ?>
                                        <div class="menu-sub-divider"></div>
                                        <a class="<?= $currentPage === 'reports' ? 'active' : '' ?>" href="<?= h(pageUrl('reports')) ?>">&#128202; التقارير</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (($isAdmin || $managerReportsEnabled) && !($isAdmin || $managerFinanceEnabled)): ?>
                            <a class="<?= $currentPage === 'reports' ? 'active' : '' ?>" href="<?= h(pageUrl('reports')) ?>">&#128202; التقارير</a>
                        <?php endif; ?>

                        <a class="<?= in_array($currentPage, ['stock', 'stock_categories'], true) ? 'active' : '' ?>" href="<?= h(pageUrl('stock')) ?>">&#127968; مخازن المطعم</a>

                        <?php if ($isAdmin): ?>
                            <a class="<?= $currentPage === 'settings' ? 'active' : '' ?>" href="<?= h(pageUrl('settings')) ?>">&#9881; الإعدادات</a>
                        <?php endif; ?>
                        <?php if ($selfEmployeeId > 0): ?>
                            <a class="<?= $currentPage === 'myportal' ? 'active' : '' ?>" href="<?= h(pageUrl('myportal')) ?>">&#128100; حسابي الشخصي</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a class="<?= $currentPage === 'myportal' ? 'active' : '' ?>" href="<?= h(pageUrl('myportal')) ?>">&#128100; حسابي الشخصي</a>
                    <?php endif; ?>
                </div>
    </aside>
    <div class="container-fluid">
        <main class="p-3 p-lg-4">
                <?php if ($message !== ''): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

                <?php if ($currentPage === 'dashboard'): ?>
                <section id="dashboard" class="mb-4">
                    <h4 class="mb-3">لوحة التحكم</h4>
                    <div class="row g-3">
                        <div class="col-6 col-xl-3"><a href="<?= h(pageUrl('employees')) ?>" style="text-decoration:none;"><div class="card card-stat shadow-sm" style="background:#0284c7"><div class="card-body"><div>الموظفين</div><h3><?= $employeeCount ?></h3></div></div></a></div>
                        <div class="col-6 col-xl-3"><a href="<?= h(pageUrl('employees')) ?>" style="text-decoration:none;"><div class="card card-stat shadow-sm" style="background:#16a34a"><div class="card-body"><div>صافي الرواتب</div><h3><?= number_format($salaryTotal, 2) ?></h3></div></div></a></div>
                        <?php if ($showDeductionCard): ?>
                            <div class="col-6 col-xl-3"><div class="card card-stat shadow-sm" style="background:#dc2626"><div class="card-body"><div>الخصومات</div><h3><?= number_format($deductionTotal, 2) ?></h3></div></div></div>
                        <?php endif; ?>
                        <?php if ($showTotalSalaryCard): ?>
                            <div class="col-6 col-xl-3"><a href="<?= h(pageUrl('salaries')) ?>" style="text-decoration:none;"><div class="card card-stat shadow-sm" style="background:#7c3aed"><div class="card-body"><div>مجموع رواتب المطعم</div><h3><?= number_format($restaurantSalaryTotal, 2) ?></h3></div></div></a></div>
                        <?php endif; ?>
                        <div class="col-6 col-xl-3"><a href="<?= h(pageUrl('collections')) ?>" style="text-decoration:none;"><div class="card card-stat shadow-sm" style="background:linear-gradient(135deg,#b45309 0%,#ea580c 55%,#f59e0b 100%)"><div class="card-body"><div>الجمع</div><h3><?= number_format($activeCollectionBalanceCard, 0) ?></h3></div></div></a></div>
                        <div class="col-6 col-xl-3"><a href="<?= h(pageUrl('daily_closing')) ?>" style="text-decoration:none;"><div class="card card-stat shadow-sm" style="background:#475569"><div class="card-body"><div>التقفيل اليومي</div><h3>&#128197;</h3></div></div></a></div>
                        <div class="col-6 col-xl-3"><a href="<?= h(pageUrl('debts')) ?>" style="text-decoration:none;"><div class="card card-stat shadow-sm" style="background:#b45309"><div class="card-body"><div>الديون</div><h3>&#128179;</h3></div></div></a></div>
                        <div class="col-6 col-xl-3"><a href="<?= h(pageUrl('settings')) ?>" style="text-decoration:none;"><div class="card card-stat shadow-sm" style="background:#0f766e"><div class="card-body"><div>الإعدادات</div><h3>&#9881;</h3></div></div></a></div>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ($currentPage === 'employees'): ?>
                <section id="employees" class="mb-4">
                    <?php
                        $deptColors = ['مطبخ'=>'#b45309','كاشير'=>'#0284c7','صالة'=>'#16a34a','ادارة'=>'#7c3aed','تنظيف'=>'#0891b2','حسابات'=>'#dc2626','اراكيل'=>'#d97706'];
                        $employeeTotalCount = count($employees);
                        $employeeTotalSalary = 0.0;
                        foreach ($employees as $empRowForTotal) {
                            $employeeTotalSalary += (float)($empRowForTotal['salary'] ?? 0);
                        }
                        $employeeAvgSalary = $employeeTotalCount > 0 ? $employeeTotalSalary / $employeeTotalCount : 0.0;
                        $nextPayrollMonth = $activePayrollMonth === 12 ? 1 : $activePayrollMonth + 1;
                        $nextPayrollYear  = $activePayrollMonth === 12 ? $activePayrollYear + 1 : $activePayrollYear;
                    ?>
                    <style>
                        /* ── Employee Page ── */
                        .ep-header {
                            background: linear-gradient(135deg,#0f172a 0%,#1e3a8a 55%,#0369a1 100%);
                            border-radius: 16px; color:#fff; padding: 20px 24px;
                        }
                        .ep-header .sub { font-size:.88rem; color:rgba(255,255,255,.8); }
                        .ep-stat { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; }
                        .ep-stat .lbl { font-size:.75rem; color:#64748b; margin-bottom:2px; }
                        .ep-stat .val { font-size:1.3rem; font-weight:700; color:#0f172a; }
                        .ep-toolbar { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:10px 14px; }
                        .ep-dept-dot { width:10px; height:10px; border-radius:50%; display:inline-block; margin-left:5px; }
                        .ep-dept-filter {
                            border: 1px solid rgba(255,255,255,.28);
                            background: rgba(255,255,255,.15);
                            color: #fff;
                            border-radius: 999px;
                            padding: 3px 11px;
                            font-size: .76rem;
                            cursor: pointer;
                            transition: background .15s, border-color .15s, transform .1s;
                        }
                        .ep-dept-filter:hover { background: rgba(255,255,255,.23); border-color: rgba(255,255,255,.45); }
                        .ep-dept-filter:active { transform: translateY(1px); }
                        .ep-dept-filter.active { background:#fff; color:#0f172a; border-color:#fff; font-weight:700; }
                        .ep-table-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:visible; }
                        .ep-table-card > .table-responsive { border-radius:14px; overflow:hidden; }
                        /* custom fixed dropdown */
                        .ep-menu-wrap { position:relative; display:inline-block; }
                        .ep-actions-menu { display:none; position:fixed; z-index:99999; background:#fff; border:1px solid #dee2e6; border-radius:10px; min-width:200px; box-shadow:0 6px 20px rgba(0,0,0,.15); padding:5px 0; }
                        .ep-actions-menu.show { display:block; }
                        .ep-actions-menu a, .ep-actions-menu button.dropdown-item { display:block; width:100%; padding:8px 16px; text-align:right; background:none; border:none; color:#212529; text-decoration:none; font-size:.85rem; cursor:pointer; white-space:nowrap; }
                        .ep-actions-menu a:hover, .ep-actions-menu button.dropdown-item:hover { background:#f1f5f9; }
                        .ep-actions-menu .ep-divider { margin:4px 0; border:none; border-top:1px solid #e2e8f0; }
                        .ep-table-card table thead th { font-size:.78rem; white-space:nowrap; padding:10px 12px; background:#0f172a; color:#e2e8f0; border:none; }
                        .ep-table-card table tbody td { font-size:.83rem; padding:10px 12px; vertical-align:middle; border-color:#f1f5f9; }
                        .ep-table-card table tbody tr { transition:background .1s; }
                        .ep-table-card table tbody tr:hover > td { background:#f8fafc; }
                        .ep-name-link { font-weight:600; color:#1e3a8a; text-decoration:none; }
                        .ep-name-link:hover { color:#0369a1; text-decoration:underline; }
                        .ep-badge-dept { font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:999px; color:#fff; }
                        .ep-badge-role { font-size:.7rem; padding:2px 8px; border-radius:999px; background:#e5e7eb; color:#374151; font-weight:600; }
                        .ep-date-green { font-size:.72rem; font-weight:700; color:#166534; background:#dcfce7; border:1px solid #86efac; border-radius:999px; padding:2px 9px; }
                        .ep-date-red   { font-size:.72rem; font-weight:700; color:#991b1b; background:#fee2e2; border:1px solid #fca5a5; border-radius:999px; padding:2px 9px; }
                        .ep-net-positive { color:#16a34a; font-weight:700; }
                        .ep-net-negative { color:#dc2626; font-weight:700; }
                        .ep-ended-row > td { background:#fff5f5 !important; }
                        .ep-ended-row .ep-name-link { color:#9f1239; }
                        .ep-row-hidden { display:none !important; }
                        .ep-actions-btn { font-size:.72rem; padding:3px 10px; }
                        .ep-admin-panel { border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; }
                        .ep-admin-panel summary { background:#f8fafc; padding:10px 16px; cursor:pointer; font-weight:600; font-size:.9rem; }
                        .ep-admin-panel summary:hover { background:#f1f5f9; }
                        .ep-admin-panel .panel-body { padding:16px; }
                        .pay-status-badge { font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:999px; }
                        .pay-uncalc  { background:#e5e7eb; color:#374151; }
                        .pay-settled { background:#fecaca; color:#7f1d1d; }
                        .pay-paid    { background:#bbf7d0; color:#14532d; }
                        .pay-pending { background:#fef08a; color:#713f12; }
                        .pay-zero-delivered { background:#bae6fd; color:#0c4a6e; }
                        .pay-has-balance { background:#fde68a; color:#78350f; }
        .ep-note-input { font-size:.75rem; min-width:120px; max-width:180px; padding:2px 7px; height:26px; border-color:#cbd5e1; }
        .ep-note-save { font-size:.72rem; padding:2px 7px; line-height:1; height:26px; }
        .ep-note-form { flex-wrap:nowrap; }
                    </style>

                    <!-- ── Header ── -->
                    <div class="ep-header mb-4 shadow-sm">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <h4 class="mb-1 fw-bold">&#128101; إدارة الموظفين</h4>
                                <div class="sub">الشهر الفعّال: <strong><?= (int)$activePayrollMonth ?>/<?= (int)$activePayrollYear ?></strong></div>
                                <?php if (!empty($deptStats)): ?>
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <button type="button" class="ep-dept-filter active" data-dept-filter="all">كل الأقسام (<?= (int)$employeeTotalCount ?>)</button>
                                    <?php foreach ($deptStats as $ds):
                                        $deptName = trim((string)($ds['department'] ?? ''));
                                        if ($deptName === '') {
                                            continue;
                                        }
                                        $dc2 = $deptColors[$deptName] ?? '#64748b'; ?>
                                    <button type="button" class="ep-dept-filter" data-dept-filter="<?= h(mb_strtolower($deptName)) ?>">
                                        <span class="ep-dept-dot" style="background:<?= h($dc2) ?>"></span>
                                        <?= h($deptName) ?> (<?= (int)$ds['cnt'] ?>)
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($isAdmin): ?>
                            <a href="<?= h(pageUrl('add_employee')) ?>" class="btn btn-light btn-sm fw-semibold px-4">+ موظف جديد</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ── KPIs + Search ── -->
                    <div class="row g-2 mb-3">
                        <div class="col-6 col-md-3">
                            <div class="ep-stat shadow-sm h-100">
                                <div class="lbl">عدد الموظفين</div>
                                <div class="val"><?= (int)$employeeTotalCount ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="ep-stat shadow-sm h-100">
                                <div class="lbl">مجموع الرواتب</div>
                                <div class="val" style="font-size:1.05rem;"><?= number_format($employeeTotalSalary, 0) ?> <span style="font-size:.7rem;color:#64748b;">د.ع</span></div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="ep-stat shadow-sm h-100">
                                <div class="lbl">متوسط الراتب</div>
                                <div class="val" style="font-size:1.05rem;"><?= number_format($employeeAvgSalary, 0) ?> <span style="font-size:.7rem;color:#64748b;">د.ع</span></div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="ep-toolbar shadow-sm h-100 d-flex align-items-center gap-2">
                                <span style="font-size:1rem;color:#94a3b8;">&#128269;</span>
                                <input id="empSearchInput" class="form-control border-0 p-0 shadow-none" placeholder="بحث بالاسم أو القسم...">
                            </div>
                        </div>
                    </div>

                    <!-- ── Toolbar ── -->
                    <div class="ep-toolbar shadow-sm mb-3 d-flex flex-wrap align-items-center gap-2 justify-content-between">
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <select id="empStatusFilter" class="form-select form-select-sm" style="width:auto;min-width:160px;">
                                <option value="all">كل الموظفين</option>
                                <option value="active">النشطون فقط</option>
                                <option value="ended">منتهية الخدمة</option>
                            </select>
                            <select id="empSalaryStateFilter" class="form-select form-select-sm" style="width:auto;min-width:180px;">
                                <option value="all">كل حالات الراتب</option>
                                <option value="delivered">المستلمون للراتب</option>
                                <option value="with_balance">لديهم صافي مستحق</option>
                                <option value="uncalculated">غير محسوب</option>
                            </select>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= h(pageUrl('employees') . '&export=attendance') ?>">&#128196; حضور CSV</a>
                            <a class="btn btn-sm btn-outline-success" href="<?= h(pageUrl('employees') . '&export=salaries') ?>">&#128203; رواتب Excel</a>
                            <a class="btn btn-sm btn-outline-primary" href="<?= h(pageUrl('reports')) ?>">&#128202; التقارير</a>
                        </div>
                        <div class="small text-muted">المعروض الآن: <strong id="empVisibleCount"><?= (int)$employeeTotalCount ?></strong></div>
                    </div>

                    <!-- ── Admin tools (collapsible) ── -->
                    <?php if ($isAdmin): ?>
                    <div class="ep-admin-panel shadow-sm mb-3">
                        <details>
                            <summary>&#9881; أدوات الإدارة (خصم جماعي — أرشفة الشهر)</summary>
                            <div class="panel-body">
                                <!-- Bulk deduction -->
                                <h6 class="mb-2">خصم جماعي على الشهر الفعّال</h6>
                                <form method="post" class="row g-2 mb-3" onsubmit="return confirm('سيتم تطبيق الخصم على جميع الموظفين النشطين. متابعة؟');">
                                    <input type="hidden" name="action" value="bulk_deduct_all_employees">
                                    <input type="hidden" name="month" value="<?= (int)$activePayrollMonth ?>">
                                    <input type="hidden" name="year" value="<?= (int)$activePayrollYear ?>">
                                    <div class="col-md-3">
                                        <input class="form-control form-control-sm" type="number" step="0.01" min="0.01" name="amount" placeholder="المبلغ (د.ع)" required>
                                    </div>
                                    <div class="col-md-6">
                                        <input class="form-control form-control-sm" name="reason" placeholder="سبب الخصم" required>
                                    </div>
                                    <div class="col-md-3">
                                        <button class="btn btn-sm btn-outline-danger w-100">تطبيق الخصم</button>
                                    </div>
                                    <div class="col-12">
                                        <details class="mt-1">
                                            <summary class="small fw-semibold" style="cursor:pointer;">استثناء موظفين (اختياري)</summary>
                                            <div class="row g-2 mt-1">
                                                <?php foreach ($employees as $empOpt):
                                                    $empOptId = (int)($empOpt['id'] ?? 0);
                                                    $empOptName = trim((string)($empOpt['name'] ?? ''));
                                                    $empOptEndDate = trim((string)($empOpt['service_end_date'] ?? ''));
                                                    $empOptEligible = !empty($empOpt['is_active']) && ($empOptEndDate === '' || !isValidIsoDate($empOptEndDate) || $empOptEndDate > $todayIso);
                                                    if ($empOptId <= 0 || !$empOptEligible) continue;
                                                ?>
                                                <div class="col-12 col-md-6 col-lg-4">
                                                    <label class="d-flex align-items-center gap-2 border rounded p-2 small">
                                                        <input class="form-check-input m-0" type="checkbox" name="exclude_employee_ids[]" value="<?= $empOptId ?>">
                                                        <?= h($empOptName) ?>
                                                    </label>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </details>
                                    </div>
                                </form>
                                <hr class="my-2">
                                <!-- Payroll archive -->
                                <h6 class="mb-2">أرشفة الشهر وفتح الشهر التالي</h6>
                                <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                                    <input type="hidden" name="action" value="open_new_payroll_month">
                                    <input type="hidden" name="new_month" value="<?= (int)$nextPayrollMonth ?>">
                                    <input type="hidden" name="new_year" value="<?= (int)$nextPayrollYear ?>">
                                    <span class="small text-muted">الشهر التالي: <strong><?= (int)$nextPayrollMonth ?>/<?= (int)$nextPayrollYear ?></strong></span>
                                    <button class="btn btn-sm btn-outline-primary" onclick="return confirm('سيتم أرشفة الشهر الحالي وفتح الشهر التالي مع تصفير السلف والخصومات والقوائم الخاصة بالشهر الجديد. متابعة؟');">فتح الشهر التالي + أرشفة الحالي</button>
                                </form>
                            </div>
                        </details>
                    </div>
                    <?php endif; ?>

                    <!-- ── Employees Table ── -->
                    <div class="ep-table-card shadow-sm">
                        <div class="table-responsive">
                            <table class="table table-borderless mb-0" id="employeesTable">
                                <thead><tr>
                                    <th>#</th>
                                    <th>الموظف</th>
                                    <th>القسم / المسمى</th>
                                    <th>الاتصال</th>
                                    <th>تاريخ المباشرة</th>
                                    <th class="text-end">الراتب الشهري</th>
                                    <th class="text-end">صافي <?= (int)$activePayrollMonth ?>/<?= (int)$activePayrollYear ?></th>
                                    <th class="text-center">حالة التسليم</th>
                                    <th class="text-center">إجراءات</th>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($employees as $empIndex => $emp): ?>
                                        <?php
                                            $dc = $deptColors[$emp['department'] ?? ''] ?? '#64748b';
                                            $serviceEndDateValue = trim((string)($emp['service_end_date'] ?? ''));
                                            $isServiceEndedRow = ($serviceEndDateValue !== '' && isValidIsoDate($serviceEndDateValue) && $serviceEndDateValue <= $todayIso);
                                            $serviceState = $isServiceEndedRow ? 'ended' : 'active';
                                            $departmentFilterKey = mb_strtolower(trim((string)($emp['department'] ?? '')));
                                            $activeSalary = $activeMonthSalaryMap[(int)$emp['id']] ?? null;
                                            $activeNetSalary = is_array($activeSalary) ? toFloat($activeSalary['net_salary'] ?? 0) : null;
                                            $activePaid = is_array($activeSalary) && !empty($activeSalary['is_paid']);
                                            $activeSettled = is_array($activeSalary) && trim((string)($activeSalary['settled_at'] ?? '')) !== '';
                                            $isNetZeroOrLess = $activeNetSalary !== null && $activeNetSalary <= 0;
                                            $salaryState = !is_array($activeSalary)
                                                ? 'uncalculated'
                                                : ($isNetZeroOrLess || $activePaid || $activeSettled ? 'delivered' : 'with_balance');
                                            $startDateValue = trim((string)($emp['start_date'] ?? ''));
                                            $searchBlob = mb_strtolower(
                                                ($emp['name'] ?? '') . ' ' . ($emp['department'] ?? '') . ' ' .
                                                ($emp['job_title'] ?? '') . ' ' . ($emp['phone'] ?? '') . ' ' .
                                                ($emp['username'] ?? '') . ' ' . $startDateValue . ' ' . $serviceEndDateValue
                                            );
                                        ?>
                                        <tr data-search="<?= h($searchBlob) ?>"
                                            data-service-state="<?= h($serviceState) ?>"
                                            data-department="<?= h($departmentFilterKey) ?>"
                                            data-salary-state="<?= h($salaryState) ?>"
                                            class="<?= $isServiceEndedRow ? 'ep-ended-row' : '' ?>">

                                            <td class="text-muted" style="font-size:.75rem;"><?= (int)$empIndex + 1 ?></td>

                                            <!-- Name + role -->
                                            <td>
                                                <a href="<?= h(pageUrl('employee_profile') . '&emp_id=' . (int)$emp['id']) ?>" class="ep-name-link d-block">
                                                    <?= h((string)$emp['name']) ?>
                                                </a>
                                                <div class="d-flex gap-1 mt-1 flex-wrap">
                                                    <span class="ep-badge-role"><?= h((string)($emp['role'] ?? 'User')) ?></span>
                                                    <?php if ($isServiceEndedRow): ?>
                                                        <span class="ep-date-red">منتهية الخدمة</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- Dept + job title -->
                                            <td>
                                                <span class="ep-badge-dept" style="background:<?= h($dc) ?>"><?= h((string)($emp['department'] ?? '—')) ?></span>
                                                <?php if (!empty($emp['job_title'])): ?>
                                                    <div class="text-muted mt-1" style="font-size:.72rem;"><?= h((string)$emp['job_title']) ?></div>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Phone + username -->
                                            <td>
                                                <div><?= h((string)($emp['phone'] ?? '—')) ?></div>
                                                <div class="text-muted" style="font-size:.72rem;"><code><?= h((string)($emp['username'] ?? '')) ?></code></div>
                                            </td>

                                            <!-- Start date -->
                                            <td>
                                                <?php if ($startDateValue !== '' && isValidIsoDate($startDateValue)): ?>
                                                    <span class="ep-date-green"><?= h($startDateValue) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted" style="font-size:.75rem;">—</span>
                                                <?php endif; ?>
                                                <?php if ($serviceEndDateValue !== '' && isValidIsoDate($serviceEndDateValue)): ?>
                                                    <div class="mt-1"><span class="ep-date-red"><?= h($serviceEndDateValue) ?></span></div>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Monthly salary -->
                                            <td class="text-end fw-semibold">
                                                <?= number_format((float)$emp['salary'], 0) ?>
                                                <div class="text-muted fw-normal" style="font-size:.7rem;">يومي: <?= number_format((float)$emp['salary'] / 30, 0) ?></div>
                                            </td>

                                            <!-- Net salary active month -->
                                            <td class="text-end">
                                                <?php if ($activeNetSalary === null): ?>
                                                    <span class="text-muted">—</span>
                                                <?php else: ?>
                                                    <span class="<?= $activeNetSalary < 0 ? 'ep-net-negative' : 'ep-net-positive' ?>">
                                                        <?= number_format((float)$activeNetSalary, 0) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Delivery status -->
                                            <td class="text-center">
                                                <?php if (!is_array($activeSalary)): ?>
                                                    <span class="pay-status-badge pay-uncalc">غير محسوب</span>
                                                <?php elseif ($isNetZeroOrLess): ?>
                                                    <span class="pay-status-badge pay-zero-delivered">تم تسليم الراتب</span>
                                                <?php elseif ($activeSettled): ?>
                                                    <span class="pay-status-badge pay-settled">مصفّى</span>
                                                <?php elseif ($activePaid): ?>
                                                    <span class="pay-status-badge pay-paid">تم التسليم</span>
                                                <?php else: ?>
                                                    <span class="pay-status-badge pay-has-balance">لديه صافي مستحق</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Actions dropdown -->
                                            <td class="text-center">
                                                <div class="ep-menu-wrap">
                                                    <button class="btn btn-sm btn-outline-secondary ep-actions-btn" onclick="epToggleMenu(event,this)">
                                                        إجراءات &#9660;
                                                    </button>
                                                    <div class="ep-actions-menu" dir="rtl">
                                                        <a href="<?= h(pageUrl('employee_profile') . '&emp_id=' . (int)$emp['id']) ?>">
                                                            &#128197; ملف الموظف
                                                        </a>
                                                        <a href="<?= h(pageUrl('salary_slip') . '&emp_id=' . (int)$emp['id'] . '&month=' . (int)$activePayrollMonth . '&year=' . (int)$activePayrollYear) ?>">
                                                            &#128203; وصل الراتب
                                                        </a>
                                                        <?php
                                                            $empWaSalaryStateText = $isNetZeroOrLess || $activePaid || $activeSettled ? 'تم تسليم الراتب' : 'لديه صافي مستحق';
                                                            if (!is_array($activeSalary)) { $empWaSalaryStateText = 'غير محسوب'; }
                                                            $empWaMsg = "وصل راتب \n"
                                                                . "الموظف: " . (string)($emp['name'] ?? '') . "\n"
                                                                . "القسم: " . (string)($emp['department'] ?? '-') . "\n"
                                                                . "الفترة: " . (int)$activePayrollMonth . "/" . (int)$activePayrollYear . "\n"
                                                                . "الراتب الأساسي: " . number_format(is_array($activeSalary) ? (float)($activeSalary['base_salary'] ?? 0) : 0, 0) . " د.ع\n"
                                                                . "الغياب: " . (is_array($activeSalary) ? (int)($activeSalary['absence_days'] ?? 0) : 0) . " يوم\n"
                                                                . "الإجازة: " . (is_array($activeSalary) ? (int)($activeSalary['leave_days'] ?? 0) : 0) . " يوم\n"
                                                                . "الخصومات: " . number_format(is_array($activeSalary) ? (float)($activeSalary['deductions'] ?? 0) : 0, 0) . " د.ع\n"
                                                                . "السلف: " . number_format(is_array($activeSalary) ? (float)($activeSalary['loans'] ?? 0) : 0, 0) . " د.ع\n"
                                                                . "المكافآت: " . number_format(is_array($activeSalary) ? (float)($activeSalary['bonuses'] ?? 0) : 0, 0) . " د.ع\n"
                                                                . "الإضافات: " . number_format(is_array($activeSalary) ? (float)($activeSalary['additions'] ?? 0) : 0, 0) . " د.ع\n"
                                                                . "صافي الراتب: " . number_format($activeNetSalary !== null ? $activeNetSalary : 0, 0) . " د.ع\n"
                                                                . "الحالة: " . $empWaSalaryStateText;
                                                            $empWaUrl = buildWhatsAppUrl((string)($emp['phone'] ?? ''), $empWaMsg);
                                                        ?>
                                                        <?php if ($empWaUrl !== ''): ?>
                                                            <a href="<?= h($empWaUrl) ?>" target="_blank" rel="noopener noreferrer" style="color:#16a34a;">
                                                                &#128232; إرسال وصل الراتب واتساب
                                                            </a>
                                                        <?php else: ?>
                                                            <span style="display:block;padding:8px 16px;color:#94a3b8;font-size:.85rem;cursor:default;">&#128232; لا يوجد رقم هاتف</span>
                                                        <?php endif; ?>
                                                        <form method="post" class="m-0">
                                                            <input type="hidden" name="action" value="calculate_salary">
                                                            <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
                                                            <input type="hidden" name="month" value="<?= (int)$activePayrollMonth ?>">
                                                            <input type="hidden" name="year" value="<?= (int)$activePayrollYear ?>">
                                                            <button class="dropdown-item text-end">&#128181; احتساب الراتب</button>
                                                        </form>
                                                        <?php if ($isAdmin || $isManager): ?>
                                                        <hr class="ep-divider">
                                                        <form method="post" class="m-0">
                                                            <input type="hidden" name="action" value="mark_salary_delivered">
                                                            <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
                                                            <input type="hidden" name="month" value="<?= (int)$activePayrollMonth ?>">
                                                            <input type="hidden" name="year" value="<?= (int)$activePayrollYear ?>">
                                                            <input type="hidden" name="return_page" value="employees">
                                                            <input type="hidden" name="payment_note" value="تم التسليم من جدول الموظفين">
                                                            <button class="dropdown-item text-end text-success">&#10003; تسليم الراتب</button>
                                                        </form>
                                                        <form method="post" class="m-0" onsubmit="return confirm('سيتم تصفية وتصفير الراتب. متابعة؟');">
                                                            <input type="hidden" name="action" value="settle_reset_salary">
                                                            <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
                                                            <input type="hidden" name="month" value="<?= (int)$activePayrollMonth ?>">
                                                            <input type="hidden" name="year" value="<?= (int)$activePayrollYear ?>">
                                                            <input type="hidden" name="return_page" value="employees">
                                                            <input type="hidden" name="payment_note" value="تصفية من جدول الموظفين">
                                                            <button class="dropdown-item text-end text-danger">&#8635; تصفية وتصفير</button>
                                                        </form>
                                                        <?php endif; ?>
                                                        <?php if ($isAdmin): ?>
                                                        <hr class="ep-divider">
                                                        <a href="<?= h(pageUrl('edit_employee') . '&emp_id=' . (int)$emp['id']) ?>">
                                                            &#9998; تعديل البيانات
                                                        </a>
                                                        <form method="post" class="m-0 js-ajax-delete-employee">
                                                            <input type="hidden" name="action" value="delete_employee">
                                                            <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
                                                            <button class="dropdown-item text-end text-danger">&#128465; حذف الموظف</button>
                                                        </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <script>
                    (function () {
                        var searchInput  = document.getElementById('empSearchInput');
                        var statusFilter = document.getElementById('empStatusFilter');
                        var salaryStateFilter = document.getElementById('empSalaryStateFilter');
                        var table        = document.getElementById('employeesTable');
                        var visibleCountEl = document.getElementById('empVisibleCount');
                        var deptFilterButtons = Array.prototype.slice.call(document.querySelectorAll('.ep-dept-filter'));
                        if (!searchInput || !statusFilter || !salaryStateFilter || !table) return;
                        var tbody = table.querySelector('tbody');
                        if (!tbody) return;
                        var selectedDepartment = 'all';

                        function getRows() {
                            return Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                        }

                        function renumberRows() {
                            getRows().forEach(function (row, index) {
                                var firstCell = row.querySelector('td');
                                if (firstCell) {
                                    firstCell.textContent = String(index + 1);
                                }
                            });
                        }

                        function applyFilters() {
                            var q     = (searchInput.value || '').trim().toLowerCase();
                            var state = (statusFilter.value || 'all').toLowerCase();
                            var salaryState = (salaryStateFilter.value || 'all').toLowerCase();
                            var dept  = selectedDepartment;
                            var visibleCount = 0;
                            getRows().forEach(function (row) {
                                var hay      = (row.getAttribute('data-search') || '').toLowerCase();
                                var rowState = (row.getAttribute('data-service-state') || 'active');
                                var rowDept  = (row.getAttribute('data-department') || '').toLowerCase();
                                var rowSalaryState = (row.getAttribute('data-salary-state') || 'uncalculated').toLowerCase();
                                var hide =
                                    (q !== '' && hay.indexOf(q) === -1) ||
                                    (state !== 'all' && rowState !== state) ||
                                    (dept !== 'all' && rowDept !== dept) ||
                                    (salaryState !== 'all' && rowSalaryState !== salaryState);
                                row.classList.toggle('ep-row-hidden', hide);
                                if (!hide) {
                                    visibleCount += 1;
                                }
                            });
                            if (visibleCountEl) {
                                visibleCountEl.textContent = String(visibleCount);
                            }
                        }

                        deptFilterButtons.forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                selectedDepartment = (btn.getAttribute('data-dept-filter') || 'all').toLowerCase();
                                deptFilterButtons.forEach(function (x) { x.classList.remove('active'); });
                                btn.classList.add('active');
                                applyFilters();
                            });
                        });

                        if (window.fetch) {
                            table.addEventListener('submit', function (event) {
                                var form = event.target;
                                if (!form || !form.classList || !form.classList.contains('js-ajax-delete-employee')) {
                                    return;
                                }
                                event.preventDefault();

                                if (!confirm('تأكيد حذف الموظف نهائياً؟')) {
                                    return;
                                }

                                var submitBtn = form.querySelector('button[type="submit"], button:not([type])');
                                if (submitBtn) {
                                    submitBtn.disabled = true;
                                }

                                fetch(window.location.pathname + window.location.search, {
                                    method: 'POST',
                                    body: new FormData(form),
                                    credentials: 'same-origin',
                                    headers: {
                                        'X-Requested-With': 'XMLHttpRequest',
                                        'Accept': 'application/json'
                                    }
                                })
                                .then(function (response) {
                                    return response.json().catch(function () {
                                        return { ok: false, message: 'تعذر قراءة استجابة الخادم.' };
                                    });
                                })
                                .then(function (data) {
                                    if (!data || !data.ok) {
                                        alert((data && data.message) ? data.message : 'فشل حذف الموظف.');
                                        return;
                                    }

                                    var row = form.closest('tr');
                                    if (row) {
                                        row.remove();
                                        renumberRows();
                                        applyFilters();
                                    }
                                })
                                .catch(function () {
                                    alert('حدث خطأ أثناء الحذف.');
                                })
                                .finally(function () {
                                    if (submitBtn) {
                                        submitBtn.disabled = false;
                                    }
                                });
                            });
                        }

                        searchInput.addEventListener('input', applyFilters);
                        statusFilter.addEventListener('change', applyFilters);
                        salaryStateFilter.addEventListener('change', applyFilters);
                        applyFilters();
                    })();
                    </script>
                </section>
                <?php endif; ?>

                <?php if ($currentPage === 'add_employee'): ?>
                <section class="mb-4">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <a href="<?= h(pageUrl('employees')) ?>" class="btn btn-outline-secondary btn-sm">&#8594; رجوع</a>
                        <h4 class="mb-0">إضافة موظف جديد</h4>
                    </div>
                    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
                    <div class="card shadow-sm border-0"><div class="card-body">
                        <form method="post" id="addEmpForm">
                            <input type="hidden" name="action" value="add_employee">
                            <div class="row g-3">
                                <!-- الاسم -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">اسم الموظف <span class="text-danger">*</span></label>
                                    <input class="form-control" name="name" placeholder="الاسم الكامل" required>
                                </div>
                                <!-- القسم -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">القسم <span class="text-danger">*</span></label>
                                    <select class="form-select" id="addEmpDepartmentSelect" name="department" required>
                                        <option value="">-- اختر القسم --</option>
                                        <?php foreach ($employeeDepartments as $d): ?>
                                            <option value="<?= h($d) ?>" <?= $defaultEmployeeDepartment === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                                        <?php endforeach; ?>
                                        <option value="__custom_department__">+ إضافة قسم جديد</option>
                                    </select>
                                </div>
                                <div class="col-md-6" id="addEmpNewDepartmentWrap" style="display:none;">
                                    <label class="form-label fw-semibold">اسم القسم الجديد <span class="text-danger">*</span></label>
                                    <input class="form-control" id="addEmpNewDepartmentInput" name="department_custom" maxlength="80" placeholder="اكتب اسم القسم الجديد">
                                </div>
                                <!-- الراتب الشهري -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">الراتب الشهري</label>
                                    <input class="form-control" name="salary" id="monthlySalary" type="number" step="0.01" min="0" placeholder="0.00" oninput="calcDaily()">
                                </div>
                                <!-- الراتب اليومي -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">الراتب اليومي <span class="text-muted" style="font-size:.8rem;">(÷ 30)</span></label>
                                    <input class="form-control bg-light" id="dailySalaryDisplay" type="text" readonly placeholder="يُحسب تلقائياً" style="color:#16a34a;font-weight:600;">
                                </div>
                                <!-- مكان العمل -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">مكان العمل</label>
                                    <select class="form-select" name="job_title">
                                        <option value="">-- اختر مكان العمل --</option>
                                        <option value="مطعم حجاية">مطعم حجاية</option>
                                        <option value="مطعم وكوفي القلعة">مطعم وكوفي القلعة</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">تاريخ مباشرة الموظف</label>
                                    <input class="form-control" type="date" name="start_date" value="<?= h(date('Y-m-d')) ?>">
                                </div>
                                <!-- الهاتف -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">رقم الهاتف</label>
                                    <input class="form-control" name="phone" placeholder="05xxxxxxxx">
                                </div>
                                <!-- الصلاحية -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">الصلاحية</label>
                                    <select class="form-select" name="role">
                                        <option value="User">موظف (User)</option>
                                        <option value="Manager">مدير (Manager)</option>
                                        <option value="Admin">مشرف (Admin)</option>
                                    </select>
                                </div>

                                <div class="col-12"><hr class="my-1"><p class="text-muted mb-1" style="font-size:.85rem;">&#128274; بيانات الدخول — يستخدمها الموظف لمشاهدة رواتبه وحضوره</p></div>

                                <!-- اسم المستخدم -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">اسم المستخدم <span class="text-danger">*</span></label>
                                    <input class="form-control" name="username" placeholder="مثال: ahmed2026" required autocomplete="off">
                                </div>
                                <!-- كلمة المرور -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">كلمة المرور <span class="text-danger">*</span></label>
                                    <input class="form-control" name="password" type="password" placeholder="كلمة المرور" required autocomplete="new-password">
                                </div>

                                <div class="col-12 d-flex gap-2 mt-2">
                                    <button type="submit" class="btn btn-primary px-4">&#10003; حفظ الموظف</button>
                                    <a href="<?= h(pageUrl('employees')) ?>" class="btn btn-outline-secondary">إلغاء</a>
                                </div>
                            </div>
                        </form>
                    </div></div>
                </section>
                <script>
                function calcDaily() {
                    var m = parseFloat(document.getElementById('monthlySalary').value) || 0;
                    document.getElementById('dailySalaryDisplay').value = m > 0 ? (m / 30).toFixed(2) : '';
                }

                (function () {
                    var departmentSelect = document.getElementById('addEmpDepartmentSelect');
                    var customWrap = document.getElementById('addEmpNewDepartmentWrap');
                    var customInput = document.getElementById('addEmpNewDepartmentInput');
                    var form = document.getElementById('addEmpForm');
                    if (!departmentSelect || !customWrap || !customInput || !form) return;

                    function syncCustomDepartmentInput() {
                        var isCustom = departmentSelect.value === '__custom_department__';
                        customWrap.style.display = isCustom ? '' : 'none';
                        customInput.required = isCustom;
                        if (!isCustom) {
                            customInput.value = '';
                        }
                    }

                    departmentSelect.addEventListener('change', syncCustomDepartmentInput);
                    form.addEventListener('submit', function (event) {
                        if (departmentSelect.value === '__custom_department__' && customInput.value.trim() === '') {
                            event.preventDefault();
                            customInput.focus();
                        }
                    });

                    syncCustomDepartmentInput();
                })();
                </script>
                <?php endif; ?>

                <?php if ($currentPage === 'edit_employee'): ?>
                <section class="mb-4">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <a href="<?= h(pageUrl('employees')) ?>" class="btn btn-outline-secondary btn-sm">&#8594; رجوع</a>
                        <h4 class="mb-0">تعديل بيانات الموظف</h4>
                    </div>
                    <?php if (!$editEmployee): ?>
                        <div class="alert alert-danger">الموظف غير موجود.</div>
                    <?php else: ?>
                    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
                    <div class="card shadow-sm border-0"><div class="card-body">
                        <form method="post">
                            <input type="hidden" name="action" value="update_employee">
                            <input type="hidden" name="employee_id" value="<?= (int)$editEmployee['id'] ?>">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">اسم الموظف <span class="text-danger">*</span></label>
                                    <input class="form-control" name="name" value="<?= h((string)$editEmployee['name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">القسم</label>
                                    <select class="form-select" name="department">
                                        <option value="">-- اختر القسم --</option>
                                        <?php
                                            $editDepartmentOptions = $employeeDepartments;
                                            $currentEditDepartment = trim((string)($editEmployee['department'] ?? ''));
                                            if ($currentEditDepartment !== '' && !in_array($currentEditDepartment, $editDepartmentOptions, true)) {
                                                $editDepartmentOptions[] = $currentEditDepartment;
                                            }
                                        ?>
                                        <?php foreach ($editDepartmentOptions as $d): ?>
                                            <option value="<?= h($d) ?>" <?= ($editEmployee['department'] ?? '') === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">الراتب الشهري</label>
                                    <input class="form-control" name="salary" id="editMonthlySalary" type="number" step="0.01" min="0" value="<?= (float)$editEmployee['salary'] ?>" oninput="calcDailyEdit()">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">الراتب اليومي <span class="text-muted" style="font-size:.8rem;">(÷ 30)</span></label>
                                    <input class="form-control bg-light" id="editDailyDisplay" type="text" readonly value="<?= number_format((float)$editEmployee['salary'] / 30, 2) ?>" style="color:#16a34a;font-weight:600;">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">المسمى الوظيفي</label>
                                    <input class="form-control" name="job_title" value="<?= h((string)($editEmployee['job_title'] ?? '')) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">رقم الهاتف</label>
                                    <input class="form-control" name="phone" value="<?= h((string)($editEmployee['phone'] ?? '')) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">تاريخ مباشرة الموظف</label>
                                    <input class="form-control" type="date" name="start_date" value="<?= h((string)($editEmployee['start_date'] ?? date('Y-m-d'))) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">الصلاحية</label>
                                    <select class="form-select" name="role">
                                        <?php foreach (['User'=>'موظف (User)','Manager'=>'مدير (Manager)','Admin'=>'مشرف (Admin)'] as $rv=>$rl): ?>
                                            <option value="<?= h($rv) ?>" <?= ($editEmployee['role'] ?? 'User') === $rv ? 'selected' : '' ?>><?= h($rl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12"><hr class="my-1"><p class="text-muted mb-1" style="font-size:.85rem;">&#128274; بيانات الدخول — اتركها فارغة إن لم ترد تغييرها</p></div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">اسم المستخدم <span class="text-danger">*</span></label>
                                    <input class="form-control" name="username" value="<?= h((string)($editEmployee['username'] ?? '')) ?>" required autocomplete="off">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">كلمة المرور الجديدة <span class="text-muted">(اختياري)</span></label>
                                    <input class="form-control" name="password" type="password" placeholder="اتركها فارغة للإبقاء على القديمة" autocomplete="new-password">
                                </div>
                                <div class="col-12 d-flex gap-2 mt-2">
                                    <button type="submit" class="btn btn-primary px-4">&#10003; حفظ التعديلات</button>
                                    <a href="<?= h(pageUrl('employees')) ?>" class="btn btn-outline-secondary">إلغاء</a>
                                </div>
                            </div>
                        </form>
                    </div></div>
                    <?php endif; ?>
                </section>
                <script>
                function calcDailyEdit() {
                    var m = parseFloat(document.getElementById('editMonthlySalary').value) || 0;
                    document.getElementById('editDailyDisplay').value = m > 0 ? (m / 30).toFixed(2) : '';
                }
                </script>
                <?php endif; ?>

                <?php if ($currentPage === 'employee_profile'): ?>
                <section class="mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-3 gap-2 flex-wrap">
                        <div class="d-flex align-items-center gap-2">
                            <a href="<?= h(pageUrl('employees')) ?>" class="btn btn-outline-secondary btn-sm">&#8594; رجوع للموظفين</a>
                            <h4 class="mb-0">ملف الموظف</h4>
                        </div>
                        <?php if ($profileEmployee): ?>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <span class="badge bg-primary" style="font-size:.95rem;"><?= h((string)$profileEmployee['name']) ?></span>
                                <a href="<?= h(pageUrl('employee_profile') . '&emp_id=' . (int)$profileEmployee['id'] . '&month=' . (int)$profileMonth . '&year=' . (int)$profileYear) ?>" class="btn btn-outline-secondary btn-sm">تحديث الصفحة</a>
                                <button type="submit" form="employeeMonthSheetForm" class="btn btn-success btn-sm">حفظ الجدول</button>
                                <span class="badge" style="background:#0ea5e9;font-size:.9rem;">القسم: <?= h((string)($profileEmployee['department'] ?? 'غير محدد')) ?></span>
                                <?php if ($profileWhatsAppUrl !== ''): ?>
                                    <a href="<?= h($profileWhatsAppUrl) ?>" target="_blank" class="btn btn-success btn-sm">إرسال التفاصيل إلى واتساب</a>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><?= h($profileWhatsAppError) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$profileEmployee): ?>
                        <div class="alert alert-warning">اختر موظفاً من قائمة الموظفين لعرض ملفه.</div>
                    <?php else: ?>
                        <div class="card shadow-sm border-0 mb-3">
                            <div class="card-body">
                                <form method="get" class="row g-2 align-items-end">
                                    <input type="hidden" name="page" value="employee_profile">
                                    <input type="hidden" name="emp_id" value="<?= (int)$profileEmployee['id'] ?>">
                                    <div class="col-md-3">
                                        <label class="form-label">الشهر</label>
                                        <input class="form-control" type="number" name="month" min="1" max="12" value="<?= (int)$profileMonth ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">السنة</label>
                                        <input class="form-control" type="number" name="year" min="2000" max="2100" value="<?= (int)$profileYear ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <button class="btn btn-outline-primary w-100">عرض الجدول الشهري</button>
                                    </div>
                                </form>
                                <form method="post" action="index.php?page=employee_profile&amp;emp_id=<?= (int)$profileEmployee['id'] ?>&amp;month=<?= (int)$profileMonth ?>&amp;year=<?= (int)$profileYear ?>" class="mt-2 d-flex gap-2 flex-wrap">
                                    <input type="hidden" name="action" value="calculate_salary">
                                    <input type="hidden" name="employee_id" value="<?= (int)$profileEmployee['id'] ?>">
                                    <input type="hidden" name="month" value="<?= (int)$profileMonth ?>">
                                    <input type="hidden" name="year" value="<?= (int)$profileYear ?>">
                                    <input type="hidden" name="redirect_to_slip" value="1">
                                    <button type="submit" class="btn btn-outline-dark btn-sm">طباعة/حفظ PDF لوصل الراتب</button>
                                </form>
                                <?php if ($isAdmin || $isManager): ?>
                                <form method="post" action="index.php?page=employee_profile&amp;emp_id=<?= (int)$profileEmployee['id'] ?>&amp;month=<?= (int)$profileMonth ?>&amp;year=<?= (int)$profileYear ?>" class="mt-2 d-flex gap-2 flex-wrap align-items-end">
                                    <input type="hidden" name="employee_id" value="<?= (int)$profileEmployee['id'] ?>">
                                    <input type="hidden" name="month" value="<?= (int)$profileMonth ?>">
                                    <input type="hidden" name="year" value="<?= (int)$profileYear ?>">
                                    <input type="hidden" name="return_page" value="employee_profile">
                                    <div class="flex-grow-1" style="min-width:220px;">
                                        <label class="form-label mb-1">ملاحظة التسليم/التصفية (اختياري)</label>
                                        <input class="form-control form-control-sm" name="payment_note" placeholder="مثال: تم التسليم نقداً">
                                    </div>
                                    <button type="submit" name="action" value="mark_salary_delivered" class="btn btn-outline-success btn-sm">تم تسليم الراتب</button>
                                    <button type="submit" name="action" value="settle_reset_salary" class="btn btn-outline-danger btn-sm" onclick="return confirm('سيتم تصفية وتصفير راتب هذا الشهر للموظف. متابعة؟');">تصفية وتصفير</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm text-white" style="background:#1d4ed8"><div class="card-body py-2"><div style="font-size:.75rem;">الراتب الكلي</div><strong><?= number_format((float)($profileSalaryRecord['base_salary'] ?? $profileTotals['base_salary']), 0) ?> د.ع</strong><div style="font-size:.7rem;opacity:.9;">تعريفي: <?= number_format((float)($profileEmployee['salary'] ?? 0), 0) ?> د.ع</div></div></div></div>
                            <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm text-white" style="background:#f59e0b"><div class="card-body py-2"><div style="font-size:.75rem;">مجموع القوائم</div><strong><?= number_format((float)$profileTotals['list_total'], 0) ?> د.ع</strong></div></div></div>
                            <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm text-white" style="background:#ef4444"><div class="card-body py-2"><div style="font-size:.75rem;">مجموع السلف</div><strong><?= number_format((float)$profileTotals['loan_total'], 0) ?> د.ع</strong></div></div></div>
                            <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm text-white" style="background:#b91c1c"><div class="card-body py-2"><div style="font-size:.75rem;">مجموع الخصم</div><strong><?= number_format((float)$profileTotals['deduction_total'], 0) ?> د.ع</strong></div></div></div>
                            <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm text-white" style="background:#16a34a"><div class="card-body py-2"><div style="font-size:.75rem;">الرصيد الإضافي</div><strong><?= number_format((float)$profileTotals['extra_total'], 0) ?> د.ع</strong></div></div></div>
                            <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm text-white" style="background:#0f766e"><div class="card-body py-2"><div style="font-size:.75rem;">صافي الراتب المتبقي</div><strong><?= number_format((float)$profileTotals['net_remaining'], 0) ?> د.ع</strong></div></div></div>
                        </div>

                        <div class="card shadow-sm border-0">
                            <div class="card-body p-0">
                                <form method="post" id="employeeMonthSheetForm">
                                    <input type="hidden" name="action" value="save_employee_month_attendance">
                                    <input type="hidden" name="employee_id" value="<?= (int)$profileEmployee['id'] ?>">
                                    <input type="hidden" name="month" value="<?= (int)$profileMonth ?>">
                                    <input type="hidden" name="year" value="<?= (int)$profileYear ?>">

                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped align-middle mb-0 employee-sheet">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th style="width: 180px;">التاريخ</th>
                                                    <th style="width: 160px;">اليوم</th>
                                                    <th style="width: 160px;">الحضور</th>
                                                    <th style="min-width: 140px;">القائمة (د.ع)</th>
                                                    <th style="min-width: 170px;">رقم القائمة</th>
                                                    <th style="min-width: 130px;">سلفة (د.ع)</th>
                                                    <th style="min-width: 130px;">خصم (د.ع)</th>
                                                    <th style="min-width: 140px;">رصيد إضافي (د.ع)</th>
                                                    <th style="min-width: 160px;">ملاحظة</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($profileDays as $dayRow): ?>
                                                    <tr class="<?= $dayRow['status'] === 'غائب' ? 'status-absent' : ($dayRow['status'] === 'اجازة' ? 'status-leave' : ($dayRow['status'] === 'مباشرة عمل' ? 'status-start' : ($dayRow['status'] === 'انهاء خدمات' ? 'status-ended' : ''))) ?>">
                                                        <td><?= h((string)$dayRow['date']) ?></td>
                                                        <td><?= h((string)$dayRow['day_name']) ?></td>
                                                        <td>
                                                            <select class="form-select form-select-sm" onchange="updateStatusRow(this)" name="status_by_date[<?= h((string)$dayRow['date']) ?>]">
                                                                <option value="حاضر" <?= $dayRow['status'] === 'حاضر' ? 'selected' : '' ?>>حاضر</option>
                                                                <option value="اجازة" <?= $dayRow['status'] === 'اجازة' ? 'selected' : '' ?>>إجازة</option>
                                                                <option value="غائب" <?= $dayRow['status'] === 'غائب' ? 'selected' : '' ?>>غائب</option>
                                                                <option value="مباشرة عمل" <?= $dayRow['status'] === 'مباشرة عمل' ? 'selected' : '' ?>>مباشرة عمل</option>
                                                                <option value="انهاء خدمات" <?= $dayRow['status'] === 'انهاء خدمات' ? 'selected' : '' ?>>إنهاء خدمات</option>
                                                            </select>
                                                        </td>
                                                        <td><input class="form-control form-control-sm" type="number" step="1" min="0" name="list_amount_by_date[<?= h((string)$dayRow['date']) ?>]" value="<?= number_format((float)$dayRow['list_amount'], 0, '.', '') ?>"></td>
                                                        <td><input class="form-control form-control-sm" type="text" name="list_number_by_date[<?= h((string)$dayRow['date']) ?>]" value="<?= h((string)$dayRow['list_number']) ?>" placeholder="ملاحظة/رقم"></td>
                                                        <td><input class="form-control form-control-sm" type="number" step="1" min="0" name="loan_amount_by_date[<?= h((string)$dayRow['date']) ?>]" value="<?= number_format((float)$dayRow['loan_amount'], 0, '.', '') ?>"></td>
                                                        <td><input class="form-control form-control-sm" type="number" step="1" min="0" name="deduction_amount_by_date[<?= h((string)$dayRow['date']) ?>]" value="<?= number_format((float)$dayRow['deduction_amount'], 0, '.', '') ?>"></td>
                                                        <td><input class="form-control form-control-sm" type="number" step="1" min="0" name="extra_credit_by_date[<?= h((string)$dayRow['date']) ?>]" value="<?= number_format((float)$dayRow['extra_credit'], 0, '.', '') ?>"></td>
                                                        <td><input class="form-control form-control-sm" type="text" name="day_note_by_date[<?= h((string)$dayRow['date']) ?>]" value="<?= h((string)($dayRow['day_note'] ?? '')) ?>" placeholder="ملاحظة..."></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="p-3 border-top bg-light d-flex justify-content-end">
                                        <button type="submit" class="btn btn-success">حفظ جدول الدوام الشهري</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <div class="small-muted mt-2">القائمة والسلفة والخصم تُطرح من الراتب، بينما الرصيد الإضافي يُضاف للراتب.</div>
                    <?php endif; ?>
                </section>
                <script>
                function updateStatusRow(sel) {
                    var tr = sel.closest('tr');
                    tr.classList.remove('status-leave', 'status-absent', 'status-start', 'status-ended');
                    if (sel.value === 'اجازة') tr.classList.add('status-leave');
                    if (sel.value === 'غائب') tr.classList.add('status-absent');
                    if (sel.value === 'مباشرة عمل') tr.classList.add('status-start');
                    if (sel.value === 'انهاء خدمات') tr.classList.add('status-ended');
                }
                </script>
                <?php endif; ?>

                <?php if ($currentPage === 'salary_slip'): ?>
                <?php $slipMonths = ['', 'يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر']; ?>
                <section class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
                        <a href="<?= h(pageUrl('salaries')) ?>" class="btn btn-outline-secondary btn-sm">&#8594; الرجوع للرواتب</a>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">طباعة / حفظ PDF</button>
                        </div>
                    </div>

                    <?php if (!$salarySlip): ?>
                        <div class="alert alert-warning">لا يوجد وصل راتب لهذا الموظف في الشهر المحدد. قم باحتساب الراتب أولاً.</div>
                    <?php else: ?>
                        <div id="salarySlipBox" class="card border-0 shadow-sm" style="max-width:900px;margin:auto;">
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between align-items-start mb-3 gap-2 flex-wrap">
                                    <div class="d-flex align-items-center gap-2">
                                        <div style="width:48px;height:48px;border-radius:50%;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;">AY</div>
                                        <div>
                                            <h4 class="mb-0">وصل راتب موظف</h4>
                                            <div class="small-muted"><?= h($appTitle) ?></div>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <div class="small-muted">رقم الوصل</div>
                                        <div class="fw-semibold"><?= h((string)$salarySlip['receipt_no']) ?></div>
                                        <div class="small-muted mt-1">تاريخ الإصدار: <?= h((string)$salarySlip['issued_at']) ?></div>
                                    </div>
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-4"><div class="p-2 border rounded bg-light"><strong>اسم الموظف:</strong> <?= h((string)$salarySlip['employee_name']) ?></div></div>
                                    <div class="col-md-4"><div class="p-2 border rounded bg-light"><strong>القسم:</strong> <?= h((string)($salarySlip['department'] ?? '')) ?></div></div>
                                    <div class="col-md-4"><div class="p-2 border rounded bg-light"><strong>الفترة:</strong> <?= h($slipMonths[(int)$salarySlip['month']] ?? (string)$salarySlip['month']) ?> / <?= (int)$salarySlip['year'] ?></div></div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-3">
                                        <tbody>
                                            <tr><th style="width:45%;">الراتب الأساسي</th><td><?= number_format((float)$salarySlip['base_salary'], 0) ?> د.ع</td></tr>
                                            <tr><th>الراتب اليومي</th><td><?= number_format((float)$salarySlip['daily_salary'], 0) ?> د.ع</td></tr>
                                            <tr><th>أيام الغياب</th><td><?= (int)$salarySlip['absence_days'] ?> يوم</td></tr>
                                            <tr><th>أيام الإجازة</th><td><?= (int)$salarySlip['leave_days'] ?> يوم</td></tr>
                                            <tr><th>مجموع القوائم (من الجدول)</th><td><?= number_format((float)($salarySlip['list_total'] ?? 0), 0) ?> د.ع</td></tr>
                                            <tr><th>مجموع السلف (من الجدول)</th><td><?= number_format((float)($salarySlip['daily_loan_total'] ?? 0), 0) ?> د.ع</td></tr>
                                            <tr><th>مجموع الخصومات الإضافية (من الجدول)</th><td><?= number_format((float)($salarySlip['daily_deduction_total'] ?? 0), 0) ?> د.ع</td></tr>
                                            <tr><th>مجموع الإضافات اليومية (من الجدول)</th><td><?= number_format((float)($salarySlip['daily_extra_total'] ?? 0), 0) ?> د.ع</td></tr>
                                            <tr><th>إجمالي الخصومات</th><td class="text-danger fw-semibold"><?= number_format((float)$salarySlip['deductions'], 0) ?> د.ع</td></tr>
                                            <tr><th>إجمالي السلف</th><td class="text-danger fw-semibold"><?= number_format((float)$salarySlip['loans'], 0) ?> د.ع</td></tr>
                                            <tr><th>المكافآت</th><td class="text-success fw-semibold"><?= number_format((float)$salarySlip['bonuses'], 0) ?> د.ع</td></tr>
                                            <tr><th>الإضافات</th><td class="text-success fw-semibold"><?= number_format((float)$salarySlip['additions'], 0) ?> د.ع</td></tr>
                                            <tr class="table-success"><th>صافي الراتب المستحق</th><td class="fw-bold fs-5 text-success"><?= number_format((float)$salarySlip['net_salary'], 0) ?> د.ع</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="d-flex justify-content-end mb-3">
                                    <div style="border:2px dashed #16a34a;color:#166534;padding:6px 14px;border-radius:10px;font-weight:700;transform:rotate(-8deg);">تم الصرف</div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-4 text-center">
                                        <div class="border-top pt-2">توقيع الموظف</div>
                                    </div>
                                    <div class="col-4 text-center">
                                        <div class="border-top pt-2">توقيع المحاسب</div>
                                    </div>
                                    <div class="col-4 text-center">
                                        <div class="border-top pt-2">توقيع الإدارة</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
                <style>
                @media print {
                    .sidebar, .btn, .alert, .small-muted { display: none !important; }
                    main { width: 100% !important; }
                    #salarySlipBox { box-shadow: none !important; border: 1px solid #ddd !important; }
                }
                </style>
                <?php endif; ?>

                <?php if ($currentPage === 'myportal'): ?>
                <?php $arMonths = ['','يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر']; ?>
                <?php if ($selfEmployeeId <= 0): ?>
                    <div class="alert alert-warning">هذه الصفحة مخصصة للموظفين فقط.</div>
                <?php else: ?>
                <section class="mb-4">
                    <h4 class="mb-3">&#128100; حسابي الشخصي — <?= h((string)($selfEmployee['name'] ?? $currentUser['name'])) ?></h4>
                    <?php if ($selfEmployee): ?>
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="card text-white shadow-sm border-0" style="background:#0284c7;">
                                <div class="card-body text-center py-3">
                                    <div style="font-size:.85rem;">أيام الحضور</div>
                                    <h3 class="mb-0"><?= $selfAttendanceStats['حاضر'] ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card text-white shadow-sm border-0" style="background:#dc2626;">
                                <div class="card-body text-center py-3">
                                    <div style="font-size:.85rem;">أيام الغياب</div>
                                    <h3 class="mb-0"><?= $selfAttendanceStats['غائب'] ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card text-white shadow-sm border-0" style="background:#d97706;">
                                <div class="card-body text-center py-3">
                                    <div style="font-size:.85rem;">أيام الإجازة</div>
                                    <h3 class="mb-0"><?= $selfAttendanceStats['اجازة'] ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card text-white shadow-sm border-0" style="background:#16a34a;">
                                <div class="card-body text-center py-3">
                                    <div style="font-size:.85rem;">الراتب الأساسي</div>
                                    <h3 class="mb-0"><?= number_format((float)($selfEmployee['salary'] ?? 0), 2) ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- جدول الرواتب -->
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-body">
                            <h6 class="mb-3">&#128181; سجل الرواتب</h6>
                            <?php if (empty($selfSalaryRows)): ?>
                                <p class="text-muted small">لا توجد سجلات رواتب بعد.</p>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>الشهر</th>
                                            <th>الراتب الأساسي</th>
                                            <th>الخصومات</th>
                                            <th>السلف</th>
                                            <th>المكافآت</th>
                                            <th>الإضافات</th>
                                            <th>أيام غياب</th>
                                            <th>أيام إجازة</th>
                                            <th class="text-success fw-bold">صافي الراتب</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($selfSalaryRows as $sr): ?>
                                        <tr>
                                            <td><?= h($arMonths[(int)$sr['month']] ?? $sr['month']) ?> <?= (int)$sr['year'] ?></td>
                                            <td><?= number_format((float)$sr['base_salary'], 2) ?></td>
                                            <td class="text-danger"><?= number_format((float)$sr['deductions'], 2) ?></td>
                                            <td class="text-danger"><?= number_format((float)$sr['loans'], 2) ?></td>
                                            <td class="text-success"><?= number_format((float)$sr['bonuses'], 2) ?></td>
                                            <td class="text-success"><?= number_format((float)$sr['additions'], 2) ?></td>
                                            <td><?= (int)($sr['absence_days'] ?? 0) ?></td>
                                            <td><?= (int)($sr['leave_days'] ?? 0) ?></td>
                                            <td class="text-success fw-bold"><?= number_format((float)$sr['net_salary'], 2) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- جدول الحضور والغياب -->
                    <div class="card shadow-sm border-0">
                        <div class="card-body">
                            <h6 class="mb-3">&#128197; سجل الحضور والغياب</h6>
                            <?php if (empty($selfAttendanceRows)): ?>
                                <p class="text-muted small">لا توجد سجلات حضور بعد.</p>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle">
                                    <thead class="table-dark">
                                        <tr><th>التاريخ</th><th>الحالة</th><th>الملاحظة</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($selfAttendanceRows as $ar): ?>
                                        <tr>
                                            <td><?= h((string)$ar['date']) ?></td>
                                            <td>
                                                <?php if ($ar['status'] === 'حاضر'): ?>
                                                    <span class="badge bg-success">حاضر</span>
                                                <?php elseif ($ar['status'] === 'غائب'): ?>
                                                    <span class="badge bg-danger">غائب</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">إجازة</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h((string)($ar['note'] ?? '')) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($currentPage === 'attendance'): ?>
                <section id="attendance" class="card shadow-sm border-0 mb-4"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2"><h5 class="mb-0">إدارة الحضور</h5><a class="btn btn-outline-success btn-sm" href="<?= h(pageUrl('attendance') . '&export=attendance') ?>">تصدير Excel</a></div>
                    <form method="post" class="row g-2 mb-3">
                        <input type="hidden" name="action" value="record_attendance">
                        <div class="col-md-4">
                            <select class="form-select" name="employee_id" required>
                                <option value="">الموظف</option>
                                <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= h((string)$emp['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3"><input class="form-control" type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-md-3"><select class="form-select" name="status"><option>حاضر</option><option>غائب</option><option>اجازة</option></select></div>
                        <div class="col-md-2"><button class="btn btn-primary w-100">حفظ</button></div>
                        <div class="col-12"><input class="form-control" name="note" placeholder="ملاحظة"></div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped"><thead><tr><th>التاريخ</th><th>الموظف</th><th>الحالة</th><th>الملاحظة</th></tr></thead><tbody>
                        <?php foreach ($attendanceRows as $row): ?><tr><td><?= h((string)$row['date']) ?></td><td><?= h((string)$row['name']) ?></td><td><?= h((string)$row['status']) ?></td><td><?= h((string)$row['note']) ?></td></tr><?php endforeach; ?>
                        </tbody></table>
                    </div>
                </div></section>
                <?php endif; ?>

                <?php if ($currentPage === 'adjustments'): ?>
                <section id="adjustments" class="card shadow-sm border-0 mb-4"><div class="card-body">
                    <h5>السلف والخصومات والمكافآت والإضافات</h5>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="add_adjustment">
                        <div class="col-md-3"><select class="form-select" name="employee_id" required><?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= h((string)$emp['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><input class="form-control" type="number" name="month" min="1" max="12" value="<?= (int)$activePayrollMonth ?>"></div>
                        <div class="col-md-2"><input class="form-control" type="number" name="year" value="<?= (int)$activePayrollYear ?>"></div>
                        <div class="col-md-2"><select class="form-select" name="type"><option value="loan">سلفة</option><option value="deduction">خصم</option><option value="bonus">مكافأة</option><option value="addition">إضافة</option></select></div>
                        <div class="col-md-2"><input class="form-control" type="number" step="0.01" name="amount" placeholder="المبلغ" required></div>
                        <div class="col-md-1"><button class="btn btn-primary w-100">حفظ</button></div>
                        <div class="col-12"><input class="form-control" name="note" placeholder="ملاحظة"></div>
                    </form>
                </div></section>
                <?php endif; ?>

                <?php if ($currentPage === 'salaries'): ?>
                <?php
                    $salaryRowsCount = count($salaryRows);
                    $salaryNetTotalView = 0.0;
                    $salaryDeductionTotalView = 0.0;
                    $salaryLoanTotalView = 0.0;
                    foreach ($salaryRows as $sRowView) {
                        $salaryNetTotalView += (float)($sRowView['net_salary'] ?? 0);
                        $salaryDeductionTotalView += (float)($sRowView['deductions'] ?? 0);
                        $salaryLoanTotalView += (float)($sRowView['loans'] ?? 0);
                    }
                ?>
                <section id="salaries" class="mb-4">
                    <style>
                        .sal-hero { border-radius: 14px; background: linear-gradient(135deg,#1f2937 0%,#0f766e 55%,#0ea5a4 100%); color: #fff; padding: 16px; }
                        .sal-hero .sub { color: rgba(255,255,255,.86); font-size: .9rem; }
                        .sal-kpi { border: 1px solid #dbe4ee; border-radius: 12px; background: #fff; padding: 12px; }
                        .sal-kpi .lbl { color: #64748b; font-size: .78rem; }
                        .sal-kpi .val { color: #0f172a; font-size: 1.2rem; font-weight: 700; }
                        .sal-panel { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
                        .sal-table-wrap { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #fff; }
                        .sal-table-wrap thead th { white-space: nowrap; }
                        .sal-role { font-size: .72rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #e2e8f0; color: #334155; display: inline-block; }
                        .sal-hidden { display: none; }
                    </style>

                    <div class="sal-hero mb-3 shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h4 class="mb-1">قسم الرواتب</h4>
                            <div class="sub">يعتمد مباشرة على بيانات الموظفين النشطين في الشهر المحدد: <strong><?= (int)$salaryFilterMonth ?>/<?= (int)$salaryFilterYear ?></strong></div>
                        </div>
                        <a class="btn btn-light btn-sm fw-semibold" href="<?= h(pageUrl('salaries') . '&export=salaries') ?>">تصدير Excel</a>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6 col-lg-3"><div class="sal-kpi shadow-sm h-100"><div class="lbl">عدد الرواتب</div><div class="val"><?= (int)$salaryRowsCount ?></div></div></div>
                        <div class="col-6 col-lg-3"><div class="sal-kpi shadow-sm h-100"><div class="lbl">مجموع الصافي</div><div class="val"><?= number_format($salaryNetTotalView, 2) ?></div></div></div>
                        <div class="col-6 col-lg-3"><div class="sal-kpi shadow-sm h-100"><div class="lbl">مجموع الخصومات</div><div class="val"><?= number_format($salaryDeductionTotalView, 2) ?></div></div></div>
                        <div class="col-6 col-lg-3"><div class="sal-kpi shadow-sm h-100"><div class="lbl">مجموع السلف</div><div class="val"><?= number_format($salaryLoanTotalView, 2) ?></div></div></div>
                    </div>

                    <div class="sal-panel shadow-sm p-3 mb-3">
                        <form method="get" class="row g-2 mb-3">
                            <input type="hidden" name="page" value="salaries">
                            <div class="col-md-6">
                                <select class="form-select" name="salary_period" required>
                                    <?php foreach ($allowedPayrollPeriods as $period): ?>
                                        <?php $selectedPeriod = ((int)$period['month'] === (int)$salaryFilterMonth && (int)$period['year'] === (int)$salaryFilterYear); ?>
                                        <option value="<?= (int)$period['month'] ?>-<?= (int)$period['year'] ?>" <?= $selectedPeriod ? 'selected' : '' ?>><?= h((string)$period['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3"><button class="btn btn-outline-primary w-100">تطبيق الفترة</button></div>
                            <div class="col-md-3"><a class="btn btn-outline-secondary w-100" href="<?= h(pageUrl('salaries') . '&salary_period=' . $activePayrollMonth . '-' . $activePayrollYear) ?>">العودة للشهر الفعّال</a></div>
                        </form>

                        <form method="post" class="row g-2 align-items-end">
                            <input type="hidden" name="action" value="calculate_salary">
                            <div class="col-md-5">
                                <label class="form-label small mb-1">اختيار الموظف (من قسم الموظفين)</label>
                                <select class="form-select" name="employee_id" required>
                                    <?php
                                    $preEmpId = (int)($_GET['emp_id'] ?? 0);
                                    foreach ($employees as $emp):
                                    ?>
                                    <option value="<?= (int)$emp['id'] ?>" <?= $preEmpId === (int)$emp['id'] ? 'selected' : '' ?>><?= h((string)$emp['name']) ?> - <?= h((string)($emp['department'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2"><label class="form-label small mb-1">الشهر</label><input class="form-control" type="number" name="month" min="1" max="12" value="<?= (int)$salaryFilterMonth ?>"></div>
                            <div class="col-md-2"><label class="form-label small mb-1">السنة</label><input class="form-control" type="number" name="year" value="<?= (int)$salaryFilterYear ?>"></div>
                            <div class="col-md-3"><button class="btn btn-success w-100">حساب صافي الراتب</button></div>
                        </form>
                    </div>

                    <?php if (is_array($salaryData)): ?>
                        <div class="alert alert-info">
                            <strong>Slip</strong> - <?= h($salaryData['employee_name']) ?> | <?= (int)$salaryData['month'] ?>/<?= (int)$salaryData['year'] ?>
                            | Net: <?= number_format((float)$salaryData['net_salary'], 2) ?>
                        </div>
                    <?php endif; ?>

                    <div class="sal-panel shadow-sm p-3 mb-3">
                        <input id="salarySearchInput" class="form-control" placeholder="بحث داخل الرواتب بالاسم، القسم، المسمى، الدور...">
                    </div>

                    <div class="sal-table-wrap shadow-sm">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="salariesTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>الموظف</th>
                                        <th>القسم</th>
                                        <th>المسمى</th>
                                        <th>الدور</th>
                                        <th>الشهر</th>
                                        <th>الأساسي</th>
                                        <th>الغياب</th>
                                        <th>الإجازات</th>
                                        <th>الخصومات</th>
                                        <th>السلف</th>
                                        <th>المكافآت</th>
                                        <th>الإضافات</th>
                                        <th>الصافي</th>
                                        <th>الحالة</th>
                                        <th>وصل</th>
                                        <th>إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($salaryRows as $row): ?>
                                        <?php
                                            $isPaidSalary = !empty($row['is_paid']);
                                            $isSettledSalary = trim((string)($row['settled_at'] ?? '')) !== '';
                                            $salaryStateText = $isSettledSalary ? 'مصفّى' : ($isPaidSalary ? 'تم التسليم' : 'غير مسلّم');
                                            $searchBlob = strtolower(trim((string)($row['name'] ?? '') . ' ' . (string)($row['department'] ?? '') . ' ' . (string)($row['job_title'] ?? '') . ' ' . (string)($row['role'] ?? '') . ' ' . (string)($row['month'] ?? '') . ' ' . (string)($row['year'] ?? '') . ' ' . $salaryStateText));
                                            $rowWaMsg = "وصل راتب \n"
                                                . "الموظف: " . (string)($row['name'] ?? '') . "\n"
                                                . "القسم: " . (string)($row['department'] ?? '-') . "\n"
                                                . "الفترة: " . (int)$row['month'] . "/" . (int)$row['year'] . "\n"
                                                . "الراتب الأساسي: " . number_format((float)$row['base_salary'], 0) . " د.ع\n"
                                                . "أيام الغياب: " . (int)$row['absence_days'] . "\n"
                                                . "أيام الإجازة: " . (int)$row['leave_days'] . "\n"
                                                . "الخصومات: " . number_format((float)$row['deductions'], 0) . " د.ع\n"
                                                . "السلف: " . number_format((float)$row['loans'], 0) . " د.ع\n"
                                                . "المكافآت: " . number_format((float)$row['bonuses'], 0) . " د.ع\n"
                                                . "الإضافات: " . number_format((float)$row['additions'], 0) . " د.ع\n"
                                                . "صافي الراتب: " . number_format((float)$row['net_salary'], 0) . " د.ع\n"
                                                . "الحالة: " . $salaryStateText;
                                            $rowWaUrl = buildWhatsAppUrl((string)($row['phone'] ?? ''), $rowWaMsg);
                                        ?>
                                        <tr data-search="<?= h($searchBlob) ?>">
                                            <td class="fw-semibold"><?= h((string)$row['name']) ?></td>
                                            <td><?= h((string)($row['department'] ?? '')) ?></td>
                                            <td class="text-muted"><?= h((string)($row['job_title'] ?? '')) ?></td>
                                            <td><span class="sal-role"><?= h((string)($row['role'] ?? 'User')) ?></span></td>
                                            <td><?= (int)$row['month'] ?>/<?= (int)$row['year'] ?></td>
                                            <td><?= number_format((float)$row['base_salary'], 2) ?></td>
                                            <td><?= (int)$row['absence_days'] ?></td>
                                            <td><?= (int)$row['leave_days'] ?></td>
                                            <td class="text-danger"><?= number_format((float)$row['deductions'], 2) ?></td>
                                            <td><?= number_format((float)$row['loans'], 2) ?></td>
                                            <td class="text-success"><?= number_format((float)$row['bonuses'], 2) ?></td>
                                            <td class="text-success"><?= number_format((float)$row['additions'], 2) ?></td>
                                            <td class="fw-bold <?= ((float)$row['net_salary'] >= 0) ? 'text-success' : 'text-danger' ?>"><?= number_format((float)$row['net_salary'], 2) ?></td>
                                            <td>
                                                <?php if ($isSettledSalary): ?>
                                                    <span class="badge bg-danger">مصفّى</span>
                                                <?php elseif ($isPaidSalary): ?>
                                                    <span class="badge bg-success">تم التسليم</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">غير مسلّم</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><a class="btn btn-outline-dark btn-sm" href="<?= h(pageUrl('salary_slip') . '&emp_id=' . (int)$row['employee_id'] . '&month=' . (int)$row['month'] . '&year=' . (int)$row['year']) ?>">PDF/طباعة</a></td>
                                            <td>
                                                <?php if ($isAdmin || $isManager): ?>
                                                    <div class="d-flex gap-1 flex-wrap">
                                                        <?php if ($rowWaUrl !== ''): ?>
                                                            <a class="btn btn-sm btn-success" href="<?= h($rowWaUrl) ?>" target="_blank" rel="noopener noreferrer" title="إرسال وصل الراتب على واتساب">&#128232; واتساب</a>
                                                        <?php else: ?>
                                                            <span class="btn btn-sm btn-outline-secondary disabled" title="لا يوجد رقم هاتف للموظف">&#128232; واتساب</span>
                                                        <?php endif; ?>
                                                        <form method="post" class="m-0">
                                                            <input type="hidden" name="action" value="mark_salary_delivered">
                                                            <input type="hidden" name="employee_id" value="<?= (int)$row['employee_id'] ?>">
                                                            <input type="hidden" name="month" value="<?= (int)$row['month'] ?>">
                                                            <input type="hidden" name="year" value="<?= (int)$row['year'] ?>">
                                                            <input type="hidden" name="return_page" value="salaries">
                                                            <input type="hidden" name="payment_note" value="تم التسليم من قائمة الرواتب">
                                                            <button class="btn btn-sm btn-outline-success">تم التسليم</button>
                                                        </form>
                                                        <form method="post" class="m-0" onsubmit="return confirm('سيتم تصفية وتصفير هذا الراتب. متابعة؟');">
                                                            <input type="hidden" name="action" value="settle_reset_salary">
                                                            <input type="hidden" name="employee_id" value="<?= (int)$row['employee_id'] ?>">
                                                            <input type="hidden" name="month" value="<?= (int)$row['month'] ?>">
                                                            <input type="hidden" name="year" value="<?= (int)$row['year'] ?>">
                                                            <input type="hidden" name="return_page" value="salaries">
                                                            <input type="hidden" name="payment_note" value="تصفية وتصفير من قائمة الرواتب">
                                                            <button class="btn btn-sm btn-outline-danger">تصفية/تصفير</button>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <script>
                    (function () {
                        var input = document.getElementById('salarySearchInput');
                        var table = document.getElementById('salariesTable');
                        if (!input || !table) {
                            return;
                        }
                        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
                        input.addEventListener('input', function () {
                            var q = (input.value || '').trim().toLowerCase();
                            rows.forEach(function (row) {
                                var hay = (row.getAttribute('data-search') || '').toLowerCase();
                                row.classList.toggle('sal-hidden', q !== '' && hay.indexOf(q) === -1);
                            });
                        });
                    })();
                    </script>
                </section>
                <?php endif; ?>

                <?php if ($currentPage === 'debts'): ?>
                <section id="debts" class="mb-4">
                    <style>
                        .debt-hero { border-radius: 14px; background: linear-gradient(135deg,#3f1d0f 0%,#9a3412 55%,#b45309 100%); color: #fff; padding: 16px; }
                        .debt-hero .sub { color: rgba(255,255,255,.86); font-size: .9rem; }
                        .debt-card { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
                        .debt-manage-drop { border: 1px solid #cbd5e1; border-radius: 10px; background: #f8fafc; }
                        .debt-manage-drop summary { list-style: none; cursor: pointer; font-weight: 700; padding: 12px 14px; display: flex; justify-content: space-between; align-items: center; }
                        .debt-manage-drop summary::-webkit-details-marker { display: none; }
                        .debt-manage-drop summary::after { content: '\25BE'; font-size: .85rem; color: #334155; transition: transform .15s ease; }
                        .debt-manage-drop[open] summary::after { transform: rotate(180deg); }
                        .debt-manage-body { padding: 0 14px 12px 14px; }
                        .debt-manage-row { border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
                        .debt-bulk-controls { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
                        .debt-cat-card { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; transition: transform .12s ease, box-shadow .12s ease; }
                        .debt-cat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(15,23,42,.08); }
                        .debt-cat-name { font-weight: 700; color: #0f172a; }
                        .debt-cat-num { font-size: 1.1rem; font-weight: 700; color: #0f172a; }
                        .debt-cat-remain { font-size: .85rem; font-weight: 700; color: #b91c1c; }
                    </style>

                    <div class="debt-hero mb-3 shadow-sm">
                        <h4 class="mb-1">قسم الديون</h4>
                        <div class="sub">إدارة الديون حسب التصنيف، مع متابعة المدفوع والمتبقي لكل قسم.</div>
                    </div>

                    <div class="debt-card shadow-sm p-3 mb-3">
                        <details class="debt-manage-drop">
                            <summary>إدارة أقسام الديون</summary>
                            <div class="debt-manage-body">
                                <form method="post" class="row g-2 mb-3">
                                    <input type="hidden" name="action" value="add_debt_category">
                                    <div class="col-md-9"><input class="form-control" name="category_name" placeholder="اسم قسم دين جديد" required></div>
                                    <div class="col-md-3"><button class="btn btn-outline-primary w-100" <?= $isAdmin ? '' : 'disabled' ?>>إضافة قسم جديد</button></div>
                                </form>

                                <form method="post" id="bulkDeleteDebtCategoriesForm" class="mb-3" onsubmit="return confirm('هل تريد حذف الأقسام المحددة؟');">
                                    <input type="hidden" name="action" value="delete_debt_categories_bulk">
                                    <input type="hidden" name="bulk_category_ids" id="bulkDebtCategoryIds" value="">
                                    <div class="debt-bulk-controls">
                                        <div class="form-check m-0">
                                            <input class="form-check-input" type="checkbox" id="toggleAllDebtCategories">
                                            <label class="form-check-label" for="toggleAllDebtCategories">تحديد الكل</label>
                                        </div>
                                        <button class="btn btn-sm btn-outline-danger" <?= $isAdmin ? '' : 'disabled' ?>>حذف المحدد</button>
                                    </div>
                                </form>

                                <?php foreach ($debtCategoryRows as $catRow): ?>
                                    <?php
                                        $catId = (int)($catRow['id'] ?? 0);
                                        $catName = trim((string)($catRow['name'] ?? ''));
                                        if ($catName === '') {
                                            continue;
                                        }
                                    ?>
                                    <div class="debt-manage-row p-2 mb-2">
                                        <div class="row g-2 align-items-center">
                                            <div class="col-md-4 fw-semibold d-flex align-items-center gap-2">
                                                <input class="form-check-input debt-cat-bulk-checkbox" type="checkbox" name="category_ids[]" value="<?= $catId ?>" form="bulkDeleteDebtCategoriesForm" <?= $isAdmin ? '' : 'disabled' ?>>
                                                <span><?= h($catName) ?></span>
                                            </div>
                                            <div class="col-md-5">
                                                <form method="post" class="d-flex gap-2">
                                                    <input type="hidden" name="action" value="rename_debt_category">
                                                    <input type="hidden" name="category_id" value="<?= $catId ?>">
                                                    <input class="form-control form-control-sm" name="new_name" value="<?= h($catName) ?>" required>
                                                    <button class="btn btn-sm btn-outline-warning" <?= $isAdmin ? '' : 'disabled' ?>>تعديل</button>
                                                </form>
                                            </div>
                                            <div class="col-md-3 text-md-end">
                                                <form method="post" class="d-inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا القسم؟');">
                                                    <input type="hidden" name="action" value="delete_debt_category">
                                                    <input type="hidden" name="category_id" value="<?= $catId ?>">
                                                    <button class="btn btn-sm btn-outline-danger" <?= $isAdmin ? '' : 'disabled' ?>>حذف</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <script>
                                (function () {
                                    var toggleAll = document.getElementById('toggleAllDebtCategories');
                                    var bulkForm = document.getElementById('bulkDeleteDebtCategoriesForm');
                                    var bulkIds = document.getElementById('bulkDebtCategoryIds');
                                    if (!toggleAll || !bulkForm || !bulkIds) {
                                        return;
                                    }

                                    function collectCheckedIds() {
                                        var checks = document.querySelectorAll('.debt-cat-bulk-checkbox:checked');
                                        var ids = [];
                                        checks.forEach(function (box) {
                                            if (!box.disabled && box.value) {
                                                ids.push(box.value);
                                            }
                                        });
                                        return ids;
                                    }

                                    toggleAll.addEventListener('change', function () {
                                        var checks = document.querySelectorAll('.debt-cat-bulk-checkbox');
                                        checks.forEach(function (box) {
                                            if (!box.disabled) {
                                                box.checked = toggleAll.checked;
                                            }
                                        });
                                        bulkIds.value = collectCheckedIds().join(',');
                                    });

                                    bulkForm.addEventListener('submit', function () {
                                        bulkIds.value = collectCheckedIds().join(',');
                                    });
                                })();
                                </script>
                            </div>
                        </details>
                    </div>

                    <div class="debt-card shadow-sm p-3 mb-3">
                        <h6 class="mb-3">إضافة دين جديد</h6>
                        <form method="post" class="row g-2">
                            <input type="hidden" name="action" value="add_debt">
                            <div class="col-md-3"><input class="form-control" name="name" placeholder="اسم صاحب الدين" required></div>
                            <div class="col-md-2"><input class="form-control" type="number" step="0.01" name="amount" placeholder="المبلغ" required></div>
                            <div class="col-md-2"><input class="form-control" type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
                            <div class="col-md-3">
                                <select class="form-select" name="debt_category" required>
                                    <?php foreach ($debtCategories as $cat): ?>
                                        <option value="<?= h($cat) ?>" <?= $defaultDebtCategory === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2"><button class="btn btn-primary w-100">حفظ الدين</button></div>
                            <div class="col-12"><input class="form-control" name="notes" placeholder="ملاحظة"></div>
                        </form>
                    </div>

                    <div class="row g-3">
                        <?php foreach ($debtCategoryRows as $catRow): ?>
                            <?php
                                $cat = trim((string)($catRow['name'] ?? ''));
                                if ($cat === '') {
                                    continue;
                                }
                                $cTotal = toFloat($debtCategoryTotals[$cat] ?? 0);
                                $cRemain = toFloat($debtCategoryRemaining[$cat] ?? 0);
                                $cCount = (int)($debtCategoryCounts[$cat] ?? 0);
                            ?>
                            <div class="col-12 col-md-6 col-xl-4">
                                <a href="<?= h(pageUrl('debt_sheet', ['cat' => $cat])) ?>" class="text-decoration-none">
                                    <div class="debt-cat-card shadow-sm p-3 h-100">
                                        <div class="debt-cat-name mb-2"><?= h($cat) ?></div>
                                        <div class="small-muted">عدد السجلات: <strong><?= $cCount ?></strong></div>
                                        <div class="debt-cat-num mt-1">الإجمالي: <?= number_format($cTotal, 2) ?></div>
                                        <div class="debt-cat-remain">المتبقي: <?= number_format($cRemain, 2) ?></div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ($currentPage === 'debt_sheet'): ?>
                <section id="debt-sheet" class="card shadow-sm border-0 mb-4"><div class="card-body">
                    <style>
                        .debt-sheet-wrap { border: 1px solid #e2e8f0; border-radius: 14px; background: #ffffff; overflow: hidden; }
                        .debt-sheet-head { background: linear-gradient(135deg,#0f172a 0%,#1e293b 60%,#334155 100%); color: #fff; padding: 14px 16px; }
                        .debt-sheet-actions .btn { min-width: 112px; }
                        .debt-sheet-info { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 10px 12px; }
                        .debt-sheet-table thead th { white-space: nowrap; }
                        .debt-sheet-print-only { display: none; }
                        @media print {
                            body * { visibility: hidden !important; }
                            #debt-sheet-print, #debt-sheet-print * { visibility: visible !important; }
                            #debt-sheet-print { position: absolute; inset: 0; }
                            .debt-sheet-no-print { display: none !important; }
                            .debt-sheet-print-only { display: block !important; }
                            .debt-sheet-wrap { border: none; box-shadow: none !important; }
                            .debt-sheet-head { background: #ffffff !important; color: #111827 !important; border-bottom: 2px solid #111827; }
                            .table-dark th { background: #e5e7eb !important; color: #111827 !important; }
                        }
                    </style>

                    <div id="debt-sheet-print" class="debt-sheet-wrap">
                    <div class="debt-sheet-head">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h5 class="mb-1">كشف الديون - <?= h($sheetCategory) ?></h5>
                                <div class="small opacity-75">تاريخ الإصدار: <?= h(date('Y-m-d H:i')) ?></div>
                            </div>
                            <div class="debt-sheet-print-only small text-end">
                                <div>نظام الإدارة المالية</div>
                                <div>تقرير ديون مفصل</div>
                            </div>
                        </div>
                    </div>

                    <div class="p-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3 debt-sheet-no-print">
                        <div>
                            <div class="debt-sheet-info">
                                <div class="small-muted mb-1">ملخص الكشف</div>
                                <div class="small-muted">الإجمالي: <strong><?= number_format($sheetTotal, 2) ?></strong> | المدفوع: <strong><?= number_format($sheetPaid, 2) ?></strong> | المتبقي: <strong class="text-danger"><?= number_format($sheetRemaining, 2) ?></strong></div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 debt-sheet-actions">
                            <?php if ($isAdmin && $sheetCategoryId > 0): ?>
                                <button type="button" class="btn btn-outline-warning btn-sm" id="toggleDebtCategoryRename">تعديل القسم</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-dark btn-sm" id="printDebtSheetBtn">طباعة</button>
                            <button type="button" class="btn btn-success btn-sm" id="saveDebtSheetPdfBtn">حفظ كـ PDF</button>
                            <button type="button" class="btn btn-outline-success btn-sm" id="sendDebtSheetWaBtn">إرسال واتساب</button>
                            <a href="<?= h(pageUrl('debts')) ?>" class="btn btn-outline-secondary btn-sm">رجوع</a>
                        </div>
                    </div>

                    <?php if ($isAdmin && $sheetCategoryId > 0): ?>
                        <div class="debt-sheet-no-print mb-3" id="debtCategoryRenameWrap" style="display:none;">
                            <form method="post" class="row g-2">
                                <input type="hidden" name="action" value="rename_debt_category">
                                <input type="hidden" name="category_id" value="<?= (int)$sheetCategoryId ?>">
                                <div class="col-md-8"><input class="form-control" name="new_name" value="<?= h($sheetCategory) ?>" required></div>
                                <div class="col-md-4"><button class="btn btn-warning w-100">حفظ تعديل القسم</button></div>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="debt-sheet-info mb-3">
                        <div class="small-muted mb-1">ملخص الكشف</div>
                        <div class="small-muted">عدد السجلات: <strong><?= (int)count($sheetDebts) ?></strong> | الإجمالي: <strong><?= number_format($sheetTotal, 2) ?></strong> | المدفوع: <strong><?= number_format($sheetPaid, 2) ?></strong> | المتبقي: <strong class="text-danger"><?= number_format($sheetRemaining, 2) ?></strong></div>
                    </div>

                    <?php if (empty($sheetDebts)): ?>
                        <div class="text-center text-muted py-4">لا توجد سجلات في هذا التصنيف.</div>
                    <?php else: ?>
                    <div class="table-responsive debt-sheet-table">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>الاسم</th>
                                    <th>التاريخ</th>
                                    <th>الإجمالي</th>
                                    <th>المدفوع</th>
                                    <th>المتبقي</th>
                                    <th>الحالة</th>
                                    <th>ملاحظة</th>
                                    <th>تسديد</th>
                                    <th>إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sheetDebts as $row): ?>
                                    <tr>
                                        <td><?= (int)$row['id'] ?></td>
                                        <td class="fw-semibold"><?= h((string)$row['name']) ?></td>
                                        <td><?= h((string)$row['date']) ?></td>
                                        <td><?= number_format((float)$row['amount'], 2) ?></td>
                                        <td><?= number_format((float)$row['paid'], 2) ?></td>
                                        <td class="<?= ((float)$row['remaining'] > 0) ? 'text-danger' : 'text-success' ?> fw-semibold"><?= number_format((float)$row['remaining'], 2) ?></td>
                                        <td><?= ((string)$row['status'] === 'closed') ? 'مغلق' : 'مفتوح' ?></td>
                                        <td><?= h((string)($row['notes'] ?? '')) ?></td>
                                        <td style="min-width:200px;">
                                            <form method="post" class="d-flex gap-1">
                                                <input type="hidden" name="action" value="pay_debt">
                                                <input type="hidden" name="debt_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="return_cat" value="<?= h($sheetCategory) ?>">
                                                <input class="form-control form-control-sm" type="number" step="0.01" name="pay_amount" placeholder="مبلغ" required>
                                                <button class="btn btn-sm btn-outline-success">تسديد</button>
                                            </form>
                                        </td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                <form method="post" class="m-0" onsubmit="return confirm('هل تريد حذف هذا الدين؟');">
                                                    <input type="hidden" name="action" value="delete_debt">
                                                    <input type="hidden" name="debt_id" value="<?= (int)$row['id'] ?>">
                                                    <input type="hidden" name="return_cat" value="<?= h($sheetCategory) ?>">
                                                    <button class="btn btn-sm btn-outline-danger">حذف</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <script>
                    (function () {
                        var renameBtn = document.getElementById('toggleDebtCategoryRename');
                        var renameWrap = document.getElementById('debtCategoryRenameWrap');
                        if (renameBtn && renameWrap) {
                            renameBtn.addEventListener('click', function () {
                                renameWrap.style.display = renameWrap.style.display === 'none' ? 'block' : 'none';
                            });
                        }

                        var printBtn = document.getElementById('printDebtSheetBtn');
                        if (printBtn) {
                            printBtn.addEventListener('click', function () {
                                document.title = 'كشف ديون - <?= h($sheetCategory) ?>';
                                window.print();
                            });
                        }

                        var pdfBtn = document.getElementById('saveDebtSheetPdfBtn');
                        if (pdfBtn) {
                            pdfBtn.addEventListener('click', function () {
                                document.title = 'وصل ديون PDF - <?= h($sheetCategory) ?>';
                                window.print();
                            });
                        }

                        var waBtn = document.getElementById('sendDebtSheetWaBtn');
                        if (waBtn) {
                            waBtn.addEventListener('click', function () {
                                var phone = prompt('ادخل رقم واتساب (مثال: 077xxxxxxx أو 9647xxxxxxxx):', '');
                                if (!phone) {
                                    return;
                                }
                                var normalized = (phone || '').replace(/\D+/g, '');
                                if (normalized.indexOf('00') === 0) {
                                    normalized = normalized.slice(2);
                                }
                                if (normalized.indexOf('0') === 0) {
                                    normalized = '964' + normalized.slice(1);
                                }
                                if (!normalized) {
                                    alert('الرقم غير صالح.');
                                    return;
                                }

                                var rows = <?= json_encode(array_map(static function (array $r): array {
                                    return [
                                        'id' => (int)($r['id'] ?? 0),
                                        'name' => (string)($r['name'] ?? ''),
                                        'date' => (string)($r['date'] ?? ''),
                                        'amount' => number_format((float)($r['amount'] ?? 0), 2, '.', ''),
                                        'paid' => number_format((float)($r['paid'] ?? 0), 2, '.', ''),
                                        'remaining' => number_format((float)($r['remaining'] ?? 0), 2, '.', ''),
                                        'status' => ((string)($r['status'] ?? '') === 'closed') ? 'مغلق' : 'مفتوح',
                                        'notes' => (string)($r['notes'] ?? ''),
                                    ];
                                }, $sheetDebts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

                                var lines = [];
                                lines.push('كشف ديون: <?= h($sheetCategory) ?>');
                                lines.push('التاريخ: <?= h(date('Y-m-d H:i')) ?>');
                                lines.push('عدد السجلات: <?= (int)count($sheetDebts) ?>');
                                lines.push('الإجمالي: <?= number_format($sheetTotal, 2, '.', '') ?>');
                                lines.push('المدفوع: <?= number_format($sheetPaid, 2, '.', '') ?>');
                                lines.push('المتبقي: <?= number_format($sheetRemaining, 2, '.', '') ?>');
                                lines.push('------------------------------');
                                rows.forEach(function (r) {
                                    lines.push('#' + r.id + ' | ' + r.name + ' | ' + r.date);
                                    lines.push('الإجمالي: ' + r.amount + ' | المدفوع: ' + r.paid + ' | المتبقي: ' + r.remaining + ' | ' + r.status);
                                    if (r.notes) {
                                        lines.push('ملاحظة: ' + r.notes);
                                    }
                                    lines.push('------------------------------');
                                });

                                var message = lines.join('\n');
                                var waUrl = 'https://wa.me/' + normalized + '?text=' + encodeURIComponent(message);
                                window.open(waUrl, '_blank');
                            });
                        }
                    })();
                    </script>
                    </div>
                    </div>
                </div></section>
                <?php endif; ?>

                <?php if ($currentPage === 'restaurants'): ?>
                <section id="restaurants" class="card shadow-sm border-0 mb-4"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2"><h5 class="mb-0">إدارة المطاعم والأرباح</h5><a class="btn btn-outline-success btn-sm" href="<?= h(pageUrl('restaurants') . '&export=finance') ?>">تصدير Excel</a></div>
                    <form method="post" class="row g-2 mb-3">
                        <input type="hidden" name="action" value="add_daily_finance">
                        <div class="col-md-3"><select class="form-select" name="restaurant_id"><?php foreach ($restaurants as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h((string)$r['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><input class="form-control" type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-md-2"><input class="form-control" type="number" step="0.01" name="sales" placeholder="المبيعات"></div>
                        <div class="col-md-2"><input class="form-control" type="number" step="0.01" name="expenses" placeholder="المصاريف"></div>
                        <div class="col-md-1"><input class="form-control" type="number" step="0.01" name="loans" placeholder="سلف"></div>
                        <div class="col-md-1"><input class="form-control" type="number" step="0.01" name="external_expenses" placeholder="خارجي"></div>
                        <div class="col-md-1"><button class="btn btn-primary w-100">حفظ</button></div>
                    </form>
                    <div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>المطعم</th><th>التاريخ</th><th>المبيعات</th><th>المصاريف</th><th>السلف</th><th>خارجي</th><th>صافي الربح</th></tr></thead><tbody>
                    <?php foreach ($financeRows as $row): ?><tr><td><?= h((string)$row['restaurant_name']) ?></td><td><?= h((string)$row['date']) ?></td><td><?= number_format((float)$row['sales'], 2) ?></td><td><?= number_format((float)$row['expenses'], 2) ?></td><td><?= number_format((float)$row['loans'], 2) ?></td><td><?= number_format((float)$row['external_expenses'], 2) ?></td><td class="text-success"><?= number_format((float)$row['net_profit'], 2) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </div></section>
                <?php endif; ?>

                <?php if ($currentPage === 'expenses'): ?>
                <section id="expenses" class="card shadow-sm border-0 mb-4"><div class="card-body">
                    <style>
                        .exp-hero {
                            background: linear-gradient(135deg,#0f172a 0%,#0f766e 52%,#0ea5a4 100%);
                            border-radius: 14px;
                            color: #fff;
                            padding: 14px 16px;
                        }
                        .exp-kpi {
                            border-radius: 12px;
                            padding: 12px 14px;
                            color: #fff;
                            font-weight: 600;
                            box-shadow: 0 8px 24px rgba(15,23,42,.08);
                        }
                        .exp-kpi strong { font-size: 1.05rem; }
                        .exp-table-wrap {
                            border: 1px solid #e2e8f0;
                            border-radius: 12px;
                            overflow: hidden;
                            background: #fff;
                        }
                        .exp-table-wrap thead th {
                            white-space: nowrap;
                            background: #0f172a;
                            color: #fff;
                            border: 0;
                        }
                    </style>

                    <div class="exp-hero d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h5 class="mb-0">قسم المصاريف</h5>
                        <a class="btn btn-light btn-sm fw-semibold" href="<?= h(pageUrl('expenses', ['export' => 'expenses', 'expense_from' => $expenseFromDate, 'expense_to' => $expenseToDate, 'expense_category' => $expenseCategoryFilter])) ?>">تصدير Excel</a>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <div class="small text-muted">إدارة وإرشفة المصاريف مع فلترة حسب التاريخ والقسم</div>
                        <a class="btn btn-outline-primary btn-sm" href="<?= h(pageUrl('expense_categories', ['expense_from' => $expenseFromDate, 'expense_to' => $expenseToDate, 'expense_category' => $expenseCategoryFilter])) ?>">خيارات الأقسام</a>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><div class="exp-kpi" style="background:#1f2937;">الإجمالي: <strong><?= number_format($generalExpenseTotalAmountFiltered, 2) ?></strong></div></div>
                        <div class="col-md-4"><div class="exp-kpi" style="background:#16a34a;">المدفوع: <strong><?= number_format($generalExpensePaidFiltered, 2) ?></strong></div></div>
                        <div class="col-md-4"><div class="exp-kpi" style="background:#dc2626;">الباقي: <strong><?= number_format($generalExpenseRemainFiltered, 2) ?></strong></div></div>
                    </div>

                    <form method="get" class="row g-2 mb-3 align-items-end">
                        <input type="hidden" name="page" value="expenses">
                        <div class="col-md-3">
                            <label class="form-label mb-1">من تاريخ</label>
                            <input class="form-control" type="date" name="expense_from" value="<?= h($expenseFromDate) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">إلى تاريخ</label>
                            <input class="form-control" type="date" name="expense_to" value="<?= h($expenseToDate) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">قسم المصروف</label>
                            <select class="form-select" name="expense_category" id="expenseCategoryFilterSelect">
                                <option value="all" <?= $expenseCategoryFilter === 'all' ? 'selected' : '' ?>>كل الأقسام</option>
                                <?php foreach ($expenseCategories as $expenseCategoryOpt): ?>
                                    <option value="<?= h($expenseCategoryOpt) ?>" <?= $expenseCategoryFilter === $expenseCategoryOpt ? 'selected' : '' ?>><?= h($expenseCategoryOpt) ?></option>
                                <?php endforeach; ?>
                                <option value="__manage_expense_categories__">⚙ خيارات الأقسام...</option>
                            </select>
                        </div>
                        <div class="col-md-2"><button class="btn btn-outline-primary w-100">فلترة</button></div>
                        <div class="col-md-2"><a class="btn btn-outline-secondary w-100" href="<?= h(pageUrl('expenses')) ?>">إلغاء الفلترة</a></div>
                    </form>

                    <form method="post" class="row g-2 mb-3">
                        <input type="hidden" name="action" value="<?= $expenseFormId > 0 ? 'update_general_expense' : 'add_general_expense' ?>">
                        <input type="hidden" name="expense_id" value="<?= (int)$expenseFormId ?>">
                        <div class="col-md-2"><input class="form-control" type="date" name="date" value="<?= h($expenseFormDate) ?>" required></div>
                        <div class="col-md-2">
                            <select class="form-select" name="category" id="expenseCategorySelect" required>
                                <?php foreach ($expenseFormCategoryOptions as $expenseCategoryOpt): ?>
                                    <option value="<?= h($expenseCategoryOpt) ?>" <?= $expenseFormCategory === $expenseCategoryOpt ? 'selected' : '' ?>><?= h($expenseCategoryOpt) ?></option>
                                <?php endforeach; ?>
                                <option value="__manage_expense_categories__">⚙ خيارات الأقسام...</option>
                            </select>
                        </div>
                        <div class="col-md-2"><input class="form-control" type="number" step="0.01" min="0" name="amount" value="<?= h((string)$expenseFormAmount) ?>" placeholder="المبلغ" required></div>
                        <div class="col-md-2"><input class="form-control" type="number" step="0.01" min="0" name="paid" value="<?= h((string)$expenseFormPaid) ?>" placeholder="المدفوع" required></div>
                        <div class="col-md-3"><input class="form-control" name="note" value="<?= h($expenseFormNote) ?>" placeholder="ملاحظات"></div>
                        <div class="col-md-1"><button class="btn btn-primary w-100"><?= $expenseFormId > 0 ? 'تحديث' : 'حفظ' ?></button></div>
                    </form>

                    <script>
                    (function () {
                        var manageOptionValue = '__manage_expense_categories__';
                        var filterSelect = document.getElementById('expenseCategoryFilterSelect');
                        var categorySelect = document.getElementById('expenseCategorySelect');
                        var manageUrl = <?= json_encode(pageUrl('expense_categories', ['expense_from' => $expenseFromDate, 'expense_to' => $expenseToDate, 'expense_category' => $expenseCategoryFilter]), JSON_UNESCAPED_UNICODE) ?>;
                        if (!categorySelect || !manageUrl) {
                            return;
                        }

                        function bindManageSelect(selectEl) {
                            if (!selectEl) {
                                return;
                            }
                            if (!selectEl.dataset.prevValue) {
                                selectEl.dataset.prevValue = selectEl.value;
                            }

                            selectEl.addEventListener('change', function () {
                                var picked = selectEl.value || '';
                                if (picked === manageOptionValue) {
                                    selectEl.value = selectEl.dataset.prevValue || '';
                                    window.location.href = manageUrl;
                                    return;
                                }
                                selectEl.dataset.prevValue = picked;
                            });
                        }

                        bindManageSelect(filterSelect);
                        bindManageSelect(categorySelect);
                    })();
                    </script>

                    <div class="exp-table-wrap table-responsive"><table class="table table-sm table-striped align-middle mb-0"><thead><tr><th>التاريخ</th><th>المادة</th><th>المبلغ</th><th>المدفوع</th><th>الباقي</th><th>ملاحظة</th><th>إجراءات</th></tr></thead><tbody>
                    <?php foreach ($generalExpenseRows as $row): ?>
                        <?php
                            $rowAmount = toFloat($row['amount'] ?? 0);
                            $rowPaid = toFloat($row['paid'] ?? 0);
                            $rowRemain = toFloat($rowAmount - $rowPaid);
                        ?>
                        <tr>
                            <td><?= h((string)$row['date']) ?></td>
                            <td><?= h((string)($row['category'] ?? '')) ?></td>
                            <td><?= number_format($rowAmount, 2) ?></td>
                            <td><?= number_format($rowPaid, 2) ?></td>
                            <td class="<?= $rowRemain > 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($rowRemain, 2) ?></td>
                            <td><?= h((string)($row['note'] ?? '')) ?></td>
                            <td>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">إجراءات</button>
                                    <ul class="dropdown-menu dropdown-menu-end text-end">
                                        <li>
                                            <a class="dropdown-item" href="<?= h(pageUrl('expenses', ['expense_from' => $expenseFromDate, 'expense_to' => $expenseToDate, 'expense_category' => $expenseCategoryFilter, 'expense_edit_id' => (int)$row['id']])) ?>">تعديل</a>
                                        </li>
                                        <li>
                                            <form method="post" class="m-0" onsubmit="return confirm('حذف هذا المصروف؟');">
                                                <input type="hidden" name="action" value="delete_general_expense">
                                                <input type="hidden" name="expense_id" value="<?= (int)$row['id'] ?>">
                                                <button class="dropdown-item text-danger">حذف</button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                </div></section>
                <?php endif; ?>

                <?php if ($currentPage === 'expense_categories'): ?>
                <section id="expense-categories" class="card shadow-sm border-0 mb-4"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h5 class="mb-0">خيارات أقسام المصاريف</h5>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= h(pageUrl('expenses', ['expense_from' => $expenseFromDate, 'expense_to' => $expenseToDate, 'expense_category' => $expenseCategoryFilter])) ?>">الرجوع إلى المصاريف</a>
                    </div>

                    <div class="row g-3">
                        <div class="col-lg-4">
                            <form method="post" class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <h6 class="mb-3">إضافة قسم</h6>
                                    <input type="hidden" name="action" value="add_expense_category">
                                    <input type="hidden" name="return_page" value="expense_categories">
                                    <input type="hidden" name="expense_from" value="<?= h($expenseFromDate) ?>">
                                    <input type="hidden" name="expense_to" value="<?= h($expenseToDate) ?>">
                                    <input type="hidden" name="expense_category" value="<?= h($expenseCategoryFilter) ?>">
                                    <input class="form-control mb-3" name="new_expense_category_name" placeholder="اسم القسم الجديد" required>
                                    <button class="btn btn-success w-100">إضافة القسم</button>
                                </div>
                            </form>
                        </div>

                        <div class="col-lg-4">
                            <form method="post" class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <h6 class="mb-3">تعديل قسم</h6>
                                    <input type="hidden" name="action" value="rename_expense_category">
                                    <input type="hidden" name="return_page" value="expense_categories">
                                    <input type="hidden" name="expense_from" value="<?= h($expenseFromDate) ?>">
                                    <input type="hidden" name="expense_to" value="<?= h($expenseToDate) ?>">
                                    <input type="hidden" name="expense_category" value="<?= h($expenseCategoryFilter) ?>">
                                    <label class="form-label small">القسم الحالي</label>
                                    <select class="form-select mb-2" name="old_expense_category_name" required>
                                        <?php foreach ($expenseCategories as $expenseCategoryOpt): ?>
                                            <option value="<?= h($expenseCategoryOpt) ?>"><?= h($expenseCategoryOpt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="form-label small">الاسم الجديد</label>
                                    <input class="form-control mb-3" name="new_expense_category_name" placeholder="الاسم الجديد" required>
                                    <button class="btn btn-primary w-100">تعديل القسم</button>
                                </div>
                            </form>
                        </div>

                        <div class="col-lg-4">
                            <form method="post" class="card border-0 shadow-sm h-100" onsubmit="return confirm('حذف القسم المحدد؟');">
                                <div class="card-body">
                                    <h6 class="mb-3">حذف قسم</h6>
                                    <input type="hidden" name="action" value="delete_expense_category">
                                    <input type="hidden" name="return_page" value="expense_categories">
                                    <input type="hidden" name="expense_from" value="<?= h($expenseFromDate) ?>">
                                    <input type="hidden" name="expense_to" value="<?= h($expenseToDate) ?>">
                                    <select class="form-select mb-3" name="expense_category_name" required>
                                        <?php foreach ($expenseCategories as $expenseCategoryOpt): ?>
                                            <option value="<?= h($expenseCategoryOpt) ?>"><?= h($expenseCategoryOpt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-danger w-100">حذف القسم</button>
                                    <div class="small text-muted mt-2">لا يمكن حذف قسم مرتبط بسجلات مصاريف.</div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div></section>
                <?php endif; ?>

                <!-- صفحة مخازن المطعم -->
                <?php if ($currentPage === 'stock'): ?>
                <?php
                    // جلب الفئات والمواد
                    $stockCategories = $pdo->query('SELECT id, name FROM stock_categories ORDER BY name')->fetchAll() ?: [];
                    $stockItems = $pdo->query('SELECT 
                        si.id, si.name, si.category_id, si.description, si.quantity, si.unit, si.min_quantity, si.unit_price,
                        sc.name as category_name
                    FROM stock_items si 
                    LEFT JOIN stock_categories sc ON si.category_id = sc.id 
                    ORDER BY si.name')->fetchAll() ?: [];
                    
                    // حساب الإجماليات
                    $totalItems = count($stockItems);
                    $totalValue = 0;
                    $lowStockCount = 0;
                    foreach ($stockItems as $item) {
                        $totalValue += toFloat($item['quantity'] * $item['unit_price']);
                        if (toFloat($item['quantity']) <= toFloat($item['min_quantity'])) {
                            $lowStockCount++;
                        }
                    }
                ?>
                <section id="stock" class="card shadow-sm border-0 mb-4"><div class="card-body">
                    <style>
                        .stock-hero {
                            background: linear-gradient(135deg,#064e3b 0%,#059669 52%,#10b981 100%);
                            border-radius: 14px;
                            color: #fff;
                            padding: 14px 16px;
                        }
                        .stock-kpi {
                            border-radius: 12px;
                            padding: 12px 14px;
                            color: #fff;
                            font-weight: 600;
                            box-shadow: 0 8px 24px rgba(15,23,42,.08);
                        }
                        .stock-kpi strong { font-size: 1.05rem; }
                    </style>
                    
                    <div class="stock-hero mb-3">
                        <h4 class="mb-2">إدارة مخازن المطعم 🏪</h4>
                        <p class="mb-0 small">نظام متكامل لإدارة المخزون والمواد المتاحة</p>
                    </div>

                    <div class="row g-2 mb-4">
                        <div class="col-md-4">
                            <div class="stock-kpi" style="background: #0891b2;">
                                <div class="small opacity-75">عدد المواد</div>
                                <strong><?= $totalItems ?></strong>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stock-kpi" style="background: #7c3aed;">
                                <div class="small opacity-75">قيمة المخزن</div>
                                <strong><?= number_format($totalValue, 2) ?></strong>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stock-kpi" style="background: #dc2626;">
                                <div class="small opacity-75">مواد منخفضة</div>
                                <strong><?= $lowStockCount ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- جدول المواد -->
                    <div class="table-responsive mb-4">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>اسم المادة</th>
                                    <th>الفئة</th>
                                    <th>الكمية</th>
                                    <th>الوحدة</th>
                                    <th>السعر</th>
                                    <th>الإجمالي</th>
                                    <th>التنبيه</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $itemIndex = 1; foreach ($stockItems as $item): ?>
                                    <?php 
                                        $itemValue = toFloat($item['quantity'] * $item['unit_price']);
                                        $isLow = toFloat($item['quantity']) <= toFloat($item['min_quantity']);
                                    ?>
                                    <tr class="<?= $isLow ? 'table-warning' : '' ?>">
                                        <td><?= $itemIndex++ ?></td>
                                        <td><strong><?= h($item['name']) ?></strong></td>
                                        <td><small><?= h($item['category_name'] ?? '-') ?></small></td>
                                        <td><?= number_format(toFloat($item['quantity']), 2) ?></td>
                                        <td><?= h($item['unit']) ?></td>
                                        <td><?= number_format(toFloat($item['unit_price']), 2) ?></td>
                                        <td><?= number_format($itemValue, 2) ?></td>
                                        <td><?= $isLow ? '<span class="badge bg-warning text-dark">⚠ منخفضة</span>' : '<span class="badge bg-success">✓ متوفرة</span>' ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#editStockModal<?= $item['id'] ?>">تعديل</button>
                                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#adjustStockModal<?= $item['id'] ?>">تعديل الكمية</button>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('حذف المادة؟');">
                                                <input type="hidden" name="action" value="delete_stock_item">
                                                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                                <button class="btn btn-sm btn-danger">حذف</button>
                                            </form>
                                        </td>
                                    </tr>

                                    <!-- Modal تعديل المادة -->
                                    <div class="modal fade" id="editStockModal<?= $item['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <form method="post" class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">تعديل: <?= h($item['name']) ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="edit_stock_item">
                                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">اسم المادة</label>
                                                        <input type="text" class="form-control" name="item_name" value="<?= h($item['name']) ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">الفئة</label>
                                                        <select class="form-select" name="category_id">
                                                            <option value="0">بدون فئة</option>
                                                            <?php foreach ($stockCategories as $cat): ?>
                                                                <option value="<?= $cat['id'] ?>" <?= $item['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                                                    <?= h($cat['name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">الوصف</label>
                                                        <textarea class="form-control" name="item_description" rows="2"><?= h($item['description']) ?></textarea>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">الوحدة</label>
                                                        <input type="text" class="form-control" name="unit" value="<?= h($item['unit']) ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">الحد الأدنى للتنبيه</label>
                                                        <input type="number" class="form-control" step="0.01" name="min_quantity" value="<?= $item['min_quantity'] ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">سعر الوحدة</label>
                                                        <input type="number" class="form-control" step="0.01" name="unit_price" value="<?= $item['unit_price'] ?>" required>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                                                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>

                                    <!-- Modal تعديل الكمية -->
                                    <div class="modal fade" id="adjustStockModal<?= $item['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <form method="post" class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">تعديل كمية: <?= h($item['name']) ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="adjust_stock_quantity">
                                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-bold">الكمية الحالية: <?= number_format(toFloat($item['quantity']), 2) ?> <?= h($item['unit']) ?></label>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">نوع العملية</label>
                                                        <select class="form-select" name="movement_type" required>
                                                            <option value="in">إدخال (إضافة) ➕</option>
                                                            <option value="out">إخراج (إنقاص) ➖</option>
                                                        </select>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">الكمية</label>
                                                        <input type="number" class="form-control" step="0.01" name="adjustment_quantity" placeholder="أدخل الكمية" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">ملاحظات</label>
                                                        <textarea class="form-control" name="movement_notes" rows="2" placeholder="سبب التعديل..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                                                    <button type="submit" class="btn btn-warning">تحديث الكمية</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- نموذج إضافة مادة جديدة -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0">إضافة مادة جديدة</h6>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="add_stock_item">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">اسم المادة *</label>
                                        <input type="text" class="form-control" name="item_name" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">الفئة</label>
                                        <select class="form-select" name="category_id">
                                            <option value="0">بدون فئة</option>
                                            <?php foreach ($stockCategories as $cat): ?>
                                                <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">الوصف</label>
                                        <textarea class="form-control" name="item_description" rows="2"></textarea>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">الكمية الأولية *</label>
                                        <input type="number" class="form-control" step="0.01" name="quantity" value="0" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">الوحدة *</label>
                                        <input type="text" class="form-control" name="unit" value="وحدة" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">الحد الأدنى *</label>
                                        <input type="number" class="form-control" step="0.01" name="min_quantity" value="0" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">سعر الوحدة *</label>
                                        <input type="number" class="form-control" step="0.01" name="unit_price" value="0" required>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-success">إضافة المادة</button>
                                        <a href="<?= h(pageUrl('stock_categories')) ?>" class="btn btn-outline-primary">إدارة الفئات</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div></section>
                <?php endif; ?>

                <!-- صفحة فئات المخزن -->
                <?php if ($currentPage === 'stock_categories'): ?>
                <?php
                    $stockCategories = $pdo->query('SELECT id, name, description FROM stock_categories ORDER BY name')->fetchAll() ?: [];
                ?>
                <section id="stock-categories" class="card shadow-sm border-0 mb-4"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h5 class="mb-0">إدارة فئات المخزن</h5>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= h(pageUrl('stock')) ?>">العودة إلى المخزن</a>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-lg-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <h6 class="mb-3">إضافة فئة جديدة</h6>
                                    <form method="post">
                                        <input type="hidden" name="action" value="add_stock_category">
                                        <div class="mb-3">
                                            <label class="form-label">اسم الفئة *</label>
                                            <input type="text" class="form-control" name="category_name" placeholder="مثال: مشروبات" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">الوصف</label>
                                            <textarea class="form-control" name="category_description" rows="2" placeholder="وصف الفئة..."></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-success w-100">إضافة الفئة</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <h6 class="mb-3">الفئات الموجودة (<?= count($stockCategories) ?>)</h6>
                                    <div class="list-group">
                                        <?php foreach ($stockCategories as $cat): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1"><?= h($cat['name']) ?></h6>
                                                    <?php if ($cat['description']): ?>
                                                        <small class="text-muted"><?= h($cat['description']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('حذف هذه الفئة؟');">
                                                    <input type="hidden" name="action" value="delete_stock_category">
                                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">حذف</button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($stockCategories)): ?>
                                            <p class="text-muted text-center py-3">لا توجد فئات بعد</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <h6 class="mb-3">تعديل اسم الفئة</h6>
                                    <form method="post" class="row g-3">
                                        <input type="hidden" name="action" value="rename_stock_category">
                                        <div class="col-md-6">
                                            <label class="form-label">اختر الفئة</label>
                                            <select class="form-select" name="old_category_id" required>
                                                <option value="">-- اختر فئة --</option>
                                                <?php foreach ($stockCategories as $cat): ?>
                                                    <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">الاسم الجديد</label>
                                            <input type="text" class="form-control" name="new_category_name" placeholder="الاسم الجديد" required>
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">تحديث الاسم</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div></section>
                <?php endif; ?>

                <?php if ($currentPage === 'collections'): ?>
                <?php
                    $collectionPeriodLabel = $collectionFilterMonth . '/' . $collectionFilterYear . ($isCollectionActivePeriod ? ' (فعّال)' : ' (مؤرشف)');
                    $collectionDetailsTitle = $collectionSectionFilter === 'all' ? 'كل أقسام الجمع' : ('تفاصيل ' . $collectionSectionFilter);
                    $collectionModalToOpen = trim((string)($_GET['collection_modal'] ?? ''));
                    $collectionModalEntryId = (int)($_GET['collection_entry_id'] ?? 0);
                ?>
                <section id="collections" class="mb-4">
                    <style>
                        .collection-hero {
                            background: linear-gradient(135deg,#7c2d12 0%,#c2410c 48%,#f59e0b 100%);
                            border-radius: 20px;
                            color: #fff;
                            padding: 22px;
                            overflow: hidden;
                            position: relative;
                        }
                        .collection-hero::after {
                            content: "";
                            position: absolute;
                            inset: auto -40px -60px auto;
                            width: 180px;
                            height: 180px;
                            border-radius: 50%;
                            background: rgba(255,255,255,.14);
                            filter: blur(2px);
                        }
                        .collection-stat {
                            background: #fff;
                            border: 1px solid #fed7aa;
                            border-radius: 16px;
                            padding: 16px;
                            box-shadow: 0 10px 30px rgba(124,45,18,.08);
                            height: 100%;
                        }
                        .collection-stat .label { color: #9a3412; font-size: .88rem; margin-bottom: 6px; }
                        .collection-stat .value { font-size: 1.55rem; font-weight: 700; color: #7c2d12; }
                        .collection-shell {
                            background: linear-gradient(180deg,#fff7ed 0%,#ffffff 100%);
                            border: 1px solid #fdba74;
                            border-radius: 20px;
                            box-shadow: 0 16px 40px rgba(124,45,18,.08);
                        }
                        .collection-table thead th {
                            background: #7c2d12;
                            color: #fff;
                            border: 0;
                            white-space: nowrap;
                        }
                        .collection-chip {
                            display: inline-flex;
                            align-items: center;
                            border-radius: 999px;
                            padding: 4px 10px;
                            font-size: .83rem;
                            font-weight: 600;
                        }
                        .collection-chip.collect { background: #dcfce7; color: #166534; }
                        .collection-chip.withdraw { background: #fee2e2; color: #b91c1c; }
                        .collection-note {
                            background: rgba(255,255,255,.18);
                            border: 1px solid rgba(255,255,255,.28);
                            border-radius: 14px;
                            padding: 12px 14px;
                        }
                        .collection-muted { color: #7c2d12; }
                        .collection-section-card {
                            display: block;
                            text-decoration: none;
                            background: #fff;
                            border: 1px solid #fdba74;
                            border-radius: 16px;
                            padding: 14px;
                            color: #7c2d12;
                            box-shadow: 0 10px 24px rgba(124,45,18,.07);
                            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
                            height: 100%;
                        }
                        .collection-section-card:hover {
                            transform: translateY(-2px);
                            box-shadow: 0 16px 30px rgba(124,45,18,.12);
                            border-color: #ea580c;
                        }
                        .collection-section-card.active {
                            background: linear-gradient(135deg,#7c2d12 0%,#c2410c 100%);
                            color: #fff;
                            border-color: transparent;
                        }
                        .collection-section-card .meta { font-size: .82rem; opacity: .82; }
                        .collection-section-card .amount { font-size: 1.2rem; font-weight: 700; }
                        .collection-manage-item {
                            border: 1px solid #fed7aa;
                            border-radius: 16px;
                            padding: 14px;
                            background: #fffaf5;
                        }
                        .collection-modal .modal-content {
                            border: 0;
                            border-radius: 20px;
                            overflow: hidden;
                            box-shadow: 0 24px 80px rgba(124,45,18,.22);
                        }
                        .collection-modal .modal-header {
                            background: linear-gradient(135deg,#7c2d12 0%,#c2410c 100%);
                            color: #fff;
                            border-bottom: 0;
                        }
                        .collection-modal .btn-close {
                            filter: invert(1);
                            opacity: .9;
                        }
                        .collection-modal .modal-body {
                            background: linear-gradient(180deg,#fff7ed 0%,#ffffff 100%);
                        }
                        @media (max-width: 767.98px) {
                            .collection-hero { padding: 18px; }
                        }
                    </style>

                    <div class="collection-hero shadow-sm mb-3">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap position-relative" style="z-index:1;">
                            <div>
                                <div class="small mb-2" style="color:rgba(255,255,255,.82);">قسم الحسابات / الجمع</div>
                                <h4 class="mb-2">إدارة الجمع الشهري</h4>
                                <div class="collection-note small">
                                    يتم تثبيت شهر العمل الحالي وعدم فتح أشهر قديمة أو غير مستخدمة. الانتقال يكون يومًا بيوم داخل نفس الشهر، ثم أرشفة الشهر لفتح الشهر التالي فقط.
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="small" style="color:rgba(255,255,255,.78);">الفترة المعروضة</div>
                                <div class="fs-5 fw-semibold"><?= h($collectionPeriodLabel) ?></div>
                                <?php if ($isCollectionActivePeriod): ?>
                                    <div class="small mt-1" style="color:rgba(255,255,255,.82);">تاريخ العمل المثبت: <?= h($collectionWorkDate) ?> / <?= h(arabicWeekdayName($collectionWorkDate)) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6 col-xl-3"><div class="collection-stat"><div class="label">مجموع الجمع خلال الشهر</div><div class="value"><?= number_format($collectionMonthlyTotal, 0) ?></div><div class="small text-muted">دينار عراقي</div></div></div>
                        <div class="col-6 col-xl-3"><div class="collection-stat"><div class="label">إجمالي السحب</div><div class="value"><?= number_format($collectionMonthlyWithdrawTotal, 0) ?></div><div class="small text-muted">دينار عراقي</div></div></div>
                        <div class="col-6 col-xl-3"><div class="collection-stat"><div class="label">الرصيد الحالي</div><div class="value"><?= number_format($collectionBalance, 0) ?></div><div class="small text-muted">بعد خصم السحوبات</div></div></div>
                        <div class="col-6 col-xl-3"><div class="collection-stat"><div class="label">أيام العمل المحفوظة</div><div class="value"><?= $collectionDayCount ?></div><div class="small text-muted">أيام مستخدمة داخل الشهر</div></div></div>
                    </div>

                    <div class="collection-shell p-3 p-lg-4 mb-3">
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                            <h5 class="mb-0">مركز عمليات الجمع</h5>
                            <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
                                <input type="hidden" name="page" value="collections">
                                <?php if ($collectionSectionFilter !== 'all'): ?>
                                    <input type="hidden" name="collection_section" value="<?= h($collectionSectionFilter) ?>">
                                <?php endif; ?>
                                <select class="form-select" name="collection_period" onchange="this.form.submit()" style="min-width:220px;">
                                    <?php foreach ($allowedCollectionPeriods as $period): ?>
                                        <option value="<?= h($period['key']) ?>" <?= $collectionFilterKey === $period['key'] ? 'selected' : '' ?>><?= h($period['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>

                        <div class="card border-0 shadow-sm mb-3" style="background:#fff; border-radius:18px;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="mb-0">إدارة أقسام الجمع</h6>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge text-bg-light border"><?= count($collectionSectionOptions) ?> قسم</span>
                                        <button class="btn btn-sm btn-outline-success" type="button" data-bs-toggle="modal" data-bs-target="#addCollectionSectionModal">إضافة قسم</button>
                                    </div>
                                </div>

                                <div class="row g-2">
                                    <?php foreach ($collectionSectionOptions as $option): ?>
                                        <div class="col-md-6 col-xl-4">
                                            <div class="collection-manage-item h-100">
                                                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                                                    <div>
                                                        <div class="fw-semibold"><?= h($option) ?></div>
                                                        <div class="small text-muted">
                                                            <?= $option === 'جمع عام' ? 'قسم احتياطي رئيسي ولا يمكن حذفه أو تعديل اسمه.' : 'يمكن تعديل الاسم أو حذف القسم، وستُنقل عملياته إلى جمع عام عند الحذف.' ?>
                                                        </div>
                                                    </div>
                                                    <a class="btn btn-sm btn-outline-secondary" href="<?= h(pageUrl('collections', ['collection_period' => $collectionFilterKey, 'collection_section' => $option])) ?>">عرض التفاصيل</a>
                                                </div>

                                                <?php if ($option !== 'جمع عام'): ?>
                                                    <div class="d-flex gap-2 flex-wrap">
                                                        <button
                                                            class="btn btn-outline-primary"
                                                            type="button"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#renameCollectionSectionModal"
                                                            data-section-name="<?= h($option) ?>"
                                                        >تعديل</button>
                                                        <button
                                                            class="btn btn-outline-danger"
                                                            type="button"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#deleteCollectionSectionModal"
                                                            data-section-name="<?= h($option) ?>"
                                                        >حذف</button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4 col-xl-3">
                                <a class="collection-section-card <?= $collectionSectionFilter === 'all' ? 'active' : '' ?>" href="<?= h(pageUrl('collections', ['collection_period' => $collectionFilterKey])) ?>">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <div class="fw-semibold">كل الأقسام</div>
                                            <div class="meta">عرض كامل تفاصيل الشهر</div>
                                        </div>
                                        <span class="badge rounded-pill text-bg-light border"><?= count($collectionRowsPrepared) ?></span>
                                    </div>
                                    <div class="amount mt-3"><?= number_format($collectionBalance, 0) ?> د.ع</div>
                                </a>
                            </div>
                            <?php foreach ($collectionSectionOptions as $sectionOption): ?>
                                <?php $sectionSummary = $collectionSectionSummaries[$sectionOption] ?? null; ?>
                                <div class="col-md-4 col-xl-3">
                                    <a class="collection-section-card <?= $collectionSectionFilter === $sectionOption ? 'active' : '' ?>" href="<?= h(pageUrl('collections', ['collection_period' => $collectionFilterKey, 'collection_section' => $sectionOption])) ?>">
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <div>
                                                <div class="fw-semibold"><?= h($sectionOption) ?></div>
                                                <div class="meta"><?= !empty($sectionSummary['last_date']) ? h((string)$sectionSummary['last_date']) : 'بدون عمليات حتى الآن' ?></div>
                                            </div>
                                            <span class="badge rounded-pill text-bg-light border"><?= (int)($sectionSummary['entry_count'] ?? 0) ?></span>
                                        </div>
                                        <div class="amount mt-3"><?= number_format((float)($sectionSummary['balance'] ?? 0), 0) ?> د.ع</div>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="row g-3 align-items-stretch">
                            <div class="col-xl-4">
                                <div class="card border-0 shadow-sm h-100" style="background:#fff; border-radius:18px;">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="mb-0">عملية جديدة</h6>
                                            <?php if ($isCollectionActivePeriod): ?>
                                                <span class="badge text-bg-light border"><?= h($collectionWorkDate) ?></span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">عرض فقط</span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($isCollectionActivePeriod): ?>
                                            <form method="post" class="row g-3">
                                                <input type="hidden" name="action" value="save_collection_entry">
                                                <div class="col-12">
                                                    <label class="form-label">التاريخ المثبت</label>
                                                    <input class="form-control bg-light" value="<?= h($collectionWorkDate) ?> - <?= h(arabicWeekdayName($collectionWorkDate)) ?>" readonly>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">قسم الجمع</label>
                                                    <select class="form-select" name="collection_name">
                                                        <?php foreach ($collectionSectionOptions as $option): ?>
                                                            <option value="<?= h($option) ?>" <?= $collectionSectionFilter === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">المبلغ بالدينار العراقي</label>
                                                    <input class="form-control" type="number" step="1" min="0" name="amount" placeholder="0">
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">ملاحظات</label>
                                                    <textarea class="form-control" name="notes" rows="3" placeholder="تفاصيل إضافية عن العملية"></textarea>
                                                </div>
                                                <div class="col-12 d-flex gap-2 flex-wrap">
                                                    <button class="btn btn-warning text-white flex-fill" type="submit" name="entry_type" value="collect">حفظ جمع جديد</button>
                                                    <button class="btn btn-outline-danger flex-fill" type="submit" name="entry_type" value="withdraw">سحب من الجمع</button>
                                                </div>
                                            </form>

                                            <div class="d-flex gap-2 flex-wrap mt-3">
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="action" value="advance_collection_day">
                                                    <button class="btn btn-outline-primary" type="submit">فتح يوم جديد<?= $nextCollectionDayLabel !== '' ? ' (' . h($nextCollectionDayLabel) . ')' : '' ?></button>
                                                </form>
                                                <form method="post" class="d-inline" onsubmit="return confirm('هل تريد أرشفة شهر الجمع الحالي وفتح الشهر التالي مباشرة؟');">
                                                    <input type="hidden" name="action" value="archive_collection_month">
                                                    <button class="btn btn-outline-dark" type="submit">أرشفة الشهر الحالي</button>
                                                </form>
                                            </div>
                                            <?php if ($nextCollectionDayLabel === ''): ?>
                                                <div class="small text-muted mt-2">لا يمكن فتح يوم جديد خارج الشهر الحالي. أرشف هذا الشهر أولاً لبدء الشهر التالي.</div>
                                            <?php else: ?>
                                                <div class="small text-muted mt-2">كل عملية تحفظ فورًا، وعند فتح يوم جديد يبقى الشهر نفسه مجمّدًا حتى الأرشفة.</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="alert alert-secondary mb-0">أنت تعرض شهرًا مؤرشفًا. الإدخال والسحب متاحان فقط في الشهر الفعّال الحالي.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-8">
                                <div class="card border-0 shadow-sm h-100" style="background:#fff; border-radius:18px;">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
                                            <div>
                                                <h6 class="mb-1"><?= h($collectionDetailsTitle) ?></h6>
                                                <div class="small collection-muted">
                                                    <?= $collectionSectionFilter === 'all' ? 'الرصيد يتحدث تراكميًا لكل عمليات الجمع والسحب في الشهر.' : 'يعرض هذا الجدول تفاصيل القسم المحدد فقط عند الضغط عليه.' ?>
                                                </div>
                                            </div>
                                            <span class="badge rounded-pill text-bg-light border"><?= count($collectionRows) ?> عملية</span>
                                        </div>

                                        <?php if ($collectionSectionFilter !== 'all' && $collectionSelectedSectionSummary !== null): ?>
                                            <div class="row g-2 mb-3">
                                                <div class="col-md-4"><div class="alert alert-warning mb-0">مجموع الجمع: <strong><?= number_format($collectionFilteredCollectTotal, 0) ?></strong> د.ع</div></div>
                                                <div class="col-md-4"><div class="alert alert-danger mb-0">إجمالي السحب: <strong><?= number_format($collectionFilteredWithdrawTotal, 0) ?></strong> د.ع</div></div>
                                                <div class="col-md-4"><div class="alert alert-success mb-0">رصيد القسم: <strong><?= number_format($collectionFilteredBalance, 0) ?></strong> د.ع</div></div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (empty($collectionRows)): ?>
                                            <div class="text-center py-5 text-muted">لا توجد عمليات محفوظة لهذا القسم في هذه الفترة.</div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-hover align-middle collection-table mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>التاريخ</th>
                                                            <th>المبلغ</th>
                                                            <th>الجمع</th>
                                                            <th>ملاحظات</th>
                                                            <th>العملية</th>
                                                            <th>الرصيد</th>
                                                            <th>خيارات</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($collectionRows as $row): ?>
                                                            <tr>
                                                                <td>
                                                                    <div class="fw-semibold"><?= h((string)$row['entry_date']) ?></div>
                                                                    <div class="small text-muted"><?= h((string)($row['entry_day'] ?? '')) ?></div>
                                                                </td>
                                                                <td class="fw-semibold"><?= number_format((float)$row['amount'], 0) ?> د.ع</td>
                                                                <td><?= h((string)($row['collection_name'] ?? '')) ?></td>
                                                                <td><?= h((string)($row['notes'] ?? '')) ?></td>
                                                                <td>
                                                                    <span class="collection-chip <?= ($row['entry_type'] ?? 'collect') === 'withdraw' ? 'withdraw' : 'collect' ?>">
                                                                        <?= ($row['entry_type'] ?? 'collect') === 'withdraw' ? 'سحب' : 'جمع' ?>
                                                                    </span>
                                                                </td>
                                                                <td class="fw-bold <?= ((float)($row['running_balance'] ?? 0) >= 0) ? 'text-success' : 'text-danger' ?>"><?= number_format((float)($row['running_balance'] ?? 0), 0) ?> د.ع</td>
                                                                <td>
                                                                    <button
                                                                        class="btn btn-sm btn-outline-primary"
                                                                        type="button"
                                                                        data-bs-toggle="modal"
                                                                        data-bs-target="#editCollectionEntryModal"
                                                                        data-entry-id="<?= (int)$row['id'] ?>"
                                                                        data-entry-amount="<?= h((string)toFloat($row['amount'] ?? 0)) ?>"
                                                                        data-entry-section="<?= h((string)($row['collection_name'] ?? 'جمع عام')) ?>"
                                                                        data-entry-notes="<?= h((string)($row['notes'] ?? '')) ?>"
                                                                        data-entry-type="<?= h((string)($row['entry_type'] ?? 'collect')) ?>"
                                                                    ><?= $isCollectionActivePeriod ? 'تعديل القيم' : 'تعديل المؤرشف' ?></button>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade collection-modal" id="addCollectionSectionModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="post">
                                    <div class="modal-header">
                                        <h5 class="modal-title">إضافة قسم جمع جديد</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <input type="hidden" name="action" value="add_collection_section">
                                        <label class="form-label">اسم القسم</label>
                                        <input class="form-control form-control-lg" name="section_name" placeholder="مثال: جمع الصيانة" required>
                                        <div class="small text-muted mt-2">سيظهر القسم مباشرة ضمن بطاقات الجمع وقائمة الإدخال.</div>
                                    </div>
                                    <div class="modal-footer border-0 pt-0 px-4 pb-4">
                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                                        <button type="submit" class="btn btn-success">حفظ القسم</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade collection-modal" id="renameCollectionSectionModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="post">
                                    <div class="modal-header">
                                        <h5 class="modal-title">تعديل اسم قسم الجمع</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <input type="hidden" name="action" value="rename_collection_section">
                                        <input type="hidden" name="old_section_name" id="renameCollectionOldName">
                                        <div class="mb-3">
                                            <label class="form-label">القسم الحالي</label>
                                            <input class="form-control bg-light" id="renameCollectionCurrentName" readonly>
                                        </div>
                                        <div>
                                            <label class="form-label">الاسم الجديد</label>
                                            <input class="form-control form-control-lg" name="new_section_name" id="renameCollectionNewName" required>
                                        </div>
                                    </div>
                                    <div class="modal-footer border-0 pt-0 px-4 pb-4">
                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                                        <button type="submit" class="btn btn-primary">حفظ التعديل</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade collection-modal" id="deleteCollectionSectionModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="post">
                                    <div class="modal-header">
                                        <h5 class="modal-title">حذف قسم الجمع</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <input type="hidden" name="action" value="delete_collection_section">
                                        <input type="hidden" name="section_name" id="deleteCollectionSectionName">
                                        <div class="alert alert-warning mb-3">
                                            سيتم حذف القسم <strong id="deleteCollectionSectionLabel"></strong> ونقل جميع عملياته تلقائيًا إلى قسم جمع عام.
                                        </div>
                                        <div class="small text-muted">هذا الإجراء لا يحذف عمليات الجمع نفسها، بل يغيّر تبعيتها فقط.</div>
                                    </div>
                                    <div class="modal-footer border-0 pt-0 px-4 pb-4">
                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                                        <button type="submit" class="btn btn-danger">تأكيد الحذف</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade collection-modal" id="editCollectionEntryModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="post" id="editCollectionEntryForm">
                                    <div class="modal-header">
                                        <h5 class="modal-title">تعديل قيم عملية الجمع</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <input type="hidden" name="action" value="update_collection_entry">
                                        <input type="hidden" name="entry_id" id="editCollectionEntryId">
                                        <input type="hidden" name="return_collection_period" value="<?= h($collectionFilterKey) ?>">
                                        <input type="hidden" name="return_collection_section" value="<?= h($collectionSectionFilter) ?>">
                                        <input type="hidden" name="confirm_archived_edit" id="confirmArchivedEditInput" value="0">
                                        <input type="hidden" id="isArchivedPeriodView" value="<?= $isCollectionActivePeriod ? '0' : '1' ?>">

                                        <?php if (!$isCollectionActivePeriod): ?>
                                            <div class="alert alert-warning">
                                                أنت الآن تعدّل بيانات من شهر مؤرشف. سيطلب النظام تأكيدًا إضافيًا قبل حفظ التعديل.
                                            </div>
                                        <?php endif; ?>

                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">المبلغ</label>
                                                <input class="form-control" type="number" step="1" min="1" name="amount" id="editCollectionEntryAmount" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">نوع العملية</label>
                                                <select class="form-select" name="entry_type" id="editCollectionEntryType">
                                                    <option value="collect">جمع</option>
                                                    <option value="withdraw">سحب</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">قسم الجمع</label>
                                                <select class="form-select" name="collection_name" id="editCollectionEntrySection">
                                                    <?php foreach ($collectionSectionOptions as $option): ?>
                                                        <option value="<?= h($option) ?>"><?= h($option) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">ملاحظات</label>
                                                <textarea class="form-control" name="notes" id="editCollectionEntryNotes" rows="3" placeholder="ملاحظات العملية"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer border-0 pt-0 px-4 pb-4">
                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                                        <button type="submit" class="btn btn-primary">حفظ التعديل</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <script>
                    (function () {
                        function fillEditEntryModalFromTrigger(trigger) {
                            if (!trigger) return;
                            var entryIdInput = document.getElementById('editCollectionEntryId');
                            var amountInput = document.getElementById('editCollectionEntryAmount');
                            var sectionInput = document.getElementById('editCollectionEntrySection');
                            var notesInput = document.getElementById('editCollectionEntryNotes');
                            var typeInput = document.getElementById('editCollectionEntryType');

                            var entryId = trigger.getAttribute('data-entry-id') || '';
                            var amount = trigger.getAttribute('data-entry-amount') || '';
                            var section = trigger.getAttribute('data-entry-section') || 'جمع عام';
                            var notes = trigger.getAttribute('data-entry-notes') || '';
                            var entryType = trigger.getAttribute('data-entry-type') || 'collect';

                            if (entryIdInput) entryIdInput.value = entryId;
                            if (amountInput) amountInput.value = amount;
                            if (sectionInput) sectionInput.value = section;
                            if (notesInput) notesInput.value = notes;
                            if (typeInput) typeInput.value = entryType === 'withdraw' ? 'withdraw' : 'collect';
                        }

                        var renameModal = document.getElementById('renameCollectionSectionModal');
                        if (renameModal) {
                            renameModal.addEventListener('show.bs.modal', function (event) {
                                var trigger = event.relatedTarget;
                                var sectionName = trigger ? (trigger.getAttribute('data-section-name') || '') : '';
                                var oldInput = document.getElementById('renameCollectionOldName');
                                var currentInput = document.getElementById('renameCollectionCurrentName');
                                var newInput = document.getElementById('renameCollectionNewName');
                                if (oldInput) oldInput.value = sectionName;
                                if (currentInput) currentInput.value = sectionName;
                                if (newInput) newInput.value = sectionName;
                            });
                        }

                        var deleteModal = document.getElementById('deleteCollectionSectionModal');
                        if (deleteModal) {
                            deleteModal.addEventListener('show.bs.modal', function (event) {
                                var trigger = event.relatedTarget;
                                var sectionName = trigger ? (trigger.getAttribute('data-section-name') || '') : '';
                                var hiddenInput = document.getElementById('deleteCollectionSectionName');
                                var label = document.getElementById('deleteCollectionSectionLabel');
                                if (hiddenInput) hiddenInput.value = sectionName;
                                if (label) label.textContent = sectionName;
                            });
                        }

                        var editEntryModal = document.getElementById('editCollectionEntryModal');
                        if (editEntryModal) {
                            editEntryModal.addEventListener('show.bs.modal', function (event) {
                                fillEditEntryModalFromTrigger(event.relatedTarget);
                            });
                        }

                        var editEntryForm = document.getElementById('editCollectionEntryForm');
                        if (editEntryForm) {
                            editEntryForm.addEventListener('submit', function (event) {
                                var isArchivedInput = document.getElementById('isArchivedPeriodView');
                                var confirmInput = document.getElementById('confirmArchivedEditInput');
                                var isArchived = isArchivedInput && isArchivedInput.value === '1';
                                if (!isArchived) {
                                    if (confirmInput) confirmInput.value = '0';
                                    return;
                                }
                                var ok = window.confirm('أنت على وشك تعديل سجل مؤرشف. هل تريد المتابعة؟');
                                if (!ok) {
                                    event.preventDefault();
                                    return;
                                }
                                if (confirmInput) confirmInput.value = '1';
                            });
                        }

                        var modalToOpen = <?= json_encode($collectionModalToOpen, JSON_UNESCAPED_UNICODE) ?>;
                        if (modalToOpen === 'add') {
                            var addEl = document.getElementById('addCollectionSectionModal');
                            if (addEl && window.bootstrap && bootstrap.Modal) {
                                bootstrap.Modal.getOrCreateInstance(addEl).show();
                            }
                        }
                        if (modalToOpen === 'rename') {
                            var renameEl = document.getElementById('renameCollectionSectionModal');
                            var currentSection = <?= json_encode($collectionSectionFilter === 'all' ? '' : $collectionSectionFilter, JSON_UNESCAPED_UNICODE) ?>;
                            if (renameEl && currentSection !== '') {
                                var oldInput = document.getElementById('renameCollectionOldName');
                                var currentInput = document.getElementById('renameCollectionCurrentName');
                                var newInput = document.getElementById('renameCollectionNewName');
                                if (oldInput) oldInput.value = currentSection;
                                if (currentInput) currentInput.value = currentSection;
                                if (newInput) newInput.value = currentSection;
                                if (window.bootstrap && bootstrap.Modal) {
                                    bootstrap.Modal.getOrCreateInstance(renameEl).show();
                                }
                            }
                        }
                        if (modalToOpen === 'edit_entry') {
                            var editEl = document.getElementById('editCollectionEntryModal');
                            var entryId = <?= (int)$collectionModalEntryId ?>;
                            if (editEl && entryId > 0 && window.bootstrap && bootstrap.Modal) {
                                var triggerBtn = document.querySelector('[data-bs-target="#editCollectionEntryModal"][data-entry-id="' + entryId + '"]');
                                if (triggerBtn) {
                                    fillEditEntryModalFromTrigger(triggerBtn);
                                    bootstrap.Modal.getOrCreateInstance(editEl).show();
                                }
                            }
                        }
                    })();
                    </script>
                </section>
                <?php endif; ?>

                <?php if ($currentPage === 'daily_closing'): ?>
                <?php
                    $dcDate = $dailyClosingWorkDate;
                    $dcTs = strtotime($dcDate) ?: time();
                    $dcMonth = (int)date('n', $dcTs);
                    $dcYear = (int)date('Y', $dcTs);
                    $dcDay = arabicWeekdayName($dcDate);
                ?>
                <section id="daily-closing" class="card shadow-sm border-0 mb-4"><div class="card-body p-3">
                    <h5 class="mb-3">الحسابات - التقفيل اليومي</h5>
                    <?php if (isset($_GET['rewind'])): ?>
                        <div class="alert alert-info py-2">تمت إعادة تاريخ العمل يومًا واحدًا للخلف: <strong><?= h($dcDate) ?></strong></div>
                    <?php endif; ?>
                    <form method="post" id="dcSaveForm" class="border rounded p-3 mb-4" style="background:#f8fafc;">
                        <input type="hidden" name="action" value="save_daily_closing">
                        <input type="hidden" name="record_id" value="0">
                        <div class="row g-2 mb-3">
                            <div class="col-md-3 col-6">
                                <label class="form-label">التاريخ</label>
                                <input type="date" class="form-control bg-light" name="closing_date" id="dcDate" value="<?= h($dcDate) ?>" readonly style="cursor:default;" tabindex="-1">
                            </div>
                            <div class="col-md-2 col-3">
                                <label class="form-label">الشهر</label>
                                <input type="text" class="form-control" name="closing_month" id="dcMonth" value="<?= $dcMonth ?>" readonly>
                            </div>
                            <div class="col-md-2 col-3">
                                <label class="form-label">السنة</label>
                                <input type="text" class="form-control" name="closing_year" id="dcYear" value="<?= $dcYear ?>" readonly>
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="form-label">اليوم</label>
                                <input type="text" class="form-control" name="closing_day" id="dcDay" value="<?= h($dcDay) ?>" readonly>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-3 col-6"><label class="form-label">مبيعات حجاية</label><input type="number" step="1" min="0" class="form-control dc-calc" id="hs" name="hajaya_sales" value="0"></div>
                            <div class="col-md-3 col-6"><label class="form-label">مصاريف حجاية</label><input type="number" step="1" min="0" class="form-control dc-calc" id="he" name="hajaya_expenses" value="0"></div>
                            <div class="col-md-3 col-6"><label class="form-label">مبيعات القلعة</label><input type="number" step="1" min="0" class="form-control dc-calc" id="qs" name="qalaa_sales" value="0"></div>
                            <div class="col-md-3 col-6"><label class="form-label">مصاريف القلعة</label><input type="number" step="1" min="0" class="form-control dc-calc" id="qe" name="qalaa_expenses" value="0"></div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-4 col-6"><label class="form-label">إضافات</label><input type="number" step="1" min="0" class="form-control dc-calc" id="da" name="additions_amount" value="0"></div>
                            <div class="col-md-8 col-6"><label class="form-label">ملاحظات الإضافات</label><input type="text" class="form-control" name="additions_notes" placeholder="مثال: حفلة عيد ميلاد أو زيادة في المبالغ"></div>
                        </div>

                        <div class="mb-2 fw-bold small">السحب والسلفات</div>
                        <div id="withdrawalsWrap" class="mb-2">
                            <div class="d-flex gap-2 mb-2">
                                <input type="number" step="1" min="0" name="withdrawal_amount[]" class="form-control dc-wa" value="0" placeholder="المبلغ">
                                <input type="text" name="withdrawal_desc[]" class="form-control" placeholder="وصف السحب">
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="addW">إضافة سحب</button>

                        <div class="alert alert-light border mb-3">
                            <div>مجموع المطعمين: <strong id="sumRestaurantSales">0</strong> د.ع</div>
                            <div>الإضافات: <strong id="sumAdditions">0</strong> د.ع</div>
                            <div>مجموع المبيعات بعد الإضافات: <strong id="sumSales">0</strong> د.ع</div>
                            <div>مصاريف المطاعم: <strong id="sumRestaurantExp">0</strong> د.ع</div>
                            <div>مجموع السحب والسلفات: <strong id="sumWithdraw">0</strong> د.ع</div>
                            <div>إجمالي المصاريف والسحب: <strong id="sumExp">0</strong> د.ع</div>
                            <div>الصافي النهائي: <strong id="sumNet">0</strong> د.ع</div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-success">حفظ التقفيل اليومي</button>
                            <button type="submit" form="dcRewindDateForm" class="btn btn-outline-warning" onclick="return confirm('هل تريد الرجوع بتاريخ العمل يومًا واحدًا للخلف؟');">الرجوع يوم واحد</button>
                            <button type="submit" form="dcForwardDateForm" class="btn btn-outline-info" onclick="return confirm('هل تريد التقدم بتاريخ العمل يومًا واحدًا للأمام؟');">تقدم يوم واحد</button>
                        </div>
                    </form>
                    <form method="post" id="dcRewindDateForm" class="d-none">
                        <input type="hidden" name="action" value="rewind_daily_closing_work_date">
                    </form>
                    <form method="post" id="dcForwardDateForm" class="d-none">
                        <input type="hidden" name="action" value="forward_daily_closing_work_date">
                    </form>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">مركز حفظ التقفيل اليومي</h6>
                        <span class="text-muted small"><?= count($dailyClosingRecords) ?> سجل</span>
                    </div>
                    <?php if (empty($dailyClosingRecords)): ?>
                        <div class="text-center text-muted py-4">لا توجد سجلات محفوظة.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle">
                                <thead class="table-dark"><tr><th>التاريخ</th><th>اليوم</th><th>الصافي</th><th>خيارات</th></tr></thead>
                                <tbody>
                                <?php foreach ($dailyClosingRecords as $rec): ?>
                                    <tr>
                                        <td><a href="<?= h(pageUrl('daily_closing_record', ['record_id' => (int)$rec['id']])) ?>" class="text-decoration-none fw-bold"><?= h((string)$rec['closing_date']) ?></a></td>
                                        <td><?= h((string)$rec['closing_day']) ?></td>
                                        <td class="<?= ((float)$rec['final_net'] >= 0) ? 'text-success' : 'text-danger' ?>"><?= number_format((float)$rec['final_net'], 2) ?></td>
                                        <td>
                                            <a class="btn btn-outline-primary btn-sm" href="<?= h(pageUrl('daily_closing_record', ['record_id' => (int)$rec['id']])) ?>">مشاهدة</a>
                                            <a class="btn btn-outline-warning btn-sm" href="<?= h(pageUrl('daily_closing_record', ['record_id' => (int)$rec['id'], 'edit' => 1])) ?>">تعديل</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div></section>

                <script>
                (function () {
                    var dayMap = {
                        "Sunday": "الأحد",
                        "Monday": "الإثنين",
                        "Tuesday": "الثلاثاء",
                        "Wednesday": "الأربعاء",
                        "Thursday": "الخميس",
                        "Friday": "الجمعة",
                        "Saturday": "السبت"
                    };
                    function get(id) { return document.getElementById(id); }
                    function num(v) { return parseFloat(v || "0") || 0; }
                    function fmt(v) { return Math.round(v).toLocaleString("ar-IQ"); }

                    var dateInput = get("dcDate");
                    var monthInput = get("dcMonth");
                    var yearInput = get("dcYear");
                    var dayInput = get("dcDay");
                    var wrap = get("withdrawalsWrap");
                    var addBtn = get("addW");

                    function updateDateFields() {
                        if (!dateInput || !dateInput.value) return;
                        var d = new Date(dateInput.value + "T00:00:00");
                        if (isNaN(d.getTime())) return;
                        monthInput.value = d.getMonth() + 1;
                        yearInput.value = d.getFullYear();
                        dayInput.value = dayMap[d.toLocaleDateString("en-US", { weekday: "long" })] || "";
                    }

                    function recalc() {
                        var hs = num(get("hs").value), he = num(get("he").value);
                        var qs = num(get("qs").value), qe = num(get("qe").value);
                        var additions = num(get("da").value);
                        var restaurantSales = hs + qs;
                        var sales = restaurantSales + additions;
                        var restaurantExp = he + qe;
                        var exp = restaurantExp;
                        var w = 0;
                        document.querySelectorAll(".dc-wa").forEach(function (el) { w += num(el.value); });
                        exp += w;
                        var net = sales - exp;
                        get("sumRestaurantSales").textContent = fmt(restaurantSales);
                        get("sumAdditions").textContent = fmt(additions);
                        get("sumSales").textContent = fmt(sales);
                        get("sumRestaurantExp").textContent = fmt(restaurantExp);
                        get("sumWithdraw").textContent = fmt(w);
                        get("sumExp").textContent = fmt(exp);
                        get("sumNet").textContent = fmt(net);
                        get("sumNet").style.color = net >= 0 ? "#16a34a" : "#dc2626";
                    }

                    if (dateInput) {
                        dateInput.addEventListener("change", updateDateFields);
                    }
                    document.querySelectorAll(".dc-calc").forEach(function (el) {
                        el.addEventListener("input", recalc);
                    });
                    if (wrap) {
                        wrap.addEventListener("input", function (e) {
                            if (e.target.classList.contains("dc-wa")) recalc();
                        });
                    }
                    if (addBtn) {
                        addBtn.addEventListener("click", function () {
                            var row = document.createElement("div");
                            row.className = "d-flex gap-2 mb-2";
                            row.innerHTML = '<input type="number" step="1" min="0" name="withdrawal_amount[]" class="form-control dc-wa" value="0" placeholder="المبلغ">' +
                                '<input type="text" name="withdrawal_desc[]" class="form-control" placeholder="وصف السحب">' +
                                '<button type="button" class="btn btn-outline-danger btn-sm">حذف</button>';
                            row.querySelector("button").addEventListener("click", function () {
                                row.remove();
                                recalc();
                            });
                            wrap.appendChild(row);
                        });
                    }
                    updateDateFields();
                    recalc();
                })();
                </script>
                <?php endif; ?>

                <?php if ($currentPage === 'daily_closing_record'): ?>
                <section id="daily-closing-record" class="card shadow-sm border-0 mb-4"><div class="card-body p-3">
                    <?php if (!$dailyClosingSelected): ?>
                        <div class="alert alert-warning mb-0">السجل المطلوب غير موجود.</div>
                    <?php else: ?>
                        <?php
                            $recordId = (int)$dailyClosingSelected['id'];
                            $withdrawRows = json_decode((string)($dailyClosingSelected['withdrawals_json'] ?? '[]'), true);
                            if (!is_array($withdrawRows) || empty($withdrawRows)) {
                                $withdrawRows = [['amount' => 0, 'desc' => '']];
                            }
                            $autoEdit = isset($_GET['edit']) && (string)$_GET['edit'] === '1';
                            $readonlyWithdrawals = array_values(array_filter($withdrawRows, static function ($w) {
                                $amount = (float)($w['amount'] ?? 0);
                                $desc = trim((string)($w['desc'] ?? ''));
                                return $amount > 0 || $desc !== '';
                            }));
                            $readonlyAdditionsAmount = (float)($dailyClosingSelected['additions_amount'] ?? 0);
                            $readonlyRestaurantSales = (float)$dailyClosingSelected['hajaya_sales'] + (float)$dailyClosingSelected['qalaa_sales'];
                            $readonlyAdditionsNotes = trim((string)($dailyClosingSelected['additions_notes'] ?? ''));
                            $closingWhatsAppMessage = implode("\n", [
                                'وصل التقفيل اليومي',
                                'التاريخ: ' . (string)$dailyClosingSelected['closing_date'],
                                'اليوم: ' . (string)$dailyClosingSelected['closing_day'],
                                'مبيعات حجاية: ' . number_format((float)$dailyClosingSelected['hajaya_sales'], 2),
                                'مصاريف حجاية: ' . number_format((float)$dailyClosingSelected['hajaya_expenses'], 2),
                                'مبيعات القلعة: ' . number_format((float)$dailyClosingSelected['qalaa_sales'], 2),
                                'مصاريف القلعة: ' . number_format((float)$dailyClosingSelected['qalaa_expenses'], 2),
                                'الإضافات: ' . number_format($readonlyAdditionsAmount, 2),
                                'ملاحظات الإضافات: ' . ($readonlyAdditionsNotes !== '' ? $readonlyAdditionsNotes : 'لا يوجد'),
                                'مجموع السحب والسلفات: ' . number_format((float)$dailyClosingSelected['total_withdrawals'], 2),
                                'الصافي النهائي: ' . number_format((float)$dailyClosingSelected['final_net'], 2) . ' د.ع',
                            ]);
                        ?>
                        <style>
                            .dcr-print-box { background: #fff; border: 1px solid #dbe4ee; border-radius: 10px; padding: 16px; }
                            .dcr-head { border-bottom: 2px solid #1e3a5f; padding-bottom: 8px; margin-bottom: 12px; }
                            .dcr-head h6 { color: #1e3a5f; margin: 0; font-weight: 700; }
                            .dcr-sub { color: #64748b; font-size: .85rem; }
                            .dcr-sec-title { background: #1e3a5f; color: #fff; font-size: .82rem; font-weight: 700; border-radius: 6px 6px 0 0; padding: 6px 10px; margin-top: 12px; }
                            .dcr-table { width: 100%; border-collapse: collapse; margin: 0; }
                            .dcr-table th, .dcr-table td { border: 1px solid #cbd5e1; padding: 7px 10px; text-align: right; font-size: .88rem; }
                            .dcr-table thead th { background: #f1f5f9; font-weight: 700; }
                            .dcr-summary { margin-top: 12px; border: 2px solid #0ea5e9; border-radius: 8px; background: #f0f9ff; padding: 10px 12px; }
                            .dcr-summary .row-item { display: flex; justify-content: space-between; padding: 3px 0; }
                            .dcr-summary .net { border-top: 1px dashed #7dd3fc; margin-top: 6px; padding-top: 8px; font-weight: 700; }
                        </style>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <h5 class="mb-0">سجل التقفيل: <?= h((string)$dailyClosingSelected['closing_date']) ?> - <?= h((string)$dailyClosingSelected['closing_day']) ?></h5>
                            <div class="d-flex gap-2">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(pageUrl('daily_closing')) ?>">رجوع</a>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.print()">طباعة</button>
                                <button type="button" class="btn btn-success btn-sm" id="sendWhatsAppReceiptBtn" data-msg="<?= h($closingWhatsAppMessage) ?>">ارسال الوصل واتساب</button>
                                <button type="button" class="btn btn-warning btn-sm" id="openEditClosingBtn">تعديل</button>
                            </div>
                        </div>

                        <div id="closingReadonlyBox" class="mb-3 dcr-print-box">
                            <div class="dcr-head">
                                <h6>وصل التقفيل اليومي</h6>
                                <div class="dcr-sub">التاريخ: <?= h((string)$dailyClosingSelected['closing_date']) ?> | اليوم: <?= h((string)$dailyClosingSelected['closing_day']) ?> | الشهر: <?= (int)$dailyClosingSelected['closing_month'] ?> | السنة: <?= (int)$dailyClosingSelected['closing_year'] ?></div>
                            </div>

                            <div class="dcr-sec-title">مبيعات ومصاريف المطاعم</div>
                            <div class="table-responsive">
                                <table class="dcr-table">
                                    <thead><tr><th>المطعم</th><th>المبيعات</th><th>المصاريف</th><th>الصافي</th></tr></thead>
                                    <tbody>
                                        <tr>
                                            <td>حجاية</td>
                                            <td><?= number_format((float)$dailyClosingSelected['hajaya_sales'], 2) ?></td>
                                            <td><?= number_format((float)$dailyClosingSelected['hajaya_expenses'], 2) ?></td>
                                            <td><?= number_format((float)$dailyClosingSelected['hajaya_net'], 2) ?></td>
                                        </tr>
                                        <tr>
                                            <td>القلعة</td>
                                            <td><?= number_format((float)$dailyClosingSelected['qalaa_sales'], 2) ?></td>
                                            <td><?= number_format((float)$dailyClosingSelected['qalaa_expenses'], 2) ?></td>
                                            <td><?= number_format((float)$dailyClosingSelected['qalaa_net'], 2) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="dcr-sec-title">الإضافات</div>
                            <div class="table-responsive">
                                <table class="dcr-table">
                                    <thead><tr><th>البيان</th><th>المبلغ</th><th>الملاحظات</th></tr></thead>
                                    <tbody>
                                        <tr>
                                            <td>إضافات على مبيعات المطعمين</td>
                                            <td><?= number_format($readonlyAdditionsAmount, 2) ?></td>
                                            <td><?= h($readonlyAdditionsNotes !== '' ? $readonlyAdditionsNotes : 'لا توجد ملاحظات') ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="dcr-sec-title">السحب والسلفات</div>
                            <div class="table-responsive">
                                <table class="dcr-table">
                                    <thead><tr><th>الوصف</th><th>المبلغ</th></tr></thead>
                                    <tbody>
                                        <?php if (empty($readonlyWithdrawals)): ?>
                                            <tr><td colspan="2" class="text-muted">لا يوجد سحب مسجل.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($readonlyWithdrawals as $w): ?>
                                                <tr>
                                                    <td><?= h((string)($w['desc'] ?? '')) ?></td>
                                                    <td><?= number_format((float)($w['amount'] ?? 0), 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="dcr-summary">
                                <div class="row-item"><span>مجموع المطعمين</span><strong><?= number_format($readonlyRestaurantSales, 2) ?> د.ع</strong></div>
                                <div class="row-item"><span>الإضافات</span><strong><?= number_format($readonlyAdditionsAmount, 2) ?> د.ع</strong></div>
                                <div class="row-item"><span>مجموع المبيعات بعد الإضافات</span><strong><?= number_format((float)$dailyClosingSelected['total_sales'], 2) ?> د.ع</strong></div>
                                <div class="row-item"><span>مصاريف المطاعم</span><strong><?= number_format((float)$dailyClosingSelected['total_restaurant_expenses'], 2) ?> د.ع</strong></div>
                                <div class="row-item"><span>مجموع السحب والسلفات</span><strong><?= number_format((float)$dailyClosingSelected['total_withdrawals'], 2) ?> د.ع</strong></div>
                                <div class="row-item"><span>إجمالي المصاريف والسحب</span><strong><?= number_format((float)$dailyClosingSelected['total_all_expenses'], 2) ?> د.ع</strong></div>
                                <div class="row-item net"><span>الصافي النهائي</span><strong class="<?= ((float)$dailyClosingSelected['final_net'] >= 0) ? 'text-success' : 'text-danger' ?>"><?= number_format((float)$dailyClosingSelected['final_net'], 2) ?> د.ع</strong></div>
                            </div>
                        </div>

                        <div class="card border-warning" id="closingEditBox" style="display:<?= $autoEdit ? 'block' : 'none' ?>;">
                            <div class="card-body">
                                <h6 class="mb-3">تعديل السجل المحفوظ</h6>
                                <form method="post" id="dcrEditForm">
                                    <input type="hidden" name="action" value="save_daily_closing">
                                    <input type="hidden" name="record_id" value="<?= $recordId ?>">
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-3 col-6"><label class="form-label">التاريخ</label><input type="date" class="form-control" name="closing_date" value="<?= h((string)$dailyClosingSelected['closing_date']) ?>" required></div>
                                        <div class="col-md-2 col-3"><label class="form-label">الشهر</label><input type="text" class="form-control" name="closing_month" value="<?= (int)$dailyClosingSelected['closing_month'] ?>" required></div>
                                        <div class="col-md-2 col-3"><label class="form-label">السنة</label><input type="text" class="form-control" name="closing_year" value="<?= (int)$dailyClosingSelected['closing_year'] ?>" required></div>
                                        <div class="col-md-3 col-6"><label class="form-label">اليوم</label><input type="text" class="form-control" name="closing_day" value="<?= h((string)$dailyClosingSelected['closing_day']) ?>" required></div>
                                    </div>
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-3 col-6"><label class="form-label">مبيعات حجاية</label><input type="number" step="1" min="0" class="form-control dcr-calc" id="dcrHS" name="hajaya_sales" value="<?= h((string)$dailyClosingSelected['hajaya_sales']) ?>"></div>
                                        <div class="col-md-3 col-6"><label class="form-label">مصاريف حجاية</label><input type="number" step="1" min="0" class="form-control dcr-calc" id="dcrHE" name="hajaya_expenses" value="<?= h((string)$dailyClosingSelected['hajaya_expenses']) ?>"></div>
                                        <div class="col-md-3 col-6"><label class="form-label">مبيعات القلعة</label><input type="number" step="1" min="0" class="form-control dcr-calc" id="dcrQS" name="qalaa_sales" value="<?= h((string)$dailyClosingSelected['qalaa_sales']) ?>"></div>
                                        <div class="col-md-3 col-6"><label class="form-label">مصاريف القلعة</label><input type="number" step="1" min="0" class="form-control dcr-calc" id="dcrQE" name="qalaa_expenses" value="<?= h((string)$dailyClosingSelected['qalaa_expenses']) ?>"></div>
                                    </div>
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-4 col-6"><label class="form-label">إضافات</label><input type="number" step="1" min="0" class="form-control dcr-calc" id="dcrDA" name="additions_amount" value="<?= h((string)($dailyClosingSelected['additions_amount'] ?? 0)) ?>"></div>
                                        <div class="col-md-8 col-6"><label class="form-label">ملاحظات الإضافات</label><input type="text" class="form-control" name="additions_notes" value="<?= h((string)($dailyClosingSelected['additions_notes'] ?? '')) ?>" placeholder="مثال: حفلة عيد ميلاد أو زيادة في المبالغ"></div>
                                    </div>
                                    <div class="mb-2 fw-bold small">السحب والسلفات</div>
                                    <div id="dcrWithdrawals" class="mb-2">
                                        <?php foreach ($withdrawRows as $w): ?>
                                            <div class="d-flex gap-2 mb-2">
                                                <input type="number" step="1" min="0" name="withdrawal_amount[]" class="form-control dcr-wa" value="<?= h((string)($w['amount'] ?? 0)) ?>">
                                                <input type="text" name="withdrawal_desc[]" class="form-control" value="<?= h((string)($w['desc'] ?? '')) ?>">
                                                <button type="button" class="btn btn-outline-danger btn-sm dcr-remove">حذف</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="dcrAddW">إضافة سحب</button>
                                    <div class="alert alert-light border mb-3">
                                        <div>مجموع المطعمين: <strong id="dcrSumRestaurantSales">0</strong> د.ع</div>
                                        <div>الإضافات: <strong id="dcrSumAdditions">0</strong> د.ع</div>
                                        <div>مجموع المبيعات بعد الإضافات: <strong id="dcrSumSales">0</strong> د.ع</div>
                                        <div>مصاريف المطاعم: <strong id="dcrSumRestaurantExp">0</strong> د.ع</div>
                                        <div>مجموع السحب والسلفات: <strong id="dcrSumWithdraw">0</strong> د.ع</div>
                                        <div>إجمالي المصاريف والسحب: <strong id="dcrSumExp">0</strong> د.ع</div>
                                        <div>الصافي النهائي: <strong id="dcrSumNet">0</strong> د.ع</div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-success">حفظ التعديلات</button>
                                        <button type="button" class="btn btn-outline-secondary" id="closeEditClosingBtn">إلغاء</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <form method="post" class="mt-3" onsubmit="return confirm('هل تريد حذف هذا السجل؟');">
                            <input type="hidden" name="action" value="delete_daily_closing">
                            <input type="hidden" name="record_id" value="<?= $recordId ?>">
                            <button class="btn btn-outline-danger btn-sm">حذف السجل</button>
                        </form>

                        <script>
                        (function () {
                            var openBtn = document.getElementById("openEditClosingBtn");
                            var closeBtn = document.getElementById("closeEditClosingBtn");
                            var waBtn = document.getElementById("sendWhatsAppReceiptBtn");
                            var editBox = document.getElementById("closingEditBox");
                            var addWBtn = document.getElementById("dcrAddW");
                            var list = document.getElementById("dcrWithdrawals");

                            function get(id) { return document.getElementById(id); }
                            function num(v) { return parseFloat(v || "0") || 0; }
                            function fmt(v) { return Math.round(v).toLocaleString("ar-IQ"); }

                            function recalcEdit() {
                                var hs = num(get("dcrHS").value), he = num(get("dcrHE").value);
                                var qs = num(get("dcrQS").value), qe = num(get("dcrQE").value);
                                var additions = num(get("dcrDA").value);
                                var restaurantSales = hs + qs;
                                var sales = restaurantSales + additions;
                                var restaurantExp = he + qe;
                                var withdraw = 0;
                                document.querySelectorAll(".dcr-wa").forEach(function (el) { withdraw += num(el.value); });
                                var exp = restaurantExp + withdraw;
                                var net = sales - exp;
                                get("dcrSumRestaurantSales").textContent = fmt(restaurantSales);
                                get("dcrSumAdditions").textContent = fmt(additions);
                                get("dcrSumSales").textContent = fmt(sales);
                                get("dcrSumRestaurantExp").textContent = fmt(restaurantExp);
                                get("dcrSumWithdraw").textContent = fmt(withdraw);
                                get("dcrSumExp").textContent = fmt(exp);
                                get("dcrSumNet").textContent = fmt(net);
                                get("dcrSumNet").style.color = net >= 0 ? "#16a34a" : "#dc2626";
                            }

                            if (openBtn) {
                                openBtn.addEventListener("click", function () {
                                    editBox.style.display = "block";
                                    editBox.scrollIntoView({ behavior: "smooth", block: "start" });
                                });
                            }
                            if (closeBtn) {
                                closeBtn.addEventListener("click", function () {
                                    editBox.style.display = "none";
                                });
                            }
                            if (waBtn) {
                                waBtn.addEventListener("click", function () {
                                    var lastPhone = localStorage.getItem("dailyClosingWhatsAppPhone") || "";
                                    var rawPhone = window.prompt("ادخل رقم واتساب (مثال: 07xxxxxxxx)", lastPhone);
                                    if (rawPhone === null) {
                                        return;
                                    }
                                    var digits = (rawPhone || "").replace(/\D+/g, "");
                                    if (digits.startsWith("00")) {
                                        digits = digits.slice(2);
                                    }
                                    if (digits.startsWith("0")) {
                                        digits = "964" + digits.slice(1);
                                    }
                                    if (!digits) {
                                        alert("رقم واتساب غير صالح.");
                                        return;
                                    }
                                    localStorage.setItem("dailyClosingWhatsAppPhone", rawPhone);
                                    var msg = waBtn.getAttribute("data-msg") || "";
                                    window.open("https://wa.me/" + digits + "?text=" + encodeURIComponent(msg), "_blank");
                                });
                            }
                            if (list) {
                                list.addEventListener("click", function (e) {
                                    if (e.target.classList.contains("dcr-remove")) {
                                        e.target.closest(".d-flex").remove();
                                        recalcEdit();
                                    }
                                });
                                list.addEventListener("input", function (e) {
                                    if (e.target.classList.contains("dcr-wa")) {
                                        recalcEdit();
                                    }
                                });
                            }
                            document.querySelectorAll(".dcr-calc").forEach(function (el) {
                                el.addEventListener("input", recalcEdit);
                            });
                            if (addWBtn) {
                                addWBtn.addEventListener("click", function () {
                                    var row = document.createElement("div");
                                    row.className = "d-flex gap-2 mb-2";
                                    row.innerHTML = '<input type="number" step="1" min="0" name="withdrawal_amount[]" class="form-control dcr-wa" value="0">' +
                                        '<input type="text" name="withdrawal_desc[]" class="form-control" value="">' +
                                        '<button type="button" class="btn btn-outline-danger btn-sm dcr-remove">حذف</button>';
                                    list.appendChild(row);
                                    recalcEdit();
                                });
                            }
                            recalcEdit();
                        })();
                        </script>
                    <?php endif; ?>
                </div></section>
                <?php endif; ?>

                <?php if ($currentPage === 'reports'): ?>
                <section id="reports" class="card shadow-sm border-0 mb-4"><div class="card-body">
                    <h5>التقارير</h5>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-primary" href="<?= h(pageUrl('reports') . '&export=attendance') ?>">تقرير حضور - Excel</a>
                        <a class="btn btn-outline-primary" href="<?= h(pageUrl('reports') . '&export=salaries') ?>">تقرير رواتب - Excel</a>
                        <a class="btn btn-outline-primary" href="<?= h(pageUrl('reports') . '&export=finance') ?>">تقرير أرباح - Excel</a>
                        <button onclick="window.print()" class="btn btn-outline-danger">تصدير PDF (Print to PDF)</button>
                    </div>
                    <div class="small-muted mt-2">لـ PDF تلقائي بدون طباعة المتصفح يمكن إضافة مكتبة TCPDF لاحقاً.</div>
                </div></section>
                <?php endif; ?>

                <?php if ($currentPage === 'settings'): ?>
                <section id="settings" class="card shadow-sm border-0 mb-4"><div class="card-body">
                    <h5>الإعدادات</h5>
                    <form method="post" class="row g-3">
                        <input type="hidden" name="action" value="save_settings">
                        <div class="col-12"><h6 class="mb-1">عام</h6></div>
                        <div class="col-md-4"><label class="form-label">اسم الموقع (يظهر أعلى الصفحة بدل Restaurant System)</label><input class="form-control" name="site_name" value="<?= h((string)($settingsRow['site_name'] ?? APP_TITLE)) ?>" placeholder="اسم الموقع"></div>
                        <div class="col-md-4"><label class="form-label">اسم مدير النظام</label><input class="form-control" name="admin_user" value="<?= h((string)($settingsRow['admin_user'] ?? 'admin')) ?>" placeholder="اسم مدير النظام"></div>
                        <div class="col-md-4"><label class="form-label">كلمة مرور جديدة (اختياري)</label><input class="form-control" type="password" name="admin_password" placeholder="اتركها فارغة بدون تغيير"></div>

                        <div class="col-12"><h6 class="mb-1">المظهر والنسخ الاحتياطي</h6></div>
                        <div class="col-md-3">
                            <label class="form-label">اللون الرئيسي للموقع</label>
                            <input class="form-control form-control-color" type="color" name="primary_color" value="<?= h($primaryColor) ?>" title="اختر اللون الرئيسي">
                        </div>
                        <div class="col-md-3 d-flex align-items-center"><input type="checkbox" class="form-check-input ms-2" name="whatsapp_enabled" <?= !empty($settingsRow['whatsapp_enabled']) ? 'checked' : '' ?>> <label class="form-check-label">واتساب مفعل</label></div>
                        <div class="col-md-3 d-flex align-items-center"><input type="checkbox" class="form-check-input ms-2" name="auto_backup_enabled" <?= !empty($settingsRow['auto_backup_enabled']) ? 'checked' : '' ?>> <label class="form-check-label">نسخ تلقائي يومي</label></div>
                        <div class="col-md-6">
                            <label class="form-label d-block">وضع الموقع</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="submit" name="dark_mode_enabled" value="1" class="btn btn-dark btn-sm" <?= $isAdmin ? '' : 'disabled' ?>>تشغيل الوضع الداكن</button>
                                <button type="submit" name="dark_mode_enabled" value="0" class="btn btn-outline-secondary btn-sm" <?= $isAdmin ? '' : 'disabled' ?>>تشغيل الوضع الفاتح</button>
                                <span class="small-muted align-self-center">الحالي: <?= $isDarkMode ? 'داكن' : 'فاتح' ?></span>
                            </div>
                        </div>

                        <div class="col-12"><h6 class="mb-1">افتراضيات الأقسام</h6></div>
                        <div class="col-md-4">
                            <label class="form-label">القسم الافتراضي عند إضافة موظف</label>
                            <select class="form-select" name="default_employee_department">
                                <option value="">-- بدون افتراضي --</option>
                                <?php foreach ($employeeDepartments as $d): ?>
                                    <option value="<?= h($d) ?>" <?= $defaultEmployeeDepartment === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">تصنيف المصروف الافتراضي</label>
                            <input class="form-control" name="default_expense_category" value="<?= h($defaultExpenseCategory) ?>" placeholder="مثال: عام">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">قسم الدين الافتراضي</label>
                            <select class="form-select" name="default_debt_category">
                                <?php foreach ($debtCategories as $cat): ?>
                                    <option value="<?= h($cat) ?>" <?= $defaultDebtCategory === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12"><h6 class="mb-1">لوحة التحكم</h6></div>
                        <div class="col-md-3 d-flex align-items-center"><input type="checkbox" class="form-check-input ms-2" name="show_deduction_card" <?= $showDeductionCard ? 'checked' : '' ?>> <label class="form-check-label">إظهار بطاقة الخصومات</label></div>
                        <div class="col-md-3 d-flex align-items-center"><input type="checkbox" class="form-check-input ms-2" name="show_total_salary_card" <?= $showTotalSalaryCard ? 'checked' : '' ?>> <label class="form-check-label">إظهار بطاقة مجموع الرواتب</label></div>

                        <div class="col-12"><h6 class="mb-1">صلاحيات المدير للأقسام</h6></div>
                        <div class="col-md-3 d-flex align-items-center"><input type="checkbox" class="form-check-input ms-2" name="manager_finance_enabled" <?= $managerFinanceEnabled ? 'checked' : '' ?>> <label class="form-check-label">السماح بقسم الحسابات/المطاعم</label></div>
                        <div class="col-md-3 d-flex align-items-center"><input type="checkbox" class="form-check-input ms-2" name="manager_reports_enabled" <?= $managerReportsEnabled ? 'checked' : '' ?>> <label class="form-check-label">السماح بالتقارير</label></div>
                        <div class="col-md-3 d-flex align-items-center"><input type="checkbox" class="form-check-input ms-2" name="manager_backup_enabled" <?= $managerBackupEnabled ? 'checked' : '' ?>> <label class="form-check-label">السماح بالنسخ الاحتياطي</label></div>

                        <div class="col-md-3"><button class="btn btn-success w-100" <?= $isAdmin ? '' : 'disabled' ?>>حفظ البيانات</button></div>
                    </form>
                    <form method="post" class="mt-2">
                        <input type="hidden" name="action" value="backup_now">
                        <button class="btn btn-outline-primary" <?= $isAdmin ? '' : 'disabled' ?>>إنشاء نسخة احتياطية الآن</button>
                    </form>

                    <div class="mt-3">
                        <h6 class="mb-2">استعادة نسخة احتياطية</h6>
                        <form method="post" class="row g-2">
                            <input type="hidden" name="action" value="restore_backup">
                            <div class="col-md-10"><input class="form-control" name="backup_path" placeholder="مسار ملف النسخة مثل C:\\xampp\\htdocs\\ayob\\data\\backups\\restaurant_20260329_120000.db"></div>
                            <div class="col-md-2"><button class="btn btn-warning w-100" <?= $isAdmin ? '' : 'disabled' ?>>استعادة نسخة</button></div>
                        </form>
                        <div class="small-muted mt-2">نسخة تلقائية: يمكن تنفيذ install.php عبر Scheduled Task يومياً لإنشاء Backup تلقائي.</div>
                    </div>

                    <div class="mt-3">
                        <h6 class="mb-2">سجل النسخ الاحتياطية</h6>
                        <div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>#</th><th>المسار</th><th>الحالة</th><th>التاريخ</th></tr></thead><tbody>
                        <?php foreach ($backupRows as $row): ?><tr><td><?= (int)$row['id'] ?></td><td><?= h((string)$row['backup_path']) ?></td><td><?= h((string)$row['status']) ?></td><td><?= h((string)$row['created_at']) ?></td></tr><?php endforeach; ?>
                        </tbody></table></div>
                    </div>
                </div></section>
                <?php endif; ?>
        </main>
    </div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function epToggleMenu(e, btn) {
    e.stopPropagation();
    var menu = btn.nextElementSibling;
    var isOpen = menu.classList.contains('show');
    document.querySelectorAll('.ep-actions-menu.show').forEach(function(m){ m.classList.remove('show'); });
    if (!isOpen) {
        var r = btn.getBoundingClientRect();
        menu.style.top = '-9999px';
        menu.style.left = '-9999px';
        menu.classList.add('show');
        var mh = menu.offsetHeight;
        menu.style.top = (r.top - mh - 4) + 'px';
        var left = r.right - menu.offsetWidth;
        if (left < 4) left = r.left;
        menu.style.left = left + 'px';
    }
}
document.addEventListener('click', function(){
    document.querySelectorAll('.ep-actions-menu.show').forEach(function(m){ m.classList.remove('show'); });
});
</script>
<footer style="background:#0f172a;color:#e2e8f0;padding:6px 10px;text-align:center;border-top:1px solid #1e293b;line-height:1.3;">
    <span style="font-size:.78rem;">جميع الحقوق محفوظة للمبرمج ايوب سعيد</span>
    <a href="https://wa.me/9647777476150?text=السلام%20عليكم%20ارغب%20بالتواصل" target="_blank" style="display:inline-block;background:#16a34a;color:#fff;text-decoration:none;padding:3px 8px;border-radius:6px;font-size:.72rem;font-weight:600;margin-inline-start:8px;">واتساب</a>
    <a href="https://instagram.com/usaxz" target="_blank" style="display:inline-block;background:#e1306c;color:#fff;text-decoration:none;padding:3px 8px;border-radius:6px;font-size:.72rem;font-weight:600;margin-inline-start:6px;">انستغرام @usaxz</a>
</footer>
</body>
</html>
