<?php
declare(strict_types=1);

function uploadImageStrict1MB(array $file, string $targetDirFs, string $targetDirPublic): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'حدث خطأ أثناء رفع الصورة.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 1024 * 1024) {
        return ['ok' => false, 'path' => null, 'error' => 'حجم الصورة يجب أن يكون 1MB أو أقل.'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'path' => null, 'error' => 'نوع الصورة غير مدعوم.'];
    }

    if (!is_dir($targetDirFs) && !@mkdir($targetDirFs, 0775, true) && !is_dir($targetDirFs)) {
        return ['ok' => false, 'path' => null, 'error' => 'تعذر إنشاء مجلد الرفع.'];
    }

    $ext = $allowed[$mime];
    $filename = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destFs = rtrim($targetDirFs, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmp, $destFs)) {
        return ['ok' => false, 'path' => null, 'error' => 'تعذر حفظ الصورة على السيرفر.'];
    }

    $publicPath = rtrim($targetDirPublic, '/') . '/' . $filename;
    return ['ok' => true, 'path' => $publicPath, 'error' => null];
}