# medpulse-hospital-system
# 🏥 MedPulse — Smart Hospital & Clinical Management Ecosystem

**MedPulse** is an enterprise-grade hospital management and real-time clinical operations ecosystem. Built to eliminate administrative bottlenecks, the platform streamlines outpatient departments (OPD), automates turn-gated virtual consultations, optimizes inpatient bed census logistics, and provides multi-tier governance aligned with national healthcare standards.

---

## 🌟 Key Architecture & Modules

### 1. Real-Time OPD Chamber & Queue Progression
* **4-Day Rolling Forecast Engine:** Enables attending physicians to monitor clinical load across Morning and Evening shifts for the active day and three subsequent rolling days.
* **Concurrency-Safe Serial Token Allocation:** Scopes patient token allocation using atomic, multi-variable constraints (`doctor_id` + `appointment_date` + `shift`), eliminating race conditions and token collisions during peak booking spikes.
* **Zero-Reload State Synchronization:** Leverages low-overhead background polling to track queue progression and consultation status reactively without manual browser refreshes.

### 2. Virtual Care & Tele-Consultation Suite
* **24/7 Verified Physician Discovery:** Provides instant discovery of on-duty clinical consultants categorized by specialty and branch deployment.
* **Turn-Gated Teleconsultation Bridges:** Secures video consultation rooms with automated turn locks, ensuring patient entry is authorized only when their specific token is actively called.

### 3. Inpatient Care & Live Bed Census
* **Dynamic Census Telemetry:** Tracks inpatient capacity across Emergency, General Wards, HDU, ICU, and Presidential Suites with live occupancy metrics.
* **Streamlined Discharge State Transitions:** Implements an automated state machine that finalizes checkout records and reverts bed availability to `AVAILABLE` upon confirmation.

### 4. Healthcare Compliance & Governance
* **Regional Regulatory Alignment:** Designed in compliance with **BMDC (Bangladesh Medical and Dental Council)** registration tracking and **DGHS** patient data privacy standards.
* **Role-Based Access Control (RBAC):** Isolates operational workflows across dedicated portals for Patients, Physicians, Staff, Branch Administrators, and Super Administrators.

---

## 🛠️ Technology Stack

* **Backend Engine:** Object-Oriented PHP 8.x with PDO transactions and atomic locking mechanisms.
* **Database Architecture:** MySQL relational schema with indexed scopes and optimized relational integrity.
* **Frontend Ecosystem:** Vanilla JavaScript (Fetch API, asynchronous reactive polling), Tailwind CSS.
* **Interface Architecture:** High-contrast, responsive dashboard interface engineered for clinical usability.

---

## 👨‍💻 System Architect

**Afzal Hossain Miraz**  
Clinical Systems Architecture & Full-Stack Engineering
