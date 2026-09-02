<?php

use App\Mail\InvoiceCreatedMail;
use App\Mail\PaymentReceivedMail;
use App\Mail\PaymentReminderMail;
use App\Mail\ResetPasswordMail;
use App\Mail\Support\CompanyBranding;
use App\Mail\Support\MailMessageData;
use App\Mail\UserInvitationMail;
use App\Mail\WelcomeMail;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('master layout omits optional blocks when values are missing', function () {
    $html = view(
        'emails.welcome',
        (new MailMessageData(
            title: 'Account notice',
            subtitle: 'A short update about your account.',
        ))->applyBranding(CompanyBranding::resolve())->toViewData()
    )->render();

    expect($html)
        ->toContain('Account notice')
        ->toContain((string) config('app.name'))
        ->toContain('Need help?')
        ->toContain('#0f172a')
        ->not->toContain('ACCOUNTING')
        ->not->toContain('FINANCIAL SUITE')
        ->not->toContain('256-Bit')
        ->not->toContain('Bank-Grade Encryption')
        ->not->toContain('Login to Dashboard')
        ->not->toContain('View Invoice')
        ->not->toContain('Invoice Summary')
        ->not->toContain('Payment Successful')
        ->not->toContain('>PAID<');
});

test('master layout shows company name when logo is missing', function () {
    $company = Company::query()->create([
        'code' => 'CO-00040',
        'name' => 'Northwind Accounting',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $html = (new WelcomeMail(
        userName: 'Jane Doe',
        userEmail: 'jane@example.com',
        dashboardUrl: 'http://localhost/dashboard',
        company: $company,
    ))->render();

    expect($html)
        ->toContain('Northwind Accounting')
        ->not->toContain('<img src=""');
});

test('welcome email uses the master layout and tenant branding', function () {
    $mailable = new WelcomeMail(
        userName: 'Jane Doe',
        userEmail: 'jane@example.com',
        dashboardUrl: 'http://localhost/dashboard',
    );

    $mailable->assertSeeInHtml('Welcome to');
    $mailable->assertSeeInHtml('Your account has been successfully created.');
    $mailable->assertSeeInHtml('Login to Dashboard');
    $mailable->assertSeeInHtml('jane@example.com');
    $mailable->assertSeeInHtml('Hello Jane Doe');
});

test('invoice created email includes summary and view action', function () {
    $mailable = new InvoiceCreatedMail(
        userName: 'Jane Doe',
        invoice: [
            'number' => 'INV-000125',
            'customer' => 'John Doe',
            'issue_date' => '28 Aug 2026',
            'due_date' => '05 Sep 2026',
            'subtotal' => 'PKR 20,000',
            'tax' => 'PKR 3,000',
            'total' => 'PKR 23,000',
        ],
        invoiceUrl: 'http://localhost/invoices/125',
    );

    $mailable->assertSeeInHtml('Your Invoice is Ready');
    $mailable->assertSeeInHtml('INV-000125');
    $mailable->assertSeeInHtml('John Doe');
    $mailable->assertSeeInHtml('PKR 23,000');
    $mailable->assertSeeInHtml('Invoice Summary');
    $mailable->assertSeeInHtml('View Invoice');
});

test('payment received email shows paid status', function () {
    $mailable = new PaymentReceivedMail(
        userName: 'Jane Doe',
        payment: [
            'reference' => 'PAY-8891',
            'invoice_number' => 'INV-000125',
            'date' => '28 Aug 2026',
            'method' => 'Bank transfer',
            'amount' => 'PKR 23,000',
        ],
        transactionUrl: 'http://localhost/payments/8891',
    );

    $mailable->assertSeeInHtml('Payment Received');
    $mailable->assertSeeInHtml('PAY-8891');
    $mailable->assertSeeInHtml('Payment Successful');
    $mailable->assertSeeInHtml('Payment successfully received.');
    $mailable->assertSeeInHtml('View Transaction');
});

test('payment reminder email includes pay now action', function () {
    $mailable = new PaymentReminderMail(
        userName: 'Jane Doe',
        invoice: [
            'number' => 'INV-000125',
            'due_date' => '05 Sep 2026',
            'amount_due' => 'PKR 25,000',
        ],
        paymentUrl: 'http://localhost/invoices/125/pay',
    );

    $mailable->assertSeeInHtml('Payment Reminder');
    $mailable->assertSeeInHtml('INV-000125');
    $mailable->assertSeeInHtml('PKR 25,000');
    $mailable->assertSeeInHtml('PENDING');
    $mailable->assertSeeInHtml('Pay Now');
});

test('password reset email includes security notice', function () {
    $mailable = new ResetPasswordMail(
        userName: 'Jane Doe',
        resetUrl: 'http://localhost/reset-password/token',
    );

    $mailable->assertSeeInHtml('Reset Your Password');
    $mailable->assertSeeInHtml('Reset Password');
    $mailable->assertSeeInHtml('If you did not request a password reset, you can safely ignore this email.');
});

test('user invitation email includes company role and inviter', function () {
    $mailable = new UserInvitationMail(
        userName: 'Jane Doe',
        roleName: 'Accountant',
        invitedBy: 'Owner Person',
        acceptUrl: 'http://localhost/invitations/accept',
    );

    $mailable->assertSeeInHtml('You Have Been Invited');
    $mailable->assertSeeInHtml('Accountant');
    $mailable->assertSeeInHtml('Owner Person');
    $mailable->assertSeeInHtml('Accept Invitation');
});
