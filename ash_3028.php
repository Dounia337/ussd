<?php

/**
 * index.php  (Gateway-Compatible Version)
 * Ashesi University Meal Plan USSD System.
 *
 * ── Why the previous version did not work ───────────────────────────────────
 *
 *  The gateway this system runs on sends and expects a DIFFERENT format
 *  from Africa's Talking (which the previous version was written for).
 *
 *  GATEWAY CONTRACT (matches the class trial/demo code):
 *
 *  INPUT  (POST fields the gateway sends to this script):
 *    msisdn      → the caller's phone number
 *    sequenceID  → unique session identifier  (used as session key)
 *    data        → the single input the user just typed
 *    network     → network code (MTN, Vodafone, etc.)
 *
 *  OUTPUT (JSON this script must echo back):
 *    {
 *      "msisdn"      : "233XXXXXXXXX",
 *      "sequenceID"  : "abc123",
 *      "timestamp"   : "20240411120000",
 *      "message"     : "Your screen text here",
 *      "continueFlag": 0   ← 0 = show screen and wait  (was "CON ")
 *                          ← 1 = show screen and end   (was "END ")
 *    }
 *
 * ── What changed from the Africa's Talking version ──────────────────────────
 *  OLD header : Content-Type: text/plain
 *  NEW header : Content-Type: application/json
 *
 *  OLD input  : $_POST['sessionId'], $_POST['phoneNumber'], $_POST['text']
 *  NEW input  : $_POST['sequenceID'], $_POST['msisdn'],     $_POST['data']
 *
 *  OLD session key : $sessionId   (from 'sessionId' POST field)
 *  NEW session key : $sequenceID  (from 'sequenceID' POST field)
 *
 *  OLD $data extraction : end(explode('*', $_POST['text']))
 *  NEW $data            : $_POST['data']  directly (gateway sends only latest input)
 *
 *  OLD response : echo "CON Welcome...\n1. Option"
 *  NEW response : echo json_encode(['message'=>'Welcome...\n1. Option', 'continueFlag'=>0])
 *
 *  OLD end     : echo "END Thank you"
 *  NEW end     : echo json_encode(['message'=>'Thank you', 'continueFlag'=>1])
 *
 * ── Everything else is identical ────────────────────────────────────────────
 *  Session table, step logic, T1/T2/T3 fields, all business rules,
 *  and every screen message are unchanged.
 * ────────────────────────────────────────────────────────────────────────────
 */

require_once 'db.php';

error_reporting(0);
date_default_timezone_set('GMT');

// ── FIXED: gateway expects JSON, not plain text ──────────────────────────────
header('Content-Type: application/json');


// ════════════════════════════════════════════════════════════════════════════
// SECTION 1 — READ GATEWAY INPUT PARAMETERS
//
// These field names MUST match what the gateway actually POSTs.
// Mismatched names cause empty variables and broken session logic.
// ════════════════════════════════════════════════════════════════════════════

// ── FIXED: was $_POST['sessionId']   → correct field is 'sequenceID' ────────
$sequenceID = isset($_POST['sequenceID']) ? trim($_POST['sequenceID']) : '';

// ── FIXED: was $_POST['phoneNumber'] → correct field is 'msisdn' ────────────
$msisdn     = isset($_POST['msisdn'])     ? trim($_POST['msisdn'])     : '';

// ── FIXED: was $_POST['text'] with explode/end() → gateway sends 'data' directly
// The gateway sends only the latest user input — no accumulation, no splitting needed.
$data       = isset($_POST['data'])       ? trim($_POST['data'])       : '';

$network    = isset($_POST['network'])    ? trim($_POST['network'])    : '';
$timestamp  = date('YmdHis');

// Use sequenceID as the session key throughout (was: $sessionId)
$sessionKey = $sequenceID;


// ════════════════════════════════════════════════════════════════════════════
// SECTION 2 — RESPONSE HELPERS
//
// Replaces the "CON "/"END " prefix pattern with JSON + continueFlag.
// Call respond() instead of setting $response and echoing at the end.
// ════════════════════════════════════════════════════════════════════════════

/**
 * respond()
 * Builds and echoes the JSON response the gateway expects, then exits.
 *
 * @param  string $message       Screen text to display to the user
 * @param  int    $continueFlag  0 = keep session open (was CON)
 *                               1 = close session     (was END)
 * @param  string $msisdn        Caller's phone number (echoed back)
 * @param  string $sequenceID    Session ID (echoed back)
 * @param  string $timestamp     Request timestamp
 */
