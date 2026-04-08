<?php

/**
 * ash_3028.php
 * Main USSD endpoint for the Ashesi University Meal Plan System.
 *
 * Follows the structure and logic style of the Ashesi class demo.
 * Uses Africa's Talking-style USSD text parsing with CON/END response format.
 *
 * USSD Input Parameters:
 *   sessionId   - Unique session identifier from the USSD gateway
 *   serviceCode - The USSD service code dialled (e.g. *714*100#)
 *   phoneNumber - The caller's MSISDN
 *   text        - Accumulated user input, levels separated by '*'
 *
 * Flow example:
 *   ""                          → Welcome screen (enter Student ID)
 *   "12342027"                  → Student validated → Main Menu
 *   "12342027*1"                → Check Balance
 *   "12342027*2"                → Request PIN
 *   "12342027*3"                → Change PIN: enter current PIN
 *   "12342027*3*1234"           → Change PIN: enter new PIN
 *   "12342027*3*1234*5678"      → Change PIN: confirm new PIN
 *   "12342027*3*1234*5678*5678" → Change PIN complete
 *   "12342027*4"                → Top Up: enter amount
 *   "12342027*4*500"            → Top Up: confirm screen
 *   "12342027*4*500*1"          → Top Up confirmed
 *   "12342027*4*500*2"          → Top Up cancelled
 */

require_once 'db.php';

error_reporting(0);
date_default_timezone_set('GMT');
header('Content-Type: text/plain');

// ──────────────────────────────────────────────
// 1. READ INPUT PARAMETERS
// ──────────────────────────────────────────────

$sessionId   = isset($_POST['sessionId'])   ? trim($_POST['sessionId'])   : '';
$serviceCode = isset($_POST['serviceCode']) ? trim($_POST['serviceCode']) : '';
$phoneNumber = isset($_POST['phoneNumber']) ? trim($_POST['phoneNumber']) : '';
$text        = isset($_POST['text'])        ? trim($_POST['text'])        : '';

// Parse input levels: split on '*', empty text = level 0
$textArray = ($text === '') ? [] : explode('*', $text);
$level     = count($textArray);


// ──────────────────────────────────────────────
// 2. HELPER FUNCTIONS  (mirroring the demo style)
// ──────────────────────────────────────────────

/**
 * Validates that a student ID is exactly 8 digits and
 * the last 4 digits represent a valid Ashesi year group (2020–2035).
 *
 * @param  string $studentId
 * @return bool
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
 * Retrieves a student record by student_id.
 * Also resets daily balance to 90 GHS if the last reset was before today.
 *
 * @param  string $studentId
 * @return array|null  Associative row or null if not found
 */
function getStudent($studentId)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT id, student_id, name, total_balance, daily_balance, last_reset_date, pin, pin_expiry
         FROM students
         WHERE student_id = ?
         LIMIT 1"
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

    // Transparently reset daily balance if a new calendar day has started
    return resetDailyBalanceIfNeeded($student);
}

/**
 * Resets a student's daily balance to 90 GHS if today differs from last_reset_date.
 * Updates the DB and returns the freshened student array.
 *
 * @param  array $student
 * @return array
 */
function resetDailyBalanceIfNeeded($student)
{
    $today = date('Y-m-d');

    if ($student['last_reset_date'] !== $today) {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "UPDATE students
             SET daily_balance   = 90.00,
                 last_reset_date = ?
             WHERE student_id = ?"
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
 * Checks whether the supplied PIN matches the stored PIN and is not expired.
 *
 * @param  string $studentId
 * @param  string $pin        4-digit string entered by the user
 * @return bool
 */
function validatePIN($studentId, $pin)
{
    $conn = getDBConnection();
    $now  = date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "SELECT pin, pin_expiry
         FROM students
         WHERE student_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$row) {
        return false;
    }

    // PIN must match
    if ($row['pin'] !== $pin) {
        return false;
    }

    // PIN must not be expired
    if (!empty($row['pin_expiry']) && strtotime($row['pin_expiry']) < strtotime($now)) {
        return false;
    }

    return true;
}

/**
 * Generates a random 4-digit PIN, stores it with a midnight expiry, and returns it.
 * Since email is not implemented, the PIN is displayed directly on the USSD screen.
 *
 * @param  string $studentId
 * @return string  The generated 4-digit PIN
 */
function generateAndStorePIN($studentId)
{
    $pin    = str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT);
    $expiry = date('Y-m-d') . ' 23:59:59';   // Valid until midnight today

    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "UPDATE students
         SET pin        = ?,
             pin_expiry = ?
         WHERE student_id = ?"
    );
    $stmt->bind_param('sss', $pin, $expiry, $studentId);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    return $pin;
}

/**
 * Updates the student's PIN to a new value, with a fresh midnight expiry.
 *
 * @param  string $studentId
 * @param  string $newPin
 * @return bool
 */
