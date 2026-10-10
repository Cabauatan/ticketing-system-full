<?php

use App\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
Route::get('inventory/export.xlsx', [InventoryController::class, 'export'])->middleware('throttle:20,1')->name('inventory.export');
Route::post('inventory', [InventoryController::class, 'store'])->middleware('throttle:30,1')->name('inventory.store');
Route::get('inventory/{asset}', [InventoryController::class, 'show'])->whereNumber('asset')->name('inventory.show');
Route::post('inventory/{asset}/attachments', [InventoryController::class, 'storeProof'])->whereNumber('asset')->middleware('throttle:20,1')->name('inventory.attachments.store');
Route::get('inventory/{asset}/attachments/{attachment}', [InventoryController::class, 'downloadProof'])->whereNumber(['asset', 'attachment'])->name('inventory.attachments.download');
Route::patch('inventory/{asset}', [InventoryController::class, 'update'])->whereNumber('asset')->middleware('throttle:30,1')->name('inventory.update');
Route::post('inventory/{asset}/movements', [InventoryController::class, 'move'])->whereNumber('asset')->middleware('throttle:30,1')->name('inventory.move');
Route::post('inventory/{asset}/stock', [InventoryController::class, 'stock'])->whereNumber('asset')->middleware('throttle:30,1')->name('inventory.stock');
Route::post('inventory/{asset}/tickets', [InventoryController::class, 'link'])->whereNumber('asset')->middleware('throttle:30,1')->name('inventory.link');
Route::get('tickets/{ticket}/assets', [InventoryController::class, 'ticketAssets'])->whereNumber('ticket')->name('tickets.assets');
