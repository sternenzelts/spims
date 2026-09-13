# Microtech – Spare Parts Inventory Management System Product Requirements Document

## Workbook Sync

| Field | Value |
|---|---|
| Module | Spare Parts Inventory Management System (SPIMS) |
| Category | Business |
| Owner | TBD |
| Priority | TBD |
| Current Phase | Planning |
| Dev Status | Not Started |
| Demo Status | Not Ready |
| Deployment Status | Not Deployed |
| Production Readiness | Not Assessed |
| Product Notes | Microtech-owned product. Current focus: Microtech production and demo installs. Customer installs parked until customer details are confirmed. Open questions in Appendix A. |

## Document Information

| Field | Value |
|---|---|
| Document Title | Microtech – Spare Parts Inventory Management System Product Requirements Document |
| Version | 0.1.0 |
| Date | September 13, 2026 |
| Status | Draft |
| Owner | TBD |
| Product Owner | Microtech Industrial Solutions, Inc. |
| First Installs | Microtech production storeroom; Microtech demo |
| Deployment Model | One install per company, each with its own application and database |
| Source Proposal | Project Proposal: Spare Parts Inventory Management System — Engr. Jabez J. Agaya, prepared by Microtech for a customer |
| Related Documents | System Design: `docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md` |
| Capability Type | Core Business Module |
| Operational Role | Business Domain |
| Ownership Type | Source of Truth |
| Dependency Level | Core |
| User Surface | Operational |
| Capability Group | Operations |
| Classification | Confidential — for Microtech use only |

> **Format note.** This document follows the KoraERP PRD structure. SPIMS is a standalone web
> application owned by Microtech Industrial Solutions, Inc. and installed separately for each company;
> it is not a module inside the Kora platform. The platform services
> the Kora template assumes — Auth Service, Audit Service, Notification Service, Document Service, and
> Workflow Application Service — are provided inside SPIMS itself or replaced by their concrete
> equivalents in Section 14. Implementation detail is kept in the System Design document.

---

## 1. Background

### 1.1 Problem Statement

Industrial sites that run a spare parts storeroom — starting with Microtech's own, and including the
customer the source proposal was prepared for — need a structured way to manage spare parts stock,
withdrawals, replenishments, and consumption history.

Without a dedicated spare parts inventory system, withdrawals may not be consistently attributed to a
person, a machine, and a purpose, and stock records may drift from what is actually on the shelves.
This creates:

- unclear records of who withdrew a spare part, what was withdrawn, how many, when, and for which equipment
- inventory errors from manual recording
- no early warning before critical spare parts run out
- no reliable consumption data for setting minimum levels and planning replenishment
- time spent manually consolidating inventory and consumption reports
- equipment downtime when a critical spare part is unexpectedly unavailable
- difficulty identifying fast-moving, slow-moving, excess, and obsolete stock

Microtech needs a standalone Spare Parts Inventory Management System that owns spare part records,
stock balances, stock movement history, and withdrawal accountability, and that it can run in its own
storeroom and install for customers without changing the software for each one.

### 1.2 Solution Overview

The Spare Parts Inventory Management System (SPIMS) provides spare part, stock balance, withdrawal,
and consumption management for industrial spare parts storerooms.

It supports:

- spare part records with stock levels and criticality
- withdrawal transactions containing multiple spare parts, confirmed by personnel ID/RFID badge scan
- replenishment receiving
- stock adjustments with reasons
- physical inventory counts with difference posting
- reversal of completed transactions
- backdated entry of transactions recorded on paper while SPIMS was unavailable
- import of existing spare parts and opening balances from Excel
- consumption and depletion monitoring
- low stock, critical stock, replenishment, high consumption, and inventory discrepancy alerts
- a dashboard and ten operational reports
- stock movement history showing the resulting inventory balance of every movement

SPIMS owns spare part records, stock balances, stock movements, and withdrawal accountability. It does
not own purchase orders, supplier payment, inventory valuation, accounting entries, maintenance work
orders, or employee master records.

For MVP, SPIMS supports a complete native lifecycle from spare part request to a completed, attributed
stock movement. There is no approval workflow. Supervisor authorization is required only for
exceptions and corrections.

SPIMS is built once and installed separately for each company. Each install has its own database and
its own settings for company name, logo, time zone, currency, withdrawal purposes, and transaction
number prefixes. Microtech runs two installs first: a production install for its own storeroom, and a
demo install with sample data for showing SPIMS to customers.

### 1.3 Architectural Positioning

SPIMS is a Core Business Module under Operations, deployed as a standalone web application with one
install per company.

It is the source of truth for spare part records, stock balances, and stock movements.

A 24/7 Inventory Custodian operates the withdrawal station. Every withdrawal has two actors: the
Inventory Custodian, who is signed in to SPIMS and processes the transaction, and the Requesting
Personnel, who has no SPIMS account and is identified by scanning their ID/RFID badge as the final step
before the withdrawal is confirmed.

#### SPIMS Owns

- spare part records
- stock level and criticality settings
- storage location records
- equipment records and part-to-equipment classification
- personnel records and badge enrollment
- stock balances
- stock transactions and stock movement records
- physical inventory count sessions
- consumption and depletion figures
- inventory alerts
- dashboard, reports, and audit history
- SPIMS user accounts and roles
- install settings

#### SPIMS Does Not Own

- purchase requests and purchase orders
- supplier master data and supplier payment
- inventory valuation and accounting ledger entries
- maintenance work orders and maintenance schedules
- employee master records
- badge issuance and physical access control
- database hosting infrastructure
- email delivery infrastructure
- customer contracts, licensing, and billing

---

## 2. Goals

### 2.1 Operational Goals

- Provide a single source of truth for spare part stock balances.
- Attribute every withdrawal to the requesting personnel, the processing custodian, the equipment, and the purpose.
- Change stock balances only through recorded stock movements.
- Show the current available quantity of any spare part.
- Warn before critical and low-stock spare parts run out.
- Measure consumption and depletion from actual withdrawal history.
- Keep the storeroom operating during a SPIMS outage through paper fallback and backdated entry.
- Serve every company from one product, with differences between companies handled through install settings rather than software changes.

### 2.2 MVP Goals

- Import existing spare parts and opening balances from Excel.
- Create and manage spare part records with stock levels and criticality.
- Record withdrawals of multiple spare parts, confirmed by personnel badge scan.
- Record replenishments, adjustments, and reversals.
- Conduct physical inventory counts and post differences.
- Calculate consumption, depletion rate, and days of stock cover.
- Raise and resolve the five alert types defined in the proposal.
- Provide the dashboard and ten reports.
- Audit every stock movement and every configuration change.
- Configure an install for a company through install settings.
- Load sample data into a demo install.

### 2.3 Success Measures

Targets are proposed, pending confirmation by Microtech (Appendix A, Q16), and measured first at the
Microtech production install.

| Measure | Target | How Measured |
|---|---|---|
| Withdrawals attributed to personnel, equipment, and purpose | 100% | Enforced at confirmation; Report B |
| Parts with no difference at the first full physical count after 90 days of operation | At least 95% | Count session results |
| Critical spare parts out of stock | Baseline set in first 3 months; target agreed after | Report F and alert history |
| Custodian time to complete a 5-line withdrawal, excluding picking | Under 60 seconds | Timed during user acceptance testing |
| Supervisor overrides | Under 2% of withdrawals per month from month 4 | Report J |
| Manual personnel identifications | Under 2% of withdrawals per month | Report J |
| Backdated transactions | Under 2% of transactions per month | Report J |
| Time to produce the Monthly Consumption Report | Under 5 minutes | Timed; replaces manual consolidation |
| Time to set up a new install, from an empty database to ready for data import | Under 1 working day, with no software changes | Timed when setting up the demo install and each customer install |

---

## 3. Scope

### 3.1 In Scope

- spare part record creation and maintenance
- stock level and criticality settings
- equipment register and part-to-equipment classification
- storage location list
- personnel registration and badge enrollment
- Excel/CSV import of spare parts and opening balances
- withdrawal transactions with multiple spare parts
- personnel identification by ID/RFID badge scan at withdrawal confirmation
- supervisor override for insufficient recorded stock
- supervisor-authorized manual personnel identification
- replenishment receiving
- stock adjustments
- reversal of completed transactions
- backdated entry from paper fallback forms
- physical inventory counts and difference posting
- consumption and depletion calculation
- five inventory alert types
- daily alert summary email
- stock balance and movement history views
- dashboard
- ten reports with Excel, CSV, and PDF export
- role-based access control
- audit history
- nightly stock balance verification
- nightly backup outside the hosting provider
- install settings for company profile, time zone, currency, withdrawal purposes, and transaction number prefixes
- sample data loading for demo installs

### 3.2 Out of Scope

- purchase request and purchase order management
- supplier master and supplier performance tracking
- inventory valuation and cost accounting
- accounting ledger posting
- maintenance work order management
- multiple storage locations per spare part
- transfers between storerooms
- manufacturer and alternate part number cross-reference
- lot, batch, serial number, and expiry tracking
- barcode or QR labels on individual spare parts
- return of unused parts to stock as a separate transaction type (Appendix A, Q13)
- reorder point and safety stock calculated from lead time and consumption variability
- offline-capable withdrawal station
- mobile application
- configurable approval workflows
- integration with ERP, maintenance management, or HR systems
- one shared system hosting several companies
- customer-specific software changes
- customer self-service sign-up
- licensing and billing of customer installs

---

## 4. Users & Actors

| Actor | Description |
|---|---|
| Inventory Custodian | Signed-in operator at the withdrawal station on every shift. Prepares and confirms withdrawals, records replenishments, enters counted quantities, and enters backdated transactions. |
| Requesting Personnel | Maintenance or production personnel who request and receive spare parts. Has no SPIMS account. Identified by ID/RFID badge scan at withdrawal confirmation. |
| Inventory Supervisor | Authorizes exceptions and corrections: insufficient stock overrides, manual personnel identification, adjustments, reversals, and count posting. Registers personnel and enrolls badges. |
| Inventory Planner | Sets stock levels and criticality, monitors consumption and depletion, and uses reports for replenishment planning. Also used by management for dashboard and reporting access. Read-only on transactions. |
| System Administrator | Manages user accounts, roles, install settings, and data imports for one install. |
| Microtech Implementation Team | Sets up each install: configures install settings, imports spare parts and opening balances, registers personnel, loads sample data into demo installs, and trains users. Works through the System Administrator role. |
| ID/RFID Badge Reader | Hardware at the withdrawal station that reads a personnel badge and passes its identifier to SPIMS. |
| Purchasing | Outside SPIMS. Uses replenishment alerts and reports to raise purchase orders. |
| System | Updates stock balances, records resulting balances, calculates consumption and depletion, evaluates alerts, verifies balances nightly, and runs nightly backups. |

A user may hold more than one role.

Each install serves one company. Users, personnel, spare parts, and stock in one install are not
visible from any other install.

---

## 5. Module Ownership

### 5.1 Module Owns

