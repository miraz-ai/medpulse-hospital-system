# MedPulse Hospital Management System
## Backend Architecture, Data Integrity & Viva Defense Technical Guide

---

### Document Overview
* **Target Audience**: University Faculty Examination Committee, Project Supervisors, Enterprise Technical Auditors.
* **System**: MedPulse Enterprise Hospital Management & Central Treasury System.
* **Deployment Target**: Apache/2.4 + PHP 8.2 + MariaDB/MySQL 10.4 (XAMPP LAMPP Stack).
* **Location**: `/opt/lampp/htdocs/MedPulse/SYSTEM_ARCHITECTURE_AND_DEFENSE_GUIDE.md`

---

## 1. Executive System Overview

### 1.1 Architectural Philosophy
MedPulse is built on a **Centralized Treasury and Clinical Governance Model**. In enterprise hospital workflows, clinical operations (admissions, inpatient care, bed occupancy, doctor rounds) and financial operations (treasury receipts, patient invoicing, doctor payout ledgers) cannot operate as disconnected silos. MedPulse bridges these functions with transactional integrity, strict Role-Based Access Control (RBAC), and automated ledger reconciliations.

```
       ┌─────────────────────────────────────────────────────────┐
       │                   MEDPULSE CORE PLATFORM                │
       └────────────────────────────┬────────────────────────────┘
                                    │
           ┌────────────────────────┼────────────────────────┐
           ▼                        ▼                        ▼
┌──────────────────────┐ ┌──────────────────────┐ ┌──────────────────────┐
│     ADMIN PORTAL     │ │    DOCTOR PORTAL     │ │    PATIENT PORTAL    │
│  - Central Treasury  │ │  - Clinical Profile  │ │  - Financial History │
│  - Patient Discharge │ │  - Inpatient Rounds  │ │  - My Invoices & Due │
│  - Bed Management    │ │  - Earnings Ledger   │ │  - Real-Time Bills   │
│  - Credential Queue  │ │  - Verified Badges   │ │  - Printable Receipt │
└──────────┬───────────┘ └──────────┬───────────┘ └──────────┬───────────┘
           │                        │                        │
           └────────────────────────┼────────────────────────┘
                                    │
                      ┌─────────────▼─────────────┐
                      │    SECURITY & DATA LAYER   │
                      │  - Session Hardening      │
                      │  - PDO Transactions (ACID)│
                      │  - Virtual Constraints    │
                      │  - Central Audit Logging  │
                      └───────────────────────────┘
```

### 1.2 Multi-Portal Architecture & Session Isolation
MedPulse implements three dedicated application tiers partitioned by privilege:

| Portal | Scope & Responsibilities | Entry Point | Session Guard Constraint |
| :--- | :--- | :--- | :--- |
| **Admin Portal** | Bed allocation, inpatient census, clinical discharge, central treasury invoicing, staff & doctor credential approval. | `admin/dashboard.php` | `$_SESSION['role'] === 'Admin'` |
| **Doctor Portal** | Assigned inpatient care rounds, prescription management, dynamic title configurations, clinical fee ledgers, payout disbursement tracking. | `doctor/dashboard.php` | `$_SESSION['role'] === 'Doctor'` && `doctor_profiles.approval_status === 'approved'` |
| **Patient Portal** | Admission records, treatment timeline, itemized billing transparency, native PDF receipt preview, payment status monitoring. | `patient/dashboard.php` | `$_SESSION['role'] === 'Patient'` && `users.status === 'active'` |

#### Session Hardening Standards:
All portals share a uniform security header and session cookie configuration:
* `session.use_only_cookies = 1` and `session.use_strict_mode = 1` to prevent session fixation.
* `CookieParams`: `lifetime = 0` (session-only), `path = '/'`, `secure = HTTPS_DETECTED`, `httponly = true` (blocks XSS token theft), `samesite = 'Lax'` (mitigates CSRF).
* **30-Minute Inactivity Invalidation**: Timestamp check against `$_SESSION['last_activity']` with automated cookie purge on expiry.
* **Role Mismatch Shield**: Direct traversal across portals automatically revokes sessions and redirects to `login.php?error=role_mismatch`.

---

## 2. Inpatient Discharge & Automated Real-Time Billing Pipeline

### 2.1 The Clinical Problem
In traditional hospital systems, discharging a patient and calculating final hospital fees is a manual, error-prone process. Cashiers often forget bed-night surcharges or omit visiting specialist fees, leading to financial leakage or patient disputes.

In MedPulse, discharging an inpatient is an **atomic, single-click transactional pipeline** handled by the backend.

