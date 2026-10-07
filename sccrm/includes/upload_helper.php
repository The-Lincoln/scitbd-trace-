<?php
// Shared media upload helper for the SCCRM services module.
// Stores files in sccrm/uploads/services/ and returns the web path,
// prefixed with $base (pass $SCCRM_BASE) so it works under any sub-path.
// Legacy rows may hold absolute '/sccrm/...' paths — render them through
// sccrm_media_url() below.

function sccrm_upload_media(array $file, string $field, ?string $current = null, string $base = ''): ?string
{
    if ($base === '' && isset($_SERVER['SCRIPT_NAME'])) {
        // Auto-detect (upload often runs before header.php sets $SCCRM_BASE).
        $sn = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
        $pos = strpos($sn, '/sccrm');
        if ($pos !== false) $base = substr($sn, 0, $pos + 6);
    }
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $current;
    }
    $allowed = [
        'image' => ['jpg', 'jpeg', 'png', 'webp'],
        'gif'   => ['gif'],
        'video' => ['mp4', 'webm', 'mov'],
    ];
    $maxSize = ['image' => 5 * 1024 * 1024, 'gif' => 10 * 1024 * 1024, 'video' => 100 * 1024 * 1024];

    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > ($maxSize[$field] ?? 0)) {
        return $current;
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed[$field] ?? [], true)) {
        return $current;
    }
    $dir = __DIR__ . '/../uploads/services';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $name = $field . '_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        return $current;
    }
    return rtrim($base, '/') . '/uploads/services/' . $name;
}

/** Map a stored media path to a working URL (fixes legacy '/sccrm/…' rows under sub-paths). */
function sccrm_media_url(?string $path, string $base = ''): string
{
    $path = (string)$path;
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    if (strpos($path, '/sccrm/') === 0) {
        return rtrim($base, '/') . substr($path, 6);
    }
    return $path;
}