- spare part records and status
- stock level and criticality settings
- storage location records
- equipment records and part-to-equipment classification
- personnel records and badge enrollment
- stock balances
- stock transactions and stock movement records
- physical inventory count sessions and counted quantities
- import batches
- consumption and depletion figures
- inventory alerts
- dashboard, list, detail, and report surfaces
- audit history
- SPIMS user accounts and roles
- install settings: company profile, time zone, currency, withdrawal purposes, transaction number prefixes, thresholds, and notification recipients

### 5.2 Module Does Not Own

- purchase requests and purchase orders
- supplier records and supplier payment
- inventory valuation
- accounting ledger entries
- maintenance work orders
- employee master records
- badge issuance and physical access control
- database hosting infrastructure
- email delivery infrastructure
- backup storage infrastructure
- customer contracts, licensing, and billing

---

## 6. Functional Requirements

### FR-SPIMS-001: Manage Spare Part Records

#### Description

The system shall allow authorized users to create and update spare part records.

A spare part record identifies a stock-tracked spare part and holds its part number, description, unit
of measure, storage location, applicable equipment, and status. The stock balance is not entered on
the spare part record; it changes only through stock movements.

#### Actors

- Inventory Supervisor
- Inventory Planner
- System Administrator
- Inventory Custodian where permitted

#### Preconditions

- User is signed in.
- User has permission to manage spare part records.
- Storage location exists where one is assigned.

#### Main Flow

1. User selects Create Spare Part.
2. System opens the spare part form.
3. User enters part number, description, unit of measure, storage location, applicable equipment, and status.
4. System validates the spare part details.
5. System creates the spare part record with a stock balance of zero.
6. System records an audit event.

#### Alternate Flows

- If the part number already exists, the system blocks creation and links to the existing record.
- If required fields are missing, the system displays validation errors.
- If the user updates an existing spare part, the system saves the changes and records old and new values in the audit history.
- If the user sets a spare part with a stock balance above zero to Inactive, the system warns that the part will no longer be available for withdrawal and requires confirmation.
- If the user lacks permission, the system denies the action.

#### Validation Rules

- Part number is required and must be unique, ignoring letter case.
- Description is required.
- Unit of measure is required.
- Quantities support up to three decimal places, for spare parts measured by length, volume, or weight.
- Unit of measure cannot be changed after the spare part has stock movements, because it would change the meaning of every recorded quantity.
- Status must be Active or Inactive.
- A spare part may be linked to more than one equipment record.
- Stock balance cannot be entered or edited on the spare part record.
- Spare parts with stock movements cannot be deleted; they can only be set to Inactive.
- Creation and updates must be audit logged.

#### Postconditions

- Spare part record is created or updated.
- New spare parts start with a stock balance of zero.
- Audit event is recorded.

### FR-SPIMS-002: Manage Stock Levels and Criticality

#### Description

The system shall allow authorized users to set the reorder point, minimum level, critical level,
maximum level, criticality, and lead time of each spare part.

These settings determine stock status, alerts, and replenishment requirements.

| Setting | Meaning |
|---|---|
| Reorder Point | Stock level at which replenishment should be requested |
| Minimum Level | Safety stock level; reaching it means replenishment is overdue |
| Critical Level | Stock level at which equipment downtime risk is imminent |
| Maximum Level | Upper stock limit, used to calculate recommended replenishment quantity |
| Criticality | Importance of the spare part to operations: Critical, Important, or Normal |
| Lead Time | Typical number of days from order to receipt; recorded for future reorder calculations |

The expected order of levels is Critical Level ≤ Minimum Level ≤ Reorder Point ≤ Maximum Level. These
definitions are pending confirmation by Microtech (Appendix A, Q8).

#### Actors

- Inventory Planner
- System Administrator

#### Preconditions

- Spare part exists.
- User has permission to manage stock levels and criticality.

#### Main Flow

1. User opens the spare part detail.
2. User selects Edit Stock Levels.
3. User enters reorder point, minimum level, critical level, maximum level, criticality, and lead time.
4. System validates the level values and their order.
5. System saves the stock level settings.
6. System re-evaluates alerts for the spare part.
7. System records an audit event with old and new values.

#### Alternate Flows

- If critical level is greater than minimum level, the system blocks the save.
- If maximum level is less than minimum level, the system blocks the save.
- If reorder point is below minimum level or above maximum level, the system warns the user and saves after confirmation.
- If the new settings clear or trigger an alert condition, the system resolves or opens the alert.
- If the user lacks permission, the system denies the action.

#### Validation Rules

- Reorder point, minimum level, critical level, and maximum level must be zero or greater.
- Critical level must not exceed minimum level.
- Maximum level, where set, must not be less than minimum level.
- Criticality is required and must be Critical, Important, or Normal.
- Lead time, where set, must be a whole number of days greater than zero.
- Critical level is a quantity and criticality is a classification; one must not be derived from the other.
- Changes must be audit logged with old and new values.

#### Postconditions

- Stock level settings are saved.
- Alerts for the spare part are re-evaluated.
- Audit event is recorded.

### FR-SPIMS-003: Manage Equipment and Storage Locations

#### Description

The system shall allow authorized users to maintain the equipment register and the storage location
list.

Equipment records identify the machines spare parts are used on. Equipment is required on every
withdrawal and is the basis of the Equipment Spare Parts Consumption Report. Storage locations identify
where spare parts are kept in the storeroom.

#### Actors

- Inventory Supervisor
- Inventory Planner
- System Administrator

#### Preconditions

- User is signed in.
- User has permission to manage equipment and storage locations.

#### Main Flow

1. User opens Equipment or Storage Locations.
2. User selects Create.
3. User enters code, name, and area or description.
4. System validates the details.
5. System creates the record.
6. User links spare parts to the equipment they are used on, where known.
7. System records an audit event.

#### Alternate Flows

- If the code already exists, the system blocks creation.
- If the user sets equipment to Inactive, the system keeps it in history and removes it from selection on new withdrawals.
- If the user sets a storage location to Inactive while it is assigned to Active spare parts, the system blocks the change and lists the affected spare parts.
- If the user lacks permission, the system denies the action.

#### Validation Rules

- Code is required and must be unique.
- Name is required.
- Inactive equipment cannot be selected on new withdrawals.
- Inactive storage locations cannot be assigned to spare parts.
- Equipment and storage locations with history cannot be deleted; they can only be set to Inactive.
- Creation and updates must be audit logged.

#### Postconditions

- Equipment or storage location record is created or updated.
- Part-to-equipment links are saved.
- Audit event is recorded.

### FR-SPIMS-004: Register Personnel and Enroll Badges

#### Description

The system shall allow authorized users to register personnel who may withdraw spare parts and to
enroll each person's ID/RFID badge.

Personnel do not sign in to SPIMS. An enrolled badge on an Active personnel record is how SPIMS
identifies who receives spare parts.

#### Actors

- Inventory Supervisor
- System Administrator
- ID/RFID Badge Reader

#### Preconditions

- User is signed in.
- User has permission to register personnel.
- The person's badge is available for enrollment.

#### Main Flow

1. User selects Register Personnel.
2. User enters employee number, full name, and department, and optionally uploads a photo.
3. User selects Enroll Badge.
4. The person's badge is scanned on the badge reader.
5. System checks that the badge is not enrolled to another person.
6. System saves the personnel record and badge enrollment.
7. System records an audit event.

#### Alternate Flows

- If the badge is already enrolled to another Active person, the system blocks enrollment and shows the current holder's name and the masked badge identifier.
- If the employee number already exists, the system blocks registration.
- If a badge is lost or replaced, the user enrolls the new badge; the previous badge stops identifying the person immediately.
- If a person leaves Microtech, the user sets the personnel record to Inactive; the badge no longer confirms withdrawals and the person's withdrawal history is kept.
- If the user lacks permission, the system denies the action.

#### Validation Rules

- Employee number is required and must be unique.
- Full name is required.
- A badge can be enrolled to only one person at a time.
- Badge identifiers are stored protected and displayed masked on every screen and report.
- The full badge identifier is visible only to System Administrators.
- Inactive personnel cannot confirm withdrawals.
- Personnel with withdrawal history cannot be deleted; they can only be set to Inactive.
- Registration, badge enrollment, badge replacement, and deactivation must be audit logged.

#### Postconditions

- Personnel record is created or updated.
- Badge is enrolled to the person.
- Audit event is recorded.

### FR-SPIMS-005: Import Spare Parts and Opening Balances

#### Description

The system shall allow authorized users to import spare part records and opening stock balances from
an Excel or CSV file.

Each opening balance is recorded as an Opening Balance stock movement, so every stock balance is
traceable to stock movements from the first day of operation.

#### Actors

- System Administrator
- Inventory Supervisor where permitted

#### Preconditions

- User is signed in.
- User has permission to import.
- The file follows the SPIMS import template.
- Storage locations referenced in the file exist.

#### Main Flow

1. User downloads the import template.
2. User uploads the completed file.
3. User maps file columns to SPIMS fields.
4. System validates every row.
5. System displays a preview showing spare parts to be created, spare parts to be updated, and opening balances to be recorded.
6. User reviews the preview and confirms the import.
7. System creates or updates the spare part records.
8. System records an Opening Balance stock movement for each spare part with an opening quantity.
9. System sets the import batch to Completed and records an audit event.

#### Alternate Flows

- If any row has errors, the system lists each row number and error and blocks confirmation until a corrected file is uploaded.
- If a part number appears more than once in the file, the system reports every duplicate row as an error.
- If a spare part in the file already has stock movements, the system reports its opening balance as an error.
- If the file contains columns the user is not permitted to change, such as stock levels uploaded by an Inventory Supervisor or opening quantities uploaded by anyone other than a System Administrator, the system reports those columns as errors.
- If the user cancels at the preview, nothing is saved and the import batch is set to Cancelled.
- If the user lacks permission, the system denies the action.

#### Validation Rules

- Part number, description, and unit of measure are required on every row.
- Part numbers must be unique within the file, ignoring letter case.
- Opening quantity must be zero or greater.
- Referenced storage locations must exist and be Active.
- Stock level values must follow the rules of FR-SPIMS-002.
- An opening balance can be recorded only once per spare part.
- Opening balances must be recorded as stock movements and never written directly to the stock balance.
- Imports must be audit logged with file name, row counts, and user.

#### Postconditions

- Spare part records are created or updated.
- Opening Balance stock movements are recorded.
- Import batch is Completed.
- Audit event is recorded.

### FR-SPIMS-006: Prepare Withdrawal Transaction

#### Description

The system shall allow the Inventory Custodian to prepare a withdrawal containing one or more spare
parts before the requesting personnel is identified.

This covers withdrawal Steps 1 to 4 of the proposal. A prepared withdrawal is a Draft and does not
change stock balances.

#### Actors

- Inventory Custodian
- Inventory Supervisor

#### Preconditions

- User is signed in at the withdrawal station.
- User has permission to process withdrawals.
- Requested spare parts are Active.

#### Main Flow

1. Requesting personnel asks the custodian for spare parts.
2. Custodian selects New Withdrawal.
3. Custodian enters each part number and quantity.
4. System displays each spare part's description, storage location, current stock balance, and resulting balance.
5. Custodian selects the equipment and purpose.
6. Custodian reviews the complete list of spare parts and quantities.
7. System displays a warning on any line that exceeds the available stock or would take the spare part to or below its minimum or critical level.
8. System keeps the withdrawal as Draft until it is confirmed through FR-SPIMS-007.

