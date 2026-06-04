<?php

require_once 'php_scripts/auth.php';
requireRole('Admin');

	

	$id = $_GET['ID'];
	$userID = $_GET['Row_ID'];
	$enqMobile = $_GET['Enq_MOBILE'];
	$uploaddate = date("Y/m/d");

	
	if (!empty($userID))
	{	
		mysqli_query($link,"DELETE FROM USERS WHERE ID='".$userID."'");
		mysqli_query($link,"ALTER TABLE USERS DROP ID");
		mysqli_query($link,"ALTER TABLE USERS AUTO_INCREMENT = 1");
		mysqli_query($link,"ALTER TABLE USERS ADD ID int UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
	
		mysqli_close($link);
		header("Location: ViewUsers.php");	
	}
	else if(!empty($enqMobile))
	{
		mysqli_query($link,"UPDATE MAINDATABASE SET STATUS = 'NOI', UPLOAD_DATE ='".$uploaddate."' WHERE MOBILE = '".$enqMobile."' ");
		
		mysqli_query($link,"DELETE FROM ENQUIRY WHERE MOBILE='".$enqMobile."' ");
		mysqli_query($link,"ALTER TABLE ENQUIRY DROP ID");
		mysqli_query($link,"ALTER TABLE ENQUIRY AUTO_INCREMENT = 1");
		mysqli_query($link,"ALTER TABLE ENQUIRY ADD ID BIGINT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
	
		mysqli_close($link);
		header("Location: ViewEnquiry.php");	
	}		
?>