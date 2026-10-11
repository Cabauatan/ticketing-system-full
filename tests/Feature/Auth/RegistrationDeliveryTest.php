<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\VerificationEmail;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class RegistrationDeliveryTest extends TestCase
{
    private RegistrationDeliveryTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('requester');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        config([
            'mail.default' => 'verification_test',
            'mail.mailers.verification_test' => ['transport' => 'verification_test'],
            'mail.from.address' => 'support@example.com',
        ]);
        $this->transport = new RegistrationDeliveryTransport;
        Mail::extend('verification_test', fn () => $this->transport);
        Mail::purge();
    }

    public function test_registration_survives_a_mail_outage_without_verifying_or_losing_the_account(): void
    {
        $this->transport->fail = true;
        Log::spy();

        $response = $this->postJson('/register', $this->registration());

        $response->assertCreated()->assertJsonPath('verification.status', 'unavailable');
        $this->assertStringNotContainsString('smtp-password-secret', $response->getContent());
        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('requester', $user->role);
        $this->assertNull($user->email_verified_at);
        $this->getJson('/api/tickets')->assertForbidden();
        Log::shouldHaveReceived('warning')->once()->with('Verification email delivery unavailable.', [
            'mailer' => 'verification_test',
            'exception_type' => TransportException::class,
        ]);
    }

    public function test_registration_reports_success_only_after_the_mail_transport_accepts_the_notification(): void
    {
        $this->postJson('/register', $this->registration())
            ->assertCreated()->assertJsonPath('verification.status', 'sent');

        $this->assertAuthenticatedAs(User::firstOrFail());
        $this->assertNull(User::firstOrFail()->email_verified_at);
        $this->assertSame(1, $this->transport->sent);
    }

    public function test_production_registration_keeps_the_password_policy_and_reports_field_errors(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(fn () => 'production');
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $weakRegistration = [...$this->registration(), 'password' => 'password', 'password_confirmation' => 'password'];

        $this->postJson('/register', $weakRegistration)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);

        $this->transport->fail = true;
        $this->postJson('/register', $this->registration())
            ->assertCreated()->assertJsonPath('verification.status', 'unavailable');
        $this->assertAuthenticatedAs(User::firstOrFail());
        $this->assertNull(User::firstOrFail()->email_verified_at);
    }

    public function test_resend_reports_unavailable_then_can_recover_without_changing_the_account(): void
    {
        $user = $this->newUser();
        $this->transport->fail = true;

        $this->actingAs($user)->postJson('/email/verification-notification')
            ->assertStatus(503)->assertJsonPath('verification.status', 'unavailable');
        $this->assertNull($user->fresh()->email_verified_at);

        $this->transport->fail = false;
        $this->postJson('/email/verification-notification')
            ->assertStatus(202)->assertJsonPath('verification.status', 'sent');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(1, $this->transport->sent);
    }

    public function test_a_verified_user_does_not_receive_another_verification_email(): void
    {
        $user = $this->newUser();
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user)->postJson('/email/verification-notification')->assertNoContent();

        $this->assertSame(0, $this->transport->sent);
    }

    public function test_production_log_transport_does_not_report_that_an_email_was_sent(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'log']);

        $this->actingAs($this->newUser())->postJson('/email/verification-notification')
            ->assertStatus(503)->assertJsonPath('verification.status', 'unavailable');
    }

    public function test_production_array_transport_does_not_report_that_an_email_was_sent(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'array']);

        $this->actingAs($this->newUser())->postJson('/email/verification-notification')
            ->assertStatus(503)->assertJsonPath('verification.status', 'unavailable');
    }

    public function test_production_failover_can_send_through_a_healthy_real_transport_before_log(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'mail.default' => 'verification_fallback',
            'mail.mailers.verification_fallback' => ['transport' => 'failover', 'mailers' => ['verification_test', 'log']],
        ]);
        $mailer = Mail::mailer('verification_fallback');
        $originalTransport = $mailer->getSymfonyTransport();
        $originalSettings = config('mail.mailers.verification_fallback');

        $this->actingAs($this->newUser())->postJson('/email/verification-notification')
            ->assertStatus(202)->assertJsonPath('verification.status', 'sent');
        $this->assertSame(1, $this->transport->sent);
        $this->assertSame($originalTransport, $mailer->getSymfonyTransport());
        $this->assertSame($originalSettings, config('mail.mailers.verification_fallback'));
    }

    public function test_production_failover_does_not_log_a_verification_email_when_real_delivery_fails(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(fn () => 'production');
        $this->transport->fail = true;
        Log::spy();
        $logger = $this->spy(LoggerInterface::class);
        config([
            'mail.default' => 'verification_fallback',
            'mail.mailers.verification_fallback' => ['transport' => 'failover', 'mailers' => ['verification_test', 'log']],
        ]);
        $mailer = Mail::mailer('verification_fallback');
        $originalTransport = $mailer->getSymfonyTransport();

        $this->actingAs($this->newUser())->postJson('/email/verification-notification')
            ->assertStatus(503)->assertJsonPath('verification.status', 'unavailable');

        $this->assertSame(1, $this->transport->attempts);
        $this->assertSame(0, $this->transport->sent);
        $this->assertSame($originalTransport, $mailer->getSymfonyTransport());
        $logger->shouldNotHaveReceived('debug');
        $logger->shouldNotHaveReceived('error');
        Log::shouldHaveReceived('warning')->once()->with('Verification email delivery unavailable.', [
            'mailer' => 'verification_fallback',
            'exception_type' => TransportException::class,
        ]);
    }

    public function test_production_failover_can_recover_through_another_real_transport(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(fn () => 'production');
        $this->transport->fail = true;
        $backup = new RegistrationDeliveryTransport;
        Mail::extend('verification_backup', fn () => $backup);
        Log::spy();
        $logger = $this->spy(LoggerInterface::class);
        config([
            'mail.default' => 'verification_fallback',
            'mail.mailers.verification_backup' => ['transport' => 'verification_backup'],
            'mail.mailers.verification_fallback' => [
                'transport' => 'failover', 'mailers' => ['verification_test', 'log', 'verification_backup'],
            ],
        ]);

        $this->actingAs($this->newUser())->postJson('/email/verification-notification')
            ->assertStatus(202)->assertJsonPath('verification.status', 'sent');

        $this->assertSame(1, $this->transport->attempts);
        $this->assertSame(1, $backup->sent);
        $logger->shouldNotHaveReceived('debug');
        $logger->shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_production_nested_composites_skip_non_delivery_children(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(fn () => 'production');
        $logger = $this->spy(LoggerInterface::class);
        config([
            'mail.default' => 'verification_rotation',
            'mail.mailers.verification_rotation' => ['transport' => 'roundrobin', 'mailers' => ['log', 'verification_fallback']],
            'mail.mailers.verification_fallback' => [
                'transport' => 'failover', 'mailers' => ['discarded_rotation', 'verification_test', 'array'],
            ],
            'mail.mailers.discarded_rotation' => ['transport' => 'roundrobin', 'mailers' => ['array', 'log']],
        ]);

        $this->actingAs($this->newUser())->postJson('/email/verification-notification')
            ->assertStatus(202)->assertJsonPath('verification.status', 'sent');

        $this->assertSame(1, $this->transport->sent);
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        $logger->shouldNotHaveReceived('debug');
    }

    public function test_production_composites_with_only_non_delivery_children_are_unavailable(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(fn () => 'production');
        Log::spy();
        config([
            'mail.default' => 'verification_rotation',
            'mail.mailers.verification_rotation' => ['transport' => 'roundrobin', 'mailers' => ['log', 'discarded_fallback']],
            'mail.mailers.discarded_fallback' => ['transport' => 'failover', 'mailers' => ['array', 'log']],
        ]);

        $this->actingAs($this->newUser())->postJson('/email/verification-notification')
            ->assertStatus(503)->assertJsonPath('verification.status', 'unavailable');

        $this->assertSame(0, $this->transport->attempts);
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        Log::shouldNotHaveReceived('debug');
    }

    public function test_cyclic_composite_mailers_are_rejected_before_sending(): void
    {
        config([
            'mail.default' => 'verification_fallback',
            'mail.mailers.verification_fallback' => ['transport' => 'failover', 'mailers' => ['verification_test', 'verification_rotation']],
            'mail.mailers.verification_rotation' => ['transport' => 'roundrobin', 'mailers' => ['verification_fallback']],
        ]);

        $this->actingAs($this->newUser())->postJson('/email/verification-notification')
            ->assertStatus(503)->assertJsonPath('verification.status', 'unavailable');

        $this->assertSame(0, $this->transport->attempts);
    }

    public function test_a_missing_composite_child_is_rejected_before_sending(): void
    {
        config([
            'mail.default' => 'verification_fallback',
            'mail.mailers.verification_fallback' => ['transport' => 'failover', 'mailers' => ['verification_test', 'not_configured']],
        ]);

        $this->actingAs($this->newUser())->postJson('/email/verification-notification')
            ->assertStatus(503)->assertJsonPath('verification.status', 'unavailable');

        $this->assertSame(0, $this->transport->attempts);
    }

    public function test_a_missing_provider_dependency_is_reported_without_breaking_registration(): void
    {
        $this->assertFalse(class_exists('Aws\\Ses\\SesClient'));
        config(['mail.default' => 'ses']);

        $this->postJson('/register', $this->registration())
            ->assertCreated()->assertJsonPath('verification.status', 'unavailable');

        $this->assertAuthenticatedAs(User::firstOrFail());
        $this->assertSame(0, $this->transport->attempts);
    }

    public function test_a_missing_mailer_returns_a_clear_delivery_failure_instead_of_breaking_registration(): void
    {
        config(['mail.default' => 'not_configured']);

        $this->postJson('/register', $this->registration())
            ->assertCreated()->assertJsonPath('verification.status', 'unavailable');
        $this->assertAuthenticatedAs(User::firstOrFail());
    }

    public function test_an_unsupported_transport_does_not_break_registration(): void
    {
        config(['mail.mailers.verification_test.transport' => 'unsupported_transport']);

        $this->postJson('/register', $this->registration())
            ->assertCreated()->assertJsonPath('verification.status', 'unavailable');
        $this->assertAuthenticatedAs(User::firstOrFail());
    }

    public function test_a_cancelled_notification_is_not_claimed_as_sent(): void
    {
        Event::listen(MessageSending::class, fn () => false);

        $this->postJson('/register', $this->registration())
            ->assertCreated()->assertJsonPath('verification.status', 'unavailable');
        $this->assertSame(0, $this->transport->sent);
    }

    public function test_delivery_status_is_not_reused_for_a_different_request(): void
    {
        $this->actingAs($this->newUser())->postJson('/email/verification-notification')
            ->assertJsonPath('verification.status', 'sent');
        $this->app->instance('request', Request::create('/different-request'));

        $this->assertSame('unavailable', app(VerificationEmail::class)->result()['status']);
    }

    public function test_unrelated_notification_errors_are_not_swallowed_as_mail_failures(): void
    {
        config([
            'mail.default' => 'verification_fallback',
            'mail.mailers.verification_fallback' => ['transport' => 'failover', 'mailers' => ['verification_test', 'log']],
        ]);
        $mailer = Mail::mailer('verification_fallback');
        $originalTransport = $mailer->getSymfonyTransport();
        Event::listen(MessageSending::class, fn () => throw new RuntimeException('Unrelated notification bug.'));
        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unrelated notification bug.');

        try {
            $this->actingAs($this->newUser())->postJson('/email/verification-notification');
        } finally {
            $this->assertSame($originalTransport, $mailer->getSymfonyTransport());
        }
    }

    /** @return array<string, string> */
    private function registration(): array
    {
        return [
            'name' => 'New Requester',
            'email' => 'new-requester@example.com',
            'password' => 'Registration-pass_26',
            'password_confirmation' => 'Registration-pass_26',
        ];
    }

    private function newUser(): User
    {
        return User::create([
            'name' => 'Unverified Requester',
            'email' => 'requester@example.com',
            'password' => 'Requester-pass_26',
        ]);
    }
}

class RegistrationDeliveryTransport extends AbstractTransport
{
    public bool $fail = false;

    public int $attempts = 0;

    public int $sent = 0;

    protected function doSend(SentMessage $message): void
    {
        $this->attempts++;
        if ($this->fail) {
            throw new TransportException('Provider unavailable; smtp-password-secret must not appear in logs or responses.');
        }
        $this->sent++;
    }

    public function __toString(): string
    {
        return 'verification_test';
    }
}