function respond($message, $continueFlag, $msisdn, $sequenceID, $timestamp)
{
    // ── FIXED: was  echo $response  (plain text)
    // ── NOW:   echo json_encode(...)
    echo json_encode([
        'msisdn'       => (string) $msisdn,
        'sequenceID'   => (string) $sequenceID,
        'timestamp'    => (string) $timestamp,
        'message'      => (string) $message,
        'continueFlag' => (int)    $continueFlag,
    ]);
    exit();
}

// Shorthand constants for continueFlag values — improve readability
define('CONTINUE_SESSION', 0);   // was: "CON " prefix
define('END_SESSION',      1);   // was: "END " prefix


// ════════════════════════════════════════════════════════════════════════════
// SECTION 3 — SESSION MANAGEMENT FUNCTIONS  (class-demo style, unchanged)
// ════════════════════════════════════════════════════════════════════════════

/**
 * sessionManager()
 * Returns current_step from ussd_sessions for $sessionKey.
 * Returns 0 if no row exists (new session).
 */
function sessionManager($sessionKey)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT current_step
         FROM   ussd_sessions
         WHERE  session_id = ?
         LIMIT  1"
    );
    $stmt->bind_param('s', $sessionKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $row ? (int) $row['current_step'] : 0;
}

/**
 * createSession()
 * Inserts a new session row at step 0.
 */
function createSession($sessionKey)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO ussd_sessions (session_id, current_step)
         VALUES (?, 0)"
    );
    $stmt->bind_param('s', $sessionKey);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * advanceSession()
 * Increments current_step by 1 and optionally writes a T-column value.
 */
function advanceSession($sessionKey, $tCol = null, $tValue = null)
{
    $allowed = ['T1', 'T2', 'T3'];
    $conn    = getDBConnection();

    if ($tCol !== null && in_array($tCol, $allowed, true)) {
        $sql  = "UPDATE ussd_sessions
                 SET    current_step = current_step + 1,
                        `{$tCol}`    = ?
                 WHERE  session_id   = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $tValue, $sessionKey);
    } else {
        $stmt = $conn->prepare(
            "UPDATE ussd_sessions
             SET    current_step = current_step + 1
             WHERE  session_id   = ?"
        );
        $stmt->bind_param('s', $sessionKey);
    }

    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * setStudentOnSession()
 * Writes the validated student_id to the session row.
 */
function setStudentOnSession($sessionKey, $studentId)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "UPDATE ussd_sessions
         SET    student_id = ?
         WHERE  session_id = ?"
    );
    $stmt->bind_param('ss', $studentId, $sessionKey);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * getSessionField()
 * Reads one field from the session row (student_id, T1, T2, or T3).
 */
function getSessionField($sessionKey, $field)
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
    $stmt->bind_param('s', $sessionKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $row ? $row[$field] : null;
}

/**
 * clearSession()
 * Deletes the session row. Called before every END response.
 */
function clearSession($sessionKey)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "DELETE FROM ussd_sessions
         WHERE  session_id = ?"
    );
    $stmt->bind_param('s', $sessionKey);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}


// ════════════════════════════════════════════════════════════════════════════
// SECTION 4 — STUDENT / BUSINESS LOGIC FUNCTIONS  (completely unchanged)
// ════════════════════════════════════════════════════════════════════════════

function validateStudentID($studentId)
{
    if (!preg_match('/^\d{8}$/', $studentId)) {
        return false;
    }
    $yearGroup = (int) substr($studentId, 4, 4);
    return ($yearGroup >= 2020 && $yearGroup <= 2035);
}

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

function formatGHS($amount)
{
    return 'GHS ' . number_format((float) $amount, 2);
}


// ════════════════════════════════════════════════════════════════════════════
// SECTION 5 — MAIN USSD FLOW
//
// Flow control is driven by current_step from ussd_sessions (unchanged).
// The only change inside each case is how the response is sent:
//   OLD: $response = "CON ..."  then  echo $response
//   NEW: respond("...", CONTINUE_SESSION, ...)
//
//   OLD: $response = "END ..."  then  clearSession()  then  echo $response
//   NEW: clearSession()  then  respond("...", END_SESSION, ...)
// ════════════════════════════════════════════════════════════════════════════

$step = sessionManager($sessionKey);

