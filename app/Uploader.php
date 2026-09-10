<?php

declare(strict_types=1);

namespace Inni;

use RuntimeException;

final class Uploader
{
    public static function store(array $file, string $subdir = 'misc'): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('업로드 실패 (code ' . $file['error'] . ')');
        }

        $max = (int) App::config('max_upload_bytes', 8 * 1024 * 1024);
        if (($file['size'] ?? 0) > $max) {
            throw new RuntimeException('파일이 너무 큽니다.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($map[$mime])) {
            throw new RuntimeException('이미지 파일만 업로드할 수 있습니다.');
        }

        $root = App::config('upload_path') ?: (App::root() . '/public/uploads');
        $destDir = rtrim($root, '/') . '/' . trim($subdir, '/');
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            throw new RuntimeException('업로드 폴더를 만들 수 없습니다.');
        }

        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $map[$mime];
        $path = $destDir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new RuntimeException('파일 저장 실패');
        }

        $urlBase = rtrim((string) App::config('upload_url', '/uploads'), '/');
        return $urlBase . '/' . trim($subdir, '/') . '/' . $name;
    }
}
