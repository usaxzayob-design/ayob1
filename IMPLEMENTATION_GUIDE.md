# Implementation Guide (PHP + VB + SQL Server + WhatsApp)

## 1) Database
- Run: `Database/CoreSchema.sql` on SQL Server.
- Create at least one employee with hashed password.

## 2) Core Payroll Logic
- VB calculator is in `samples/vb/PayrollCalculator.vb`.
- Formula:
  - DailySalary = TotalSalary / WorkingDays
  - Deduction = Absences * DailySalary
  - NetSalary = TotalSalary - Deduction - Loans + Bonuses + Additions

## 3) Desktop (VB WinForms)
- Login sample: `samples/vb/LoginFormExample.vb`
- Employee insert sample: `samples/vb/EmployeeInsertExample.vb`
- Recommended NuGet package:
  - `BCrypt.Net-Next`

## 4) Web (PHP + Bootstrap)
- Attendance page: `samples/php/attendance.php`
- Dashboard page: `samples/php/dashboard.php`

## 5) WhatsApp
- Meta Cloud API sample: `samples/php/whatsapp_cloud_api.php`
- Twilio sample: `samples/php/twilio_whatsapp.php`

## 6) Recommended API Endpoints
- POST `/api/auth/login`
- GET `/api/employees`
- POST `/api/employees`
- GET `/api/attendance?employee_id=1`
- POST `/api/attendance`
- GET `/api/salary-details?month=3&year=2026`
- POST `/api/salary-details/calculate`
- GET `/api/dashboard`
- POST `/api/notifications/whatsapp`

## 7) Security Notes
- Never store passwords as plain text.
- Use prepared statements for all SQL queries.
- Keep API tokens in environment variables.
- Add role checks (Admin / Employee) on each protected endpoint.

## 8) Advanced Features Mapping
- Roles: table `Roles` and app-level RBAC checks.
- Logging: table `AuditLogs`.
- Monthly archive: SQL Agent job to move old records.
- Backup: SQL Server Maintenance Plan or scheduled script.
