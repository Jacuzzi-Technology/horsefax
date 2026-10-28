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
Route::get('/search/suggest', [PublicController::class, 'suggest'])->middleware('throttle:suggest')->name('search.suggest');
Route::get('/horse/{horse}', [PublicController::class, 'horse'])->middleware('throttle:records')->name('horse.public');
Route::get('/report/{token}', [PublicController::class, 'report'])->middleware('throttle:reports')->name('report.show');
Route::get('/sample-report', [PublicController::class, 'sample'])->name('sample');
Route::get('/sample-report/pdf', [PublicController::class, 'samplePdf'])->middleware('throttle:reports')->name('sample.pdf');
Route::get('/horse/{horse}/pdf', [PublicController::class, 'horsePdf'])->middleware('throttle:reports')->name('horse.pdf');
Route::get('/report/{token}/pdf', [PublicController::class, 'reportPdf'])->middleware('throttle:reports')->name('report.pdf');
foreach (['how-it-works', 'for-owners', 'for-professionals', 'partners', 'about', 'privacy', 'terms', 'record-corrections', 'factual-record-policy'] as $page) {
    Route::get('/'.$page, [PublicController::class, 'page'])->defaults('page', $page)->name($page);
}
Route::get('/contact', [PublicController::class, 'contact'])->name('contact');
Route::post('/contact', [PublicController::class, 'sendContact'])->middleware('throttle:forms')->name('contact.send');
Route::post('/partners', [PublicController::class, 'sendPartnerInquiry'])->middleware('throttle:forms')->name('partners.send');
Route::post('/horse/{horse}/corrections', [PublicController::class, 'reportCorrection'])->middleware('throttle:forms')->name('corrections.store');
Route::get('/robots.txt', [PublicController::class, 'robots']);
Route::get('/sitemap.xml', [PublicController::class, 'sitemap']);
Route::get('/show-records/{record}', [\App\Http\Controllers\ShowRecordController::class, 'show'])->name('show-records.show');
Route::get('/files/{document}', [FileController::class, 'show'])->middleware('throttle:files')->name('files.show');
// QR codes with the HF mark, for catalogs and stall cards; and the seller's confirm-or-deny link for a claim.
Route::get('/horse/{horse}/qr.{format}', [\App\Http\Controllers\QrController::class, 'horse'])->whereIn('format', ['svg', 'png'])->middleware('throttle:files')->name('horse.qr');
// Radiographs: buyers view (when the owner allows) and open files; the request needs an account.
Route::get('/horse/{horse}/radiographs/{set}', [\App\Http\Controllers\RadiographController::class, 'view'])->name('radiographs.view');
Route::get('/horse/{horse}/radiographs/{set}/files/{file}', [\App\Http\Controllers\RadiographController::class, 'file'])->middleware('throttle:files')->name('radiographs.file');
Route::get('/claims/confirm/{token}', [\App\Http\Controllers\ClaimConfirmationController::class, 'show'])->name('claims.confirm');
Route::post('/claims/confirm/{token}', [\App\Http\Controllers\ClaimConfirmationController::class, 'respond'])->middleware('throttle:forms')->name('claims.confirm.respond');

