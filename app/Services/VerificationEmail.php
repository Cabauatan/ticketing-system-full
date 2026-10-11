<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\ExceptionInterface as MailerException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use TypeError;

class VerificationEmail
{
    private const REQUEST_ATTRIBUTE = 'verification_email_delivery';

    private const UNAVAILABLE_MESSAGE = 'We could not send the verification email. Please try again later or contact an administrator.';

    public function send(User $user): void
    {
        request()->attributes->set(self::REQUEST_ATTRIBUTE, [
            'user_id' => $user->getKey(),
            'status' => 'unavailable',
            'message' => self::UNAVAILABLE_MESSAGE,
        ]);
        $mailer = config('mail.default');

        // Resolve configuration separately: setup failures must not disguise
        // unrelated errors from rendering the notification or database writes.
        try {
            $transport = $this->verificationTransport($mailer);
            if ($transport === null) {
                throw new InvalidArgumentException('This transport does not deliver email.');
            }
            $instance = Mail::mailer($mailer);
            $originalTransport = $instance->getSymfonyTransport();
        } catch (InvalidArgumentException|MailerException|TypeError $exception) {
            $this->logFailure($mailer, $exception::class);

            return;
        }

        // Use only delivery transports for this notification. Restore the cached
        // mailer afterwards so other mail keeps its configured transport policy.
        $instance->setSymfonyTransport($transport);
        try {
            $user->notify(new VerifyEmail);
        } catch (TransportExceptionInterface|RfcComplianceException $exception) {
            $this->logFailure($mailer, $exception::class);
        } finally {
            $instance->setSymfonyTransport($originalTransport);
        }
    }

    public function recordSent(NotificationSent $event): void
    {
        $delivery = request()->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! is_array($delivery)
            || ! $event->notification instanceof VerifyEmail
            || $event->channel !== 'mail'
            || ! $event->response instanceof SentMessage
            || $event->notifiable->getKey() !== $delivery['user_id']) {
            return;
        }

        request()->attributes->set(self::REQUEST_ATTRIBUTE, [
            'user_id' => $delivery['user_id'],
            'status' => 'sent',
            'message' => 'A verification email has been sent. Check your inbox and spam folder.',
        ]);
    }

    /** @return array{status: string, message: string} */
    public function result(): array
    {
        $delivery = request()->attributes->get(self::REQUEST_ATTRIBUTE);

        return [
            'status' => is_array($delivery) && $delivery['status'] === 'sent' ? 'sent' : 'unavailable',
            'message' => is_array($delivery) ? $delivery['message'] : self::UNAVAILABLE_MESSAGE,
        ];
    }

    /** @param array<int, string> $visited */
    private function verificationTransport(mixed $name, array $visited = []): ?TransportInterface
    {
        if (! is_string($name) || in_array($name, $visited, true)) {
            throw new InvalidArgumentException('Invalid mailer configuration.');
        }

        $settings = config('mail.mailers.'.$name);
        if (! is_array($settings)) {
            throw new InvalidArgumentException('Mailer configuration is missing.');
        }
        if (isset($settings['url'])) {
            $settings = array_merge($settings, (new ConfigurationUrlParser)->parseConfiguration($settings));
            $settings['transport'] = $settings['driver'] ?? null;
        }
        $transport = $settings['transport'] ?? null;

        if (app()->isProduction() && in_array($transport, ['log', 'array', 'null'], true)) {
            return null;
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            if (empty($settings['mailers']) || ! is_array($settings['mailers'])) {
                throw new InvalidArgumentException('Mail transport list is missing.');
            }
            $transports = [];
            foreach ($settings['mailers'] as $child) {
                $childTransport = $this->verificationTransport($child, [...$visited, $name]);
                if ($childTransport !== null) {
                    $transports[] = $childTransport;
                }
            }
            if ($transports === []) {
                return null;
            }

            // Symfony composite transports normally log provider exceptions,
            // which can contain credentials or the signed verification URL.
            $class = $transport === 'failover' ? FailoverTransport::class : RoundRobinTransport::class;

            return new $class($transports, $settings['retry_after'] ?? 60, new NullLogger);
        }

        $dependency = match ($transport) {
            'resend' => 'Resend',
            'postmark' => 'Symfony\\Component\\Mailer\\Bridge\\Postmark\\Transport\\PostmarkTransportFactory',
            'mailgun' => 'Symfony\\Component\\Mailer\\Bridge\\Mailgun\\Transport\\MailgunTransportFactory',
            'ses' => 'Aws\\Ses\\SesClient',
            'ses-v2' => 'Aws\\SesV2\\SesV2Client',
            default => null,
        };
        if ($dependency !== null && ! class_exists($dependency)) {
            throw new InvalidArgumentException('Mail driver dependency is missing.');
        }
        if (in_array($transport, ['postmark', 'mailgun'], true)
            && ! empty($settings['client'])
            && ! class_exists('Symfony\\Component\\HttpClient\\HttpClient')) {
            throw new InvalidArgumentException('Mail HTTP client dependency is missing.');
        }

        return Mail::mailer($name)->getSymfonyTransport();
    }

    private function logFailure(mixed $mailer, string $exceptionType): void
    {
        // Provider exception messages may contain credentials or signed URLs.
        Log::warning('Verification email delivery unavailable.', [
            'mailer' => is_string($mailer) && preg_match('/^[a-zA-Z0-9_-]+$/', $mailer) ? $mailer : 'invalid',
            'exception_type' => $exceptionType,
        ]);
    }
}