switch ($step) {

    // ════════════════════════════════════════════════════════════════════════
    // STEP 0 — New session: show welcome screen
    // ════════════════════════════════════════════════════════════════════════
    case 0:
        createSession($sessionKey);
        advanceSession($sessionKey);

        // ── FIXED: was  $response = "CON Welcome..."  echo $response
        respond(
            "Welcome to Ashesi Meal Plan\r\n" .
            "--------------------------------\r\n" .
            "Please enter your Student ID:",
            CONTINUE_SESSION,   // 0 — keep session open
            $msisdn, $sequenceID, $timestamp
        );
        break;


    // ════════════════════════════════════════════════════════════════════════
    // STEP 1 — Student ID received
    // ════════════════════════════════════════════════════════════════════════
    case 1:
        $studentId = $data;

        if (!validateStudentID($studentId)) {
            clearSession($sessionKey);
            // ── FIXED: was  $response = "END ..."  echo $response
            respond(
                "Invalid Student ID.\r\n" .
                "ID must be 8 digits.\r\n" .
                "Last 4 digits = year group.\r\n" .
                "Please dial again.",
                END_SESSION,   // 1 — close session
                $msisdn, $sequenceID, $timestamp
            );
            break;
        }

        $student = getStudent($studentId);

        if (!$student) {
            clearSession($sessionKey);
            respond(
                "Student ID not found.\r\n" .
                "Please contact the Registrar\r\n" .
                "or dial again with a valid ID.",
                END_SESSION,
                $msisdn, $sequenceID, $timestamp
            );
            break;
        }

        setStudentOnSession($sessionKey, $studentId);
        advanceSession($sessionKey);

        respond(
            "Welcome, " . $student['name'] . "!\r\n" .
            "================================\r\n" .
            "MAIN MENU\r\n" .
            "--------------------------------\r\n" .
            "1. Check Balance\r\n" .
            "2. Request PIN\r\n" .
            "3. Change PIN\r\n" .
            "4. Top Up Meal Plan\r\n" .
            "0. Exit",
            CONTINUE_SESSION,
            $msisdn, $sequenceID, $timestamp
        );
        break;


    // ════════════════════════════════════════════════════════════════════════
    // STEP 2 — Main menu choice received
    // ════════════════════════════════════════════════════════════════════════
    case 2:
        $studentId  = getSessionField($sessionKey, 'student_id');
        $menuChoice = $data;

        $student = getStudent($studentId);

        if (!$student) {
            clearSession($sessionKey);
            respond(
                "Session expired. Please dial again.",
                END_SESSION,
                $msisdn, $sequenceID, $timestamp
            );
            break;
        }

        switch ($menuChoice) {

            case '1':   // Check Balance
                clearSession($sessionKey);
                respond(
                    "Meal Plan Balance\r\n" .
                    "================================\r\n" .
                    "Name  : " . $student['name'] . "\r\n" .
                    "ID    : " . $student['student_id'] . "\r\n" .
                    "--------------------------------\r\n" .
                    "Total : " . formatGHS($student['total_balance']) . "\r\n" .
                    "Daily : " . formatGHS($student['daily_balance']) . "\r\n" .
                    "--------------------------------\r\n" .
                    "Daily balance resets at midnight.",
                    END_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;

            case '2':   // Request PIN
                $pin = generateAndStorePIN($studentId);
                clearSession($sessionKey);
                respond(
                    "PIN Request Successful!\r\n" .
                    "================================\r\n" .
                    "Your PIN : " . $pin . "\r\n" .
                    "Expires  : Midnight today\r\n" .
                    "--------------------------------\r\n" .
                    "Keep this PIN private.\r\n" .
                    "Do not share with anyone.",
                    END_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;

            case '3':   // Change PIN — prompt for current PIN
                advanceSession($sessionKey, 'T1', '3');
                respond(
                    "Change PIN\r\n" .
                    "--------------------------------\r\n" .
                    "Enter your current 4-digit PIN:",
                    CONTINUE_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;

            case '4':   // Top Up — prompt for amount
                advanceSession($sessionKey, 'T1', '4');
                respond(
                    "Top Up Meal Plan\r\n" .
                    "--------------------------------\r\n" .
                    "Current Balance:\r\n" .
                    formatGHS($student['total_balance']) . "\r\n" .
                    "--------------------------------\r\n" .
                    "Enter amount to top up (GHS):\r\n" .
                    "(Min: 10 | Max: 5,000)",
                    CONTINUE_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;

            case '0':   // Exit
                clearSession($sessionKey);
                respond(
                    "Thank you, " . $student['name'] . "!\r\n" .
                    "Have a great day at Ashesi.",
                    END_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;

            default:
                clearSession($sessionKey);
                respond(
                    "Invalid option selected.\r\n" .
                    "Please dial again and choose\r\n" .
                    "a valid menu option.",
                    END_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;
        }
        break;


    // ════════════════════════════════════════════════════════════════════════
    // STEP 3 — First sub-screen input
    // T1='3' → Change PIN (current PIN entered)
    // T1='4' → Top Up (amount entered)
    // ════════════════════════════════════════════════════════════════════════
    case 3:
        $studentId  = getSessionField($sessionKey, 'student_id');
        $menuChoice = getSessionField($sessionKey, 'T1');
        $input3     = $data;

        $student = getStudent($studentId);

        if (!$student) {
            clearSession($sessionKey);
            respond(
                "Session expired. Please dial again.",
                END_SESSION,
                $msisdn, $sequenceID, $timestamp
            );
            break;
        }

        switch ($menuChoice) {

            case '3':   // Change PIN: validate current PIN
                if (!preg_match('/^\d{4}$/', $input3)) {
                    clearSession($sessionKey);
                    respond(
                        "Invalid PIN format.\r\n" .
                        "PIN must be exactly 4 digits.\r\n" .
                        "Please dial again.",
                        END_SESSION,
                        $msisdn, $sequenceID, $timestamp
                    );
                    break;
                }

                if (!validatePIN($studentId, $input3)) {
                    clearSession($sessionKey);
                    respond(
                        "Incorrect PIN or PIN expired.\r\n" .
                        "Please request a new PIN first\r\n" .
                        "then try again.",
                        END_SESSION,
                        $msisdn, $sequenceID, $timestamp
                    );
                    break;
                }

                advanceSession($sessionKey, 'T2', $input3);
                respond(
                    "PIN verified successfully.\r\n" .
                    "--------------------------------\r\n" .
                    "Enter your new 4-digit PIN:",
                    CONTINUE_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;

            case '4':   // Top Up: validate amount
                if (!is_numeric($input3)) {
                    clearSession($sessionKey);
                    respond(
                        "Invalid amount entered.\r\n" .
                        "Please enter a numeric amount\r\n" .
                        "and dial again.",
                        END_SESSION,
                        $msisdn, $sequenceID, $timestamp
                    );
                    break;
                }

                $amount = (float) $input3;

                if ($amount < 10) {
                    clearSession($sessionKey);
                    respond(
                        "Amount too low.\r\n" .
                        "Minimum top-up is GHS 10.00.\r\n" .
                        "Please dial again.",
                        END_SESSION,
                        $msisdn, $sequenceID, $timestamp
                    );
                    break;
                }

                if ($amount > 5000) {
                    clearSession($sessionKey);
                    respond(
                        "Amount too high.\r\n" .
                        "Maximum top-up is GHS 5,000.\r\n" .
                        "Please dial again.",
                        END_SESSION,
                        $msisdn, $sequenceID, $timestamp
                    );
                    break;
                }

                advanceSession($sessionKey, 'T2', (string) $amount);

                $newBalance = (float) $student['total_balance'] + $amount;
                respond(
                    "Confirm Top Up\r\n" .
                    "================================\r\n" .
                    "Amount  : " . formatGHS($amount) . "\r\n" .
                    "Current : " . formatGHS($student['total_balance']) . "\r\n" .
                    "After   : " . formatGHS($newBalance) . "\r\n" .
                    "--------------------------------\r\n" .
                    "1. Confirm\r\n" .
                    "2. Cancel",
                    CONTINUE_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;

            default:
                clearSession($sessionKey);
                respond(
                    "Invalid session state. Please dial again.",
                    END_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;
        }
        break;


    // ════════════════════════════════════════════════════════════════════════
    // STEP 4 — Second sub-screen input
    // T1='3' → Change PIN (new PIN entered — check it differs, prompt confirm)
    // T1='4' → Top Up (confirmation choice: 1=yes, 2=cancel)
    // ════════════════════════════════════════════════════════════════════════
    case 4:
        $studentId  = getSessionField($sessionKey, 'student_id');
        $menuChoice = getSessionField($sessionKey, 'T1');
        $input3     = getSessionField($sessionKey, 'T2');
        $input4     = $data;

        $student = getStudent($studentId);

        if (!$student) {
            clearSession($sessionKey);
            respond(
                "Session expired. Please dial again.",
                END_SESSION,
                $msisdn, $sequenceID, $timestamp
            );
            break;
        }

        switch ($menuChoice) {

            case '3':   // Change PIN: new PIN submitted
                if (!preg_match('/^\d{4}$/', $input4)) {
                    clearSession($sessionKey);
                    respond(
                        "Invalid PIN format.\r\n" .
                        "New PIN must be exactly 4 digits.\r\n" .
                        "Please dial again.",
                        END_SESSION,
                        $msisdn, $sequenceID, $timestamp
                    );
                    break;
                }

                if ($input4 === $input3) {
                    clearSession($sessionKey);
                    respond(
                        "New PIN cannot be the same\r\n" .
                        "as your current PIN.\r\n" .
                        "Please dial again with a\r\n" .
                        "different PIN.",
                        END_SESSION,
                        $msisdn, $sequenceID, $timestamp
                    );
                    break;
                }

                advanceSession($sessionKey, 'T3', $input4);
                respond(
                    "Confirm New PIN\r\n" .
                    "--------------------------------\r\n" .
                    "Re-enter new PIN to confirm:",
                    CONTINUE_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;

            case '4':   // Top Up: process confirmation
                $amount  = (float) $input3;
                $confirm = $input4;

                if ($confirm === '1') {
                    topUpBalance($studentId, $amount);
                    $newBalance = (float) $student['total_balance'] + $amount;
                    clearSession($sessionKey);
                    respond(
                        "Top Up Successful!\r\n" .
                        "================================\r\n" .
                        "Name    : " . $student['name'] . "\r\n" .
                        "Added   : " . formatGHS($amount) . "\r\n" .
                        "Balance : " . formatGHS($newBalance) . "\r\n" .
                        "--------------------------------\r\n" .
                        "Payment simulated. No real\r\n" .
                        "funds were transferred.\r\n" .
                        "Thank you!",
                        END_SESSION,
                        $msisdn, $sequenceID, $timestamp
                    );

                } elseif ($confirm === '2') {
                    clearSession($sessionKey);
                    respond(
                        "Top Up Cancelled.\r\n" .
                        "Your balance remains unchanged.\r\n" .
                        "Dial again to restart.",
                        END_SESSION,
                        $msisdn, $sequenceID, $timestamp
                    );

                } else {
                    clearSession($sessionKey);
                    respond(
                        "Invalid option.\r\n" .
                        "Top Up has been cancelled.\r\n" .
                        "Please dial again.",
                        END_SESSION,
                        $msisdn, $sequenceID, $timestamp
                    );
                }
                break;

            default:
                clearSession($sessionKey);
                respond(
                    "Invalid session state. Please dial again.",
                    END_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;
        }
        break;


    // ════════════════════════════════════════════════════════════════════════
    // STEP 5 — PIN confirmation (Change PIN final step)
    // T1='3'  T2=currentPIN  T3=newPIN  $data=confirmPIN
    // ════════════════════════════════════════════════════════════════════════
    case 5:
        $studentId  = getSessionField($sessionKey, 'student_id');
        $menuChoice = getSessionField($sessionKey, 'T1');
        $newPin     = getSessionField($sessionKey, 'T3');
        $confirmPin = $data;

        $student = getStudent($studentId);

        if (!$student) {
            clearSession($sessionKey);
            respond(
                "Session expired. Please dial again.",
                END_SESSION,
                $msisdn, $sequenceID, $timestamp
            );
            break;
        }

        switch ($menuChoice) {

            case '3':
                if (!preg_match('/^\d{4}$/', $confirmPin)) {
                    clearSession($sessionKey);
                    respond(
                        "Invalid PIN format.\r\n" .
                        "Confirmation PIN must be 4 digits.\r\n" .
                        "Please dial again.",
                        END_SESSION,
                        $msisdn, $sequenceID, $timestamp
                    );
                    break;
                }

                if ($newPin !== $confirmPin) {
                    clearSession($sessionKey);
                    respond(
                        "PINs do not match.\r\n" .
                        "Please dial again and enter\r\n" .
                        "the same PIN in both steps.",
                        END_SESSION,
                        $msisdn, $sequenceID, $timestamp
                    );
                    break;
                }

                updatePIN($studentId, $newPin);
                clearSession($sessionKey);
                respond(
                    "PIN Changed Successfully!\r\n" .
                    "================================\r\n" .
                    "Your PIN has been updated.\r\n" .
                    "New PIN expires at midnight.\r\n" .
                    "--------------------------------\r\n" .
                    "Thank you, " . $student['name'] . "!",
                    END_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;

            default:
                clearSession($sessionKey);
                respond(
                    "Invalid session state. Please dial again.",
                    END_SESSION,
                    $msisdn, $sequenceID, $timestamp
                );
                break;
        }
        break;


    // ════════════════════════════════════════════════════════════════════════
    // DEFAULT — Unexpected step
    // ════════════════════════════════════════════════════════════════════════
    default:
        clearSession($sessionKey);
        respond(
            "Session limit reached.\r\n" .
            "Please dial again to start over.",
            END_SESSION,
            $msisdn, $sequenceID, $timestamp
        );
        break;
        }