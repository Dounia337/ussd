<?php

/**
 * index.php  (Session-Refactored)
 * Ashesi University Meal Plan USSD System.
 *
 * ── What changed from the text-level version ────────────────────────────────
 *  OLD: switch ($level)  where $level = count(explode('*', $text))
 *  NEW: switch ($step)   where $step  = sessionManager($sessionId)
 *
 *  The accumulated `text` string is only used to extract the single latest
 *  user input ($data = last element after splitting on '*').
 *  Flow control is driven entirely by the `current_step` stored in the
 *  `ussd_sessions` DB table, keyed on $sessionId.
 *
 * ── Session field mapping ────────────────────────────────────────────────────
 *  current_step  │ Meaning
 *  ─────────────────────────────────────────────────────────────────────────
 *       0        │ New session – welcome screen shown, step advanced to 1
 *       1        │ Student ID received – validated, main menu shown
 *       2        │ Main menu choice stored in T1 – sub-screen shown
 *       3        │ First sub-input stored in T2 – next prompt shown
 *       4        │ Second sub-input stored in T3 – final confirmation shown
 *  (deleted)     │ Session row cleared after every END response
 *
 *  T1  = main menu choice     ('1' | '2' | '3' | '4')
 *  T2  = 1st sub-input        (current PIN  OR  top-up amount)
 *  T3  = 2nd sub-input        (new PIN candidate)
 * ────────────────────────────────────────────────────────────────────────────
 */

require_once 'db.php';

error_reporting(0);
date_default_timezone_set('GMT');
header('Content-Type: text/plain');


// ════════════════════════════════════════════════════════════════════════════
// SECTION 1 — READ GATEWAY INPUT PARAMETERS
// ════════════════════════════════════════════════════════════════════════════

$sessionId   = isset($_POST['sessionId'])   ? trim($_POST['sessionId'])   : '';
$serviceCode = isset($_POST['serviceCode']) ? trim($_POST['serviceCode']) : '';
$phoneNumber = isset($_POST['phoneNumber']) ? trim($_POST['phoneNumber']) : '';
$text        = isset($_POST['text'])        ? trim($_POST['text'])        : '';

// ── Extract only the latest single user input ────────────────────────────────
// `text` from Africa's Talking accumulates all inputs separated by '*'.
// We split ONLY to grab the last entry — the input the user just typed.
// The array length is NOT used to determine step (that is done via DB below).
$textArray = ($text === '') ? [] : explode('*', $text);
$data      = !empty($textArray) ? end($textArray) : '';   // current user input


// ════════════════════════════════════════════════════════════════════════════
// SECTION 2 — SESSION MANAGEMENT FUNCTIONS  (class-demo style)
// ════════════════════════════════════════════════════════════════════════════

/**
 * sessionManager()
 * Looks up the ussd_sessions record for $sessionId and returns current_step.
 * Returns 0 if no record exists — i.e., this is a brand-new session.
 *
 * Mirrors the class demo's sessionManager(), which returned the count of
 * filled T-columns. Here we store the step number explicitly in the DB.
 *
 * @param  string $sessionId  — unique ID provided by the USSD gateway
 * @return int    current_step (0 = no session / new)
 */
function sessionManager($sessionId)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT current_step
         FROM   ussd_sessions
         WHERE  session_id = ?
         LIMIT  1"
    );
    $stmt->bind_param('s', $sessionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    // No record → treat as step 0 (fresh dial)
    return $row ? (int) $row['current_step'] : 0;
}

/**
 * createSession()
 * Inserts a new row in ussd_sessions at step 0 for this $sessionId.
 * Uses INSERT IGNORE so a duplicate dial never causes an error.
 * Mirrors the class demo's IdentifyUser().
 *
 * @param  string $sessionId
 * @return void
 */
function createSession($sessionId)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO ussd_sessions (session_id, current_step)
         VALUES (?, 0)"
    );
    $stmt->bind_param('s', $sessionId);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * advanceSession()
 * Increments current_step by 1 and optionally writes a value into T1, T2, or T3.
 * Called after each valid user input to move the session forward.
 * Mirrors the class demo's UpdateTransactionType() + step counter combined.
 *
 * @param  string      $sessionId
 * @param  string|null $tCol    Which column to populate: 'T1', 'T2', or 'T3'
 * @param  string|null $tValue  The value to store in that column
 * @return void
 */
