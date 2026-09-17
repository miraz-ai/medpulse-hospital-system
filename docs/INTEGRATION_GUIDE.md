# MedPulse Clinical Telemetry & Inpatient Care Integration Guide
**Author:** Principal Full-Stack Software Engineer & Database Architect  
**Audience:** Doctor Dashboard Engineering Team & Patient Dashboard Engineering Team  
**Scope:** Complete Real-Time Event Infrastructure, WebSocket/SSE Contracts, Database Schemas, and REST/SQL Queries

---

## 1. Architectural Overview & System Invariants

The MedPulse Inpatient & Care Team infrastructure is designed with **mathematical data integrity** enforced at the MySQL engine layer and an **event-driven real-time dispatch bus**.

### Core Guarantees:
1. **Strict 1-to-1 Patient-to-Bed Invariant**:
   - A patient can occupy **at most one** bed at any time.
   - A hospital bed can hold **at most one** patient at any time.
   - Enforced by virtual generated columns `active_patient_id` and `active_bed_id` with `UNIQUE` constraints in `bed_allocations`. Race conditions will automatically trigger SQL error `1062 (Duplicate entry)` instead of corrupting bed telemetry.
2. **Many-to-Many Care Teams**:
   - A patient can have multiple attending doctors concurrently via `patient_doctor_assignments`.
   - Admins and chief physicians can assign or remove doctors dynamically.
   - Exactly one doctor can be designated as the `is_primary` physician.
3. **Atomic Relocations**:
   - Moving a patient between beds executes inside a single database transaction with deterministic ascending row locks (`SELECT ... FOR UPDATE`), immediately releasing the source bed and acquiring the destination bed with zero window of vulnerability.
4. **Automatic Discharge Telemetry**:
   - Discharging a patient marks their allocation `Discharged`, releases the bed to `Available`, marks care team assignments `Inactive`, and broadcasts immediate real-time termination alerts.

---

## 2. Database Schema Reference

### `bed_allocations` (Active Admissions & Constraints)
```sql
CREATE TABLE bed_allocations (
  allocation_id INT AUTO_INCREMENT PRIMARY KEY,
  bed_id INT NOT NULL,
  patient_id INT NOT NULL,
  attending_doctor_id INT NULL,
  admitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  discharged_at DATETIME NULL,
  status ENUM('Active','Discharged','Transferred') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Virtual Generated Columns enforcing 1-to-1 active uniqueness:
  active_patient_id INT GENERATED ALWAYS AS (IF(status = 'Active', patient_id, NULL)) VIRTUAL,
  active_bed_id INT GENERATED ALWAYS AS (IF(status = 'Active', bed_id, NULL)) VIRTUAL,
  UNIQUE KEY uq_active_patient (active_patient_id),
  UNIQUE KEY uq_active_bed (active_bed_id),
  CONSTRAINT fk_bed_alloc_bed FOREIGN KEY (bed_id) REFERENCES hospital_beds (bed_id),
  CONSTRAINT fk_bed_alloc_patient FOREIGN KEY (patient_id) REFERENCES users (user_id)
);
```

### `patient_doctor_assignments` (Many-to-Many Junction Table)
```sql
CREATE TABLE patient_doctor_assignments (
  assignment_id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  doctor_id INT NOT NULL,
  assigned_by INT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  notes VARCHAR(255) NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  active_pair VARCHAR(64) GENERATED ALWAYS AS (IF(status = 'Active', CONCAT(patient_id, ':', doctor_id), NULL)) VIRTUAL,
  UNIQUE KEY uq_active_patient_doctor (active_pair),
  CONSTRAINT fk_pda_patient FOREIGN KEY (patient_id) REFERENCES users (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_pda_doctor FOREIGN KEY (doctor_id) REFERENCES users (user_id) ON DELETE CASCADE
);
```

