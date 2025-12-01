<?php
if (!isset($_SESSION['email'])) {
    header("Location: LoginModule.php");
    exit();
}