function advanceSession($sessionId, $tCol = null, $tValue = null)
{
    $allowed = ['T1', 'T2', 'T3'];
    $conn    = getDBConnection();

    if ($tCol !== null && in_array($tCol, $allowed, true)) {
        // Advance step AND store a T-column value in one atomic query
        $sql  = "UPDATE ussd_sessions
                 SET    current_step = current_step + 1,
                        `{$tCol}`    = ?
                 WHERE  session_id   = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $tValue, $sessionId);
    } else {
        // Advance step only (no T-column to update)
        $stmt = $conn->prepare(
            "UPDATE ussd_sessions
             SET    current_step = current_step + 1
             WHERE  session_id   = ?"
        );
        $stmt->bind_param('s', $sessionId);
    }

    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * setStudentOnSession()
 * Stores the validated student_id on the session row.
 * Called once at step 1 so all later steps can read it without re-parsing text.
 *
 * @param  string $sessionId
 * @param  string $studentId
 * @return void
 */
function setStudentOnSession($sessionId, $studentId)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "UPDATE ussd_sessions
         SET    student_id = ?
         WHERE  session_id = ?"
    );
    $stmt->bind_param('ss', $studentId, $sessionId);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * getSessionField()
 * Reads a single named field from the session row.
 * Mirrors the class demo's GetTransactionType().
 *
 * @param  string $sessionId
 * @param  string $field  One of: 'student_id' | 'T1' | 'T2' | 'T3'
 * @return string|null
 */
function getSessionField($sessionId, $field)
{
    $allowed = ['student_id', 'T1', 'T2', 'T3'];

    if (!in_array($field, $allowed, true)) {
        return null;
    }

    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT `{$field}`
         FROM   ussd_sessions
         WHERE  session_id = ?
         LIMIT  1"
    );
    $stmt->bind_param('s', $sessionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $row ? $row[$field] : null;
}

/**
 * clearSession()
 * Deletes the session row for $sessionId from ussd_sessions.
 * Must be called before every END response — matches class demo's clearSession().
 *
 * @param  string $sessionId
 * @return void
 */
function clearSession($sessionId)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "DELETE FROM ussd_sessions
         WHERE  session_id = ?"
    );
    $stmt->bind_param('s', $sessionId);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}


// ════════════════════════════════════════════════════════════════════════════
// SECTION 3 — STUDENT / BUSINESS LOGIC FUNCTIONS  (unchanged from v1)
// ════════════════════════════════════════════════════════════════════════════

/**
 * Validates an 8-digit Ashesi student ID.
 * Last 4 digits must be a year group between 2020 and 2035.
 */
function validateStudentID($studentId)
{
    if (!preg_match('/^\d{8}$/', $studentId)) {
        return false;
    }
    $yearGroup = (int) substr($studentId, 4, 4);
    return ($yearGroup >= 2020 && $yearGroup <= 2035);
}

/**
 * Fetches a student from the DB by student_id.
 * Transparently resets the daily balance if a new day has started.
 */
function getStudent($studentId)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT id, student_id, name, total_balance, daily_balance,
                last_reset_date, pin, pin_expiry
         FROM   students
         WHERE  student_id = ?
         LIMIT  1"
    );
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $result  = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$student) {
        return null;
    }

    return resetDailyBalanceIfNeeded($student);
}

/**
 * Resets daily_balance to 90 GHS if last_reset_date is before today.
 */
function resetDailyBalanceIfNeeded($student)
{
    $today = date('Y-m-d');

    if ($student['last_reset_date'] !== $today) {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "UPDATE students
             SET    daily_balance   = 90.00,
                    last_reset_date = ?
             WHERE  student_id      = ?"
        );
        $stmt->bind_param('ss', $today, $student['student_id']);
        $stmt->execute();
        $stmt->close();
        $conn->close();

        $student['daily_balance']   = '90.00';
        $student['last_reset_date'] = $today;
    }

    return $student;
}

/**
 * Returns true if $pin matches the stored PIN and has not yet expired.
 */