### `bed_transfer_history` (Audit Log of Relocations)
```sql
CREATE TABLE bed_transfer_history (
  transfer_id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  from_bed_id INT NOT NULL,
  to_bed_id INT NOT NULL,
  transferred_by INT NOT NULL,
  reason TEXT NULL,
  transferred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bth_patient FOREIGN KEY (patient_id) REFERENCES users (user_id),
  CONSTRAINT fk_bth_from_bed FOREIGN KEY (from_bed_id) REFERENCES hospital_beds (bed_id),
  CONSTRAINT fk_bth_to_bed FOREIGN KEY (to_bed_id) REFERENCES hospital_beds (bed_id)
);
```

### `notifications` (Universal Notification & Dispatch Queue)
```sql
CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipient_id INT NOT NULL,
  recipient_type ENUM('DOCTOR', 'PATIENT', 'ADMIN') NOT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  event_type VARCHAR(64) NOT NULL, -- PATIENT_BED_MOVED, PATIENT_DISCHARGED, DOCTOR_ASSIGNED, BED_ASSIGNED
  metadata LONGTEXT NOT NULL,       -- Strictly typed JSON payload
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_recipient (recipient_id, recipient_type, is_read)
);
```

---

## 3. Real-Time Event Bus & Channels

The platform provides two seamless options for real-time telemetry:
1. **Server-Sent Events (SSE)** — Zero-dependency, runs directly on LAMPP/Apache out of the box.
2. **WebSocket Server** — Available at `ws://localhost:8080` (Node.js daemon in `backend/websocket_server.js`).

### Channel Addressing Convention
| Target Audience | Channel Identifier | Description |
| :--- | :--- | :--- |
| Specific Doctor | `doctor:{doctorId}` | E.g. `doctor:20` for Dr. Mikasa |
| Specific Patient | `patient:{patientId}` | E.g. `patient:18` for Robert Downey Jr. |
| Hospital Admin Console | `admin:all` | Global telemetry broadcast |

---

## 4. Standard Event Schemas & Typed Payloads

### Event 1: `PATIENT_BED_MOVED`
Dispatched whenever an admin transfers a patient to a new bed.  
**Emitted to:**
- `patient:{patient_id}`
- Each `doctor:{doctor_id}` actively assigned to that patient
- `admin:all`

#### JSON Payload:
```json
{
  "event": "PATIENT_BED_MOVED",
  "channel": "doctor:20",
  "recipient_id": 20,
  "recipient_type": "DOCTOR",
  "title": "Patient Bed Moved: Robert Downey Jr.",
  "message": "Your patient Robert Downey Jr. has been relocated from Bed EMG-101 (Emergency) to Bed ICU-501 (ICU). Active rounds list updated.",
  "timestamp": "2026-09-17T16:55:51Z",
  "data": {
    "patient_id": 18,
    "patient_name": "Robert Downey Jr.",
    "old_bed": {
      "bed_id": 1,
      "bed_number": "EMG-101",
      "ward_type": "Emergency",
      "floor_number": 1
    },
    "new_bed": {
      "bed_id": 381,
      "bed_number": "ICU-501",
      "ward_type": "ICU",
      "floor_number": 5
    },
    "room_number": "Floor 5 - ICU (Bed ICU-501)",
    "reason": "Escalation to Intensive Care for continuous hemodynamic monitoring",
    "transferred_by": "Dr. Afzal (Clinical Admin)",
    "timestamp": "2026-09-17 16:55:51"
  }
}
```

#### Frontend Dashboard Reaction:
- **Doctor Dashboard:** Update the patient's room badge in your **Daily Rounds** view from `EMG-101` to `ICU-501`. Trigger a subtle audio ping or toast notification.
- **Patient Dashboard:** Update the **Current Ward & Bed** banner card in `patient/dashboard.php` so the patient immediately sees their new room assignment and daily rate.

---

### Event 2: `PATIENT_DISCHARGED`
Dispatched when a patient's inpatient stay is formally concluded and their bed released.  
**Emitted to:**
- `patient:{patient_id}`
- Each `doctor:{doctor_id}` assigned to the patient
- `admin:all`

