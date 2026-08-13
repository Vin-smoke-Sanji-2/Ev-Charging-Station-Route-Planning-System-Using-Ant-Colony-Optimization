<?php

namespace App\Providers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridApiTransport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel 11's MailManager has no built-in SendGrid transport (only
        // Mailgun/Postmark/SES/Resend/SMTP) - this registers one using the
        // SendGrid Web API v3 (symfony/sendgrid-mailer, installed for
        // exactly this), the officially-documented Laravel extension point
        // for a third-party Symfony Mailer bridge. Config lives on the
        // 'sendgrid' mailer in config/mail.php.
        Mail::extend('sendgrid', function (array $config) {
            return new SendgridApiTransport($config['key']);
        });
    }
}