#### Alternate Flows

- If the part number is not found, the system displays a not-found message and offers a description search.
- If the same spare part is entered twice, the system combines the two lines into one line with the total quantity.
- If one line is for a different machine or purpose, the custodian sets the equipment and purpose on that line.
- If a line exceeds the available stock, the custodian reduces the quantity, removes the line, or requests a supervisor override through FR-SPIMS-008.
- If the spare part is Inactive, the system blocks adding it.
- If the connection is interrupted, the system keeps the Draft so the custodian can continue when the connection returns.
- If the withdrawal is abandoned, the custodian cancels the Draft and no stock movement is recorded.

#### Validation Rules

- At least one line is required.
- Quantity must be greater than zero.
- Only Active spare parts can be added.
- Each spare part can appear only once per withdrawal.
- Equipment is required and must be Active.
- Purpose is required and must be an Active withdrawal purpose from the install settings (FR-SPIMS-018). The default list, taken from the proposal, is: Preventive Maintenance, Corrective Maintenance, Breakdown Repair, Machine Modification, Replacement of Worn Part, Other Maintenance Activity.
- Remarks are required when the selected purpose is set to require remarks; by default, Other Maintenance Activity.
- Draft withdrawals do not change stock balances.

#### Postconditions

- Draft withdrawal exists with its lines, equipment, and purpose.
- No stock balance is changed.

### FR-SPIMS-007: Confirm Withdrawal Through Personnel ID Scan

#### Description

The system shall require the requesting personnel's ID/RFID badge to be scanned as the final step
before a withdrawal is confirmed, and shall deduct all withdrawn quantities together when the
withdrawal is confirmed.

This covers withdrawal Steps 5 to 8 of the proposal. The badge scan identifies who receives the spare
parts. It is performed after all spare parts have been entered and reviewed, not at the start of the
transaction.

#### Actors

- Inventory Custodian
- Requesting Personnel
- ID/RFID Badge Reader
- Inventory Supervisor, for manual identification
- System

#### Preconditions

- A Draft withdrawal exists and has been reviewed through FR-SPIMS-006.
- Every line has sufficient stock, or a supervisor override has been authorized through FR-SPIMS-008.
- Requesting personnel is registered and Active.

#### Main Flow

1. Custodian selects Scan Personnel ID.
2. Requesting personnel scans their badge on the badge reader.
3. System identifies the personnel and displays their name, employee number, department, and photo.
4. Custodian checks that the person at the counter matches the displayed identity.
5. Custodian selects Confirm Withdrawal.
6. System checks the current stock balance of every line again.
7. System records a withdrawal stock movement for every line, including the resulting inventory balance.
8. System deducts all withdrawn quantities from the stock balances.
9. System sets the withdrawal to Completed and assigns a transaction number.
10. System evaluates alerts for the withdrawn spare parts.
11. System records an audit event.
12. Custodian issues the spare parts to the requesting personnel.

#### Alternate Flows

- If the badge is not enrolled, or the personnel record is Inactive, the system rejects the scan and the withdrawal stays Draft.
- If the person at the counter does not match the displayed identity, the custodian clears the identification and the withdrawal stays Draft.
- If the requesting personnel does not have their badge, or the badge reader is not working, an Inventory Supervisor may authorize manual selection of the personnel with a reason. The withdrawal is marked Manually Identified and appears in the Exceptions Report. This flow is pending confirmation by Microtech (Appendix A, Q12).
- If stock changed after review and any line is now short, the system rejects the whole confirmation, names the affected spare part, and the withdrawal stays Draft.
- If the confirmation cannot be saved, no stock movement is recorded and no stock balance changes.
- If the withdrawal is not confirmed within the identification time limit after the scan, the system clears the identification and requires a new scan.

#### Validation Rules

- Personnel identification is required before confirmation.
- Personnel identification must happen after all lines, equipment, and purpose have been entered.
- Changing any line, equipment, or purpose after the scan clears the identification and requires a new scan.
- The identification time limit defaults to 2 minutes and is configurable.
- All lines are confirmed together, or none are confirmed.
- No line may reduce a stock balance below zero.
- A Completed withdrawal must record the personnel, custodian, equipment, and purpose.
- Manual identification requires Inventory Supervisor authorization and a reason.
- Every withdrawn line must create a stock movement that records the resulting inventory balance.
- Transaction numbers are sequential, unique, and prefixed by transaction type using the prefixes in the install settings, for example WD-2026-000123.
- Completed withdrawals cannot be edited; corrections use FR-SPIMS-011.
- Confirmation must be audit logged.

#### Postconditions

- Withdrawal is Completed.
- Stock balances are reduced.
- Alerts are evaluated.
- Audit event is recorded.
- The transaction record contains:
  - transaction number
  - date and time
  - personnel employee number and masked badge identifier
  - personnel name
  - part numbers
  - part descriptions
  - quantities withdrawn
  - equipment
  - purpose
  - remaining inventory balance of each spare part

### FR-SPIMS-008: Authorize Insufficient Stock Override

#### Description

The system shall allow an Inventory Supervisor to authorize a withdrawal line when the recorded stock
balance is lower than the quantity physically available on the shelf.

SPIMS does not allow negative stock. An override records a stock adjustment that brings the stock
balance to the quantity the supervisor verified, and the withdrawal is then confirmed against the
corrected balance.

#### Actors

- Inventory Custodian
- Inventory Supervisor
- System

#### Preconditions

- A Draft withdrawal has a line whose quantity exceeds the recorded stock balance.
- The spare parts have been physically checked on the shelf.
- An Inventory Supervisor is available to authorize.

#### Main Flow

1. Custodian selects Request Override on the affected line.
2. Inventory Supervisor authenticates on the withdrawal screen.
3. Supervisor enters the physically verified quantity on the shelf.
4. Supervisor selects the reason: Found Stock or Unrecorded Receipt.
5. System attaches the authorization to the Draft withdrawal.
6. Custodian continues to personnel identification and confirmation through FR-SPIMS-007.
7. On confirmation, the system records the adjustment and the withdrawal together.
8. System opens an Inventory Discrepancy alert for the spare part.
9. System records an audit event for the override.

#### Alternate Flows

- If the supervisor declines, the custodian must reduce the quantity or remove the line.
- If the withdrawal confirmation fails, neither the adjustment nor the withdrawal is recorded.
- If the line quantity is increased after authorization beyond the verified quantity, the system removes the authorization and requires a new one.
- If the stock balance at confirmation is already enough for the line, for example because a replenishment was recorded after authorization, the system confirms the withdrawal without the adjustment, discards the override, and still opens an Inventory Discrepancy alert so the spare part is counted.
- If the supervisor is also the user processing the withdrawal, the system allows the authorization and the audit record shows the same user in both roles.
- If the user lacks permission, the system denies the authorization.

#### Validation Rules

- Only Inventory Supervisors can authorize overrides.
- A reason is required.
- Verified quantity must be at least the quantity of the withdrawal line.
- The adjustment brings the stock balance to the verified quantity at the moment of confirmation.
- An override adjustment can only increase the stock balance.
- No stock balance may become negative.
- The adjustment and the withdrawal must be recorded together or not at all.
- Every override opens an Inventory Discrepancy alert.
- Overrides must be audit logged with supervisor, reason, recorded balance, and verified quantity.

#### Postconditions

- Adjustment and withdrawal are recorded together.
- Stock balance reflects the verified quantity less the withdrawn quantity.
- Inventory Discrepancy alert is open.
- Audit event is recorded.

### FR-SPIMS-009: Record Replenishment

#### Description

The system shall allow authorized users to record spare parts received into the storeroom.

A replenishment increases stock balances and records a stock movement for each spare part received.

#### Actors

- Inventory Custodian
- Inventory Supervisor

#### Preconditions

- User is signed in.
- User has permission to record replenishments.
- Received spare parts exist and are Active, or are created through FR-SPIMS-001.
- A delivery receipt or purchase order reference is available.

#### Main Flow

1. Custodian selects New Replenishment.
2. Custodian enters the supplier, delivery receipt or purchase order reference, and date received.
3. Custodian enters each part number, quantity received, and unit cost where known.
4. System displays each spare part's description, current stock balance, and resulting balance.
5. Custodian reviews and confirms the replenishment.
6. System records a replenishment stock movement for every line, including the resulting inventory balance.
7. System adds the received quantities to the stock balances.
8. System sets the replenishment to Completed and assigns a transaction number.
9. System re-evaluates alerts for the replenished spare parts.
10. System records an audit event.

#### Alternate Flows

- If a received spare part is not yet in SPIMS, the custodian creates the spare part record through FR-SPIMS-001 and continues.
- If the same delivery reference has already been recorded for the same supplier, the system warns of a possible duplicate and requires confirmation.
- If a received quantity would take the stock balance above the maximum level, the system warns and allows confirmation.
- If the spare part is Inactive, the system blocks the line.
- If the user lacks permission, the system denies the action.

#### Validation Rules

- At least one line is required.
- Quantity must be greater than zero.
- Supplier is required.
- Delivery receipt or purchase order reference is required.
- Date received is required and cannot be in the future.
- Unit cost, where entered, must be zero or greater and is recorded in the install's currency.
- Each spare part can appear only once per replenishment.
- Every line must create a stock movement that records the resulting inventory balance.
- Replenishments must be audit logged.

#### Postconditions

- Replenishment is Completed.
- Stock balances are increased.
- Alerts that no longer apply are resolved.
- Audit event is recorded.

### FR-SPIMS-010: Record Stock Adjustment

#### Description

The system shall allow an Inventory Supervisor to increase or decrease a stock balance, with a reason,
when stock changes without a withdrawal or receipt.

#### Actors

- Inventory Supervisor

#### Preconditions

- Spare part exists.
- User has permission to adjust stock.
- The reason for the adjustment is known.

#### Main Flow

1. Supervisor selects New Adjustment.
2. Supervisor selects the spare part.
3. System displays the current stock balance.
4. Supervisor enters the adjustment quantity as an increase or decrease.
5. Supervisor selects the reason and enters remarks.
6. System displays the resulting balance.
7. Supervisor confirms the adjustment.
8. System records an adjustment stock movement, including the resulting inventory balance.
9. System updates the stock balance.
10. System re-evaluates alerts for the spare part.
11. System records an audit event.

#### Alternate Flows

- If a decrease would take the stock balance below zero, the system blocks the adjustment.
- If the reason is missing, the system blocks the adjustment.
- If the adjustment is intended to correct a completed withdrawal or replenishment, the system directs the user to FR-SPIMS-011.
- If the difference was found during a physical count, the system directs the user to post it through FR-SPIMS-013.
- If the user lacks permission, the system denies the action.

#### Validation Rules

- Adjustment quantity must not be zero.
- Reason is required and must be one of: Damage, Shrinkage, Miscount, Unrecorded Issue, Unrecorded Receipt, Found Stock, Other.
- Remarks are required when the reason is Other.
- No stock balance may become negative.
- Inactive spare parts can be adjusted, so remaining stock can be corrected or written off.
- Every adjustment must create a stock movement that records the resulting inventory balance.
- Adjustments must be audit logged.

