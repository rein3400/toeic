<?php
require_once '../includes/session_handler.php';
session_unset();
session_destroy();
header("Location: login.php");
exit();
?>