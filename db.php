<?php

/**
 * db.php
 * Database connection handler for the Ashesi Meal Plan USSD System.
 * Uses MySQLi with a clean, reusable connection pattern.
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'deubaybe.dounia');        
define('DB_PASS', 'Dou81387');            
define('DB_NAME', 'mobileapps_2026B_deubaybe_dounia');
define('DB_PORT', 3306);

/**
 * Returns an active MySQLi connection.
 * Terminates with a JSON error if the connection fails.
 *
 * @return mysqli
 */
function getDBConnection()
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

    if ($conn->connect_error) {
        // Return a structured error so the USSD gateway gets a valid response
        header('Content-Type: text/plain');
        echo "END System error. Please try again later.";
        exit();
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}