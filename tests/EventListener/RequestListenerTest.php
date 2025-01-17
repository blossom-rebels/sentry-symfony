<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class RequestListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var MockObject&TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var RequestListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->listener = new RequestListener($this->hub, $this->tokenStorage);
    }

    /**
     * @dataProvider handleKernelRequestEventForSymfonyVersionAtLeast43DataProvider
     * @dataProvider handleKernelRequestEventForSymfonyVersionLowerThan43DataProvider
     *
     * @param GetResponseEvent|RequestEvent $requestEvent
     */
    public function testHandleKernelRequestEvent($requestEvent, ?TokenInterface $token, UserDataBag $expectedUser): void
    {
        $scope = new Scope();

        $this->tokenStorage->expects($this->once())
            ->method('getToken')
            ->willReturn($token);

        $this->hub->expects($this->once())
            ->method('configureScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->listener->handleKernelRequestEvent($requestEvent);

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertEquals($expectedUser, $event->getUser());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventForSymfonyVersionLowerThan43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '>=')) {
            return;
        }

        yield 'token IS NULL' => [
            new GetResponseEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            null,
            UserDataBag::createFromUserIpAddress('127.0.0.1'),
        ];

        yield 'token.authenticated = FALSE' => [
            new GetResponseEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            new class() extends AbstractToken {
                public function __construct()
                {
                    parent::__construct();

                    $this->setAuthenticated(false);
                }

                public function getCredentials()
                {
                    return null;
                }
            },
            UserDataBag::createFromUserIpAddress('127.0.0.1'),
        ];

        yield 'token.authenticated = TRUE && token.user IS NULL' => [
            new GetResponseEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            new class() extends AbstractToken {
                public function __construct()
                {
                    parent::__construct();

                    $this->setAuthenticated(true);
                }

                public function getCredentials()
                {
                    return null;
                }
            },
            UserDataBag::createFromUserIpAddress('127.0.0.1'),
        ];

        yield 'token.authenticated = TRUE && token.user INSTANCEOF string' => [
            new GetResponseEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            new class() extends AbstractToken {
                public function __construct()
                {
                    parent::__construct();

                    $this->setAuthenticated(true);
                    $this->setUser('foo_user');
                }

                public function getCredentials()
                {
                    return null;
                }
            },
            new UserDataBag(null, null, '127.0.0.1', 'foo_user'),
        ];

        yield 'token.authenticated = TRUE && token.user INSTANCEOF UserInterface' => [
            new GetResponseEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            new class() extends AbstractToken {
                public function __construct()
                {
                    parent::__construct();

                    $this->setAuthenticated(true);
                    $this->setUser(new class() implements UserInterface {
                        public function getRoles()
                        {
                            return [];
                        }

                        public function getPassword()
                        {
                            return null;
                        }

                        public function getSalt()
                        {
                            return null;
                        }

                        public function getUsername()
                        {
                            return 'foo_user';
                        }

                        public function eraseCredentials(): void
                        {
                        }
                    });
                }

                public function getCredentials()
                {
                    return null;
                }
            },
            new UserDataBag(null, null, '127.0.0.1', 'foo_user'),
        ];

        yield 'token.authenticated = TRUE && token.user INSTANCEOF object && __toString() method EXISTS' => [
            new GetResponseEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            new class() extends AbstractToken {
                public function __construct()
                {
                    parent::__construct();

                    $this->setAuthenticated(true);
                    $this->setUser(new class() {
                        public function __toString(): string
                        {
                            return 'foo_user';
                        }
                    });
                }

                public function getCredentials()
                {
                    return null;
                }
            },
            new UserDataBag(null, null, '127.0.0.1', 'foo_user'),
        ];
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventForSymfonyVersionAtLeast43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '<')) {
            return;
        }

        yield 'token IS NULL' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            null,
            UserDataBag::createFromUserIpAddress('127.0.0.1'),
        ];

        yield 'token.authenticated = FALSE' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            new class() extends AbstractToken {
                public function __construct()
                {
                    parent::__construct();

                    $this->setAuthenticated(false);
                }

                public function getCredentials()
                {
                    return null;
                }
            },
            UserDataBag::createFromUserIpAddress('127.0.0.1'),
        ];

        yield 'token.authenticated = TRUE && token.user IS NULL' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            new class() extends AbstractToken {
                public function __construct()
                {
                    parent::__construct();

                    $this->setAuthenticated(true);
                }

                public function getCredentials()
                {
                    return null;
                }
            },
            UserDataBag::createFromUserIpAddress('127.0.0.1'),
        ];

        yield 'token.authenticated = TRUE && token.user INSTANCEOF string' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            new class() extends AbstractToken {
                public function __construct()
                {
                    parent::__construct();

                    $this->setAuthenticated(true);
                    $this->setUser('foo_user');
                }

                public function getCredentials()
                {
                    return null;
                }
            },
            new UserDataBag(null, null, '127.0.0.1', 'foo_user'),
        ];

        yield 'token.authenticated = TRUE && token.user INSTANCEOF UserInterface' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            new class() extends AbstractToken {
                public function __construct()
                {
                    parent::__construct();

                    $this->setAuthenticated(true);
                    $this->setUser(new class() implements UserInterface {
                        public function getRoles()
                        {
                            return [];
                        }

                        public function getPassword()
                        {
                            return null;
                        }

                        public function getSalt()
                        {
                            return null;
                        }

                        public function getUsername()
                        {
                            return 'foo_user';
                        }

                        public function eraseCredentials(): void
                        {
                        }
                    });
                }

                public function getCredentials()
                {
                    return null;
                }
            },
            new UserDataBag(null, null, '127.0.0.1', 'foo_user'),
        ];

        yield 'token.authenticated = TRUE && token.user INSTANCEOF object && __toString() method EXISTS' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            new class() extends AbstractToken {
                public function __construct()
                {
                    parent::__construct();

                    $this->setAuthenticated(true);
                    $this->setUser(new class() {
                        public function __toString(): string
                        {
                            return 'foo_user';
                        }
                    });
                }

                public function getCredentials()
                {
                    return null;
                }
            },
            new UserDataBag(null, null, '127.0.0.1', 'foo_user'),
        ];
    }

    /**
     * @dataProvider handleKernelControllerEventWithSymfonyVersionAtLeast43DataProvider
     * @dataProvider handleKernelControllerEventWithSymfonyVersionLowerThan43DataProvider
     *
     * @param ControllerEvent|FilterControllerEvent $controllerEvent
     * @param array<string, string>                 $expectedTags
     */
    public function testHandleKernelControllerEvent($controllerEvent, array $expectedTags): void
    {
        $scope = new Scope();

        $this->hub->expects($this->any())
            ->method('configureScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->listener->handleKernelControllerEvent($controllerEvent);

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertSame($expectedTags, $event->getTags());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelControllerEventWithSymfonyVersionAtLeast43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '<')) {
            return;
        }

        yield 'event.requestType != MASTER_REQUEST' => [
            new ControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request([], [], ['_route' => 'homepage']),
                HttpKernelInterface::SUB_REQUEST
            ),
            [],
        ];

        yield 'event.requestType = MASTER_REQUEST && request.attributes._route NOT EXISTS ' => [
            new ControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request(),
                HttpKernelInterface::MASTER_REQUEST
            ),
            [],
        ];

        yield 'event.requestType = MASTER_REQUEST && request.attributes._route EXISTS' => [
            new ControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request([], [], ['_route' => 'homepage']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            [
                'route' => 'homepage',
            ],
        ];
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelControllerEventWithSymfonyVersionLowerThan43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '>=')) {
            return;
        }

        yield 'event.requestType != MASTER_REQUEST' => [
            new FilterControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request([], [], ['_route' => 'homepage']),
                HttpKernelInterface::SUB_REQUEST
            ),
            [],
        ];

        yield 'event.requestType = MASTER_REQUEST && request.attributes._route NOT EXISTS ' => [
            new FilterControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request(),
                HttpKernelInterface::MASTER_REQUEST
            ),
            [],
        ];

        yield 'event.requestType = MASTER_REQUEST && request.attributes._route EXISTS' => [
            new FilterControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request([], [], ['_route' => 'homepage']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            [
                'route' => 'homepage',
            ],
        ];
    }
}
