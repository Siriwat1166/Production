<?php
// logout.php
require_once 'config/config.php';
require_once 'classes/Auth.php';

$auth = new Auth();
$auth->logout();
?>