// Signed in
Route::middleware(['auth', 'standing'])->group(function () {
    Route::get('/dashboard', [AccountController::class, 'dashboard'])->name('dashboard');
    Route::get('/claim', [\App\Http\Controllers\ClaimCodeController::class, 'form'])->name('claim-code.form');
    Route::get('/horses/{horse}/radiographs', [\App\Http\Controllers\RadiographController::class, 'index'])->name('radiographs.index');
    Route::post('/horses/{horse}/radiographs', [\App\Http\Controllers\RadiographController::class, 'store'])->middleware('throttle:forms')->name('radiographs.store');
    Route::get('/horses/{horse}/radiographs/{set}', [\App\Http\Controllers\RadiographController::class, 'manage'])->name('radiographs.manage');
    Route::post('/horses/{horse}/radiographs/{set}/files', [\App\Http\Controllers\RadiographController::class, 'upload'])->middleware('throttle:radiograph-uploads')->name('radiographs.upload');
    Route::post('/horses/{horse}/radiographs/{set}/access', [\App\Http\Controllers\RadiographController::class, 'updateAccess'])->name('radiographs.access');
    Route::post('/horses/{horse}/radiographs/{set}/requests/{access}', [\App\Http\Controllers\RadiographController::class, 'decide'])->name('radiographs.decide');
    Route::post('/horse/{horse}/radiographs/{set}/request', [\App\Http\Controllers\RadiographController::class, 'requestAccess'])->middleware('throttle:forms')->name('radiographs.request');
    Route::post('/claim', [\App\Http\Controllers\ClaimCodeController::class, 'redeem'])->middleware('throttle:forms')->name('claim-code.redeem');
    Route::get('/account/identity', [\App\Http\Controllers\IdentityController::class, 'form'])->name('identity');
    Route::post('/account/identity', [\App\Http\Controllers\IdentityController::class, 'submit'])->middleware('throttle:uploads')->name('identity.submit');
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

    Route::post('/horses/{horse}/results/{record}', [\App\Http\Controllers\ShowRecordController::class, 'claim'])->middleware('throttle:forms')->name('show-records.claim');
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
    Route::get('/members', [\App\Http\Controllers\Admin\MemberController::class, 'index'])->name('members');
    Route::get('/members/{user}', [\App\Http\Controllers\Admin\MemberController::class, 'show'])->name('member');
    Route::post('/members/{user}/plan', [\App\Http\Controllers\Admin\MemberController::class, 'plan'])->name('member.plan');
    Route::get('/show-records', [AdminController::class, 'showRecords'])->name('show-records');
    Route::get('/offspring', [\App\Http\Controllers\Admin\OffspringController::class, 'index'])->name('offspring');
    Route::post('/show-records/{record}/attach', [AdminController::class, 'attachResults'])->name('show-records.attach');
    Route::post('/horses/{horse}/claim-codes', [\App\Http\Controllers\ClaimCodeController::class, 'issue'])->name('claim-codes.issue');
    Route::get('/claim-codes/{code}', [\App\Http\Controllers\ClaimCodeController::class, 'certificate'])->name('claim-codes.certificate');
    Route::get('/claim-codes/{code}/qr.svg', [\App\Http\Controllers\ClaimCodeController::class, 'qr'])->name('claim-codes.qr');
    Route::post('/claim-codes/{code}/revoke', [\App\Http\Controllers\ClaimCodeController::class, 'revoke'])->name('claim-codes.revoke');
    Route::get('/identity/{check}/document', [\App\Http\Controllers\IdentityController::class, 'document'])->name('identity.document');
    Route::get('/qr', [\App\Http\Controllers\QrController::class, 'batchForm'])->name('qr');
    Route::post('/qr', [\App\Http\Controllers\QrController::class, 'batch'])->name('qr.batch');
    Route::get('/sale-lots', [\App\Http\Controllers\Admin\SaleLotController::class, 'index'])->name('sale-lots');
    Route::post('/suggestions/{suggestion}', [\App\Http\Controllers\Admin\ReviewSuggestionController::class, 'decide'])->name('suggestions.decide');
    Route::get('/names/batch', [\App\Http\Controllers\Admin\NameReviewController::class, 'batch'])->name('names.batch');
    Route::post('/names/batch', [\App\Http\Controllers\Admin\NameReviewController::class, 'batchDecide'])->name('names.batch.decide');
    Route::get('/names', [\App\Http\Controllers\Admin\NameReviewController::class, 'index'])->name('names');
    Route::post('/names/{review}', [\App\Http\Controllers\Admin\NameReviewController::class, 'decide'])->name('names.decide');
    Route::post('/sale-lots/{lot}/attach', [\App\Http\Controllers\Admin\SaleLotController::class, 'attach'])->name('sale-lots.attach');
    Route::post('/sale-lots/{lot}/dismiss', [\App\Http\Controllers\Admin\SaleLotController::class, 'dismiss'])->name('sale-lots.dismiss');
});
