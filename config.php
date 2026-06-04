<?php

    $DB_SERVERNAME = getenv('DB_SERVERNAME') ?: "localhost";
    $DB_USERNAME = getenv('DB_USERNAME') ?: "root";
    $DB_PASSWORD = getenv('DB_PASSWORD') ?: "";
    $DB_NAME = getenv('DB_NAME') ?: "callnow_incredit";

    $link = mysqli_connect($DB_SERVERNAME, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
    if($link === false){
        die("ERROR: Could Not Connect to Database, Reason:- " . mysqli_connect_error());
    }

    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

?>