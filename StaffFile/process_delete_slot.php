<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slot_id = (int) ($_POST['slot_id'] ?? 0);

    if ($slot_id <= 0) {
        $_SESSION['error_message'] = "Invalid slot selected.";
        header("Location: staff_availability.php");
        exit();
    }

    try {
        // Booked na slot: puwede lang alisin kapag lumipas na at minarkahang
        // Not Attended ang adopter. Slot lang ang nabubura - buo pa rin ang
        // application, at makakapili pa rin siya ng bagong oras sa reschedule link.
        if (($_POST['mode'] ?? '') === 'missed') {
            $stmt = $pdo->prepare(
                "DELETE s FROM appointment_slots s
                 JOIN adoption_applications aa ON aa.application_id = s.booked_by_application_id
                 WHERE s.slot_id = ? AND s.status = 'Booked'
                   AND aa.interview_missed_at IS NOT NULL
                   AND TIMESTAMP(s.slot_date, s.start_time) <= NOW()"
            );
            $stmt->execute([$slot_id]);
            if ($stmt->rowCount() > 0) {
                $_SESSION['success_message'] = "Missed interview slot removed from the list.";
                log_activity($pdo, "Removed missed interview slot", "Slot ID #$slot_id");
            } else {
                $_SESSION['error_message'] = "Unable to remove this slot.";
            }
            header("Location: staff_availability.php");
            exit();
        }

        // Booked slots hindi puwedeng burahin dahil may naka-link nang application - dapat i-cancel muna ang application na iyon
        $stmt = $pdo->prepare("DELETE FROM appointment_slots WHERE slot_id = ? AND status = 'Open'");
        $stmt->execute([$slot_id]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['success_message'] = "Availability slot removed.";
            log_activity($pdo, "Removed interview availability slot", "Slot ID #$slot_id");
        } else {
            $_SESSION['error_message'] = "Unable to remove this slot. It may already be booked by an applicant.";
        }
        header("Location: staff_availability.php");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error removing slot: " . $e->getMessage();
        header("Location: staff_availability.php");
        exit();
    }
} else {
    header("Location: staff_availability.php");
    exit();
}
