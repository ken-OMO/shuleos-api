<?php

namespace App\Providers;

use App\Contracts\Communication\EmailProviderInterface;
use App\Contracts\Communication\SmsProviderInterface;
use App\Contracts\ParentPortal\PaymentProviderInterface;
use App\Contracts\TeacherPortal\PushProviderInterface;
use App\Core\Security\File\Contracts\VirusScannerInterface;
use App\Core\Security\File\FileSecurityManager;
use App\Core\Security\File\Scanners\ClamAVScanner;
use App\Core\Security\File\Scanners\NullVirusScanner;
use App\Core\Security\File\Validators\ArchiveValidator;
use App\Core\Security\File\Validators\ExtensionValidator;
use App\Core\Security\File\Validators\MagicNumberValidator;
use App\Core\Security\File\Validators\MimeValidator;
use App\Core\Security\File\Validators\OfficeDocumentValidator;
use App\Core\Security\File\Validators\VirusScanner;
use App\Services\Communication\Providers\AfricasTalkingSmsProvider;
use App\Services\Communication\Providers\FakeSmsProvider;
use App\Services\Communication\Providers\LogEmailProvider;
use App\Services\Communication\Providers\ResendEmailProvider;
use App\Services\ParentPortal\Providers\FakePaymentProvider;
use App\Services\ParentPortal\Providers\LogPaymentProvider;
use App\Services\ParentPortal\Providers\MpesaPaymentProvider;
use App\Services\TeacherPortal\Providers\FirebasePushProvider;
use App\Services\TeacherPortal\Providers\LogPushProvider;
use ArrayIterator;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VirusScannerInterface::class, function () {
            return app()->environment(['local', 'testing'])
                ? new NullVirusScanner
                : new ClamAVScanner;
        });

        $this->app->singleton(FileSecurityManager::class, function ($app) {
            return new FileSecurityManager(new ArrayIterator([
                $app->make(ExtensionValidator::class),
                $app->make(MimeValidator::class),
                $app->make(MagicNumberValidator::class),
                $app->make(ArchiveValidator::class),
                $app->make(OfficeDocumentValidator::class),
                $app->make(VirusScanner::class),
            ]));
        });

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
        $this->app->bind(PaymentProviderInterface::class, fn () => match (config('parent_portal_phase_two.payment_provider')) {
            'mpesa' => new MpesaPaymentProvider,
            'fake' => new FakePaymentProvider,
            'log' => new LogPaymentProvider,
            default => throw new RuntimeException('Unsupported parent payment provider.'),
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
