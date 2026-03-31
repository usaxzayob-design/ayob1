# دليل التنفيذ السريع

## الأدوات المطلوبة
- Visual Studio (VB.NET)
- SQL Server + SSMS
- XAMPP (PHP)
- Chrome

## 1) إنشاء قاعدة البيانات
- نفذ الملف: Database/RestaurantSystem_Minimal.sql

## 2) مشروع VB.NET
- New Project
- Windows Forms App (.NET Framework)
- اسم المشروع: RestaurantSystem

## 3) ربط قاعدة البيانات
- استخدم الملف: samples/vb/DB.vb

## 4) تسجيل الدخول
- استخدم المقطع: samples/vb/LoginFormSnippet.vb

## 5) إدارة الموظفين
- استخدم المقطع: samples/vb/EmployeesFormSnippet.vb

## 6) المرحلة 2: الحضور + الربط بالراتب
- نفذ ملف SQL التالي داخل SSMS:
	- Database/Phase2_AttendanceAndPayroll.sql
- أضف هذه الملفات لمشروع VB:
	- samples/vb/AttendanceService.vb
	- samples/vb/SalaryService.vb
	- samples/vb/AttendanceFormSnippet.vb
	- samples/vb/DashboardFormSnippet.vb

### عناصر واجهة AttendanceForm المطلوبة
- cmbEmployee (ComboBox)
- dtDate (DateTimePicker)
- cmbStatus (ComboBox)
- btnSave (Button)
- btnFilter (Button)
- btnCalcSalary (Button)
- DataGridView1 (DataGridView)

### عناصر واجهة DashboardForm المطلوبة
- PanelEmployees, PanelSalaries, PanelDeductions, PanelProfit (Panels)
- lblEmployees, lblSalaries, lblDeductions, lblProfit (Labels)
- btnEmployees, btnAttendance, btnSalaries (Buttons)

## 7) تحديث الرواتب (AbsenceDays / LeaveDays / DailySalary)
- نفذ ملف SQL التالي:
	- Database/Phase3_SalaryPrecision.sql
- نسخة SalaryService الحالية أصبحت تطبق:
	- الراتب اليومي = الراتب الاساسي / 30
	- خصم الغياب بالكامل
	- اول يومين اجازة بدون خصم ثم خصم الباقي

## 8) واجهة الرواتب + PDF + واتساب
- أضف الملفات التالية:
	- samples/vb/SalariesFormSnippet.vb
	- samples/vb/SalaryPdfService.vb
	- samples/vb/WhatsAppService.vb

### عناصر واجهة SalariesForm المطلوبة
- cmbEmployee, cmbMonth, cmbYear (ComboBox)
- txtLoans, txtBonuses, txtAdditions, txtPhone (TextBox)
- btnCalculate, btnPrintPdf, btnSendWhatsApp (Button)
- dgvSalaries (DataGridView)

### الحزم المطلوبة في VB
- iTextSharp (لتوليد PDF)
- Microsoft.Office.Interop.Excel (لتصدير Excel)

## 9) الحسابات + الصلاحيات + الاشعارات
- نفذ ملف SQL التالي:
	- Database/Phase4_5_FinanceRolesNotifications.sql
- أضف ملفات VB التالية:
	- samples/vb/FinanceService.vb
	- samples/vb/FinanceFormSnippet.vb
	- samples/vb/SecurityAndNotificationsService.vb

### نقاط عمل سريعة
- صافي الربح: Sales - Expenses - Loans - ExternalExpenses
- الصلاحيات: Admin / Manager / User عبر عمود Role
- الاشعارات: جدول Notifications وربطه بالموظف
- واتساب سريع: فتح wa.me
- واتساب احترافي API: استخدم ملف PHP الموجود في samples/php/whatsapp_cloud_api.php

## 10) Excel + أرشفة + أمان + Backup
- نفذ ملف SQL التالي:
	- Database/Phase6_Archive.sql
- أضف ملفات VB التالية:
	- samples/vb/ExcelExportService.vb
	- samples/vb/PasswordSecurity.vb
	- samples/vb/ArchiveService.vb
	- samples/vb/DatabaseMaintenanceService.vb
	- samples/vb/ExportButtonsSnippets.vb
	- samples/vb/ArchiveAndBackupSnippet.vb
	- samples/vb/PermissionsSnippet.vb

### ماذا تغطي هذه الملفات
- تصدير DataGridView إلى Excel (الرواتب/الحضور/الأرباح/الديون)
- أرشفة شهرية إلى جدول Archive
- تشفير SHA256 لكلمات المرور عند الاضافة وتسجيل الدخول
- نسخ احتياطي واسترجاع قاعدة البيانات
- إخفاء أو تعطيل الأزرار حسب الدور

## 11) مطابقة المشروع مع المراحل المطلوبة
- المرحلة 1 (الأساسيات):
	- تسجيل الدخول: index.php (نموذج دخول + أدوار)
	- Dashboard: index.php#dashboard
	- إدارة الموظفين: index.php#employees
	- إدارة الحضور: index.php#attendance
- المرحلة 2 (الرواتب والمالية):
	- الرواتب + صافي تلقائي: index.php#salaries
	- السلف/الخصومات/المكافآت/الإضافات: index.php#adjustments
- المرحلة 3 (المطاعم):
	- القلعة + حجاية + صافي ربح لكل مطعم + العام: index.php#restaurants
- المرحلة 4 (المالية العامة):
	- المصاريف العامة: index.php#expenses
	- الديون + التسديد + المتبقي: index.php#debts
- المرحلة 5 (التقارير):
	- Excel (حضور/رواتب/أرباح): index.php#reports
	- PDF: طباعة المتصفح Print to PDF داخل index.php#reports
- المرحلة 6 (النسخ الاحتياطي):
	- نسخة فورية + استعادة + نسخة تلقائية يومية: index.php#backup + index.php#settings
- المرحلة 7 (الإعدادات):
	- اسم النظام + كلمة مرور المدير + صلاحيات + تفعيل واتساب: index.php#settings

## 12) الاختبار والتصحيح (تم تنفيذه)
- اختبار البنية:
	- data/smoke_test.php
- اختبار المرحلة 1 و2:
	- data/phase1_phase2_test.php

نتيجة آخر اختبار:
- attendance_unique: ok
- salary_calc: ok
- salary_net=2700.00

## ملاحظة مهمة
تم تحديث المقاطع الأساسية لتستخدم تشفير SHA256 لكلمة المرور عند الحفظ وتسجيل الدخول.
وإذا رغبت باستخدام تشفير أقوى (bcrypt) استخدم النسخ التالية:
- samples/vb/LoginFormExample.vb
- samples/vb/EmployeeInsertExample.vb