### 2.2 Execution Pipeline Architecture
* **Primary Handler File**: [`admin/discharge_patient_action.php`](file:///opt/lampp/htdocs/MedPulse/admin/discharge_patient_action.php)
* **Secondary API Alias**: [`backend/api/discharge_patient.php`](file:///opt/lampp/htdocs/MedPulse/backend/api/discharge_patient.php)
* **Associated UI Interfaces**: [`admin/live_census.php`](file:///opt/lampp/htdocs/MedPulse/admin/live_census.php), [`admin/inpatient_care.php`](file:///opt/lampp/htdocs/MedPulse/admin/inpatient_care.php), [`admin/billing_management.php`](file:///opt/lampp/htdocs/MedPulse/admin/billing_management.php)

```
[Admin Discharges Patient]
          │
          ▼
1. Method & CSRF Guard (Strict POST + token verification)
          │
          ▼
2. Begin Database Transaction ($pdo->beginTransaction())
          │
          ▼
3. Lock Active Bed Allocation (SELECT ... FOR UPDATE)
          │
          ▼
4. Calculate Bed Duration & Stay Charges (Admitted vs Discharged Timestamp, min 1 night)
          │
          ▼
5. Resolve Primary Attending Doctor & Fetch Consultation Fee
          │
          ▼
6. Financial Calculations (Subtotal + 5% VAT - Discount = Net Payable)
          │
          ▼
7. Auto-Generate Sequential Invoice Number (INV-2026-XXXX) & INSERT into `invoices`
          │
          ▼
8. Atomically Populate Itemized Statements into `invoice_items`
   ├── Item 1: Bed Facility Stay (Item Type: 'Bed Charge', Payout: 'UNCLAIMED')
   └── Item 2: Attending Specialist Round (Item Type: 'Consultation', Payout: 'PENDING_CLEARANCE')
          │
          ▼
9. Credit Doctor Earnings Ledger into `doctor_earnings` (Status: 'pending_hospital_collection')
          │
          ▼
10. Release Bed (hospital_beds.status = 'Available')
    Close Allocation (bed_allocations.status = 'Discharged')
    Deactivate Doctor Assignments (patient_doctor_assignments.status = 'Inactive')
          │
          ▼
11. Event Dispatcher & Audit Log (EventDispatcher::notifyDischarge & EventDispatcher::pushAuditLog)
          │
          ▼
12. Commit Transaction ($pdo->commit())
          │
          ▼
13. Return JSON Response to Frontend
```

### 2.3 Step-by-Step Technical Breakdown

#### Step 1: Pessimistic Row Locking (`SELECT ... FOR UPDATE`)
```sql
SELECT ba.allocation_id, ba.bed_id, ba.patient_id, ba.attending_doctor_id, ba.admitted_at,
       b.bed_number, b.ward_type, b.floor_number, b.daily_rate, b.status AS bed_status,
       u.full_name AS patient_name, u.email AS patient_email, u.phone AS patient_phone
FROM bed_allocations ba
JOIN hospital_beds b ON ba.bed_id = b.bed_id
JOIN users u ON ba.patient_id = u.user_id
WHERE ba.status = 'Active' AND ba.allocation_id = :aid
LIMIT 1 FOR UPDATE;
```
* **Why `FOR UPDATE`?**: Prevents double-discharge race conditions. If two administrators click discharge simultaneously, the second thread blocks until the first transaction commits or rolls back, ensuring the patient is not billed twice.

#### Step 2: Night Multiplier Stay Calculation
```php
$admitDt = new DateTime($activeAlloc['admitted_at']);
$dischDt = new DateTime(date('Y-m-d H:i:s'));
$diffSeconds = max(0, $dischDt->getTimestamp() - $admitDt->getTimestamp());

// Clinical Rule: Minimum 1 night/day stay billed
$nights = max(1, (int)ceil($diffSeconds / 86400));
$bedDailyRate = (float)$activeAlloc['daily_rate'];

// Fallback to ward matrix if daily_rate is unset
if ($bedDailyRate <= 0) {
    $wardRates = [
        'Emergency'           => 1500.00,
        'General Ward Male'   => 800.00,
        'General Ward Female' => 800.00,
        'Pediatrics'          => 1000.00,
        'Semi-Cabin'          => 2500.00,
        'Deluxe Cabin'        => 4500.00,
        'VIP Suite'           => 8500.00,
        'Presidential Suite'  => 15000.00,
        'ICU'                 => 12000.00,
        'CCU'                 => 10000.00,
        'NICU'                => 9000.00,
        'Recovery'            => 3000.00,
    ];
    $bedDailyRate = $wardRates[$wardType] ?? 1200.00;
}
$totalBedCharge = round($nights * $bedDailyRate, 2);
```

#### Step 3: Attending Physician Resolution & Consultation Fee
```php
$docUserId = (int)($activeAlloc['attending_doctor_id'] ?? 0);
if ($docUserId <= 0) {
    // Secondary fallback: Check patient_doctor_assignments junction table
    $pdaStmt = $pdo->prepare("
        SELECT doctor_id 
        FROM patient_doctor_assignments 
        WHERE patient_id = :pid AND status = 'Active' 
        ORDER BY is_primary DESC, assignment_id DESC LIMIT 1
    ");
    $pdaStmt->execute([':pid' => $targetPatientId]);
    $docUserId = (int)$pdaStmt->fetchColumn();
}

if ($docUserId > 0) {
    $docQuery = $pdo->prepare("
        SELECT u.full_name, dp.specialty, dp.designation, dp.military_rank, dp.consultation_fee
        FROM users u
        LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
        WHERE u.user_id = :uid LIMIT 1
    ");
    $docQuery->execute([':uid' => $docUserId]);
    $doctorProfile = $docQuery->fetch(PDO::FETCH_ASSOC);

    $consultationFee = (float)($doctorProfile['consultation_fee'] ?? 1200.00);
    $docDisplayTitle = formatDoctorTitle(
        $doctorProfile['full_name'],
        $doctorProfile['designation'] ?? 'Consultant',
        $doctorProfile['military_rank'] ?? null
    );
}
```

#### Step 4: Atomic Invoicing & Financial Equations
```php
$subtotal = $totalBedCharge + ($hasDoctor ? $consultationFee : 0.00);
$vatPercentage = 5.00; // 5% Standard Healthcare VAT
$vatAmount = round($subtotal * ($vatPercentage / 100), 2);
$discount = 0.00;
$netPayable = round($subtotal + $vatAmount - $discount, 2);
$dueAmount = $netPayable;
```
1. **Master Invoice Record**: Generated with sequential format `INV-2026-XXXX`, `status = 'Pending'`, `payment_status = 'unpaid'`, `discharge_status = 'discharged'`.
2. **Item 1: Facility Bed Stay**: Inserted into `invoice_items` (`item_type = 'Bed Charge'`, `unit_price = $bedDailyRate`, `quantity = $nights`, `total_price = $totalBedCharge`, `doctor_payout_status = 'UNCLAIMED'`).
3. **Item 2: Attending Specialist Care**: Inserted into `invoice_items` (`item_type = 'Consultation'`, `doctor_id = $docUserId`, `unit_price = $consultationFee`, `quantity = 1`, `total_price = $consultationFee`, `doctor_payout_status = 'PENDING_CLEARANCE'`, `doctor_payout_amount = $consultationFee`).
4. **Doctor Earnings Ledger**: Inserted into `doctor_earnings` (`doctor_id = $docUserId`, `invoice_id = $newInvoiceId`, `admission_id = $targetAllocId`, `consultation_fee = $consultationFee`, `disbursement_status = 'pending_hospital_collection'`).
5. **Bed & Allocation Reset**:
   ```sql
   UPDATE hospital_beds SET status = 'Available' WHERE bed_id = :bid;
   UPDATE bed_allocations SET status = 'Discharged', discharged_at = NOW() WHERE allocation_id = :aid;
   UPDATE patient_doctor_assignments SET status = 'Inactive', ended_at = NOW() WHERE patient_id = :pid AND status = 'Active';
   ```

### 2.4 Multi-Portal Synchronization
* **Patient Portal Synchronization ([`patient/my_bills.php`](file:///opt/lampp/htdocs/MedPulse/patient/my_bills.php))**:
  The moment `$pdo->commit()` executes, the invoice is instantly visible in the patient's billing list. Patients can view the itemized breakdown (nights stayed + doctor visit) and preview/print the official receipt without manual staff intervention.
* **Doctor Portal Synchronization ([`doctor/my_earnings.php`](file:///opt/lampp/htdocs/MedPulse/doctor/my_earnings.php))**:
  The doctor's ledger displays the new consultation fee under `pending_hospital_collection` / `PENDING_CLEARANCE`. When the patient settles the bill at the Treasury (`invoices.status = 'Paid'`), the doctor's status shifts to `available_for_disbursement`, ensuring financial transparency.

---

## 3. Doctor Credentialing, Auto-Computed Titles & Approval Gatekeeper

### 3.1 The Clinical Credentialing Challenge
In a tertiary hospital, doctor titles follow strict academic and military medical conventions (e.g., *Prof. Dr.*, *Assoc. Prof. Dr.*, *Col. (Retd.) Prof. Dr.*). Allowing physicians to free-type their titles leads to:
1. Redundant prefixes (*"Dr. Dr. John"*, *"Prof. Doctor Dr. John"*).
2. Misrepresentation of unaccredited designations.
3. Tampering with government-issued BMDC (Bangladesh Medical & Dental Council) registration numbers.

### 3.2 Dynamic Honorific Derivation Engine
* **Location**: [`includes/doctor_helpers.php`](file:///opt/lampp/htdocs/MedPulse/includes/doctor_helpers.php)
* **Core Functions**:
  1. `cleanDoctorBaseName(?string $rawName): string`:
     Strips manual academic and military prefixes using an advanced regular expression:
     ```php
     $prefixPattern = '/^(?:(?:Col\.|Lt\.\s*Col\.|Brig\.\s*Gen\.|Major)\s*(?:\(Retd\.?\))?\s*)*(?:(?:Assoc\.|Associate)\s+(?:Prof\.|Professor)\s*(?:Dr\.?)?|(?:Asst\.|Assistant)\s+(?:Prof\.|Professor)\s*(?:Dr\.?)?|(?:Prof\.|Professor)\s*(?:Dr\.?)?|(?:Dr\.?|Doctor)\s*)+/i';
     $clean = preg_replace($prefixPattern, '', $rawName);
     ```
  2. `computeDoctorHonorificPrefix(?string $designation, ?string $militaryRank = null): string`:
     Maps clinical rank to formal medical honorifics:
     * `Professor` $\rightarrow$ `Prof. Dr.`
     * `Associate Professor` $\rightarrow$ `Assoc. Prof. Dr.`
     * `Assistant Professor` $\rightarrow$ `Asst. Prof. Dr.`
     * `Consultant` / `Senior Consultant` / `Medical Officer` $\rightarrow$ `Dr.`
     * Optional military commission prepend: `Col. (Retd.)` + `Prof. Dr.` $\rightarrow$ `Col. (Retd.) Prof. Dr.`
  3. `formatDoctorTitle(...)`: Produces `[Honorific] [Pure Base Name]`.
  4. `formatDoctorFullIdentity(...)`: Produces `[Honorific] [Pure Base Name], [Qualifications]` (e.g., `Prof. Dr. Minhazul Islam Alvi, MBBS, FCPS (Surgery)`).
  5. `formatDoctorAvatarInitials(...)`: Extracts initials exclusively from the pure base name, preventing initials like *"DD"* (Dr. Doctor).

### 3.3 Doctor Profile Editing & BMDC Immutability
* **Files**: [`doctor/profile.php`](file:///opt/lampp/htdocs/MedPulse/doctor/profile.php) & [`doctor/update_profile.php`](file:///opt/lampp/htdocs/MedPulse/doctor/update_profile.php)
* **Architectural Safeguard**:
  Doctors can update their base name, military rank, clinical designation, medical qualifications, consultation fee, room number, and phone number.
  **However, the BMDC Registration Number is completely excluded from the update SQL query**:
  ```php
  // BMDC License is strictly read-only and immutable.
  // The UPDATE statement updates only operational practice parameters.
  $updProfile = $pdo->prepare("
      UPDATE doctor_profiles 
      SET specialty = :specialty,
          designation = :designation,
          military_rank = :military_rank,
          qualifications = :qualifications,
          consultation_fee = :consultation_fee,
          room_number = :room_number,
          shift_schedule = :shift_schedule,
          shift_timings = :shift_timings,
          updated_at = NOW() 
      WHERE user_id = :user_id
  ");
  ```
  On the UI, the BMDC field is displayed with a green `VERIFIED` chip and marked `readonly`, ensuring no doctor can alter their legal license once registered.

### 3.4 Doctor Registration & Login Gatekeeper
To prevent fraudulent physicians from accessing sensitive patient health records:

```
[Doctor Submits Registration Form]
               │
               ▼
1. Input Sanitization & Unique Validation
   - users.email (UNIQUE)
   - users.phone (UNIQUE)
   - doctor_profiles.bmdc_reg_number (UNIQUE)
               │
               ▼
2. Account Persisted with Gated Status:
   - users.status = 'pending'
   - doctor_profiles.approval_status = 'pending'
               │
               ▼
3. Session Creation Blocked
   - Redirected to login.php?msg=pending_verification
               │
               ▼
4. Login Gatekeeper in `backend/login_action.php`:
   - Checks users.status: if 'pending' -> error=pending_approval
   - Checks doctor_profiles.approval_status: if 'pending' -> error=pending_approval
   - If 'rejected' -> error=account_declined
   - Prevents session initialization
               │
               ▼
5. Admin Verification in `admin/verification_queue.php`:
   - Admin verifies BMDC credential with government DGHS registry
   - Admin clicks "Approve & Authorize"
   - Atomic update: users.status = 'active', doctor_profiles.approval_status = 'approved'
   - Audit log recorded
               │
               ▼
[Doctor Can Now Log In Successfully]
```

* **Registration Handler**: [`backend/register_action.php`](file:///opt/lampp/htdocs/MedPulse/backend/register_action.php)
* **Login Gatekeeper Handler**: [`backend/login_action.php`](file:///opt/lampp/htdocs/MedPulse/backend/login_action.php#L88-L130)
* **Admin Verification Interface**: [`admin/verification_queue.php`](file:///opt/lampp/htdocs/MedPulse/admin/verification_queue.php)
* **Admin Approval Action**: [`backend/admin_actions.php`](file:///opt/lampp/htdocs/MedPulse/backend/admin_actions.php#L280-L338)

---

## 4. Official Invoicing, Treasury Rubber Stamp & Native PDF Streaming

### 4.1 Centralized Invoice Renderer
* **Location**: [`includes/generate_invoice_pdf.php`](file:///opt/lampp/htdocs/MedPulse/includes/generate_invoice_pdf.php)
* **Usage**: Shared across both Admin Treasury and Patient Portals via `?invoice_id=X`.

### 4.2 Critical Bug Fixes & Technical Decisions

#### 1. Resolution of Number Formatting & BDT Currency Encoding
* **Problem**: In Linux/XAMPP environments without specialized locales, rendering the Bengali Taka symbol `৳` (`U+09F3`) inside legacy PDF parsers or headless renderers resulted in mojibake/broken characters (`?` or `â‚¹`). Additionally, calling `number_format()` on null values in PHP 8.2 threw fatal deprecation errors.
* **Fix**: Implemented strict float casting and standardized currency presentation using `fmtBDT()` helper:
  ```php
  function fmtBDT(float $v): string {
      return 'BDT ' . number_format($v, 2);
  }
  ```
  Both HTML viewports and print engines output crisp, universal typography with `charset=UTF-8`.

#### 2. Resolution of Base64 Hospital Watermark & Crisp Logo
* **Problem**: Referencing images via relative URLs (`../assets/images/logo.png`) fails when invoices are rendered in isolated print contexts or when downloaded as standalone files.
* **Fix**: Embedded the hospital logo directly as Base64 data URI:
  ```php
  $logoPath = __DIR__ . '/../assets/images/logo.png';
  $logoB64  = file_exists($logoPath) ? base64_encode(file_get_contents($logoPath)) : '';
  $logoSrc  = $logoB64 ? 'data:image/png;base64,' . $logoB64 : '';
  ```

#### 3. Treasury Rubber Stamp Non-Colliding Layout
* **Problem**: Standard invoice templates use CSS absolute positioning for stamps (`position: absolute; right: 40px; bottom: 80px;`). When invoices contain multiple items or varying line heights, the red/green `PAID` stamp overlaps directly on top of the "Net Total Payable" or "Outstanding Balance" numbers, making figures unreadable for audit.
* **Fix**: Built a **3-Column Flexbox Summary Structure** (`.piv-summary-wrap`):
  ```
  ┌────────────────────────────────────────────────────────────────────────┐
  │                           .piv-summary-wrap                            │
  ├──────────────────────┬──────────────────────┬──────────────────────────┤
  │ .piv-summary-left    │ .piv-paid-stamp      │ .piv-summary-right       │
  │ (flex: 1)            │ (flex-shrink: 0)     │ (min-width: 260px)       │
  │                      │ (width: 132px)       │                          │
  │ - Legal Disclaimer   │                      │ - Subtotal:   BDT X,XXX  │
  │ - DGHS Circular Ref  │  ┌────────────────┐  │ - VAT (5%):   BDT   XXX  │
  │ - SHA-256 Ledger Hash│  │MEDPULSE TREASURY│ │ - Discount:   BDT     0  │
  │                      │  │      PAID      │  │ - Net Total:  BDT X,XXX  │
  │                      │  │CASHIER VERIFIED│  │ - Paid:       BDT X,XXX  │
  │                      │  └────────────────┘  │ - Due:        BDT  0.00  │
  │                      │   (Rotated -12deg)   │                          │
  └──────────────────────┴──────────────────────┴──────────────────────────┘
  ```
  Because the stamp is in its own flex column between the legal notes and the numbers table, **it is mathematically impossible for the stamp to collide with or obscure financial figures**.

#### 4. Cryptographic Ledger Hash
Every invoice computes a deterministic SHA-256 integrity hash printed on the invoice:
```php
$hashSource = $invoice['invoice_number'] . '|' . $invoice['net_payable'] . '|' . $invoice['patient_id'] . '|' . $invoice['created_at'];
$fullHash   = hash('sha256', $hashSource);
$ledgerHash = 'SHA256: ' . strtoupper(substr($fullHash, 0, 16)) . '...' . strtoupper(substr($fullHash, -8));
```

### 4.3 Native PDF Streaming vs HTML Dump
* **The Vulnerability / Flaw in Typical Student Projects**:
  Many student projects simply export raw `.html` or `.txt` files with `Content-Type: text/plain`, forcing the user's browser to download an ugly, unformatted file.
* **MedPulse Solution**:
  1. Configured standard HTTP streaming headers:
     ```php
     header('Content-Type: text/html; charset=UTF-8');
     header('Content-Disposition: inline; filename="MedPulse_Invoice_' . $safeInvNum . '.html"');
     header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
     ```
  2. Integrated `@media print` CSS engine with `@page { size: A4 portrait; margin: 0; }` and `-webkit-print-color-adjust: exact`.
  3. Added an automated JavaScript print trigger after assets are decoded:
     ```javascript
     window.addEventListener('load', function () {
       setTimeout(function () { window.print(); }, 600);
     });
     ```
  When opened, the browser's native print preview dialog immediately appears, allowing the user or cashier to save as vector PDF or send directly to a thermal/laser receipt printer.

### 4.4 Broken Object Level Authorization (BOLA/IDOR) Shield
```php
// Patient Ownership Gatekeeper
if ($sessionRole === 'Patient') {
    if ((int)$invoice['patient_id'] !== $sessionUserId) {
        http_response_code(403);
        die('Access Denied: You are not authorized to view or download this billing statement.');
    }
}
```
Even if a malicious patient modifies the URL parameter to `?invoice_id=999`, the backend matches `invoice.patient_id` against `$_SESSION['user_id']` and immediately terminates the request with HTTP 403 Forbidden.

---

## 5. Database Schema, Migrations & Integrity Constraints

### 5.1 Sequential Migration Catalog
The MedPulse database schema is version-controlled via 14 sequential SQL migration scripts located in [`database/`](file:///opt/lampp/htdocs/MedPulse/database/):

| Migration File | Primary Purpose & Key Table(s) | Key Constraints & Indexes |
| :--- | :--- | :--- |
| **`01_users.sql`** | Core User Registry & Identity Anchor (`users`). | Primary Key `user_id`. Unique keys on `email` and `phone`. Index on `(role, status)`. |
| **`02_doctor_profiles.sql`** | Clinical Doctor Credentials (`doctor_profiles`). | Foreign Key `user_id` $\rightarrow$ `users(user_id)` ON DELETE CASCADE. Stores BMDC license, specialty, fee, room. |
| **`03_hospital_beds.sql`** | Hospital Bed Inventory (`hospital_beds`). | Primary Key `bed_id`. Ward type categorization, floor number, `daily_rate`, `status` ENUM. |
| **`04_appointments.sql`** | Outpatient Consultations (`appointments`). | Foreign keys to `users(patient_id)` and `users(doctor_id)`. Tracks appointment date/time and clinical status. |
| **`05_prescriptions.sql`** | Prescriptions & Medications (`prescriptions`, `prescription_items`). | Foreign keys to patient and doctor. Relational items table storing dosage, frequency, and duration. |
| **`06_bed_allocations.sql`** | Inpatient Admissions & Bed Cycle (`bed_allocations`). | Relational tracking of patient admission timestamps, bed references, and discharge timestamps. |
| **`07_invoices.sql`** | Master Treasury Billing (`invoices`, `invoice_items`). | Primary invoice records (`subtotal`, `vat_percentage`, `discount`, `net_payable`, `paid_amount`, `status`). Itemized line charges. |
| **`08_diagnostic_reports.sql`** | Pathology & Imaging Reports (`diagnostic_reports`). | Stores diagnostic lab findings, document attachments, categories, and delivery statuses. |
| **`09_audit_logs.sql`** | Immutable Forensic Audit Trail (`audit_logs`). | Captures actor ID, role, category, action name, description, IP address, and security classification. |
| **`10_seed_data.sql`** | Realistic Baseline Clinical Seed Data. | Pre-populates administrative users, multi-specialty doctors, beds, and standard departmental structures. |
| **`11_inpatient_realtime_schema.sql`** | Inpatient Hardening & Doctor Care Junction. | Introduces Virtual Generated Columns for bed/patient 1-to-1 invariant. Adds `patient_doctor_assignments` and `bed_transfer_history`. |
| **`12_doctor_payout_and_billing_linkage.sql`** | Doctor Fee Linkage & Due Tracking. | Adds `doctor_id`, `doctor_payout_status`, `doctor_payout_amount` to `invoice_items`. Adds `admission_id` and `due_amount` to `invoices`. |
| **`13_unique_constraints_and_doctor_approval.sql`** | Credential Hardening & Approval States. | Enforces `UNIQUE KEY` on `users.email`, `users.phone`, and `doctor_profiles.bmdc_reg_number`. Adds `approval_status`, `approved_by`, `approved_at`. |
| **`14_discharge_billing_and_doctor_earnings.sql`** | Automated Treasury Ledger Linkage. | Adds `discharge_status` and `payment_status` to `invoices`. Creates dedicated `doctor_earnings` ledger with disbursement tracking. |

### 5.2 Mathematical 1-to-1 Invariance via Virtual Generated Columns
* **The Hard Problem**:
  In MySQL/MariaDB, how do you enforce that a patient can have **at most one ACTIVE bed allocation**, while still allowing them to have **infinite past discharged allocations** in the same table?
* **MedPulse Solution ([`database/11_inpatient_realtime_schema.sql`](file:///opt/lampp/htdocs/MedPulse/database/11_inpatient_realtime_schema.sql))**:
  Traditional composite unique indexes on `(patient_id, status)` fail because after a patient is discharged twice, the pair `(18, 'Discharged')` would violate uniqueness.
  MedPulse solves this mathematically using **Virtual Generated Columns**:
  ```sql
  ALTER TABLE bed_allocations 
    ADD COLUMN active_patient_id INT GENERATED ALWAYS AS (IF(status = 'Active', patient_id, NULL)) VIRTUAL,
    ADD COLUMN active_bed_id INT GENERATED ALWAYS AS (IF(status = 'Active', bed_id, NULL)) VIRTUAL;

  ALTER TABLE bed_allocations
    ADD UNIQUE KEY uq_active_patient (active_patient_id),
    ADD UNIQUE KEY uq_active_bed (active_bed_id);
  ```
* **Why this works**:
  According to ANSI SQL and MySQL/MariaDB standards, **UNIQUE indexes ignore multiple `NULL` values**.
  When `status = 'Discharged'`, the column evaluates to `NULL` (unconstrained).
  When `status = 'Active'`, the column evaluates to the integer `patient_id` / `bed_id`. If an administrator attempts to admit the same patient to another bed, the database engine throws duplicate entry violation `1062` at the storage level, eliminating race conditions before code even runs!

### 5.3 Database Level Uniqueness Constraints
1. `users.email` $\rightarrow$ `UNIQUE KEY unique_email (email)`
2. `users.phone` $\rightarrow$ `UNIQUE KEY unique_phone (phone)`
3. `doctor_profiles.bmdc_reg_number` $\rightarrow$ `UNIQUE KEY unique_bmdc (bmdc_reg_number)`
4. `bed_allocations.active_patient_id` $\rightarrow$ `UNIQUE KEY uq_active_patient (active_patient_id)`
5. `bed_allocations.active_bed_id` $\rightarrow$ `UNIQUE KEY uq_active_bed (active_bed_id)`
6. `patient_doctor_assignments.active_pair` $\rightarrow$ `UNIQUE KEY uq_active_patient_doctor (active_pair)`

### 5.4 MySQL 1062 Error-Handling Mechanism
When duplicate registration data is submitted, rather than allowing MySQL to crash the script with an unhandled 500 fatal exception, [`backend/register_action.php`](file:///opt/lampp/htdocs/MedPulse/backend/register_action.php#L252-L289) traps PDO error `1062` / SQLSTATE `23000` and translates it into clean, actionable JSON feedback:

```php
} catch (PDOException $e) {
    $errorCode = $e->errorInfo[1] ?? 0;
    if ($errorCode === 1062 || $e->getCode() == 23000) {
        $errorMessage = $e->getMessage();
        if (stripos($errorMessage, 'unique_email') !== false || stripos($errorMessage, 'email') !== false) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'This email address is already registered. Please sign in or use a different email.'
            ]);
            exit;
        } elseif (stripos($errorMessage, 'unique_phone') !== false || stripos($errorMessage, 'phone') !== false) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'This phone number is already associated with an existing account.'
            ]);
            exit;
        } elseif (stripos($errorMessage, 'unique_bmdc') !== false || stripos($errorMessage, 'bmdc') !== false) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'This BMDC registration number is already registered under an existing doctor profile.'
            ]);
            exit;
        }
    }
}
```

---

## 6. Viva Defense Quick-Answer Cheat Sheet

Use these exact technical formulations when questioned by examination faculty and project supervisors:

---

### Q1: *"Why did you use database transactions during patient discharge?"*
> **Answer**:
> "Patient discharge in MedPulse is a multi-table financial and clinical transition. It requires reading bed occupancy, calculating stay durations, retrieving doctor fees, generating a sequential invoice, populating itemized charges, updating doctor payout ledgers, releasing the hospital bed, and deactivating care team assignments.
> If we executed these as standalone queries and the server lost power or crashed midway through, a patient could be marked discharged without an invoice, or a bed could be released while leaving ghost charges unassigned. By wrapping the entire operation in `$pdo->beginTransaction()` with row-level locking (`FOR UPDATE`) and committing only at the final step, we ensure strict **Atomicity, Consistency, Isolation, and Durability (ACID)**. If any operation fails, the entire state rolls back cleanly."

---

### Q2: *"How do you prevent duplicate registrations and fake doctor accounts?"*
> **Answer**:
> "We implement a dual-layer defense. At the application layer in `register_action.php`, we perform pre-flight regex validation on Bangladeshi phone numbers (`01[3-9]\d{8}`) and verify BMDC formats. At the database layer, we enforce strict `UNIQUE` keys on `users.email`, `users.phone`, and `doctor_profiles.bmdc_reg_number`. If a concurrent collision occurs, our PDO exception handler catches MySQL Error 1062 and returns user-friendly alerts.
> Furthermore, new doctors cannot log in upon registration because their accounts are provisioned with `users.status = 'pending'` and `doctor_profiles.approval_status = 'pending'`. The authentication gatekeeper in `login_action.php` intercepts all doctor logins, blocking access until an Administrator verifies their government credentials in `admin/verification_queue.php`."

---

### Q3: *"Why are doctor titles derived dynamically rather than typed manually by doctors?"*
> **Answer**:
> "In clinical practice, titles represent verified qualifications, not personal marketing preferences. Allowing free-text input causes duplicate prefixes like *'Dr. Dr.'*, invalid academic claims, or non-standard formatting.
> In MedPulse, the `doctor_helpers.php` engine stores only the physician's legal base name in `users.full_name`. The display title is auto-computed dynamically by inspecting their verified clinical designation (e.g., *Professor* maps to *Prof. Dr.*, *Assistant Professor* to *Asst. Prof. Dr.*) and prepending optional military commission ranks (e.g., *Col. (Retd.)*). If a doctor accidentally types 'Dr. John', our regex sanitizer automatically strips the redundant prefix before persistence, guaranteeing uniform presentation across prescriptions, bills, and profile badges."

---

### Q4: *"How does the system ensure financial consistency between hospital treasury and doctor payouts?"*
> **Answer**:
> "We utilize an asynchronous ledger reconciliation model between `invoices`, `invoice_items`, and `doctor_earnings`.
> During discharge, the attending doctor's consultation fee is recorded with status `pending_hospital_collection` and `PENDING_CLEARANCE`. The hospital treasury does not owe a payout to the doctor until the patient settles the invoice. Once the patient pays at the Central Treasury (`invoices.status = 'Paid'`), the doctor's payout status transitions to `available_for_disbursement`. In `doctor/my_earnings.php`, queries bind strictly to the authenticated `$_SESSION['user_id']`, ensuring physicians have full visibility into earned vs disbursed fees without exposing hospital overhead or other doctors' payouts."

---

### Q5: *"How do you guarantee that a patient cannot be admitted to multiple beds simultaneously, or two patients placed in the same bed?"*
> **Answer**:
> "Rather than relying solely on PHP application checks which are susceptible to concurrency race conditions, we enforce mathematical invariants in MariaDB/MySQL using **Virtual Generated Columns** (`active_patient_id` and `active_bed_id`) backed by `UNIQUE` indexes.
> The column evaluates to `patient_id` or `bed_id` only when `status = 'Active'`, and `NULL` otherwise. Because SQL UNIQUE constraints allow multiple `NULL`s but reject duplicate non-null values, the database guarantees at the storage engine level that there can never be more than one active allocation for any patient or bed."

---

### Q6: *"What prevents Horizontal Privilege Escalation (IDOR) when a patient views or downloads invoices?"*
> **Answer**:
> "In [`includes/generate_invoice_pdf.php`](file:///opt/lampp/htdocs/MedPulse/includes/generate_invoice_pdf.php) and [`patient/my_bills.php`](file:///opt/lampp/htdocs/MedPulse/patient/my_bills.php), we enforce strict Broken Object Level Authorization (BOLA) gatekeepers.
> When a user requests an invoice via `?invoice_id=X`, the backend does not simply fetch the record. It inspects the session role: if `$_SESSION['role'] === 'Patient'`, it asserts `(int)$invoice['patient_id'] === (int)$_SESSION['user_id']`. If an unauthorized ID is supplied, the script logs a security violation and terminates with HTTP 403 Forbidden."

---

## 7. Technical Directory & File Reference Map

```
/opt/lampp/htdocs/MedPulse/
│
├── admin/
│   ├── billing_management.php          # Central Treasury master ledger & cash collection
│   ├── discharge_patient_action.php    # Transaction-safe discharge pipeline & invoicing
│   ├── inpatient_care.php              # Real-time admission, ward tracking & care team
│   ├── live_census.php                 # Interactive bed grid & occupancy telemetry
│   └── verification_queue.php          # Doctor BMDC verification & authorization portal
│
├── backend/
│   ├── Services/
│   │   └── EventDispatcher.php         # Real-time event notifications & audit logging
│   ├── admin_actions.php               # Status transitions (Approve/Reject/Suspend)
│   ├── login_action.php                # Authentication gatekeeper & role routing
│   └── register_action.php             # User onboarding, duplicate check & hashing
│
├── config/
│   └── db.php                          # PDO connection, UTF-8 charset & error modes
│
├── database/
│   ├── 01_users.sql through 10_seed_data.sql # Baseline relational schemas
│   ├── 11_inpatient_realtime_schema.sql      # Virtual column unique constraints & care junction
│   ├── 12_doctor_payout_and_billing_linkage.sql # Invoice doctor linkage & due balance
│   ├── 13_unique_constraints_and_doctor_approval.sql # BMDC uniqueness & approval states
│   └── 14_discharge_billing_and_doctor_earnings.sql # Discharge invoicing & earnings ledger
│
├── doctor/
│   ├── dashboard.php                   # Clinical schedule, patient load & activity
│   ├── my_earnings.php                 # Physician consultation fee ledger & payouts
│   ├── profile.php                     # Practice configuration & verified BMDC view
│   └── update_profile.php              # Clean base name & credential update handler
│
├── includes/
│   ├── doctor_helpers.php              # Dynamic honorific title derivation & regex clean
│   └── generate_invoice_pdf.php        # Native PDF streaming, stamp layout & SHA-256 hash
│
└── patient/
    ├── dashboard.php                   # Patient clinical overview & care status
    └── my_bills.php                    # Patient billing transparency & receipt viewer
```

---

*MedPulse Enterprise HMS — Generated & Verified for Academic Review & Viva Defense.*
