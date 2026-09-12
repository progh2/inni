<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\CatalogCsv;
use Inni\Csrf;
use Inni\Database;
use Inni\View;
use InvalidArgumentException;
use Throwable;

final class CatalogCsvController
{
    public function index(): void
    {
        $user = $this->requireWriter();
        $result = $_SESSION['_catalog_csv_result'] ?? null;
        unset($_SESSION['_catalog_csv_result']);
        if (!is_array($result)) {
            $result = null;
        }
        $locations = Database::pdo()->query(
            "SELECT id, name, kind, code FROM locations WHERE kind IN ('room','storage') ORDER BY kind, name"
        )->fetchAll();
        View::render('catalog/csv', compact('user', 'result', 'locations'));
    }

    public function template(): void
    {
        $this->requireWriter();
        $this->sendCsv('inni-catalog-template.csv', CatalogCsv::template());
    }

    public function export(): void
    {
        $this->requireWriter();
        $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
        $locationId = is_string($_GET['location_id'] ?? null) ? $_GET['location_id'] : '';
        try {
            $exported = CatalogCsv::export(
                Database::pdo(),
                $type !== '' ? $type : null,
                $locationId !== '' ? $locationId : null,
            );
        } catch (InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
            App::redirect('catalog/csv');
        }
        $stamp = gmdate('Ymd');
        $this->sendCsv("inni-catalog-{$stamp}.csv", $exported['csv']);
    }

    public function import(): void
    {
        $user = $this->requireWriter();
        Csrf::requirePost();

        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            App::flash('error', 'CSV 파일을 선택하세요.');
            App::redirect('catalog/csv');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error !== UPLOAD_ERR_OK) {
            App::flash('error', '업로드에 실패했습니다.');
            App::redirect('catalog/csv');
        }

        $max = (int) App::config('max_upload_bytes', 8 * 1024 * 1024);
        if ((int) ($file['size'] ?? 0) > $max) {
            App::flash('error', '파일이 너무 큽니다.');
            App::redirect('catalog/csv');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            App::flash('error', 'CSV 파일을 읽지 못했습니다.');
            App::redirect('catalog/csv');
        }

        $name = (string) ($file['name'] ?? 'upload.csv');
        $raw = file_get_contents($tmp);
        if (!is_string($raw) || $raw === '') {
            App::flash('error', 'CSV 파일이 비어 있습니다.');
            App::redirect('catalog/csv');
        }

        try {
            $result = CatalogCsv::import(Database::pdo(), $user, $raw, $name);
        } catch (InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
            App::redirect('catalog/csv');
        } catch (Throwable $e) {
            error_log((string) $e);
            App::flash('error', '가져오기를 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
            App::redirect('catalog/csv');
        }

        $_SESSION['_catalog_csv_result'] = $result;
        $msg = "가져오기 완료: 신규 {$result['created']} · 수정 {$result['updated']} · 건너뜀 {$result['skipped']}";
        $applied = $result['created'] + $result['updated'];
        App::flash($applied > 0 ? 'ok' : 'error', $msg);
        App::redirect('catalog/csv');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireWriter(): array
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user)) {
            App::flash('error', '품목 CSV는 담당교사만 사용할 수 있습니다.');
            App::redirect('more');
        }
        return $user;
    }

    private function sendCsv(string $filename, string $csv): never
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'inni-catalog.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo CatalogCsv::BOM . $csv;
        exit;
    }
}
