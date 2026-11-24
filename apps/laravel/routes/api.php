<?php
declare(strict_types=1);

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [ApiController::class, 'health']);
Route::get('/items', [ApiController::class, 'listItems']);
Route::get('/items/{id}', [ApiController::class, 'getItem'])->whereNumber('id');
Route::post('/folders', [ApiController::class, 'createFolder']);
Route::post('/files/upload', [ApiController::class, 'uploadFile']);
Route::patch('/items/{id}', [ApiController::class, 'updateItem'])->whereNumber('id');
Route::delete('/items/{id}', [ApiController::class, 'deleteItem'])->whereNumber('id');
Route::post('/items/{id}/restore', [ApiController::class, 'restoreItem'])->whereNumber('id');
Route::post('/trash/empty', [ApiController::class, 'emptyTrash']);
Route::get('/files/{id}/download', [ApiController::class, 'downloadFile'])->whereNumber('id');
Route::get('/files/{id}/preview', [ApiController::class, 'previewFile'])->whereNumber('id');
Route::get('/storage', [ApiController::class, 'storage']);