#### Postconditions

- Stock balance is adjusted.
- Adjustment stock movement is recorded.
- Audit event is recorded.

### FR-SPIMS-011: Reverse Completed Transaction

#### Description

The system shall allow an Inventory Supervisor to reverse a completed withdrawal, replenishment, or
adjustment that was recorded in error.

Completed transactions are never edited or deleted. A reversal records equal and opposite stock
movements and links them to the original transaction, so both remain in the history.

#### Actors

- Inventory Supervisor
- System

#### Preconditions

- Transaction is Completed.
- Transaction has not already been reversed.
- User has permission to reverse transactions.

#### Main Flow

1. Supervisor opens the Completed transaction.
2. Supervisor selects Reverse Transaction.
3. System displays the stock movements to be reversed and the resulting balances.
4. Supervisor enters the reason for the reversal.
5. Supervisor confirms the reversal.
6. System records a reversing transaction with equal and opposite stock movements of the same movement type.
7. System updates the stock balances.
8. System sets the original transaction to Reversed and links it to the reversing transaction.
9. System re-evaluates alerts for the affected spare parts.
10. System records an audit event.

#### Alternate Flows

- If reversing would take any stock balance below zero, such as reversing a replenishment whose spare parts have since been withdrawn, the system blocks the reversal and names the affected spare part.
- If the transaction is already Reversed, the system blocks the action.
- If only part of a transaction is wrong, the supervisor reverses the whole transaction and the correct transaction is recorded again.
- If the transaction is an Opening Balance, a reversing transaction, or an adjustment recorded by an override or a count posting, the system blocks the reversal; corrections use FR-SPIMS-010 or FR-SPIMS-013.
- If a reversed withdrawal included an override, only the withdrawal is reversed; the override adjustment remains, because the stock was physically present.
- If the user lacks permission, the system denies the action.

#### Validation Rules

- Reason is required.
- Only Completed withdrawals, replenishments, and adjustments can be reversed.
- A transaction can be reversed only once.
- A reversing transaction cannot itself be reversed.
- Reversing movements use the same movement type as the original, so a reversed withdrawal no longer counts toward consumption.
- Reversing movements carry the date and time the original transaction took place, so consumption is corrected in the original period and no period shows negative consumption.
- The original and reversing transactions both remain visible in the history.
- Reversals must be audit logged.

#### Postconditions

- Original transaction is Reversed.
- Reversing transaction is Completed and linked to the original.
- Stock balances are restored.
- Audit event is recorded.

### FR-SPIMS-012: Record Backdated Transaction

#### Description

The system shall allow authorized users to enter a withdrawal or replenishment that took place
earlier and was recorded on the paper fallback form while SPIMS was unavailable.

The system records both the date and time the transaction took place and the date and time it was
entered.

#### Actors

- Inventory Custodian
- Inventory Supervisor

#### Preconditions

- A completed paper fallback form exists.
- SPIMS is available again.
- User has permission to record backdated transactions.

#### Main Flow

1. Custodian selects New Withdrawal or New Replenishment and selects Backdated Entry.
2. Custodian enters the date and time the transaction took place and the paper form number.
3. Custodian enters the lines, and for a withdrawal the equipment and purpose, from the paper form.
4. For a withdrawal, the custodian selects the requesting personnel recorded and signed on the paper form.
5. Custodian enters the reason for backdating.
6. Custodian confirms the transaction.
7. System records the stock movements and updates the stock balances.
8. System marks the transaction as Backdated and stores both dates.
9. System re-evaluates alerts for the affected spare parts.
10. System records an audit event.

#### Alternate Flows

- If the transaction date and time is in the future, the system blocks the entry.
- If the transaction date is older than the backdating limit, the system requires Inventory Supervisor authorization.
- If a backdated withdrawal exceeds the current stock balance, the system blocks it and directs the custodian to FR-SPIMS-008.
- If the paper form number has already been entered, the system blocks the entry as a duplicate.
- If the user lacks permission, the system denies the action.

#### Validation Rules

- Reason for backdating is required.
- Paper fallback form number is required and must be unique.
- Transaction date and time must not be in the future.
- The backdating limit defaults to 7 days and is configurable.
- Personnel, equipment, and purpose are required for backdated withdrawals.
- Backdated withdrawals identify personnel from the signed paper form rather than a badge scan, so every backdated entry is included in the daily exceptions summary to supervisors.
- Consumption figures use the date and time the transaction took place.
- The resulting inventory balance recorded on each movement is the balance at the time of entry.
- Backdated transactions appear in the Exceptions Report.
- Backdated entries must be audit logged.

#### Postconditions

- Transaction is Completed and marked Backdated.
- Stock balances are updated.
- Both the transaction date and the entry date are recorded.
- Audit event is recorded.

### FR-SPIMS-013: Conduct Physical Inventory Count

#### Description

The system shall allow authorized users to run a physical inventory count for all spare parts, a
storage location, or a criticality class, record counted quantities, and post differences to stock
balances.

This provides the process behind the proposal's periodic physical inventory counts and Inventory
Discrepancy Alert.

#### Actors

- Inventory Supervisor
- Inventory Custodian
- System

#### Preconditions

- User is signed in.
- User has permission to open or count a session.
- Spare parts in the selected scope exist.

#### Main Flow

1. Supervisor opens a count session and selects the scope.
2. System records the stock balance of each spare part in scope at the time the session opens.
3. Custodian counts each spare part on the shelf and enters the counted quantity.
4. System records the date and time each spare part was counted.
5. Custodian marks the session as Counted.
6. System opens an Inventory Discrepancy alert for each difference above the session tolerance.
7. Supervisor reviews the differences and enters a reason for each difference that requires one.
8. Supervisor posts the session.
9. System records a count variance stock movement for each difference, setting each stock balance equal to its counted quantity.
10. System resolves Inventory Discrepancy alerts for the posted spare parts.
11. System records an audit event.

#### Alternate Flows

- If a stock movement for a spare part was recorded after that part was counted, the system flags it as needing a recount; the supervisor recounts or acknowledges it before posting.
- If a spare part in scope has no counted quantity, the system blocks posting until it is counted or removed from the session.
- If withdrawals and replenishments take place during the session, they are processed normally and listed on the posting screen.
- If the supervisor cancels the session, no stock movement is recorded and the session is set to Cancelled.
- If the user lacks permission, the system denies the action.

#### Validation Rules

- Counted quantity must be zero or greater.
- A spare part can appear only once per session.
- The difference posted is the counted quantity minus the stock balance at the time of posting, not the balance when the session opened.
- A reason is required for a difference when the stock balance at session open was zero, or when the difference as a percentage of that balance exceeds the session tolerance.
- Session tolerance defaults to 0%, so every difference requires a reason unless changed.
- Reason must be one of: Miscount, Damage, Unrecorded Issue, Unrecorded Receipt, Found Stock, Shrinkage, Other.
- Only Inventory Supervisors can open, post, or cancel sessions.
- All differences in a session are posted together, or none are posted.
- Count sessions must be audit logged.

#### Postconditions

- Session is Posted.
- Each counted spare part's stock balance equals its counted quantity.
- Count variance stock movements are recorded.
- Audit event is recorded.

### FR-SPIMS-014: Monitor Consumption and Depletion

#### Description

The system shall calculate consumption and depletion figures for each spare part from its withdrawal
history.

Average Monthly Consumption is calculated as stated in the proposal: Total Quantity Withdrawn ÷ Number
of Months.

#### Actors

- Inventory Planner
- Inventory Supervisor
- Inventory Custodian
- System

#### Preconditions

- User is signed in.
- User has permission to view consumption and depletion.

#### Main Flow

1. User opens Consumption & Depletion for one spare part or for all spare parts.
2. System calculates the figures over the analysis window.
3. System displays daily, weekly, and monthly consumption; average monthly consumption; average daily consumption; days of stock cover; projected reorder date; recommended replenishment quantity; movement class; and data confidence.
4. User filters by criticality, movement class, equipment, or data confidence.
5. User opens the spare part detail or the related report.

#### Alternate Flows

- If a spare part has no withdrawals in the window, the system shows zero consumption and no projection.
- If a spare part's history is shorter than the window, the system calculates over the available history and shows the data confidence level.
- If average daily consumption is zero, days of stock cover and projected reorder date are not shown.
- If the stock balance is already at or below the reorder point, the system shows Reorder Now instead of a projected date.
- If the user lacks permission, the system denies access.

#### Validation Rules

- Consumption counts withdrawal stock movements only; replenishments, adjustments, count variances, and opening balances are excluded.
- Reversed withdrawals are excluded from consumption.
- Consumption is assigned to the date the withdrawal took place, including backdated withdrawals.
- Daily, weekly, and monthly periods use the install's time zone, which defaults to Philippine Standard Time.
- The analysis window defaults to the most recent 6 months and is configurable.
- Average Monthly Consumption = Total Quantity Withdrawn ÷ Number of Months in the window.
- Average Daily Consumption = Total Quantity Withdrawn ÷ Number of Days in the window.
- Days of Stock Cover = Current Stock Balance ÷ Average Daily Consumption.
- Projected Reorder Date = Today + (Current Stock Balance − Reorder Point) ÷ Average Daily Consumption.
- Recommended Replenishment Quantity = Maximum Level − Current Stock Balance, shown when the stock balance is at or below the reorder point and a maximum level is set.
- Data confidence is based on the number of withdrawals in the window: None (0), Low (1–2), Moderate (3–5), Good (6 or more).
- Projections with Low confidence are visibly marked, and no projection is shown with None.
- Movement class defaults, applied in this order:
  - Dead Stock: no withdrawal in the last 12 months
  - Slow-Moving: no withdrawal in the last 90 days
  - Fast-Moving: at least one withdrawal in the window, and among the top 20% of classified spare parts by number of withdrawals in the window
  - Normal: all other classified spare parts
- Movement class uses number of withdrawals, not quantity, because spare parts are measured in different units.
- A spare part is classified only after 90 days since its first stock movement; before that it is Not Yet Classified.
- Movement class thresholds are configurable.
- Consumption and depletion figures are calculated and cannot be edited.

#### Postconditions

- User views consumption and depletion figures.
- No stock balance is changed.

### FR-SPIMS-015: Raise and Resolve Inventory Alerts

#### Description

The system shall raise alerts for low stock, critical stock, replenishment, high consumption, and
inventory discrepancy conditions, and resolve them when the condition clears.

An alert is raised once for a spare part and condition and stays open until the condition clears. It
is not raised again on every transaction.

| Alert | Raised When | Resolved When | Severity |
|---|---|---|---|
| Replenishment | Stock balance is at or below the reorder point | Stock balance is above the reorder point | Info |
| Low Stock | Stock balance is at or below the minimum level | Stock balance is above the minimum level | Warning |
| Critical Stock | Stock balance is at or below the critical level, or a spare part with Critical criticality reaches zero | Stock balance is above the critical level and above zero | Critical |
| High Consumption | Consumption in the last 30 days is more than twice the baseline rate for 30 days, with at least 3 withdrawals in the last 30 days | The condition is no longer true at the nightly check | Warning |
| Inventory Discrepancy | A count session is marked Counted with a difference above tolerance, or a supervisor override is recorded | The spare part's count is posted | Warning |

