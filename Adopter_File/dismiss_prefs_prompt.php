<?php
session_start();

if (isset($_SESSION['user_id'])) {
    $_SESSION['prefs_prompt_skipped'] = true;
}

http_response_code(204);