#### JSON Payload:
```json
{
  "event": "PATIENT_DISCHARGED",
  "channel": "doctor:20",
  "recipient_id": 20,
  "recipient_type": "DOCTOR",
  "title": "Patient Discharged: Robert Downey Jr.",
  "message": "Patient Robert Downey Jr. has been discharged from Bed ICU-501. Inpatient care completed.",
  "timestamp": "2026-09-17T17:00:00Z",
  "data": {
    "patient_id": 18,
    "patient_name": "Robert Downey Jr.",
    "released_bed": {
      "bed_id": 381,
      "bed_number": "ICU-501",
      "ward_type": "ICU",
      "floor_number": 5
    },
    "discharge_summary": "Hemodynamically stable. Discharge approved with 5-day oral antibiotic course.",
    "discharged_at": "2026-09-17 17:00:00",
    "discharged_by": "Dr. Afzal (Admin)"
  }
}
```

#### Frontend Dashboard Reaction:
- **Doctor Dashboard:** Remove patient from the active Inpatient Rounds table and move them to Discharged Patients list.
- **Patient Dashboard:** Transition dashboard view from "Admitted Inpatient" mode to "Post-Discharge Outpatient" mode; display discharge summary and invoice review button.

---

### Event 3: `DOCTOR_ASSIGNED`
Dispatched when clinical administration assigns or updates the attending care team.  
**Emitted to:**
- `patient:{patient_id}`
- Each newly assigned `doctor:{doctor_id}`

#### JSON Payload:
```json
{
  "event": "DOCTOR_ASSIGNED",
  "channel": "doctor:21",
  "recipient_id": 21,
  "recipient_type": "DOCTOR",
  "title": "New Inpatient Assigned: Robert Downey Jr.",
  "message": "You have been assigned to inpatient care for Robert Downey Jr.",
  "timestamp": "2026-09-17T17:05:00Z",
  "data": {
    "patient_id": 18,
    "patient_name": "Robert Downey Jr.",
    "action": "ASSIGNED",
    "timestamp": "2026-09-17 17:05:00"
  }
}
```

---

## 5. Client Integration Code Snippets

### A. Frontend SSE Connection (Recommended for Web)
```javascript
// Example for Doctor Dashboard (e.g. Doctor #20)
const doctorId = 20;
const sse = new EventSource(`/MedPulse/backend/api/realtime_stream.php?channel=doctor:${doctorId}`);

// 1. Connection established
sse.addEventListener('CONNECTED', (e) => {
  console.log('[SSE] Connected to MedPulse Telemetry Stream:', JSON.parse(e.data));
});

// 2. Bed Moved Event
sse.addEventListener('PATIENT_BED_MOVED', (e) => {
  const event = JSON.parse(e.data);
  console.log('[BED_MOVED]', event.payload);
  
  // Example UI action:
  showNotificationToast(`Patient ${event.payload.patient_name} relocated to ${event.payload.new_bed.bed_number}`);
  updatePatientBedInRoundsTable(event.payload.patient_id, event.payload.new_bed);
});

// 3. Discharge Event
sse.addEventListener('PATIENT_DISCHARGED', (e) => {
  const event = JSON.parse(e.data);
  console.log('[DISCHARGE]', event.payload);
  removePatientFromActiveRounds(event.payload.patient_id);
});

// Auto-reconnect is handled natively by the browser's EventSource implementation!
```

### B. Frontend WebSocket Connection (If Using WebSocket Daemon)
```javascript
const ws = new WebSocket('ws://localhost:8080?channel=doctor:20');

ws.onopen = () => {
  console.log('[WS] Connected to MedPulse WebSocket');
};

ws.onmessage = (event) => {
  const msg = JSON.parse(event.data);
  if (msg.event === 'PATIENT_BED_MOVED') {
    handleBedRelocation(msg.payload);
  } else if (msg.event === 'PATIENT_DISCHARGED') {
    handleDischarge(msg.payload);
  }
};
```

---

## 6. Ready-to-Use SQL Queries for Teammates

### For Doctor Dashboard (`doctor/dashboard.php` & `doctor/rounds.php`):