#### Actors

- System
- Inventory Custodian
- Inventory Supervisor
- Inventory Planner

#### Preconditions

- Spare part is Active.
- Stock levels are set for level-based alerts.

#### Main Flow

1. A transaction is completed, stock level settings change, a count session is marked Counted, or the nightly check runs.
2. System evaluates alert conditions for the affected spare parts, or for all Active spare parts during the nightly check.
3. System opens an alert for each condition that has become true and has no open alert.
4. System shows open alerts on the dashboard and in the alert list.
5. User acknowledges the alert and may add a note.
6. System resolves the alert when its condition clears.
7. System includes new, open, and resolved alerts in the daily alert summary.

#### Alternate Flows

- If a stock level is zero or not set, the system does not raise the alert based on that level.
- If a spare part moves between stock levels, the system resolves the current stock level alert and opens the alert for the new level.
- If a condition clears and later becomes true again, the system opens a new alert.
- If a spare part is set to Inactive, the system resolves its open alerts.
- If the daily alert summary cannot be sent, alerts remain visible in SPIMS and the failure is logged.

#### Validation Rules

- Only one alert can be open for each spare part and alert type.
- A spare part can have only one open stock level alert at a time — Critical Stock, Low Stock, or Replenishment — showing the most severe.
- The High Consumption baseline rate is the average daily consumption over the analysis window, excluding the last 30 days, multiplied by 30.
- High Consumption alerts are raised only for spare parts with at least 60 days of baseline history.
- The High Consumption multiplier defaults to 2.0 and the minimum withdrawals to 3; both are configurable.
- Acknowledgement records the user and the date and time.
- Alerts cannot be deleted.
- Alert history is kept.

#### Postconditions

- Alerts reflect the current condition of each spare part.
- Acknowledgements and resolutions are recorded.

### FR-SPIMS-016: View Stock Balance and Movement History

#### Description

The system shall allow authorized users to search spare parts and view current stock balances, stock
status, and the complete stock movement history of each spare part.

Movement history provides the audit trail described in the proposal: date and time, transaction type,
part number and description, quantity change, personnel, and resulting inventory balance.

#### Actors

- Inventory Custodian
- Inventory Supervisor
- Inventory Planner
- System Administrator

#### Preconditions

- User is signed in.
- User has permission to view stock balances.

#### Main Flow

1. User searches by part number or description.
2. System displays matching spare parts with stock balance, unit of measure, storage location, stock status, and criticality.
3. User filters by storage location, criticality, stock status, equipment, or movement class.
4. User opens a spare part.
5. System displays the spare part detail, stock levels, consumption summary, open alerts, and movement history.
6. User filters the movement history by date, movement type, personnel, or equipment.
7. User opens a transaction from the movement history.

#### Alternate Flows

- If no part number matches exactly, the system displays spare parts whose descriptions match, including partial words and minor misspellings.
- If no spare parts match, the system displays a no-results state.
- If a spare part has no stock movements, the system displays an empty movement history.
- If the user lacks permission, the system denies access.

#### Validation Rules

- Stock status is one of:
  - Out of Stock: stock balance is zero
  - Critical: at or below the critical level
  - Low: at or below the minimum level
  - Reorder: at or below the reorder point
  - In Stock: above the reorder point
- Stock status uses only the levels set above zero.
- Stock balance is display only and cannot be edited.
- Each movement shows: date and time taken place; date and time recorded, where different; transaction number and type; part number and description; quantity change, negative for decreases; personnel name and masked badge identifier; custodian; equipment; purpose; and resulting inventory balance.
- Movement history is listed in the order the movements were recorded.
- Movement records cannot be edited or deleted.

#### Postconditions

- User views stock balances and movement history.
- No stock balance is changed.

### FR-SPIMS-017: View Dashboard and Generate Reports

#### Description

The system shall provide a dashboard summarizing stock status, alerts, exceptions, and recent activity,
and shall generate the ten reports listed in Section 11.2 with Excel, CSV, and PDF export.

#### Actors

- Inventory Custodian
- Inventory Supervisor
- Inventory Planner
- System Administrator

#### Preconditions

- User is signed in.
- User has permission to view the dashboard or the selected report.

#### Main Flow

1. User opens the dashboard.
2. System displays the summary cards and lists described in Section 11.1.
3. User selects a card to open the records behind it.
4. User opens Reports and selects a report.
5. User sets the report filters.
6. System generates the report.
7. User exports the report to Excel, CSV, or PDF.
8. System records an audit event for the export.

#### Alternate Flows

- If no data matches, the system displays an empty state.
- If an export is large, the system prepares it in the background and notifies the user when it is ready to download.
- If the user lacks permission for a report, the system hides it from the report list.

#### Validation Rules

- Reports include Completed transactions only; Draft and Cancelled transactions are excluded.
- Reversed transactions are shown marked as Reversed and excluded from totals.
- Period filters use the date and time transactions took place.
- Badge identifiers are masked in every report.
- Prepared export files are available to download for 7 days.
- Report headers and exported files show the company name and logo from the install settings.
- Report exports must be audit logged.

#### Postconditions

- User views the dashboard or report.
- Export file is produced where requested.
- No stock balance is changed.

### FR-SPIMS-018: Configure Install Settings

#### Description

The system shall allow a System Administrator to configure the settings that differ between
companies, so the same SPIMS software can be installed for Microtech and for each customer without
software changes.

| Setting Group | Settings |
|---|---|
| Company Profile | Company name, logo, and address shown on screens, reports, and exported files |
| Regional | Time zone, which defaults to Philippine Standard Time, and currency for unit cost |
| Withdrawal Purposes | Purposes offered on withdrawals, whether each requires remarks, and whether each is Active |
| Transaction Numbering | Prefix for each transaction type; defaults are WD, RP, AJ, and OP |
| Thresholds | Analysis window, movement class thresholds, High Consumption multiplier and minimum withdrawals, identification time limit, backdating limit, and default count tolerance |
| Notifications | Recipients of the daily alert and exceptions summaries, and whether individual alert emails are on for each alert type |

#### Actors

- System Administrator
- Microtech Implementation Team, through the System Administrator role

#### Preconditions

- User is signed in.
- User has permission to configure install settings.

#### Main Flow

1. User opens Install Settings.
2. System displays the current settings, with defaults for settings not yet changed.
3. User changes one or more settings.
4. System validates the changes.
5. System saves the settings.
6. System applies the settings to new transactions, calculations, reports, and notifications.
7. System records an audit event with old and new values.

#### Alternate Flows

- If the company name has not been set, the system shows a setup prompt to System Administrators and blocks stock transactions until it is set.
- If a withdrawal purpose has been used on a transaction, the user cannot delete it; the user can rename it or set it to Inactive, and past transactions keep their link to it.
- If the user changes the time zone after transactions exist, the system warns that daily, weekly, and monthly figures will be regrouped and requires confirmation.
- If the user changes a transaction number prefix, new transactions use the new prefix and existing transaction numbers do not change.
- If the user changes the currency after unit costs have been recorded, the system warns that existing unit costs are not converted and requires confirmation.
- If the user lacks permission, the system denies the action.

#### Validation Rules

- Company name is required before the install can record stock transactions.
- At least one withdrawal purpose must be Active.
- Withdrawal purpose names must be unique.
- Transaction number prefixes must be unique across transaction types and contain letters only.
- Threshold values must be greater than zero, except the default count tolerance, which may be zero.
- Settings apply only to the install they are set in.
- Settings changes must be audit logged with old and new values.

#### Postconditions

- Settings are saved and applied.
- Audit event is recorded.

### FR-SPIMS-019: Load Sample Data

#### Description

The system shall allow a System Administrator to load sample spare parts, storage locations,
equipment, personnel, and transaction history into a demo install, so SPIMS can be shown to customers
with realistic activity.

Sample data is for demo installs only and must never enter an install that holds a company's real
data.

#### Actors

- System Administrator
- Microtech Implementation Team, through the System Administrator role

#### Preconditions

- The install was designated as a demo install when it was set up.
- User has permission to load sample data.

#### Main Flow

1. User opens Sample Data.
2. System confirms that the install is a demo install.
3. User selects Load Sample Data.
4. System loads sample spare parts, storage locations, equipment, personnel with sample badges, and several months of sample withdrawals, replenishments, adjustments, and counts.
5. System calculates consumption, depletion, and alerts from the sample history.
6. System records an audit event.

#### Alternate Flows

- If the install is a production install, the system does not offer sample data loading.
- If the demo install already contains data, the user may reset it after confirmation; the system removes all data and loads the sample data again.
- If the user lacks permission, the system denies the action.

#### Validation Rules

- An install is designated as demo or production when it is set up, and the designation cannot be changed from within SPIMS.
- Sample data can be loaded or reset only in a demo install.
- Resetting a demo install is the only action in SPIMS that removes stock movements.
- Sample personnel use fictional names.
- A demo install shows a Demo label on every screen and on every exported report.
- Loading and resetting sample data must be audit logged.

#### Postconditions

- Demo install contains sample data with calculated consumption, depletion, and alerts.
- Audit event is recorded.

---

## 7. Lifecycle

### 7.1 Lifecycle Overview

SPIMS manages lifecycles for spare parts, stock transactions, physical count sessions, inventory
alerts, and import batches.

It does not manage purchase order, supplier payment, accounting posting, or maintenance work order
lifecycles. All SPIMS lifecycles are native; there is no configurable workflow.

### 7.2 Spare Part Lifecycle States

| Status | Meaning |
|---|---|
| Active | Spare part can be used in withdrawals, replenishments, adjustments, and counts |
| Inactive | Spare part is kept with its history; it cannot be added to new withdrawals or replenishments and raises no alerts, but can still be adjusted and counted |

### 7.3 Stock Transaction Lifecycle States

| Status | Meaning |
|---|---|
| Draft | Transaction is being prepared; it has no effect on stock and can be edited |
| Completed | Transaction is confirmed; its stock movements are recorded and it cannot be edited |
| Cancelled | Draft was abandoned before confirmation; it had no effect on stock |
| Reversed | Completed transaction was corrected by a linked reversing transaction; both sets of stock movements remain |

Allowed transitions: Draft → Completed, Draft → Cancelled, Completed → Reversed.

Stock transactions do not use the Pending or Approved statuses of the Kora status standard. A withdrawal
is confirmed immediately at the counter, and any supervisor authorization for an exception is given
while the transaction is still Draft. Reversed is used because a Completed stock transaction can only
be corrected, never cancelled.

A transaction may also carry these markers, which are not statuses:

| Marker | Meaning |
|---|---|
| Backdated | Entered after the date it took place, from a paper fallback form (FR-SPIMS-012) |
| Manually Identified | Personnel selected by a supervisor instead of a badge scan (FR-SPIMS-007) |
| Override | Includes a supervisor-authorized stock adjustment (FR-SPIMS-008) |

### 7.4 Stock Movement Types

