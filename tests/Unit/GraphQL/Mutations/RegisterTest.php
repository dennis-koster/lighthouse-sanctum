<?php

declare(strict_types=1);

namespace DanielDeWit\LighthouseSanctum\Tests\Unit\GraphQL\Mutations;

use DanielDeWit\LighthouseSanctum\Contracts\Services\EmailVerificationServiceInterface;
use DanielDeWit\LighthouseSanctum\Enums\RegisterStatus;
use DanielDeWit\LighthouseSanctum\Exceptions\HasApiTokensException;
use DanielDeWit\LighthouseSanctum\GraphQL\Mutations\Register;
use DanielDeWit\LighthouseSanctum\Tests\stubs\Users\UserHasApiTokens;
use DanielDeWit\LighthouseSanctum\Tests\stubs\Users\UserMustVerifyEmail;
use DanielDeWit\LighthouseSanctum\Tests\Traits\MocksUserProvider;
use DanielDeWit\LighthouseSanctum\Tests\Unit\AbstractUnitTest;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Auth\User;
use Laravel\Sanctum\NewAccessToken;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;

class RegisterTest extends AbstractUnitTest
{
    use MocksUserProvider;

    /**
     * @test
     */
    public function it_registers_a_user(): void
    {
        $token = Mockery::mock(NewAccessToken::class);
        $token->plainTextToken = '1234567890';

        /** @var UserHasApiTokens|MockInterface $user */
        $user = $this->mockUser(UserHasApiTokens::class)
            ->shouldReceive('createToken')
            ->with('default')
            ->andReturn($token)
            ->getMock();

        $userProvider = $this->mockUserProvider($user);

        $mutation = new Register(
            $this->mockAuthManager($userProvider),
            $this->mockConfig(),
            $this->mockHasher(),
            Mockery::mock(EmailVerificationServiceInterface::class),
        );

        $result = $mutation(null, [
            'name'                  => 'Foo Bar',
            'email'                 => 'foo@bar.com',
            'password'              => 'supersecret',
            'password_confirmation' => 'supersecret',
        ]);

        static::assertIsArray($result);
        static::assertCount(2, $result);
        static::assertTrue(RegisterStatus::SUCCESS()->is($result['status']));
        static::assertSame('1234567890', $result['token']);
    }

    /**
     * @test
     */
    public function it_sends_an_email_verification_notification(): void
    {
        $token = Mockery::mock(NewAccessToken::class);
        $token->plainTextToken = '1234567890';

        /** @var UserMustVerifyEmail|MockInterface $user */
        $user = $this->mockUser(UserMustVerifyEmail::class)
            ->shouldReceive('sendEmailVerificationNotification')
            ->getMock()
            ->shouldReceive('createToken')
            ->with('default')
            ->andReturn($token)
            ->getMock();

        $userProvider = $this->mockUserProvider($user);

        $mutation = new Register(
            $this->mockAuthManager($userProvider),
            $this->mockConfig(),
            $this->mockHasher(),
            Mockery::mock(EmailVerificationServiceInterface::class),
        );

        $result = $mutation(null, [
            'name'                  => 'Foo Bar',
            'email'                 => 'foo@bar.com',
            'password'              => 'supersecret',
            'password_confirmation' => 'supersecret',
        ]);

        static::assertIsArray($result);
        static::assertCount(2, $result);
        static::assertTrue(RegisterStatus::MUST_VERIFY_EMAIL()->is($result['status']));
        static::assertNull($result['token']);
    }

    /**
     * @test
     */
    public function it_sends_an_email_verification_notification_with_a_custom_url(): void
    {
        $token = Mockery::mock(NewAccessToken::class);
        $token->plainTextToken = '1234567890';

        /** @var UserMustVerifyEmail|MockInterface $user */
        $user = $this->mockUser(UserMustVerifyEmail::class)
            ->shouldReceive('sendEmailVerificationNotification')
            ->getMock()
            ->shouldReceive('createToken')
            ->with('default')
            ->andReturn($token)
            ->getMock();

        $userProvider = $this->mockUserProvider($user);

        /** @var EmailVerificationServiceInterface|MockInterface $verificationService */
        $verificationService = Mockery::mock(EmailVerificationServiceInterface::class)
            ->shouldReceive('setVerificationUrl')
            ->with('custom-url')
            ->getMock();

        $mutation = new Register(
            $this->mockAuthManager($userProvider),
            $this->mockConfig(),
            $this->mockHasher(),
            $verificationService,
        );

        $result = $mutation(null, [
            'name'                  => 'Foo Bar',
            'email'                 => 'foo@bar.com',
            'password'              => 'supersecret',
            'password_confirmation' => 'supersecret',
            'verification_url'      => [
                'url' => 'custom-url',
            ],
        ]);

        static::assertIsArray($result);
        static::assertCount(2, $result);
        static::assertTrue(RegisterStatus::MUST_VERIFY_EMAIL()->is($result['status']));
        static::assertNull($result['token']);
    }

    /**
     * @test
     */
    public function it_throws_an_exception_if_the_user_provider_is_not_found(): void
    {
        static::expectException(RuntimeException::class);
        static::expectExceptionMessage('User provider not found.');

        $mutation = new Register(
            $this->mockAuthManager(null),
            $this->mockConfig(),
            $this->mockHasher(),
            Mockery::mock(EmailVerificationServiceInterface::class),
        );

        $mutation(null, [
            'name'                  => 'Foo Bar',
            'email'                 => 'foo@bar.com',
            'password'              => 'supersecret',
            'password_confirmation' => 'supersecret',
        ]);
    }

    /**
     * @test
     */
    public function it_throws_an_exception_if_the_user_does_not_have_the_has_api_tokens_trait(): void
    {
        $user = $this->mockUser(User::class);

        static::expectException(HasApiTokensException::class);
        static::expectExceptionMessage('"' . get_class($user) . '" must implement "Laravel\Sanctum\Contracts\HasApiTokens".');

        $userProvider = $this->mockUserProvider($user);

        $mutation = new Register(
            $this->mockAuthManager($userProvider),
            $this->mockConfig(),
            $this->mockHasher(),
            Mockery::mock(EmailVerificationServiceInterface::class),
        );

        $mutation(null, [
            'name'                  => 'Foo Bar',
            'email'                 => 'foo@bar.com',
            'password'              => 'supersecret',
            'password_confirmation' => 'supersecret',
        ]);
    }

    /**
     * @return Hasher|MockInterface
     */
    protected function mockHasher()
    {
        /** @var Hasher|MockInterface $hasher */
        $hasher = Mockery::mock(Hasher::class)
            ->shouldReceive('make')
            ->with('supersecret')
            ->andReturn('hashed-password')
            ->getMock();

        return $hasher;
    }

    /**
     * @return UserProvider|MockInterface
     */
    protected function mockUserProvider(?User $user)
    {
        /** @var UserProvider|MockInterface $userProvider */
        $userProvider = Mockery::mock(UserProvider::class)
            ->shouldReceive('createModel')
            ->andReturn($user)
            ->getMock();

        return $userProvider;
    }

    /**
     * @template T of User
     * @param class-string<T> $class
     * @return T|MockInterface
     */
    protected function mockUser(string $class)
    {
        /** @var T|MockInterface $user */
        $user = Mockery::mock($class)
            ->shouldReceive('fill')
            ->with([
                'name'     => 'Foo Bar',
                'email'    => 'foo@bar.com',
                'password' => 'hashed-password',
            ])
            ->andReturnSelf()
            ->getMock()
            ->shouldReceive('save')
            ->andReturnTrue()
            ->getMock();

        return $user;
    }
}
