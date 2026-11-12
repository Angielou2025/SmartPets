<?php
/**
 * Wala nang hiwalay na Account Settings page ang staff: ang asul na pop-up na
 * kapareho ng sa Adopter na ang gamit (Adopter_File/_profile_setup_modal.php,
 * binubuksan mula sa menu ng profile sa itaas - connection/staff_header.php).
 * Ang mga lumang link dito ay dinadala sa Dashboard at kusang binubuksan ang pop-up.
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

header("Location: /Caps2/StaffFile/staff_dashboard.php?account=settings");
exit();