| Movement Type | Stock Effect | Recorded By |
|---|---|---|
| Opening Balance | Increase | FR-SPIMS-005 |
| Withdrawal | Decrease; increase when reversing | FR-SPIMS-007, FR-SPIMS-011, FR-SPIMS-012 |
| Replenishment | Increase; decrease when reversing | FR-SPIMS-009, FR-SPIMS-011, FR-SPIMS-012 |
| Adjustment | Increase or decrease | FR-SPIMS-008, FR-SPIMS-010, FR-SPIMS-011 |
| Count Variance | Increase or decrease | FR-SPIMS-013 |

### 7.5 Physical Count Session Lifecycle States

| Status | Meaning |
|---|---|
| Open | Counting is in progress |
| Counted | Counting is finished and differences are under review |
| Posted | Differences are recorded and stock balances equal counted quantities |
| Cancelled | Session was stopped before posting; it had no effect on stock |

### 7.6 Inventory Alert Lifecycle States

| Status | Meaning |
|---|---|
| Open | Condition is true and the alert has not been acknowledged |
| Acknowledged | A user has seen the alert and the condition is still true |
| Resolved | Condition has cleared |

### 7.7 Import Batch Lifecycle States

| Status | Meaning |
|---|---|
| Pending | File is uploaded and not yet validated |
| Validated | File passed validation and is awaiting confirmation |
| Completed | Spare parts and opening balances are imported |
| Failed | File has validation errors and nothing was imported |
| Cancelled | User cancelled at the preview and nothing was imported |

### 7.8 Lifecycle Rules

- Stock balances change only through stock movements recorded by Completed transactions or Posted count sessions.
- Every stock movement records the resulting inventory balance.
- No stock balance can become negative.
- Completed transactions cannot be edited or deleted.
- Corrections use reversal, adjustment, or physical count.
- Supervisor authorization for an exception is given while the transaction is Draft.
- Changing a Draft withdrawal after the badge scan clears the personnel identification.
- Spare parts, personnel, equipment, and storage locations with history are set to Inactive, never deleted.
- Stock movements are removed only when a demo install is reset (FR-SPIMS-019).

---

## 8. Permissions & Access Control

| Capability | Inventory Custodian | Inventory Supervisor | Inventory Planner | System Administrator |
|---|---|---|---|---|
| Search Spare Parts and View Stock Balances | Yes | Yes | Yes | Yes |
| View Movement History | Yes | Yes | Yes | Yes |
| Create Spare Part Record | Conditional | Yes | Yes | Yes |
| Edit Spare Part Record | Conditional | Yes | Yes | Yes |
| Manage Stock Levels and Criticality | No | No | Yes | Yes |
| Manage Equipment and Storage Locations | No | Yes | Yes | Yes |
| Register Personnel and Enroll Badges | No | Yes | No | Yes |
| View Full Badge Identifier | No | No | No | Yes |
| Import Spare Parts | No | Yes | No | Yes |
| Import Opening Balances | No | No | No | Yes |
| Prepare and Confirm Withdrawal | Yes | Yes | No | No |
| Authorize Insufficient Stock Override | No | Yes | No | No |
| Authorize Manual Personnel Identification | No | Yes | No | No |
| Record Replenishment | Yes | Yes | No | No |
| Record Stock Adjustment | No | Yes | No | No |
| Reverse Completed Transaction | No | Yes | No | No |
| Record Backdated Transaction | Conditional | Yes | No | No |
| Open, Post, or Cancel Count Session | No | Yes | No | No |
| Enter Counted Quantities | Yes | Yes | No | No |
| View Consumption and Depletion | Yes | Yes | Yes | Yes |
| Acknowledge Alerts | Conditional | Yes | Yes | Yes |
| View Dashboard | Yes | Yes | Yes | Yes |
| View and Export Reports | Conditional | Yes | Yes | Yes |
| View Audit History | No | Yes | Conditional | Yes |
| Manage Users and Roles | No | No | No | Yes |
| Configure Install Settings | No | No | No | Yes |
| Load or Reset Sample Data, Demo Installs Only | No | No | No | Yes |

Conditional access:

| Role | Capability | Condition |
|---|---|---|
| Inventory Custodian | Create Spare Part Record | Only when receiving a spare part not yet in SPIMS |
| Inventory Custodian | Edit Spare Part Record | Description, unit of measure, and storage location only; not status |
| Inventory Custodian | Record Backdated Transaction | Within the backdating limit; older entries need supervisor authorization |
| Inventory Custodian | Acknowledge Alerts | Stock level alerts only |
| Inventory Custodian | View and Export Reports | Current Inventory, Low Stock, and Critical Spare Parts reports only |
| Inventory Planner | View Audit History | Changes to spare part records and stock level settings only |

Requesting Personnel have no SPIMS account and no system access. They take part only by scanning their
badge at withdrawal confirmation.

The System Administrator role does not include stock transactions. A user who needs both must be
given both roles.

---

## 9. Audit & Governance

### 9.1 Audit Requirements

SPIMS should audit:

- spare part creation and updates
- stock level and criticality changes, with old and new values
- equipment and storage location changes
- personnel registration, badge enrollment, badge replacement, and deactivation
- rejected badge scans
- import batches
- withdrawal confirmation
- insufficient stock override authorization
- manual personnel identification authorization
- replenishment confirmation
- stock adjustments
- transaction reversals
- backdated entries
- count session opening, posting, and cancellation
- alert acknowledgement
- report exports
- user sign-in, failed sign-in, and role changes
- install settings changes
- sample data loading and demo install reset

### 9.2 Governance Rules

- SPIMS is the source of truth for spare part stock balances and stock movements.
- Stock movement history is the inventory audit trail. Stock movement records are never edited or deleted. The only exception is resetting a demo install, which holds no real data (FR-SPIMS-019).
- Each install holds one company's data only. No data is shared between installs.
- Each stock balance must equal the total of that spare part's stock movements. The system verifies this nightly and notifies System Administrators of any difference.
- Every withdrawal must be attributed to requesting personnel, custodian, equipment, and purpose.
- Every adjustment, override, reversal, backdated entry, and manual identification must carry a reason.
- Purchasing owns purchase orders. SPIMS stores only the supplier name and the delivery receipt or purchase order reference.
- Badge identifiers are protected and masked.
- The badge scan records who received spare parts. It does not by itself prevent unauthorized withdrawal; that depends on the custodian checking the identity displayed at the scan (RSK-SPIMS-002).
- SPIMS does not use a workflow. Supervisor authorization is the only approval mechanism.
- Stock movements and audit history are retained indefinitely.

---

## 10. Notifications

### 10.1 Notification Types

- open alert indicator, shown on every screen
- daily alert summary email listing new, open, and resolved alerts
- daily exceptions summary email to supervisors listing overrides, manual identifications, backdated entries, and reversals
- report export ready notification
- nightly stock balance verification failure notification to System Administrators
- nightly backup failure notification to System Administrators

### 10.2 Notification Rules

- SPIMS decides when notifications are needed.
- The email delivery service delivers email notifications.
- Recipients of each summary are configured in the install settings (FR-SPIMS-018).
- Alerts are notified once, when opened, through the daily summary.
- Individual alert emails are off by default and can be turned on per alert type. Whether Critical Stock alerts email immediately is pending confirmation by Microtech (Appendix A, Q14).
- Notifications link to the related spare part, transaction, count session, or alert.
- A failed email delivery does not block any storeroom operation and is logged.

---

## 11. Reporting & Analytics

### 11.1 Dashboard Requirements

Dashboard should show:

- open alerts by severity
- spare parts at or below the reorder point
- spare parts at or below the critical level
- Critical-criticality spare parts that are out of stock
- number of withdrawals and replenishments today
- the ten most recent transactions
- the ten spare parts with the most withdrawals this month
- exceptions this week: overrides, manual identifications, backdated entries, and reversals
- open count sessions
- last successful backup and last stock balance verification, for System Administrators

### 11.2 Report Requirements

SPIMS should support:

| Report | Name | Proposal Reference | Contents | Filters |
|---|---|---|---|---|
| A | Current Inventory Report | 7A | Part number, description, stock balance, unit of measure, storage location, minimum level, maximum level, reorder point, stock status | Storage location, criticality, stock status |
| B | Spare Parts Withdrawal Report | 7B | Transaction number, date and time, employee number, masked badge identifier, personnel name, part number, description, quantity withdrawn, equipment, purpose | Date range, spare part, personnel, equipment, purpose |
| C | Monthly Consumption Report | 7C | Part number, description, month, quantity consumed | Date range, spare part, equipment |
| D | Depletion Rate Report | 7D | Historical consumption, average consumption, depletion rate, current stock balance, days of stock cover, recommended replenishment quantity, data confidence | Criticality, movement class, data confidence |
| E | Low Stock Report | 7E | Spare parts at or below the minimum level | Storage location, criticality |
| F | Critical Spare Parts Report | 7F | Spare parts with Critical criticality and their current availability | Stock status |
| G | Personnel Withdrawal History | 7G | Spare parts withdrawn by a person | Personnel, date range |
| H | Equipment Spare Parts Consumption Report | 7H | Spare parts consumed by each equipment | Equipment, date range |
| I | Replenishment Report | 3B | Date received, transaction number, supplier, delivery reference, part number, quantity, unit cost | Date range, spare part, supplier |
| J | Exceptions Report | Added | Overrides, manual identifications, backdated entries, reversals, and adjustments, with user and reason | Date range, exception type, user |

Report I is required by the proposal's software function list in Section 3B but is not listed in its
Section 7 reports. It is included here and pending confirmation (Appendix A, Q9). Report J is added so
supervisors can review every exception from one place.

---

## 12. UX / UI Requirements

### 12.1 Dashboard

Dashboard should provide:

- summary cards
- alert indicators by severity
- exception indicators
- quick actions for New Withdrawal, New Replenishment, and Spare Part Search
- navigation from every card to its list or report

### 12.2 List View

List views should support:

- search
- filtering
- sorting
- pagination
- status visibility
- stock balance visibility where applicable
- storage location and criticality visibility where applicable
- date visibility

Applicable lists:

- spare parts
- stock transactions
- stock movements
- alerts
- personnel
- equipment
- storage locations
- count sessions
- import batches

### 12.3 Detail View

Spare part detail should show:

- part number, description, and unit of measure
- stock balance and stock status
- stock levels and criticality
- storage location
- linked equipment
- consumption and depletion summary
- open alerts
- movement history
- audit history
- available actions

Transaction detail should show:

- transaction number, type, and status
- date and time taken place and date and time recorded
- custodian, personnel, equipment, and purpose
- lines with quantities and resulting balances
- markers: Backdated, Manually Identified, Override
- link to the reversing or reversed transaction
- audit history
- available actions

### 12.4 Withdrawal Station Screen

The withdrawal station screen is the custodian's primary screen and should support:

- completing an entire withdrawal with the keyboard and badge reader only, without a mouse
- focus on the part number field when the screen opens and after each line is added
- description, storage location, stock balance, and resulting balance shown as soon as a part number is entered
- highlighting of lines that exceed available stock or fall to or below the minimum or critical level
- equipment and purpose selection by typing
- a distinct Scan Personnel ID step, available only after the lines, equipment, and purpose are entered
- the person's name and photo displayed large enough to compare with the person at the counter
- a confirmation message showing the transaction number
- keeping the Draft through a connection interruption
- a clear message when SPIMS cannot be reached, directing the custodian to the paper fallback form

### 12.5 Transaction Forms

