# Ashesi University Meal Plan — USSD Application

A server-side USSD application that lets Ashesi University students manage their meal plan account — check balance, request a PIN, change their PIN, and top up — entirely over a basic mobile network with no smartphone or internet required.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Thought Process & Design Decisions](#2-thought-process--design-decisions)
3. [Architecture](#3-architecture)
4. [USSD Flow — Step by Step](#4-ussd-flow--step-by-step)
5. [Database Schema](#5-database-schema)
6. [Code Walkthrough](#6-code-walkthrough)
7. [Security Practices](#7-security-practices)
8. [Setup & Deployment](#8-setup--deployment)
9. [Verifying the Seed Data](#9-verifying-the-seed-data)
10. [File Reference](#10-file-reference)

---

## 1. Project Overview

| Item | Detail |
|---|---|
| Language | PHP (procedural) |
| Database | MySQL  |
| Protocol | USSD (Unstructured Supplementary Service Data) |
| Gateway interface | HTTP POST — JSON response |
| Entry point | `ash_3028.php` |
| DB setup | `database.sql` |
| DB connection | `db.php` |

The system handles the full USSD session lifecycle: it receives each user keystroke as an HTTP POST from the mobile gateway, persists the conversation state across requests in a `ussd_sessions` table, applies business rules, and replies with a JSON payload the gateway renders on the user's screen.

---

## 2. Thought Process & Design Decisions

### 2.1 Context — Replicating the Existing Ashesi Meal Plan System

Ashesi University already runs an online meal plan system. Students use it to check their balance, manage their PIN, and top up their account. This project is a **USSD replica** of that system, built as a lab assignment for the Mobile Applications course. The core business rules — 4-digit PIN, daily balance of GHS 90.00, PIN expiring at midnight, and the top-up flow — are not invented here; they mirror what the real system already does. The task was to faithfully translate those rules into a USSD interface.

### 2.2 Stateless HTTP, Stateful Conversation

Each keystroke the user makes generates a **new, independent HTTP POST** to the server. The USSD gateway does not maintain any application state — it just forwards the latest input and a `sequenceID` that identifies the ongoing session.

The core design challenge is therefore: *how do you run a multi-step dialogue when every message arrives as a fresh HTTP request?*

**Solution chosen — database-backed session store.**

A `ussd_sessions` table acts as a finite-state machine. Every row tracks:
- Which step the dialogue is currently on (`current_step`)
- What the user entered in previous steps (`T1`, `T2`, `T3`)
- Which student is authenticated (`student_id`)

This was preferred over:
- **PHP sessions / cookies** — unreliable with a gateway intermediary; the gateway does not forward cookies.
- **Encoding state in the `data` string** — brittle, exposes internals, and USSD `data` payloads are short.
- **In-memory/Redis** — adds infrastructure complexity for a project of this scale.

### 2.3 Step Numbering

Steps are numbered `0–5` and map directly to the screens a user sees:

| Step | Screen shown |
|---|---|
| 0 | Welcome — enter Student ID |
| 1 | Student ID just entered — show Main Menu |
| 2 | Main menu choice just entered — branch to sub-flow |
| 3 | First sub-input (current PIN or top-up amount) |
| 4 | Second sub-input (new PIN or top-up confirmation) |
| 5 | PIN confirmation (Change PIN only) |

This numbering was chosen so `current_step` always represents *"what the user just told us"*, and the switch-case logic reads as *"given that we are at step N, process the input and advance"*.

### 2.4 T1 / T2 / T3 Slots

Because multiple flows share the same step numbers, the session row carries three generic string slots (`T1`, `T2`, `T3`) whose meaning depends on the flow:

| Slot | Change PIN flow | Top Up flow |
|---|---|---|
| T1 | `'3'` (menu choice) | `'4'` (menu choice) |
| T2 | Current (old) PIN | Amount entered |
| T3 | New PIN (before confirmation) | — |

Storing the menu choice in `T1` was the key insight: at step 3, 4, and 5 the code reads `T1` to know which flow it is in, then interprets the remaining input accordingly. This avoids duplicating step logic into separate tables or URL parameters.

### 2.5 PIN Behaviour

The PIN rules are carried over directly from the real Ashesi meal plan system:
- **4 digits** — the same format students already use in the existing online system.
- **Day-scoped** — the real system issues a PIN that is only valid on the day it is requested; this is replicated here by setting `pin_expiry` to `23:59:59` on the generation date.
- **Regenerated on request** — just like the online system, there is no persistent PIN; the student requests one each time they need it.

The one implementation detail introduced here is storing the PIN as `VARCHAR(4)` rather than an integer, to preserve leading zeros (e.g. `0042`).

### 2.6 Daily Balance Reset

The daily balance of **GHS 90.00** that resets at midnight is a rule from the real Ashesi meal plan system. The implementation decision made here was *how* to apply that reset: rather than running a nightly cron job, the reset is applied **lazily at read time** inside `resetDailyBalanceIfNeeded()`. When `getStudent()` is called, it compares `last_reset_date` with `CURDATE()`. If they differ, it updates `daily_balance` to `90.00` and stamps today's date.

This approach was chosen because:
- No background process to configure or monitor.
- No risk of a cron running during peak load.
- The reset happens the first time a student interacts after midnight — accurate and self-contained.

### 2.7 Prepared Statements Everywhere

Every single database query uses MySQLi prepared statements with bound parameters. This was a non-negotiable design rule to eliminate SQL injection — the most critical attack vector for a system that accepts raw user input through a public phone network.

### 2.8 Session Cleanup on Every END

Before sending any `END_SESSION` response (whether success, error, or exit), the code always calls `clearSession($sessionKey)` to delete the row. This keeps the `ussd_sessions` table lean and prevents stale rows from accumulating.

### 2.9 `continueFlag` Constants

```php
define('CONTINUE_SESSION', 0);
define('END_SESSION',      1);
```

These named constants replace magic numbers in the `respond()` calls, making the intent explicit at every branch point.

---

## 3. Architecture

```
Mobile Handset
     │  dials *899*3028#  /  enters digits
     ▼
USSD Gateway  ──── HTTP POST ────►  ash_3028.php
(Telecom)           sequenceID           │
                    msisdn               │  reads/writes
                    data                 ▼
                    network         MySQL Database
                                   ┌─────────────────┐
◄─── JSON response ────────────────┤  students        │
     message                       │  ussd_sessions   │
     continueFlag (0/1)            └─────────────────┘
     sequenceID
     msisdn
```

---

## 4. USSD Flow — Step by Step

### Dial-in (Step 0)
```
User dials *899*3028#

Screen:
  Welcome to Ashesi Meal Plan
  Please enter your Student ID:
```

### Student ID Entry (Step 1)
```
User enters: 12342027

Validation:
  • Must be exactly 8 digits
  • Last 4 digits must be a year between 2020–2035
  • Must exist in the students table

On success → Main Menu
On failure → END with error message
```

### Main Menu (Step 2)
```
Welcome, Kwame Asante!
MAIN MENU
1. Check Balance
2. Request PIN
3. Change PIN
4. Top Up Meal Plan
0. Exit
```

### Option 1 — Check Balance
```
Meal Plan Balance
Name  : Kwame Asante
ID    : 12342027
Total : GHS 10,000.00
Daily : GHS 90.00
Daily balance resets at midnight.
[Session ends]
```

### Option 2 — Request PIN
```
PIN Request Successful!
Your PIN : 4821
Expires  : Midnight today
Keep this PIN private.
Do not share with anyone.
[Session ends]
```
PIN is generated server-side (`rand(0,9999)` padded to 4 digits), stored in the database, and displayed once on-screen.

### Option 3 — Change PIN (Steps 3 → 4 → 5)
```
Step 3:  Enter your current 4-digit PIN:
         [user enters 4821]

Step 4:  PIN verified successfully.
         Enter your new 4-digit PIN:
         [user enters 1234]

Step 5:  Confirm New PIN
         Re-enter new PIN to confirm:
         [user enters 1234]

Result:  PIN Changed Successfully!
         New PIN expires at midnight.
[Session ends]
```
Validations applied: format check (exactly 4 digits), expiry check, old ≠ new, new = confirmation.

### Option 4 — Top Up Meal Plan (Steps 3 → 4)
```
Step 3:  Current Balance: GHS 10,000.00
         Enter amount to top up (GHS):
         (Min: 10 | Max: 5,000)
         [user enters 500]

Step 4:  Confirm Top Up
         Amount  : GHS 500.00
         Current : GHS 10,000.00
         After   : GHS 10,500.00
         1. Confirm
         2. Cancel
         [user enters 1]

Result:  Top Up Successful!
         Added   : GHS 500.00
         Balance : GHS 10,500.00
[Session ends]
```

### Option 0 — Exit
```
Thank you, Kwame Asante!
Have a great day at Ashesi.
[Session ends]
```

---

## 5. Database Schema

### `students`

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | Internal primary key |
| `student_id` | `VARCHAR(8) UNIQUE` | 8-digit ID: first 4 random, last 4 = year group |
| `name` | `VARCHAR(100)` | Display name |
| `total_balance` | `DECIMAL(10,2)` | Lifetime wallet balance (net of top-ups) |
| `daily_balance` | `DECIMAL(10,2)` | Resets to 90.00 GHS at midnight each day |
| `last_reset_date` | `DATE` | Compared to `CURDATE()` to trigger lazy reset |
| `pin` | `VARCHAR(4) NULL` | Current PIN; NULL if never requested |
| `pin_expiry` | `DATETIME NULL` | `YYYY-MM-DD 23:59:59`; NULL if no PIN |

`VARCHAR(4)` for `pin` is deliberate — it preserves leading zeros (e.g., `0042`).

### `ussd_sessions`

| Column | Type | Notes |
|---|---|---|
| `session_id` | `VARCHAR(100) PK` | Gateway-supplied `sequenceID` |
| `student_id` | `VARCHAR(8) NULL` | Set after authentication |
| `current_step` | `TINYINT UNSIGNED` | 0–5; drives the main switch-case |
| `T1` | `VARCHAR(10) NULL` | Menu choice (`'1'`–`'4'`) |
| `T2` | `VARCHAR(20) NULL` | Current PIN or top-up amount |
| `T3` | `VARCHAR(20) NULL` | New PIN (Change PIN flow only) |
| `created_at` | `DATETIME` | Auto-set on insert |
| `updated_at` | `DATETIME` | Auto-updated on every write |

Sessions are deleted (not soft-deleted) when a terminal response is sent.

---

## 6. Code Walkthrough

### `db.php`

Defines four constants (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`) and exposes a single function `getDBConnection()`. If the connection fails, it outputs a plain-text `END` message so the USSD gateway always gets a valid (if error) response rather than a PHP fatal.

Connection charset is set to `utf8mb4` to support the full Unicode range.

### `ash_3028.php` — Structure

```
Section 1 — Read POST parameters
Section 2 — respond() helper + CONTINUE/END constants
Section 3 — Session management (create / advance / read / clear)
Section 4 — Business logic (validate ID, get student, reset daily balance,
             validate PIN, generate PIN, update PIN, top up, format GHS)
Section 5 — Main switch(step) — the USSD state machine
```

#### Key functions

| Function | Purpose |
|---|---|
| `respond()` | Builds and echoes the JSON payload, then `exit()`s |
| `sessionManager()` | Returns `current_step` for a session (0 if new) |
| `createSession()` | `INSERT IGNORE` — safe to call on every step-0 hit |
| `advanceSession()` | `current_step++` and optionally writes a T-slot |
| `setStudentOnSession()` | Writes validated `student_id` to the session row |
| `getSessionField()` | Reads one whitelisted field (`student_id`, `T1`, `T2`, `T3`) |
| `clearSession()` | Deletes the session row before every END response |
| `validateStudentID()` | Regex + year-range check (format only, no DB hit) |
| `getStudent()` | Fetches student row and triggers lazy daily-reset |
| `resetDailyBalanceIfNeeded()` | Compares `last_reset_date` with today; resets if stale |
| `validatePIN()` | Checks PIN match and expiry in one query |
| `generateAndStorePIN()` | `rand(0,9999)` padded to 4 digits; sets expiry to midnight |
| `updatePIN()` | Writes new PIN + midnight expiry after Change PIN flow |
| `topUpBalance()` | `total_balance = total_balance + ?` atomic add |
| `formatGHS()` | `GHS 1,234.56` display formatting |

---

## 7. Security Practices

| Practice | Where applied |
|---|---|
| Prepared statements | Every SQL query in all functions |
| Field whitelist | `getSessionField()` and `advanceSession()` reject non-allowlisted column names |
| Input validation | Student ID: regex `/^\d{8}$/` + year range; PIN: `/^\d{4}$/`; amount: `is_numeric()` + min/max bounds |
| PIN expiry | Every PIN expires at `23:59:59` on the day it is generated |
| Session teardown | `clearSession()` called before **every** `END_SESSION` response |
| No sensitive data in URL | All input arrives via `$_POST`; nothing is in query strings |
| Error suppression | `error_reporting(0)` prevents stack traces leaking to the gateway |

> **Note:** `db.php` currently contains plaintext database credentials. In a production deployment these should be moved to environment variables or a config file outside the web root and excluded from version control.

---

## 8. Setup & Deployment

### Requirements

- PHP 7.4+ with MySQLi extension
- MySQL 5.7+ or MariaDB 10.3+
- A USSD gateway that can POST to an HTTP endpoint

### Steps

1. **Create the database** (the name in `db.php` is `mobileapps_2026B_deubaybe_dounia`):
   ```sql
   CREATE DATABASE mobileapps_2026B_deubaybe_dounia
     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. **Run the schema and seed script:**
   ```bash
   mysql -u <user> -p mobileapps_2026B_deubaybe_dounia < database.sql
   ```

3. **Update credentials** in `db.php` to match your environment.

4. **Place files on a PHP-capable web server** (Apache / Nginx) accessible by your USSD gateway.

5. **Configure the gateway** to POST to `ash_3028.php` with these fields:

   | POST field | Description |
   |---|---|
   | `sequenceID` | Unique session identifier |
   | `msisdn` | Caller's phone number |
   | `data` | Latest digit(s) the user entered |
   | `network` | Network operator code |

6. **Expected JSON response format:**
   ```json
   {
     "msisdn":       "233201234567",
     "sequenceID":   "abc123",
     "timestamp":    "20260412103045",
     "message":      "Welcome to Ashesi Meal Plan\r\nPlease enter your Student ID:",
     "continueFlag": 0
   }
   ```
   `continueFlag: 0` = keep session alive; `continueFlag: 1` = terminate session.

---

## 9. Verifying the Seed Data

After running `database.sql`, use these queries to confirm the data is correct.

### View all students
```sql
SELECT
    student_id,
    name,
    CONCAT('GHS ', FORMAT(total_balance, 2))  AS total_balance,
    CONCAT('GHS ', FORMAT(daily_balance, 2))  AS daily_balance,
    last_reset_date,
    IFNULL(pin, '(none)')                     AS pin,
    IFNULL(pin_expiry, '(none)')              AS pin_expiry
FROM students
ORDER BY id;
```

Expected rows:

| student_id | name | total_balance | daily_balance | pin | pin_expiry |
|---|---|---|---|---|---|
| 12342027 | Kwame Asante | GHS 10,000.00 | GHS 90.00 | 4821 | today 23:59:59 |
| 56782026 | Ama Boateng | GHS 4,500.50 | GHS 90.00 | (none) | (none) |
| 87652025 | Kofi Mensah | GHS 320.75 | GHS 90.00 | 1193 | yesterday 23:59:59 |
| 43212028 | Abena Owusu | GHS 1,200.00 | GHS 90.00 | (none) | (none) |
| 99102027 | Yaw Darko | GHS 25,000.00 | GHS 90.00 | 7654 | today 23:59:59 |

### Check which students have active (non-expired) PINs
```sql
SELECT student_id, name, pin, pin_expiry
FROM   students
WHERE  pin IS NOT NULL
  AND  pin_expiry >= NOW();
```

### Check which students have expired PINs (should trigger "Request a new PIN" message)
```sql
SELECT student_id, name, pin, pin_expiry
FROM   students
WHERE  pin IS NOT NULL
  AND  pin_expiry < NOW();
```
*Kofi Mensah (87652025) should appear here — his PIN expiry is set to yesterday.*

### Check which students need a daily balance reset (last_reset_date < today)
```sql
SELECT student_id, name, daily_balance, last_reset_date
FROM   students
WHERE  last_reset_date < CURDATE();
```
*On the day you run the script, Kwame, Ama, Kofi, and Yaw should appear (last_reset_date = yesterday). Abena's was set to CURDATE() so she does not appear.*

### Confirm the ussd_sessions table is empty at start
```sql
SELECT COUNT(*) AS active_sessions FROM ussd_sessions;
```

### Inspect a live session mid-flow (useful during testing)
```sql
SELECT
    session_id,
    student_id,
    current_step,
    T1, T2, T3,
    created_at,
    updated_at
FROM ussd_sessions
ORDER BY created_at DESC
LIMIT 10;
```

---

## 10. File Reference

| File | Purpose |
|---|---|
| [ash_3028.php](ash_3028.php) | Main USSD application — gateway entry point, state machine, all business logic |
| [db.php](db.php) | Database connection factory (`getDBConnection()`) |
| [database.sql](database.sql) | Schema creation (`students`, `ussd_sessions`) and 5 demo student seed rows |

---

*Built for the Ashesi University Mobile Applications course — demonstrates USSD session management, lazy daily-reset patterns, and day-scoped PIN security over a stateless HTTP gateway interface.*

---

## AI Assistance Declaration

Claude (claude.ai / Claude Code by Anthropic) was used as an AI assistant during the development of this project. Specifically, it helped with:

- Structuring and writing the PHP session management functions
- Drafting and reviewing SQL schema and seed data
- Writing and formatting this README document
- Suggesting security practices (prepared statements, field whitelisting, input validation patterns)

All design decisions, the overall flow logic, and the business rules were defined by the student based on the course lab requirements and the existing Ashesi meal plan system. AI-generated code and text were reviewed, tested, and adapted before use.
