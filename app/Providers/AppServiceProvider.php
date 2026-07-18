<?php

namespace App\Providers;

use App\Contracts\Communication\EmailProviderInterface;
use App\Contracts\Communication\SmsProviderInterface;
use App\Contracts\TeacherPortal\PushProviderInterface;
use App\Services\Communication\Providers\AfricasTalkingSmsProvider;
use App\Services\Communication\Providers\FakeSmsProvider;
use App\Services\Communication\Providers\LogEmailProvider;
use App\Services\Communication\Providers\ResendEmailProvider;
use App\Services\TeacherPortal\Providers\FirebasePushProvider;
use App\Services\TeacherPortal\Providers\LogPushProvider;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EmailProviderInterface::class, function () {
            return match (config('communication.email.provider')) {
                'resend' => new ResendEmailProvider,
                'log' => new LogEmailProvider,
                default => throw new RuntimeException('Unsupported communication email provider.'),
            };
        });
        $this->app->bind(SmsProviderInterface::class, function () {
            return match (config('communication.sms.provider')) {
                'africas_talking' => new AfricasTalkingSmsProvider,
                'fake' => new FakeSmsProvider,
                default => throw new RuntimeException('Unsupported communication SMS provider.'),
            };
        });
        $this->app->bind(PushProviderInterface::class, fn () => match (config('teacher_portal_phase_two.push_provider')) {
            'log' => new LogPushProvider,
            'firebase' => new FirebasePushProvider,
            default => throw new RuntimeException('Unsupported teacher push provider.'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