Replenishment, adjustment, and backdated entry forms should support:

- spare part entry by part number
- quantity entry
- reason selection where required
- supplier and reference entry where required
- transaction date and paper form number for backdated entries
- resulting balance preview
- validation feedback
- confirm and cancel actions

### 12.6 Physical Count Screen

The physical count screen should support:

- spare parts in scope listed in storage location order
- counted quantity entry by keyboard
- counting progress
- hiding recorded stock balances from the custodian while counting, so counts are not influenced by the expected quantity
- differences, reasons, and recount flags shown to the supervisor at review
- post and cancel actions for the supervisor

### 12.7 Mobile Responsiveness

SPIMS is primarily desktop-oriented. The withdrawal station, imports, settings, and report exports
remain desktop-first.

Mobile and tablet support should prioritize:

- spare part lookup and stock balance
- dashboard and alerts for supervisors and planners
- counted quantity entry on a tablet during physical counts

### 12.8 Install Settings Screen

The install settings screen should support:

- settings grouped as listed in FR-SPIMS-018
- a setup checklist for a new install: company profile, withdrawal purposes, storage locations, equipment, spare part import, opening balances, personnel and badges, and backup confirmation
- defaults shown for settings not yet changed
- a visible Demo or Production label for the install

---

## 13. Non-Functional Requirements

### NFR-SPIMS-001: Data Integrity

SPIMS must keep each stock balance equal to the total of its stock movements, prevent negative stock,
prevent duplicate part numbers and badge enrollments, and prevent any stock balance change that is not
recorded as a stock movement.

### NFR-SPIMS-002: Calculation Accuracy

Quantities must be stored and calculated to three decimal places without rounding error. Consumption
and depletion figures must follow the formulas in FR-SPIMS-014, and daily, weekly, and monthly periods
must use the install's time zone.

### NFR-SPIMS-003: Auditability

Every stock movement, authorization, configuration change, and report export must be traceable to a
user, a date and time, and a reason where required.

### NFR-SPIMS-004: Security and Access Control

SPIMS must require sign-in, enforce role-based access, temporarily lock an account for 15 minutes after
repeated failed sign-ins so a locked account cannot stop a shift for long,
use encrypted connections, protect and mask badge identifiers, and prevent direct database access from
browsers.

### NFR-SPIMS-005: Reliability

Every transaction and count posting must be recorded completely or not at all. Two withdrawals of the
same spare part confirmed at the same time, including from two stations, must not withdraw more than
the available stock.

### NFR-SPIMS-006: Performance

- Withdrawal confirmation of up to 10 lines completes in under 500 milliseconds at the 95th percentile, measured at the server.
- Part number lookup responds in under 150 milliseconds at the 95th percentile, measured at the server.
- Dashboard and report pages load in under 2 seconds at the 95th percentile.

### NFR-SPIMS-007: Availability and Continuity

Microtech's installs are hosted online on a database tier with no uptime guarantee, and customer
installs may be hosted differently. The storeroom must be able to
continue issuing spare parts during an outage using the paper fallback form, with transactions entered
afterward through FR-SPIMS-012. Draft withdrawals must survive brief connection interruptions. Scheduled
daily activity must keep a free-tier database from pausing, including on the demo install, which may
go unused between customer demonstrations.

### NFR-SPIMS-008: Backup, Recovery, and Retention

SPIMS must back up its database nightly to storage outside the hosting provider, keep 30 daily and 12
monthly backups, and notify System Administrators when a backup fails. Data loss must not exceed 24
hours. A restore must be tested successfully before go-live. Stock movements and audit history are
retained indefinitely.

### NFR-SPIMS-009: Usability

The withdrawal flow must be completable using only the keyboard and badge reader. Screens must remain
readable and operable on the withdrawal station during all shifts.

### NFR-SPIMS-010: Maintainability

Alert thresholds, analysis window, movement class thresholds, identification time limit, backdating
limit, and count tolerance must be configurable by a System Administrator without code changes.
Records must keep their references, status history, and movement history. Differences between
companies must be handled through install settings, not software changes, and every install must run
a released version of the same software.

### NFR-SPIMS-011: Boundary Control

SPIMS must not take on purchasing, supplier payment, inventory valuation, accounting, or maintenance
work order responsibilities.

### NFR-SPIMS-012: Portability

SPIMS must run on any standard PostgreSQL database and on S3-compatible or local file storage, without
depending on features specific to one hosting provider, so an install can be hosted by Microtech or on
a customer's own infrastructure.

### NFR-SPIMS-013: Install Isolation

Each install must have its own database, file storage, users, and settings, and no data may be shared
between installs. A new install must be creatable from an empty database using released software and
install settings only.

---

## 14. Dependencies

### DEP-SPIMS-001: Authentication and Role-Based Access

Required dependency for sign-in, roles, and access control. Provided inside SPIMS by the application
framework's authentication and a role and permission package, not by a separate platform service.

### DEP-SPIMS-002: PostgreSQL Database

Required dependency for storing all SPIMS records. Each install needs its own PostgreSQL database.

Microtech's production and demo installs use Supabase Postgres on the free tier. The free tier allows
2 active projects, so these two installs use the whole allowance; any further environment on the same
account needs a paid plan. The tier has no automated backups, pauses a project after a week of low
activity, has no uptime guarantee, and limits each database to 500 MB. NFR-SPIMS-007 and NFR-SPIMS-008
address these limits. Expected data growth is about 6 MB per year at 50 withdrawal lines per day.

The database for each customer install is chosen with that customer (Appendix A, Q17).

### DEP-SPIMS-003: Application Hosting

Required dependency for running the SPIMS web application, scheduled nightly jobs, and background
exports. Hosting for Microtech's installs is pending (Appendix A, Q15); hosting for each customer install is
decided with that customer (Appendix A, Q17).

### DEP-SPIMS-004: Withdrawal Station Hardware

Required dependency for withdrawal processing at each production install. Includes the workstation, ID/RFID badge reader, UPS, and
network connection at the storeroom. A badge reader that acts as a keyboard is recommended because it
needs no drivers.

### DEP-SPIMS-005: Personnel ID Badges

Required dependency for personnel identification. Every person authorized to withdraw spare parts needs
an enrolled badge. Badge technology is pending (Appendix A, Q1). If badges are purchased new, 13.56 MHz
MIFARE DESFire badges are recommended.

### DEP-SPIMS-006: Email Delivery Service

Optional dependency for daily summaries and administrator notifications. SPIMS operates without it.

### DEP-SPIMS-007: Off-Platform Backup Storage

Required dependency for nightly backups. Must be outside the database hosting provider. Destination is
pending (Appendix A, Q3).

### DEP-SPIMS-008: Spare Parts Master List

Required dependency for go-live of each production install. Each company's own list of spare parts and
quantities is the source for FR-SPIMS-005 in that company's install. Its data quality determines Phase
1 effort. The starting list for Microtech's production install is pending (Appendix A,
Q18). The Excel list prepared for the customer is not imported into any Microtech install; its use is
parked (Appendix A, Q19).

### DEP-SPIMS-009: Personnel and Equipment Lists

Required dependency for go-live of each production install. Personnel lists are needed for badge enrollment, and an equipment list
is needed for the equipment register, which every withdrawal requires.

### DEP-SPIMS-010: File Storage

Optional dependency for personnel photos and prepared report exports.

---

## 15. Risks

### RSK-SPIMS-001: Service Unavailability at a 24/7 Storeroom

SPIMS is hosted online on a tier with no uptime guarantee. When the internet connection or hosting
service is unavailable, withdrawals cannot be confirmed in SPIMS, possibly during an equipment
breakdown.

Mitigation:

- keep numbered paper fallback forms at the withdrawal station
- enter paper transactions afterward as backdated entries (FR-SPIMS-012)
- keep Draft withdrawals through brief connection interruptions
- train every custodian shift on the fallback procedure
- evaluate an offline-capable withdrawal station as a future consideration

### RSK-SPIMS-002: Badge Scan Treated as Access Control

The proposal states that personnel identification will prevent unauthorized withdrawal. A badge scan
records who received spare parts; it does not stop a person from presenting someone else's badge.
Common 125 kHz proximity badges can also be copied with inexpensive equipment.

Mitigation:

- describe the badge scan as accountability and traceability in client-facing material
- display the person's name and photo at the scan for the custodian to check
- recommend 13.56 MHz MIFARE DESFire badges if badges are purchased new
- review Personnel Withdrawal History (Report G) for unusual patterns

### RSK-SPIMS-003: Data Loss on the Free Hosting Tier

The free database tier used by Microtech's installs has no automated backups. A database failure or a
mistaken change could lose inventory history.

Mitigation:

- run nightly backups to storage outside the hosting provider (NFR-SPIMS-008)
- notify System Administrators when a backup fails
- test a restore before go-live
- move to a paid tier with automated backups if the restore test shows an unacceptable recovery point

### RSK-SPIMS-004: Stock Balance Inaccuracy

Recording errors, unrecorded withdrawals, or poor opening data may cause stock balances to differ from
what is on the shelves.

Mitigation:

- change stock balances only through stock movements
- require reasons for adjustments, overrides, and reversals
- run physical counts and post differences (FR-SPIMS-013)
- verify stock balances against movement history nightly
- complete a full physical verification count before go-live

### RSK-SPIMS-005: System Bypassed Under Time Pressure

If SPIMS blocks an urgent withdrawal because the recorded balance is wrong or the person has no badge,
spare parts may be issued without being recorded.

Mitigation:

- provide supervisor override for insufficient recorded stock (FR-SPIMS-008)
- provide supervisor-authorized manual identification (FR-SPIMS-007)
- confirm that a supervisor can be reached on every shift, including nights (Appendix A, Q11)
- send supervisors a daily exceptions summary, including backdated withdrawals, which are identified from a paper signature rather than a badge scan
- review the Exceptions Report weekly
- track override and manual identification rates against Section 2.3

### RSK-SPIMS-006: Poor Quality of Opening Data

The existing Excel list may contain duplicate or inconsistent part numbers and outdated quantities.

Mitigation:

- consolidate part numbers and descriptions during Phase 1
- block imports with errors and show a preview before confirming (FR-SPIMS-005)
- complete a full physical verification count before go-live
- run SPIMS alongside the paper process for two weeks before go-live

### RSK-SPIMS-007: Loss of Trust in Alerts and Projections

Repeated alerts, or depletion projections based on little history, may lead users to ignore alerts and
reports.

Mitigation:

- raise each alert once and resolve it automatically
- show only one stock level alert per spare part
- send a daily summary instead of an email per alert
- show data confidence and hide projections without history
- tune thresholds during the continuous improvement phase

### RSK-SPIMS-008: Ownership Overlap with Purchasing and Accounting

Replenishment alerts and unit costs may pull SPIMS toward purchase orders or inventory valuation.

Mitigation:

- store supplier name, delivery reference, and unit cost as references only
- keep purchase orders outside SPIMS
- keep purchase requisitions and inventory valuation as future considerations

### RSK-SPIMS-009: Overbuilding Too Early

An offline station, forecasting, or multiple storage locations may delay the MVP.

Mitigation:

