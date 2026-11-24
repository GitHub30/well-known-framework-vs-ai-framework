<?php
declare(strict_types=1);

use App\Http\Controllers\MetaController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'home']);
Route::get('/drive/folders/{id}', [PageController::class, 'folder'])->whereNumber('id');
Route::get('/drive/recent', [PageController::class, 'recent']);
Route::get('/drive/starred', [PageController::class, 'starred']);
Route::get('/drive/shared', [PageController::class, 'shared']);
Route::get('/drive/trash', [PageController::class, 'trash']);
Route::get('/drive/search', [PageController::class, 'search']);
Route::get('/share/{token}', [PageController::class, 'share'])->where('token', '[a-zA-Z0-9_-]+');
Route::get('/about', [PageController::class, 'about']);
Route::get('/offline', [PageController::class, 'offline']);

Route::get('/manifest.webmanifest', [MetaController::class, 'manifest']);
Route::get('/robots.txt', [MetaController::class, 'robots']);
Route::get('/sitemap.xml', [MetaController::class, 'sitemap']);
Route::get('/llms.txt', [MetaController::class, 'llms']);
Route::get('/llms-full.txt', [MetaController::class, 'llmsFull']);
Route::get('/openapi.json', [MetaController::class, 'openapi']);
