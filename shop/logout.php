<?php
session_start();

// Clear all session data
session_unset();
session_destroy();

// Redirect to index.php one folder up
header("Location: ../index.php?logged_out=true");
exit();