function validatePIN($studentId, $pin)
{
    $conn = getDBConnection();
    $now  = date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "SELECT pin, pin_expiry
         FROM   students
         WHERE  student_id = ?
         LIMIT  1"
    );
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$row)                   { return false; }
    if ($row['pin'] !== $pin)    { return false; }

    if (!empty($row['pin_expiry']) &&
        strtotime($row['pin_expiry']) < strtotime($now)) {
        return false;
    }

    return true;
}

/**
 * Generates a 4-digit PIN, saves it with midnight expiry, returns the PIN.
 * PIN is displayed on-screen because email is not implemented.
 */
function generateAndStorePIN($studentId)
{
    $pin    = str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT);
    $expiry = date('Y-m-d') . ' 23:59:59';

    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "UPDATE students
         SET    pin        = ?,
                pin_expiry = ?
         WHERE  student_id = ?"
    );
    $stmt->bind_param('sss', $pin, $expiry, $studentId);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    return $pin;
}

/**
 * Writes a new PIN (with fresh midnight expiry) to the students table.
 */
function updatePIN($studentId, $newPin)
{
    $expiry = date('Y-m-d') . ' 23:59:59';

    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "UPDATE students
         SET    pin        = ?,
                pin_expiry = ?
         WHERE  student_id = ?"
    );
    $stmt->bind_param('sss', $newPin, $expiry, $studentId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    return $affected > 0;
}

/**
 * Adds $amount to the student's total_balance (simulated payment).
 */
function topUpBalance($studentId, $amount)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "UPDATE students
         SET    total_balance = total_balance + ?
         WHERE  student_id   = ?"
    );
    $stmt->bind_param('ds', $amount, $studentId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    return $affected > 0;
}

/**
 * Formats a GHS amount for USSD display.
 */
function formatGHS($amount)
{
    return 'GHS ' . number_format((float) $amount, 2);
}


// ════════════════════════════════════════════════════════════════════════════
// SECTION 4 — DETERMINE CURRENT SESSION STEP  ← KEY CHANGE
//
// sessionManager() queries ussd_sessions using $sessionId as the key.
// The returned integer drives the switch below.
// This completely replaces: $level = count(explode('*', $text))
// ════════════════════════════════════════════════════════════════════════════

$step     = sessionManager($sessionId);   // ← replaces: $level = count($textArray)
$response = '';


