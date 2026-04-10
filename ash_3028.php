<?php

header('Content-Type: application/json');

$msisdn = isset($_POST['msisdn']) ? trim($_POST['msisdn']) : '';

$sequenceID = isset($_POST['sequenceID']) ? trim($_POST['sequenceID']) : '';

$data = isset($_POST['data']) ? trim($_POST['data']) : '';

$network = isset($_POST['network']) ? trim($_POST['network']) : '';

$timestamp = date('YmdHis');

$message = '';

$continueFlag = 0;

$message = "Welcome to Ashesi University Demo\r\n1. Proceed\r\n2. Exit";

$continueFlag = 0;

               echo json_encode([

    'msisdn' => (string) $msisdn,

    'sequenceID' => (string) $sequenceID,

    'timestamp' => (string) $timestamp,

    'message' => (string) $message,

    'continueFlag' => (int) $continueFlag,

]);

?>
 