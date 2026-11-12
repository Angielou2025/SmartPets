<?php
/**
 * Rescue Story Video ng bawat pet - maikling video kung paano na-rescue ang pet.
 * In-a-upload ng staff (Pet Inventory) at ipinapakita sa adopter sa "Why this pet?"
 * kasama ng Veterinarian's assessment. Nasa uploads/videos/ ang mga file.
 */

// 10MB: pinakamalaking file na tinatanggap ng InfinityFree
const SP_PET_VIDEO_MAX_BYTES = 10 * 1024 * 1024;
const SP_PET_VIDEO_TYPES = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'm4v' => 'video/mp4'];

/**
 * Sine-save ang na-upload na video ($_FILES['...']). Ibinabalik ang
 * ['file' => pangalan] kapag ayos, o ['error' => mensahe].
 */
function sp_save_pet_video(array $upload, int $petId): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['error' => 'Please choose a video file.'];
    }
    if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($upload['size'] ?? 0) > SP_PET_VIDEO_MAX_BYTES) {
        return ['error' => 'The video is larger than 10 MB. Please use a shorter or smaller video (about 30 seconds).'];
    }
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        return ['error' => 'The video could not be uploaded. Please try again.'];
    }
    $ext = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
    if (!isset(SP_PET_VIDEO_TYPES[$ext])) {
        return ['error' => 'Please upload an MP4, WEBM, or MOV video.'];
    }
    // Totoong video ba (hindi lang pinalitan ang pangalan)? Tinitingnan ang simula ng file.
    $head = (string) @file_get_contents($upload['tmp_name'], false, null, 0, 16);
    $isMp4 = substr($head, 4, 4) === 'ftyp';
    $isWebm = substr($head, 0, 4) === "\x1A\x45\xDF\xA3";
    if (!$isMp4 && !$isWebm) {
        return ['error' => 'This file is not a valid video.'];
    }
    $dir = __DIR__ . '/../uploads/videos';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['error' => 'The video folder could not be created on the server.'];
    }
    $name = 'pet' . $petId . '_' . bin2hex(random_bytes(6)) . '.' . ($isWebm ? 'webm' : ($ext === 'webm' ? 'mp4' : $ext));
    if (!move_uploaded_file($upload['tmp_name'], $dir . '/' . $name)) {
        return ['error' => 'The video could not be saved. Please try again.'];
    }
    return ['file' => $name];
}

/** Binubura ang lumang video file (kapag pinalitan o tinanggal) */
function sp_delete_pet_video(?string $file): void
{
    $file = basename((string) $file);
    if ($file !== '' && is_file(__DIR__ . '/../uploads/videos/' . $file)) {
        @unlink(__DIR__ . '/../uploads/videos/' . $file);
    }
}

/** URL ng video para sa <video>, o '' kapag wala */
function sp_pet_video_url(?string $file, string $base = '../'): string
{
    $file = basename((string) $file);
    return ($file !== '' && is_file(__DIR__ . '/../uploads/videos/' . $file)) ? $base . 'uploads/videos/' . rawurlencode($file) : '';
}
