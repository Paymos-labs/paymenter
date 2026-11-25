<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Paymos\Paymos;

// Public signed webhook endpoint (CSRF-exempt; authenticated via HMAC-SHA256 X-Webhook-Signature).
Route::post('/extensions/gateways/paymos/webhook', [Paymos::class, 'webhook'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('extensions.gateways.paymos.webhook');

// Protected admin Connect Paymos endpoints (web + CSRF; strict admin.gateways.update / Gate::allows('update', Gateway::class) inside Paymos).
Route::post('/extensions/gateways/paymos/admin/connect/start', [Paymos::class, 'connectStart'])
    ->middleware(['web'])
    ->name('extensions.gateways.paymos.admin.connect.start');

Route::post('/extensions/gateways/paymos/admin/connect/poll', [Paymos::class, 'connectPoll'])
    ->middleware(['web'])
    ->name('extensions.gateways.paymos.admin.connect.poll');

// Protected admin Refunds endpoints (web + CSRF; strict admin.invoices.update / Gate::allows('update', Invoice::class) inside Paymos).
Route::get('/extensions/gateways/paymos/admin/refunds/{invoiceId}', [Paymos::class, 'refundPanel'])
    ->middleware(['web'])
    ->name('extensions.gateways.paymos.admin.refunds.panel');

Route::post('/extensions/gateways/paymos/admin/refunds/submit', [Paymos::class, 'refundSubmit'])
    ->middleware(['web'])
    ->name('extensions.gateways.paymos.admin.refunds.submit');

Route::post('/extensions/gateways/paymos/admin/refunds/check', [Paymos::class, 'refundCheck'])
    ->middleware(['web'])
    ->name('extensions.gateways.paymos.admin.refunds.check');