switch ($step) {

    // ════════════════════════════════════════════════════════════════════════
    // STEP 0 — New session: show welcome screen, prompt for Student ID
    // Mirrors demo case 0: create the session row then show welcome
    // ════════════════════════════════════════════════════════════════════════
    case 0:
        createSession($sessionId);    // ← INSERT INTO ussd_sessions
        advanceSession($sessionId);   // ← current_step 0 → 1

        $response  = "Welcome to Ashesi Meal Plan\n";
        $response .= "--------------------------------\n";
        $response .= "Please enter your Student ID:";
        break;


    // ════════════════════════════════════════════════════════════════════════
    // STEP 1 — Student ID received: validate and show main menu
    // $data = the student ID the user just typed
    // ════════════════════════════════════════════════════════════════════════
    case 1:
        $studentId = $data;   // ← replaces: $studentId = $textArray[0]

        if (!validateStudentID($studentId)) {
            $response  = "END Invalid Student ID.\n";
            $response .= "ID must be 8 digits.\n";
            $response .= "Last 4 digits = year group.\n";
            $response .= "Please dial again.";
            clearSession($sessionId);   // ← DELETE FROM ussd_sessions
            break;
        }

        $student = getStudent($studentId);

        if (!$student) {
            $response  = "END Student ID not found.\n";
            $response .= "Please contact the Registrar\n";
            $response .= "or dial again with a valid ID.";
            clearSession($sessionId);   // ← DELETE FROM ussd_sessions
            break;
        }

        // Persist validated student_id on session row for all future steps
        setStudentOnSession($sessionId, $studentId);   // ← UPDATE ussd_sessions SET student_id
        advanceSession($sessionId);                    // ← current_step 1 → 2

        $response  = "Welcome, " . $student['name'] . "!\n";
        $response .= "================================\n";
        $response .= "MAIN MENU\n";
        $response .= "--------------------------------\n";
        $response .= "1. Check Balance\n";
        $response .= "2. Request PIN\n";
        $response .= "3. Change PIN\n";
        $response .= "4. Top Up Meal Plan\n";
        $response .= "0. Exit";
        break;


    // ════════════════════════════════════════════════════════════════════════
    // STEP 2 — Main menu choice received
    // $data = the digit the user pressed (1, 2, 3, 4, or 0)
    // student_id is read from ussd_sessions — not from $text
    // ════════════════════════════════════════════════════════════════════════
    case 2:
        $studentId  = getSessionField($sessionId, 'student_id');   // ← replaces: $textArray[0]
        $menuChoice = $data;                                        // ← replaces: $textArray[1]

        $student = getStudent($studentId);

        if (!$student) {
            $response = "END Session expired. Please dial again.";
            clearSession($sessionId);   // ← DELETE FROM ussd_sessions
            break;
        }

        switch ($menuChoice) {

            // ── 1. Check Balance ─────────────────────────────────────────
            case '1':
                $response  = "END Meal Plan Balance\n";
                $response .= "================================\n";
                $response .= "Name  : " . $student['name'] . "\n";
                $response .= "ID    : " . $student['student_id'] . "\n";
                $response .= "--------------------------------\n";
                $response .= "Total : " . formatGHS($student['total_balance']) . "\n";
                $response .= "Daily : " . formatGHS($student['daily_balance']) . "\n";
                $response .= "--------------------------------\n";
                $response .= "Daily balance resets at midnight.";
                clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                break;

            // ── 2. Request PIN ───────────────────────────────────────────
            case '2':
                $pin = generateAndStorePIN($studentId);
                $response  = "END PIN Request Successful!\n";
                $response .= "================================\n";
                $response .= "Your PIN : " . $pin . "\n";
                $response .= "Expires  : Midnight today\n";
                $response .= "--------------------------------\n";
                $response .= "Keep this PIN private.\n";
                $response .= "Do not share with anyone.";
                clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                break;

            // ── 3. Change PIN — store choice, prompt for current PIN ─────
            case '3':
                // Store T1 = '3' so step 3 knows which branch it is in
                advanceSession($sessionId, 'T1', '3');   // ← UPDATE T1='3', step 2→3

                $response  = "CON Change PIN\n";
                $response .= "--------------------------------\n";
                $response .= "Enter your current 4-digit PIN:";
                break;

            // ── 4. Top Up — store choice, prompt for amount ──────────────
            case '4':
                // Store T1 = '4' so step 3 knows which branch it is in
                advanceSession($sessionId, 'T1', '4');   // ← UPDATE T1='4', step 2→3

                $response  = "CON Top Up Meal Plan\n";
                $response .= "--------------------------------\n";
                $response .= "Current Balance:\n";
                $response .= formatGHS($student['total_balance']) . "\n";
                $response .= "--------------------------------\n";
                $response .= "Enter amount to top up (GHS):\n";
                $response .= "(Min: 10 | Max: 5,000)";
                break;

            // ── 0. Exit ──────────────────────────────────────────────────
            case '0':
                $response  = "END Thank you, " . $student['name'] . "!\n";
                $response .= "Have a great day at Ashesi.";
                clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                break;

            default:
                $response  = "END Invalid option selected.\n";
                $response .= "Please dial again and choose\n";
                $response .= "a valid menu option.";
                clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                break;
        }
        break;


    // ════════════════════════════════════════════════════════════════════════
    // STEP 3 — First sub-screen input received
    // Branch on T1 to know which menu path is active:
    //   T1 = '3' → Change PIN: user entered their current PIN
    //   T1 = '4' → Top Up: user entered the amount to top up
    // ════════════════════════════════════════════════════════════════════════
    case 3:
        $studentId  = getSessionField($sessionId, 'student_id');   // ← replaces: $textArray[0]
        $menuChoice = getSessionField($sessionId, 'T1');            // ← replaces: $textArray[1]
        $input3     = $data;                                        // ← replaces: $textArray[2]

        $student = getStudent($studentId);

        if (!$student) {
            $response = "END Session expired. Please dial again.";
            clearSession($sessionId);   // ← DELETE FROM ussd_sessions
            break;
        }

        switch ($menuChoice) {

            // ── Change PIN: validate current PIN, prompt for new PIN ──────
            case '3':
                if (!preg_match('/^\d{4}$/', $input3)) {
                    $response  = "END Invalid PIN format.\n";
                    $response .= "PIN must be exactly 4 digits.\n";
                    $response .= "Please dial again.";
                    clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                    break;
                }

                if (!validatePIN($studentId, $input3)) {
                    $response  = "END Incorrect PIN or PIN expired.\n";
                    $response .= "Please request a new PIN first\n";
                    $response .= "then try again.";
                    clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                    break;
                }

                // Current PIN verified — store it in T2, advance to step 4
                advanceSession($sessionId, 'T2', $input3);   // ← UPDATE T2=currentPIN, step 3→4

                $response  = "CON PIN verified successfully.\n";
                $response .= "--------------------------------\n";
                $response .= "Enter your new 4-digit PIN:";
                break;

            // ── Top Up: validate amount, show confirmation screen ─────────
            case '4':
                if (!is_numeric($input3)) {
                    $response  = "END Invalid amount entered.\n";
                    $response .= "Please enter a numeric amount\n";
                    $response .= "and dial again.";
                    clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                    break;
                }

                $amount = (float) $input3;

                if ($amount < 10) {
                    $response  = "END Amount too low.\n";
                    $response .= "Minimum top-up is GHS 10.00.\n";
                    $response .= "Please dial again.";
                    clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                    break;
                }

                if ($amount > 5000) {
                    $response  = "END Amount too high.\n";
                    $response .= "Maximum top-up is GHS 5,000.\n";
                    $response .= "Please dial again.";
                    clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                    break;
                }

                // Amount validated — store it in T2, advance to step 4
                advanceSession($sessionId, 'T2', (string) $amount);   // ← UPDATE T2=amount, step 3→4

                $newBalance = (float) $student['total_balance'] + $amount;
                $response   = "CON Confirm Top Up\n";
                $response  .= "================================\n";
                $response  .= "Amount  : " . formatGHS($amount) . "\n";
                $response  .= "Current : " . formatGHS($student['total_balance']) . "\n";
                $response  .= "After   : " . formatGHS($newBalance) . "\n";
                $response  .= "--------------------------------\n";
                $response  .= "1. Confirm\n";
                $response  .= "2. Cancel";
                break;

            default:
                $response = "END Invalid session state. Please dial again.";
                clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                break;
        }
        break;


    // ════════════════════════════════════════════════════════════════════════
    // STEP 4 — Second sub-screen input received
    // Branch on T1:
    //   T1 = '3' → Change PIN: new PIN submitted (validate, prompt confirm)
    //   T1 = '4' → Top Up: confirmation choice entered (1=yes, 2=cancel)
    // T2 is read from session (no $text parsing needed)
    // ════════════════════════════════════════════════════════════════════════
    case 4:
        $studentId  = getSessionField($sessionId, 'student_id');   // ← replaces: $textArray[0]
        $menuChoice = getSessionField($sessionId, 'T1');            // ← replaces: $textArray[1]
        $input3     = getSessionField($sessionId, 'T2');            // ← replaces: $textArray[2]
        $input4     = $data;                                        // ← replaces: $textArray[3]

        $student = getStudent($studentId);

        if (!$student) {
            $response = "END Session expired. Please dial again.";
            clearSession($sessionId);   // ← DELETE FROM ussd_sessions
            break;
        }

        switch ($menuChoice) {

            // ── Change PIN: new PIN received — check it differs, prompt confirm
            case '3':
                if (!preg_match('/^\d{4}$/', $input4)) {
                    $response  = "END Invalid PIN format.\n";
                    $response .= "New PIN must be exactly 4 digits.\n";
                    $response .= "Please dial again.";
                    clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                    break;
                }

                // New PIN must differ from the current PIN (stored in T2)
                if ($input4 === $input3) {
                    $response  = "END New PIN cannot be the same\n";
                    $response .= "as your current PIN.\n";
                    $response .= "Please dial again with a\n";
                    $response .= "different PIN.";
                    clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                    break;
                }

                // Store new PIN candidate in T3, advance to step 5
                advanceSession($sessionId, 'T3', $input4);   // ← UPDATE T3=newPIN, step 4→5

                $response  = "CON Confirm New PIN\n";
                $response .= "--------------------------------\n";
                $response .= "Re-enter new PIN to confirm:";
                break;

            // ── Top Up: process confirmation choice ───────────────────────
            case '4':
                $amount  = (float) $input3;   // ← amount was stored in T2 at step 3
                $confirm = $input4;

                if ($confirm === '1') {
                    topUpBalance($studentId, $amount);
                    $newBalance = (float) $student['total_balance'] + $amount;

                    $response  = "END Top Up Successful!\n";
                    $response .= "================================\n";
                    $response .= "Name    : " . $student['name'] . "\n";
                    $response .= "Added   : " . formatGHS($amount) . "\n";
                    $response .= "Balance : " . formatGHS($newBalance) . "\n";
                    $response .= "--------------------------------\n";
                    $response .= "Payment simulated. No real\n";
                    $response .= "funds were transferred.\n";
                    $response .= "Thank you!";

                } elseif ($confirm === '2') {
                    $response  = "END Top Up Cancelled.\n";
                    $response .= "Your balance remains unchanged.\n";
                    $response .= "Dial again to restart.";

                } else {
                    $response  = "END Invalid option.\n";
                    $response .= "Top Up has been cancelled.\n";
                    $response .= "Please dial again.";
                }

                clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                break;

            default:
                $response = "END Invalid session state. Please dial again.";
                clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                break;
        }
        break;


    // ════════════════════════════════════════════════════════════════════════
    // STEP 5 — PIN confirmation received (Change PIN final step only)
    // T1='3'  T2=currentPIN  T3=newPIN  $data=confirmPIN
    // ════════════════════════════════════════════════════════════════════════
    case 5:
        $studentId  = getSessionField($sessionId, 'student_id');   // ← replaces: $textArray[0]
        $menuChoice = getSessionField($sessionId, 'T1');            // must be '3'
        $newPin     = getSessionField($sessionId, 'T3');            // ← replaces: $textArray[3]
        $confirmPin = $data;                                        // ← replaces: $textArray[4]

        $student = getStudent($studentId);

        if (!$student) {
            $response = "END Session expired. Please dial again.";
            clearSession($sessionId);   // ← DELETE FROM ussd_sessions
            break;
        }

        switch ($menuChoice) {

            // ── Change PIN: match new PIN against its confirmation ─────────
            case '3':
                if (!preg_match('/^\d{4}$/', $confirmPin)) {
                    $response  = "END Invalid PIN format.\n";
                    $response .= "Confirmation PIN must be 4 digits.\n";
                    $response .= "Please dial again.";
                    clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                    break;
                }

                if ($newPin !== $confirmPin) {
                    $response  = "END PINs do not match.\n";
                    $response .= "Please dial again and enter\n";
                    $response .= "the same PIN in both steps.";
                    clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                    break;
                }

                // Both entries match — commit the new PIN to the students table
                updatePIN($studentId, $newPin);

                $response  = "END PIN Changed Successfully!\n";
                $response .= "================================\n";
                $response .= "Your PIN has been updated.\n";
                $response .= "New PIN expires at midnight.\n";
                $response .= "--------------------------------\n";
                $response .= "Thank you, " . $student['name'] . "!";
                clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                break;

            default:
                $response = "END Invalid session state. Please dial again.";
                clearSession($sessionId);   // ← DELETE FROM ussd_sessions
                break;
        }
        break;


    // ════════════════════════════════════════════════════════════════════════
    // DEFAULT — Unexpected step (should never be reached in normal operation)
    // ════════════════════════════════════════════════════════════════════════
    default:
        $response  = "END Session limit reached.\n";
        $response .= "Please dial again to start over.";
        clearSession($sessionId);   // ← DELETE FROM ussd_sessions
        break;
}


// ════════════════════════════════════════════════════════════════════════════
// SECTION 5 — SEND RESPONSE TO GATEWAY
// ════════════════════════════════════════════════════════════════════════════
echo $response;