#### Query 1: Get all active inpatients currently assigned to a doctor
```sql
SELECT 
    u.user_id AS patient_id,
    u.full_name AS patient_name,
    u.gender,
    u.age,
    u.blood_group,
    u.phone AS patient_phone,
    b.bed_id,
    b.bed_number,
    b.ward_type,
    b.floor_number,
    ba.admitted_at,
    pda.is_primary,
    pda.notes AS care_notes
FROM patient_doctor_assignments pda
JOIN users u ON pda.patient_id = u.user_id
JOIN bed_allocations ba ON u.user_id = ba.patient_id AND ba.status = 'Active'
JOIN hospital_beds b ON ba.bed_id = b.bed_id
WHERE pda.doctor_id = :doctor_user_id 
  AND pda.status = 'Active'
ORDER BY pda.is_primary DESC, b.floor_number ASC, b.bed_number ASC;
```

#### Query 2: Get unread notifications for a doctor
```sql
SELECT id, title, message, event_type, metadata, created_at
FROM notifications
WHERE recipient_id = :doctor_user_id 
  AND recipient_type = 'DOCTOR'
  AND is_read = 0
ORDER BY created_at DESC;
```

---

### For Patient Dashboard (`patient/dashboard.php`):

#### Query 1: Get patient's current active bed & admission details
```sql
SELECT 
    ba.allocation_id,
    ba.admitted_at,
    b.bed_id,
    b.bed_number,
    b.ward_type,
    b.floor_number,
    b.daily_rate
FROM bed_allocations ba
JOIN hospital_beds b ON ba.bed_id = b.bed_id
WHERE ba.patient_id = :patient_user_id 
  AND ba.status = 'Active'
LIMIT 1;
```

#### Query 2: Get all doctors currently on the patient's care team
```sql
SELECT 
    doc.user_id AS doctor_id,
    doc.full_name AS doctor_name,
    doc.email AS doctor_email,
    dp.specialty,
    dp.room_number,
    pda.is_primary,
    pda.assigned_at
FROM patient_doctor_assignments pda
JOIN users doc ON pda.doctor_id = doc.user_id
LEFT JOIN doctor_profiles dp ON doc.user_id = dp.user_id
WHERE pda.patient_id = :patient_user_id 
  AND pda.status = 'Active'
ORDER BY pda.is_primary DESC, doc.full_name ASC;
```

#### Query 3: Get patient's bed transfer history
```sql
SELECT 
    bth.transfer_id,
    b1.bed_number AS from_bed,
    b1.ward_type AS from_ward,
    b2.bed_number AS to_bed,
    b2.ward_type AS to_ward,
    bth.reason,
    bth.transferred_at,
    admin.full_name AS transferred_by_name
FROM bed_transfer_history bth
JOIN hospital_beds b1 ON bth.from_bed_id = b1.bed_id
JOIN hospital_beds b2 ON bth.to_bed_id = b2.bed_id
JOIN users admin ON bth.transferred_by = admin.user_id
WHERE bth.patient_id = :patient_user_id
ORDER BY bth.transferred_at DESC;
```

---

## 7. Backend Action API Endpoints Reference

All endpoints accept `application/x-www-form-urlencoded` with standard `csrf_token` and require authenticated session cookies:

1. **Atomic Bed Transfer:** `POST /MedPulse/backend/api/bed_transfer.php`
   - Parameters: `patient_id` (int), `from_bed_id` (int), `to_bed_id` (int), `reason` (string, optional)
2. **Multi-Doctor Assignment:** `POST /MedPulse/backend/api/doctor_assignment.php`
   - Parameters: `patient_id` (int), `doctor_ids[]` (array of ints), `primary_doctor_id` (int, optional), `notes` (string, optional)
3. **Patient Discharge:** `POST /MedPulse/backend/api/discharge_patient.php`
   - Parameters: `patient_id` (int), `summary` (string, optional)
4. **Available Beds Lookup:** `GET /MedPulse/backend/api/get_available_beds.php?ward={ward_type}`
5. **Inpatient Directory:** `GET /MedPulse/backend/api/get_inpatient_data.php`
6. **Real-Time SSE Stream:** `GET /MedPulse/backend/api/realtime_stream.php?channel={channel}&last_id={last_event_id}`

---
*End of Integration Guide. Happy coding!*