- keep the MVP to the proposal's scope and the gaps identified in this document
- move advanced capabilities to future considerations
- keep workflow out of SPIMS

### RSK-SPIMS-010: Customer-Specific Changes Split the Product

Changes made for one customer in that customer's copy of the software would make installs drift apart
until they can no longer be updated together.

Mitigation:

- keep one set of software and one release line for all installs
- handle differences between companies through install settings (FR-SPIMS-018)
- add a customer's request to the product for every install, or decline it
- do not keep separate software copies per customer
- limit install settings to what genuinely differs between companies

### RSK-SPIMS-011: Customer Hosting and Security Requirements

Large customers may require hosting on their own infrastructure or an approved cloud provider, a
security review, or local data storage, and may not accept a free hosting tier or third-party storage
of their employees' names, photos, and badge identifiers.

Mitigation:

- keep SPIMS portable to any PostgreSQL database and file storage (NFR-SPIMS-012)
- agree hosting, backups, and data ownership with each customer during Phase 1
- keep personnel photos optional
- handle personnel data in line with the Philippine Data Privacy Act of 2012

### RSK-SPIMS-012: Demo Data Mixed with Real Data

Sample data loaded into a production install, or a company's real data entered into a demo install,
would corrupt real stock records or expose them during demonstrations.

Mitigation:

- keep demo and production as separate installs with separate databases
- allow sample data only in installs designated as demo at setup (FR-SPIMS-019)
- show a Demo label on every screen and exported report of a demo install
- never import a company's real data into a demo install

---

## 16. Future Considerations

- offline-capable withdrawal station
- return of unused spare parts to stock as a separate transaction type
- reorder point calculated from lead time and consumption
- safety stock calculated from consumption variability
- purchase requisitions generated from replenishment alerts
- multiple storage locations per spare part and transfers between them
- manufacturer and alternate part numbers
- barcode or QR labels on spare parts
- inventory valuation
- supplier master and supplier lead time tracking
- integration with maintenance management or ERP systems
- scheduled report emails
- consumption forecasting
- mobile app for supervisors
- tools to update and monitor many customer installs from one place

---

## 17. AI Generation Notes

When generating or updating this PRD, AI agents must:

- preserve this heading hierarchy
- use only one H1 title
- keep SPIMS positioned as the owner of spare part records, stock balances, stock movements, and withdrawal accountability
- avoid turning SPIMS into a purchasing, accounting, valuation, or maintenance system
- distinguish the Inventory Custodian, who signs in, from Requesting Personnel, who are identified by badge and have no account
- keep the badge scan as the final step before withdrawal confirmation
- never describe a stock balance change without a stock movement
- never allow negative stock or editing of Completed transactions
- keep Average Monthly Consumption = Total Quantity Withdrawn ÷ Number of Months as the baseline calculation
- keep critical level, a quantity, separate from criticality, a classification
- describe the badge scan as accountability, not as prevention of unauthorized access
- keep workflow out of SPIMS; supervisor authorization is the only approval mechanism
- keep implementation detail in the System Design document, not in this PRD
- keep this PRD and the System Design document consistent; when one changes, update the other
- keep the software free of company-specific values; differences between companies belong in install settings
- treat each install as serving one company; do not add company scoping to records
- never load sample data outside a demo install
- use Kora terminology consistently

---

## Appendix A: Open Questions

The current focus is Microtech's production and demo installs. Customer installs are parked until the
customer's details are confirmed.

None of these questions blocks software development. The Blocks column shows what each one holds up.
Where a question is unanswered, SPIMS uses the default described in the referenced section.

### A.1 Microtech Installs

| # | Question | Answered By | Blocks | Affects |
|---|---|---|---|---|
| Q1 | Will existing Microtech employee IDs be used as badges? If so, are they 125 kHz proximity, 13.56 MHz MIFARE, or barcode? | Microtech IT | Badge reader purchase | DEP-SPIMS-004, DEP-SPIMS-005, RSK-SPIMS-002 |
| Q2 | How often will physical counts be done, and at what scope? | Microtech storeroom | Nothing | FR-SPIMS-013 |
| Q3 | Where will nightly backups be stored, and who holds access? | Microtech IT | Microtech go-live | NFR-SPIMS-008, DEP-SPIMS-007 |
| Q4 | Who receives the daily alert and exception summaries? Are scheduled report emails wanted? | Microtech management | Nothing | Section 10 |
| Q5 | Is the proposal's purpose list final for Microtech, or should it match existing maintenance work-type codes? | Microtech maintenance | Nothing; the default list is used until answered | FR-SPIMS-006, FR-SPIMS-018 |
| Q6 | Does an equipment list exist to import, or will it be built during Phase 1? | Microtech maintenance | Microtech go-live; withdrawals require equipment | FR-SPIMS-003, DEP-SPIMS-009 |
| Q7 | How many shifts and custodians are there? | Microtech storeroom | Nothing | User setup, training |
| Q8 | Are the definitions of reorder point, minimum level, and critical level in FR-SPIMS-002 correct? If minimum level and reorder point mean the same thing, the Low Stock and Replenishment alerts should be merged. | Microtech / Engr. Agaya | Nothing; the FR-SPIMS-002 definitions are used until answered | FR-SPIMS-002, FR-SPIMS-015 |
| Q9 | Is a Replenishment Report wanted? It is in the proposal's Section 3B but not its Section 7. | Engr. Agaya | Nothing | Section 11.2 |
| Q10 | Is a spare part typically used on more than one machine? | Microtech maintenance | Nothing | FR-SPIMS-003 |
| Q11 | Can a supervisor be reached on every shift, including nights? If not, who authorizes overrides and manual identification? | Microtech storeroom | Microtech go-live | FR-SPIMS-007, FR-SPIMS-008, RSK-SPIMS-005 |
| Q12 | Is supervisor-authorized manual identification acceptable when a person has no badge or the reader fails? | Microtech | Nothing | FR-SPIMS-007 |
| Q13 | How are unused spare parts returned to the storeroom today, and how often? If often, Return to Stock should move into the MVP. | Microtech storeroom | Nothing | Section 3.2, Section 16 |
| Q14 | Should Critical Stock alerts send an email immediately rather than in the daily summary? | Microtech management | Nothing | Section 10 |
| Q15 | Where will the SPIMS application for Microtech's production and demo installs be hosted? | Engineering / Microtech IT | Deployment | DEP-SPIMS-003 |
| Q16 | Are the success measure targets in Section 2.3 acceptable? | Microtech management | Nothing | Section 2.3 |
| Q18 | Which spare parts list will Microtech's production install start from? The Excel list prepared for the customer is not imported into any Microtech install. | Microtech storeroom | Microtech go-live | FR-SPIMS-005, DEP-SPIMS-008, RSK-SPIMS-012 |

Until Q13 is answered, a return of unused spare parts is handled by reversing the original withdrawal
and recording the withdrawal again with the quantity actually used.

### A.2 Customer Installs — Parked

| # | Question | Answered By | Blocks | Affects |
|---|---|---|---|---|
| Q17 | Will customer installs be hosted by Microtech by default, or on each customer's own infrastructure? | Microtech management | First customer install | DEP-SPIMS-002, DEP-SPIMS-003, RSK-SPIMS-011 |
| Q19 | How will the Excel spare parts list prepared for the customer be used, and which other details does the customer still need to confirm? | Microtech / customer | First customer install | FR-SPIMS-005, DEP-SPIMS-008 |

For each customer install, Q1, Q2, Q5, Q6, Q7, Q10, Q11, and Q13 must be answered again by that
customer.

---

## Appendix B: Proposal Traceability

| Proposal Section | Requirement | PRD Reference |
|---|---|---|
| 1 | 24/7 Inventory Custodian, ID/RFID identification, automatic recording | Sections 1.3, 4; FR-SPIMS-006, FR-SPIMS-007 |
| 2 | Twelve objectives | Section 2; FR-SPIMS-001 to FR-SPIMS-017 |
| 3A | Custodian responsibilities | Section 4; FR-SPIMS-007, FR-SPIMS-009, FR-SPIMS-013 |
| 3B | Twelve database fields | FR-SPIMS-001, FR-SPIMS-002, FR-SPIMS-003, FR-SPIMS-014, FR-SPIMS-016 |
| 3B | Nineteen software functions | FR-SPIMS-001 to FR-SPIMS-017; Sections 10, 11 |
| 3B | Inventory withdrawal and replenishment update | FR-SPIMS-007, FR-SPIMS-009 |
| 3B | Audit trail with inventory balance | FR-SPIMS-016; Section 9 |
| 3C | ID/RFID scanner for identification, scanned last | FR-SPIMS-007 |
| 4 | Withdrawal Steps 1 to 4 | FR-SPIMS-006 |
| 4 | Withdrawal Steps 5 to 8 | FR-SPIMS-007 |
| 4 | Step 3 purpose list | FR-SPIMS-006 |
| 4 | Step 8 transaction record fields | FR-SPIMS-007 |
| 5 | Consumption and depletion analysis | FR-SPIMS-014 |
| 5 | Minimum level, maximum level, safety stock, reorder point, recommended replenishment quantity | FR-SPIMS-002, FR-SPIMS-014; calculated safety stock in Section 16 |
| 6 | Five system alerts | FR-SPIMS-015 |
| 7 | Reports A to H | Section 11.2 |
| 8 | Hardware and resources | DEP-SPIMS-004, DEP-SPIMS-005 |
| 9 | System architecture flow | FR-SPIMS-006, FR-SPIMS-007, FR-SPIMS-014, FR-SPIMS-015 |
| 10 | Expected benefits | Section 2 |
| 11 | Implementation phases | System Design, Section 14 |

### Additions Beyond the Proposal

| Addition | Reason |
|---|---|
| Physical inventory count process (FR-SPIMS-013) | The proposal's Inventory Discrepancy Alert has no process that produces it |
| Insufficient stock override (FR-SPIMS-008) | Prevents spare parts being issued unrecorded when the recorded balance is wrong |
| Manual personnel identification (FR-SPIMS-007) | Prevents spare parts being issued unrecorded when a badge is missing or the reader fails |
| Backdated entry (FR-SPIMS-012) | Keeps the storeroom operating during an outage of an online system |
| Transaction reversal (FR-SPIMS-011) | Corrects errors without editing or deleting history |
| Excel import (FR-SPIMS-005) | Existing spare parts data is in Excel |
| Stock level definitions (FR-SPIMS-002) | The proposal uses minimum level and reorder point without distinguishing them |
| Part-to-equipment classification (FR-SPIMS-003) | Proposal Section 3B lists Equipment/application as a spare part field |
| Replenishment Report (Report I) | Required by proposal Section 3B but missing from Section 7 |
| Exceptions Report (Report J) | One place for supervisors to review every exception |
| Data confidence on projections (FR-SPIMS-014) | Projections from little history are unreliable |
| One stock level alert per spare part (FR-SPIMS-015) | Prevents three alerts for one spare part |
| Unit of measure and decimal quantities (FR-SPIMS-001) | Spare parts such as oil and cable are not counted in pieces |
| Install settings (FR-SPIMS-018) | SPIMS is a Microtech product installed separately for each company |
| Sample data for demo installs (FR-SPIMS-019) | Microtech demonstrates SPIMS to customers |
