CREATE TABLE students (

    -- Primary key
    id              INT UNSIGNED        NOT NULL AUTO_INCREMENT,

    -- Student identifier: exactly 8 digits.
    -- First 4  = random number assigned at enrollment.
    -- Last  4  = year group (e.g. 2026, 2027).
    student_id      VARCHAR(8)          NOT NULL,

    -- Full display name
    name            VARCHAR(100)        NOT NULL,

    -- Meal plan wallet total (accumulated over all top-ups, net of deductions)
    total_balance   DECIMAL(10, 2)      NOT NULL DEFAULT 0.00,

    -- Daily allowance: resets to 90.00 GHS at midnight each day
    daily_balance   DECIMAL(10, 2)      NOT NULL DEFAULT 90.00,

    -- The date daily_balance was last reset (YYYY-MM-DD).
    -- Compared with CURDATE() to determine whether a reset is due.
    last_reset_date DATE                NOT NULL DEFAULT (CURDATE()),

    -- 4-digit PIN stored as a VARCHAR to preserve leading zeros (e.g. "0042")
    pin             VARCHAR(4)              NULL DEFAULT NULL,

    -- PIN is valid from generation until 23:59:59 on the same day.
    -- NULL means no PIN has been generated yet.
    pin_expiry      DATETIME                NULL DEFAULT NULL,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_id (student_id)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Ashesi University Meal Plan — student accounts';

CREATE TABLE ussd_sessions (
 
    -- The unique session ID supplied by the USSD gateway on every request.
    -- Used as the primary key — no auto-increment needed.
    session_id      VARCHAR(100)    NOT NULL,
 
    -- The student_id entered by the user at step 1.
    -- NULL until the student has been validated.
    student_id      VARCHAR(8)          NULL DEFAULT NULL,
 
    -- Tracks which step of the dialogue the user is currently on.
    --   0 → fresh session  (welcome screen shown)
    --   1 → student ID entered and validated; main menu shown
    --   2 → main menu choice stored; sub-screen shown
    --   3 → first sub-input stored (current PIN  OR  top-up amount)
    --   4 → second sub-input stored (new PIN  OR  top-up confirmation)
    -- Session is deleted when an END response is sent.
    current_step    TINYINT UNSIGNED NOT NULL DEFAULT 0,
 
    -- T1: stores the main menu choice (1, 2, 3, or 4).
    -- Set at step 2, read at steps 3, 4, and 5.
    T1              VARCHAR(10)         NULL DEFAULT NULL,
 
    -- T2: stores the first sub-screen input.
    --   For Change PIN  → the user's current (old) PIN
    --   For Top Up      → the top-up amount as entered
    -- Set at step 3, read at steps 4 and 5.
    T2              VARCHAR(20)         NULL DEFAULT NULL,
 
    -- T3: stores the second sub-screen input.
    --   For Change PIN  → the user's proposed new PIN
    -- Set at step 4, read at step 5.
    T3              VARCHAR(20)         NULL DEFAULT NULL,
 
    -- Timestamps for debugging and expiry management.
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,
 
    PRIMARY KEY (session_id)
 
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Tracks per-request USSD session state for the Meal Plan system';
 

-- ── 4. Insert 5 demo students 
--  Format:  first-4 random | last-4 year-group
--  IDs used:
--    12342027  →  Kwame Asante          (Class of 2027)
--    56782026  →  Ama Boateng           (Class of 2026)
--    87652025  →  Kofi Mensah           (Class of 2025)
--    43212028  →  Abena Owusu           (Class of 2028)
--    99102027  →  Yaw Darko             (Class of 2027)
--
--  last_reset_date is set to yesterday so the first call
--  triggers a daily balance reset to 90.00 (demonstrates the
--  reset logic without manual intervention).

INSERT INTO students
    (student_id, name, total_balance, daily_balance, last_reset_date, pin, pin_expiry)
VALUES

    -- Student 1: Kwame — healthy balance, active PIN
    (
        '12342027',
        'Kwame Asante',
        10000.00,
        90.00,
        DATE_SUB(CURDATE(), INTERVAL 1 DAY),   -- triggers reset on first call
        '4821',
        CONCAT(CURDATE(), ' 23:59:59')
    ),

    -- Student 2: Ama — mid-range balance, no PIN yet
    (
        '56782026',
        'Ama Boateng',
        4500.50,
        90.00,
        DATE_SUB(CURDATE(), INTERVAL 1 DAY),
        NULL,
        NULL
    ),

    -- Student 3: Kofi — low balance (nearly depleted), expired PIN
    (
        '87652025',
        'Kofi Mensah',
        320.75,
        90.00,
        DATE_SUB(CURDATE(), INTERVAL 1 DAY),
        '1193',
        DATE_SUB(NOW(), INTERVAL 1 DAY)        -- expired yesterday — tests expiry logic
    ),

    -- Student 4: Abena — fresh student, just enrolled, no PIN
    (
        '43212028',
        'Abena Owusu',
        1200.00,
        90.00,
        CURDATE(),                              -- reset already done today
        NULL,
        NULL
    ),

    -- Student 5: Yaw — high balance power user, active PIN
    (
        '99102027',
        'Yaw Darko',
        25000.00,
        90.00,
        DATE_SUB(CURDATE(), INTERVAL 1 DAY),
        '7654',
        CONCAT(CURDATE(), ' 23:59:59')
    );


-- ── 5. Verify inserted data 
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