function updatePIN($studentId, $newPin)
{
    $expiry = date('Y-m-d') . ' 23:59:59';

    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "UPDATE students
         SET pin        = ?,
             pin_expiry = ?
         WHERE student_id = ?"
    );
    $stmt->bind_param('sss', $newPin, $expiry, $studentId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    return $affected > 0;
}

/**
 * Adds the specified amount to the student's total_balance.
 * Simulates a payment gateway top-up (no real payment processed).
 *
 * @param  string $studentId
 * @param  float  $amount
 * @return bool
 */
function topUpBalance($studentId, $amount)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "UPDATE students
         SET total_balance = total_balance + ?
         WHERE student_id = ?"
    );
    $stmt->bind_param('ds', $amount, $studentId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    return $affected > 0;
}

/**
 * Formats a GHS monetary amount for display on the USSD screen.
 *
 * @param  float|string $amount
 * @return string  e.g. "GHS 1,250.00"
 */
function formatGHS($amount)
{
    return 'GHS ' . number_format((float) $amount, 2);
}


// ──────────────────────────────────────────────
// 3. MAIN USSD FLOW   (switch on input level)
// ──────────────────────────────────────────────

$response = '';

switch ($level) {

    // ── LEVEL 0: First dial – no input yet ──────────────────────────────────
    case 0:
        $response  = "CON Welcome to Ashesi Meal Plan\n";
        $response .= "--------------------------------\n";
        $response .= "Please enter your Student ID:";
        break;


    // ── LEVEL 1: Student ID submitted ───────────────────────────────────────
    case 1:
        $studentId = $textArray[0];

        if (!validateStudentID($studentId)) {
            $response  = "END Invalid Student ID.\n";
            $response .= "ID must be 8 digits.\n";
            $response .= "Last 4 digits = year group.\n";
            $response .= "Please dial again.";
            break;
        }

        $student = getStudent($studentId);

        if (!$student) {
            $response  = "END Student ID not found.\n";
            $response .= "Please contact the Registrar\n";
            $response .= "or dial again with a valid ID.";
            break;
        }

        $response  = "CON Welcome, " . $student['name'] . "!\n";
        $response .= "================================\n";
        $response .= "MAIN MENU\n";
        $response .= "--------------------------------\n";
        $response .= "1. Check Balance\n";
        $response .= "2. Request PIN\n";
        $response .= "3. Change PIN\n";
        $response .= "4. Top Up Meal Plan\n";
        $response .= "0. Exit";
        break;


    // ── LEVEL 2: Main menu option selected ─────────────────────────────────
    case 2:
        $studentId  = $textArray[0];
        $menuChoice = $textArray[1];

        // Re-validate the student at every step for security
        if (!validateStudentID($studentId)) {
            $response = "END Session error. Please dial again.";
            break;
        }

        $student = getStudent($studentId);

        if (!$student) {
            $response = "END Session expired. Please dial again.";
            break;
        }

        switch ($menuChoice) {

            // ── 1. Check Balance ──────────────────────────────────────────
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
                break;

            // ── 2. Request PIN ────────────────────────────────────────────
            case '2':
                $pin = generateAndStorePIN($studentId);
                $response  = "END PIN Request Successful!\n";
                $response .= "================================\n";
                $response .= "Your PIN : " . $pin . "\n";
                $response .= "Expires  : Midnight today\n";
                $response .= "--------------------------------\n";
                $response .= "Keep this PIN private.\n";
                $response .= "Do not share with anyone.";
                break;

            // ── 3. Change PIN – prompt for current PIN ────────────────────
            case '3':
                $response  = "CON Change PIN\n";
                $response .= "--------------------------------\n";
                $response .= "Enter your current 4-digit PIN:";
                break;

            // ── 4. Top Up – prompt for amount ─────────────────────────────
            case '4':
                $response  = "CON Top Up Meal Plan\n";
                $response .= "--------------------------------\n";
                $response .= "Current Balance:\n";
                $response .= formatGHS($student['total_balance']) . "\n";
                $response .= "--------------------------------\n";
                $response .= "Enter amount to top up (GHS):\n";
                $response .= "(Min: 10 | Max: 5,000)";
                break;

            // ── 0. Exit ───────────────────────────────────────────────────
            case '0':
                $response  = "END Thank you, " . $student['name'] . "!\n";
                $response .= "Have a great day at Ashesi.";
                break;

            default:
                $response  = "END Invalid option selected.\n";
                $response .= "Please dial again and choose\n";
                $response .= "a valid menu option.";
                break;
        }
        break;


    // ── LEVEL 3: Third input submitted ─────────────────────────────────────
    case 3:
        $studentId  = $textArray[0];
        $menuChoice = $textArray[1];
        $input3     = $textArray[2];

        if (!validateStudentID($studentId)) {
            $response = "END Session error. Please dial again.";
            break;
        }

        $student = getStudent($studentId);

        if (!$student) {
            $response = "END Session expired. Please dial again.";
            break;
        }

        switch ($menuChoice) {

            // ── Change PIN: validate current PIN, prompt for new PIN ──────
            case '3':
                if (!preg_match('/^\d{4}$/', $input3)) {
                    $response  = "END Invalid PIN format.\n";
                    $response .= "PIN must be exactly 4 digits.\n";
                    $response .= "Please dial again.";
                    break;
                }

                if (!validatePIN($studentId, $input3)) {
                    $response  = "END Incorrect PIN or PIN expired.\n";
                    $response .= "Please request a new PIN first\n";
                    $response .= "then try again.";
                    break;
                }

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
                    break;
                }

                $amount = (float) $input3;

                if ($amount < 10) {
                    $response  = "END Amount too low.\n";
                    $response .= "Minimum top-up is GHS 10.00.\n";
                    $response .= "Please dial again.";
                    break;
                }

                if ($amount > 5000) {
                    $response  = "END Amount too high.\n";
                    $response .= "Maximum top-up is GHS 5,000.\n";
                    $response .= "Please dial again.";
                    break;
                }

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
                break;
        }
        break;


    // ── LEVEL 4: Fourth input submitted ────────────────────────────────────
    case 4:
        $studentId  = $textArray[0];
        $menuChoice = $textArray[1];
        $input3     = $textArray[2];
        $input4     = $textArray[3];

        if (!validateStudentID($studentId)) {
            $response = "END Session error. Please dial again.";
            break;
        }

        $student = getStudent($studentId);

        if (!$student) {
            $response = "END Session expired. Please dial again.";
            break;
        }

        switch ($menuChoice) {

            // ── Change PIN: new PIN submitted, prompt to confirm it ───────
            case '3':
                if (!preg_match('/^\d{4}$/', $input4)) {
                    $response  = "END Invalid PIN format.\n";
                    $response .= "New PIN must be exactly 4 digits.\n";
                    $response .= "Please dial again.";
                    break;
                }

                // New PIN must differ from current PIN
                if ($input4 === $input3) {
                    $response  = "END New PIN cannot be the same\n";
                    $response .= "as your current PIN.\n";
                    $response .= "Please dial again with a\n";
                    $response .= "different PIN.";
                    break;
                }

                $response  = "CON Confirm New PIN\n";
                $response .= "--------------------------------\n";
                $response .= "Re-enter new PIN to confirm:";
                break;

            // ── Top Up: process user's confirmation choice ────────────────
            case '4':
                $amount  = (float) $input3;
                $confirm = $input4;

                if ($confirm === '1') {
                    // Process the simulated top-up
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
                break;

            default:
                $response = "END Invalid session state. Please dial again.";
                break;
        }
        break;


    // ── LEVEL 5: Fifth input submitted (Change PIN confirmation) ───────────
    case 5:
        $studentId  = $textArray[0];
        $menuChoice = $textArray[1];
        $input3     = $textArray[2];   // current PIN
        $newPin     = $textArray[3];   // new PIN
        $confirmPin = $textArray[4];   // confirmation of new PIN

        if (!validateStudentID($studentId)) {
            $response = "END Session error. Please dial again.";
            break;
        }

        $student = getStudent($studentId);

        if (!$student) {
            $response = "END Session expired. Please dial again.";
            break;
        }

        switch ($menuChoice) {

            // ── Change PIN: compare new PIN vs confirmation ───────────────
            case '3':
                if (!preg_match('/^\d{4}$/', $confirmPin)) {
                    $response  = "END Invalid PIN format.\n";
                    $response .= "Confirmation PIN must be 4 digits.\n";
                    $response .= "Please dial again.";
                    break;
                }

                if ($newPin !== $confirmPin) {
                    $response  = "END PINs do not match.\n";
                    $response .= "Please dial again and enter\n";
                    $response .= "the same PIN in both steps.";
                    break;
                }

                // All checks passed — commit the PIN change
                updatePIN($studentId, $newPin);

                $response  = "END PIN Changed Successfully!\n";
                $response .= "================================\n";
                $response .= "Your PIN has been updated.\n";
                $response .= "New PIN expires at midnight.\n";
                $response .= "--------------------------------\n";
                $response .= "Thank you, " . $student['name'] . "!";
                break;

            default:
                $response = "END Invalid session state. Please dial again.";
                break;
        }
        break;


    // ── FALLBACK: Unexpected depth ──────────────────────────────────────────
    default:
        $response  = "END Session limit reached.\n";
        $response .= "Please dial again to start over.";
        break;
}

// ──────────────────────────────────────────────
// 4. SEND RESPONSE
// ──────────────────────────────────────────────
echo $response;