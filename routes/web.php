<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ContributorController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\HorseController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\SharingController;
use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

// Public
Route::get('/', [PublicController::class, 'home'])->name('home');
Route::get('/search', [PublicController::class, 'search'])->middleware('throttle:search')->name('search');
Route::get('/horse/{horse}', [PublicController::class, 'horse'])->name('horse.public');
Route::get('/report/{token}', [PublicController::class, 'report'])->middleware('throttle:reports')->name('report.show');
Route::get('/sample-report', [PublicController::class, 'sample'])->name('sample');
foreach (['how-it-works', 'for-owners', 'for-professionals', 'about', 'privacy', 'terms', 'record-corrections'] as $page) {
    Route::get('/'.$page, [PublicController::class, 'page'])->defaults('page', $page)->name($page);
}
Route::get('/contact', [PublicController::class, 'contact'])->name('contact');
Route::post('/contact', [PublicController::class, 'sendContact'])->middleware('throttle:forms')->name('contact.send');
Route::post('/horse/{horse}/corrections', [PublicController::class, 'reportCorrection'])->middleware('throttle:forms')->name('corrections.store');
Route::get('/robots.txt', [PublicController::class, 'robots']);
Route::get('/sitemap.xml', [PublicController::class, 'sitemap']);
Route::get('/files/{document}', [FileController::class, 'show'])->middleware('throttle:files')->name('files.show');

// Signed in
Route::middleware(['auth', 'standing'])->group(function () {
    Route::get('/dashboard', [AccountController::class, 'dashboard'])->name('dashboard');
    Route::get('/account', [AccountController::class, 'account'])->name('account');

    Route::get('/horses/new', [HorseController::class, 'create'])->name('horses.create');
    Route::post('/horses', [HorseController::class, 'store'])->middleware('throttle:forms')->name('horses.store');
    Route::get('/horses/{horse}', [HorseController::class, 'show'])->name('horses.show');
    Route::get('/horses/{horse}/edit', [HorseController::class, 'edit'])->name('horses.edit');
    Route::put('/horses/{horse}', [HorseController::class, 'update'])->name('horses.update');
    Route::get('/horses/{horse}/history', [HorseController::class, 'audit'])->name('horses.audit');
    Route::get('/horses/{horse}/claim', [HorseController::class, 'claimForm'])->name('horses.claim');
    Route::post('/horses/{horse}/claim', [HorseController::class, 'claim'])->middleware('throttle:forms')->name('horses.claim.store');

    Route::post('/horses/{horse}/identifiers', [RecordController::class, 'addIdentifier'])->name('record.identifiers');
    Route::post('/horses/{horse}/names', [RecordController::class, 'addName'])->name('record.names');
    Route::post('/horses/{horse}/events', [RecordController::class, 'addEvent'])->name('record.events');
    Route::get('/horses/{horse}/events/{event}/correct', [RecordController::class, 'correctForm'])->name('record.events.correct');
    Route::post('/horses/{horse}/events/{event}/correct', [RecordController::class, 'correct'])->name('record.events.correct.store');
    Route::post('/horses/{horse}/documents', [RecordController::class, 'addDocument'])->middleware('throttle:uploads')->name('record.documents');
    Route::post('/horses/{horse}/photo', [RecordController::class, 'setPhoto'])->middleware('throttle:uploads')->name('record.photo');
    Route::get('/pedigree/lookup', [\App\Http\Controllers\PedigreeController::class, 'lookup'])->middleware('throttle:search')->name('pedigree.lookup');
    Route::get('/pedigree/ancestry', [\App\Http\Controllers\PedigreeController::class, 'ancestry'])->middleware('throttle:search')->name('pedigree.ancestry');
    Route::post('/horses/{horse}/pedigree', [RecordController::class, 'savePedigree'])->name('record.pedigree');
    Route::post('/horses/{horse}/media', [RecordController::class, 'addMedia'])->middleware('throttle:uploads')->name('record.media');
    Route::post('/horses/{horse}/visibility', [RecordController::class, 'setVisibility'])->name('record.visibility');

    Route::get('/horses/{horse}/sharing', [SharingController::class, 'index'])->name('sharing');
    Route::post('/horses/{horse}/sharing/public', [SharingController::class, 'togglePublic'])->name('sharing.public');
    Route::post('/horses/{horse}/sharing/links', [SharingController::class, 'store'])->name('sharing.store');
    Route::post('/horses/{horse}/sharing/links/{link}/revoke', [SharingController::class, 'revoke'])->name('sharing.revoke');

    Route::post('/horses/{horse}/transfer', [TransferController::class, 'start'])->name('transfers.start');
    Route::post('/transfers/{transfer}/accept', [TransferController::class, 'accept'])->name('transfers.accept');
    Route::post('/transfers/{transfer}/decline', [TransferController::class, 'decline'])->name('transfers.decline');
    Route::post('/transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');

    Route::post('/horses/{horse}/contributors', [ContributorController::class, 'invite'])->middleware('throttle:forms')->name('contributors.invite');
    Route::post('/horses/{horse}/contributors/{contributor}/revoke', [ContributorController::class, 'revoke'])->name('contributors.revoke');
    Route::post('/contributors/{contributor}/accept', [ContributorController::class, 'accept'])->name('contributors.accept');
    Route::post('/contributors/{contributor}/decline', [ContributorController::class, 'decline'])->name('contributors.decline');
    Route::get('/horses/{horse}/contribute', [ContributorController::class, 'show'])->name('contribute');
    Route::post('/horses/{horse}/contribute/events', [ContributorController::class, 'addEvent'])->name('contribute.events');
    Route::post('/horses/{horse}/contribute/documents', [ContributorController::class, 'addDocument'])->middleware('throttle:uploads')->name('contribute.documents');
});

// Staff
Route::middleware(['auth', 'standing', 'staff'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'queue'])->name('queue');
    Route::get('/reviews/{item}', [AdminController::class, 'review'])->name('review');
    Route::post('/reviews/{item}/approve', [AdminController::class, 'approve'])->name('review.approve');
    Route::post('/reviews/{item}/reject', [AdminController::class, 'reject'])->name('review.reject');
    Route::get('/horses', [AdminController::class, 'horses'])->name('horses');
    Route::get('/horses/{horse}', [AdminController::class, 'horse'])->name('horse');
    Route::post('/verify/{type}/{id}', [AdminController::class, 'verify'])->name('verify');
    Route::post('/withdraw/{type}/{id}', [AdminController::class, 'withdraw'])->name('withdraw');
    Route::post('/users/{user}/credentials', [AdminController::class, 'credentials'])->name('credentials');
    Route::get('/messages', [AdminController::class, 'messages'])->name('messages');
});
