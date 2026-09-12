<?php

declare(strict_types=1);

namespace Inni;

use Inni\Controllers\AssetController;
use Inni\Controllers\AuthController;
use Inni\Controllers\CatalogCsvController;
use Inni\Controllers\HomeController;
use Inni\Controllers\InventoryController;
use Inni\Controllers\ItemController;
use Inni\Controllers\LabelController;
use Inni\Controllers\LoanController;
use Inni\Controllers\MoreController;
use Inni\Controllers\RoomController;
use Inni\Controllers\ScanController;
use Inni\Controllers\SearchController;
use Inni\Controllers\SettingsController;

final class Router
{
    public static function dispatch(string $route): void
    {
        $route = trim($route, '/');
        if ($route === '') {
            $route = 'home';
        }

        $map = [
            'login' => [AuthController::class, 'login'],
            'logout' => [AuthController::class, 'logout'],
            'auth/demo' => [AuthController::class, 'demo'],
            'auth/google' => [AuthController::class, 'googleStart'],
            'auth/google/callback' => [AuthController::class, 'googleCallback'],

            'home' => [HomeController::class, 'index'],
            'search' => [SearchController::class, 'index'],
            'scan' => [ScanController::class, 'index'],
            'scan/resolve' => [ScanController::class, 'resolve'],

            'rooms' => [RoomController::class, 'index'],
            'rooms/show' => [RoomController::class, 'show'],
            'rooms/save' => [RoomController::class, 'save'],

            'items/new' => [ItemController::class, 'createForm'],
            'items/save' => [ItemController::class, 'save'],
            'items/show' => [ItemController::class, 'show'],
            'items/edit' => [ItemController::class, 'editForm'],
            'items/update' => [ItemController::class, 'update'],
            'items/issue' => [ItemController::class, 'issue'],
            'items/restock' => [ItemController::class, 'restock'],
            'items/cancel-issue' => [ItemController::class, 'cancelIssue'],

            'catalog/csv' => [CatalogCsvController::class, 'index'],
            'catalog/csv/template' => [CatalogCsvController::class, 'template'],
            'catalog/csv/export' => [CatalogCsvController::class, 'export'],
            'catalog/csv/import' => [CatalogCsvController::class, 'import'],

            'assets/show' => [AssetController::class, 'show'],
            'assets/loan' => [AssetController::class, 'loan'],
            'assets/move' => [AssetController::class, 'move'],
            'assets/report' => [AssetController::class, 'report'],
            'assets/photo' => [AssetController::class, 'photo'],

            'loans' => [LoanController::class, 'index'],
            'loans/return' => [LoanController::class, 'returnLoan'],

            'labels' => [LabelController::class, 'index'],
            'labels/print' => [LabelController::class, 'print'],

            'inventory' => [InventoryController::class, 'index'],
            'inventory/start' => [InventoryController::class, 'start'],
            'inventory/show' => [InventoryController::class, 'show'],
            'inventory/confirm' => [InventoryController::class, 'confirm'],
            'inventory/finish' => [InventoryController::class, 'finish'],
            'inventory/result' => [InventoryController::class, 'result'],

            'more' => [MoreController::class, 'index'],
            'settings' => [SettingsController::class, 'index'],
            'settings/save' => [SettingsController::class, 'save'],
            'settings/users' => [SettingsController::class, 'users'],
            'settings/approve' => [SettingsController::class, 'approve'],
        ];

        if (!isset($map[$route])) {
            http_response_code(404);
            View::render('errors/404', ['current_route' => $route], 'layouts/bare');
            return;
        }

        [$class, $method] = $map[$route];
        $controller = new $class();
        $controller->$method();
    }
}
