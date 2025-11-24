<?php
// production/clear_session.php
session_start();
session_unset();
session_destroy();
echo "Production session cleared!";
echo "<br><a href='login.php'>Go to Login</a>";