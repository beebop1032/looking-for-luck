<?php

declare(strict_types=1);

namespace App\Web;

use App\Lottery\FileGridStorage;
use App\Lottery\GridStorage;
use App\Lottery\GridStorageFactory;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Where "My grids" live. By default (LFL_GRIDS=server) in a file on the
 * server, shared by everyone who opens the app: right for a personal
 * install. With LFL_GRIDS=browser, the web interface keeps each visitor's
 * grids in a cookie of their own browser instead: right for a public
 * deployment, which has no accounts. The command line and the scheduled sync
 * always use the file.
 *
 * The cookie is strictly necessary to the feature the visitor asks for, so it
 * needs no consent; it is HttpOnly and never leaves this site.
 */
#[AsAlias(GridStorageFactory::class)]
final class BrowserGrids implements GridStorageFactory, EventSubscriberInterface
{
    private const COOKIE_PREFIX = 'lfl_grids_';
    private const LIFETIME = '+400 days'; // the longest browsers accept

    /** @var array<string, string> cookie values to send, by lottery slug */
    private array $pending = [];

    public function __construct(
        private readonly RequestStack $requestStack,
        #[Autowire('%app.grids%')]
        private readonly string $mode = 'server',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    /**
     * Are the grids of this request kept in the browser?
     */
    public function enabled(): bool
    {
        return 'browser' === $this->mode && null !== $this->requestStack->getMainRequest();
    }

    public function create(string $slug, string $dataDir): GridStorage
    {
        return $this->enabled() ? new CookieGridStorage($this, $slug) : new FileGridStorage($dataDir);
    }

    public function read(string $slug): ?string
    {
        if (isset($this->pending[$slug])) {
            return $this->pending[$slug];
        }
        $value = $this->requestStack->getMainRequest()?->cookies->get(self::COOKIE_PREFIX.$slug);

        return \is_string($value) ? $value : null;
    }

    public function write(string $slug, string $value): void
    {
        $this->pending[$slug] = $value;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || [] === $this->pending) {
            return;
        }

        $secure = $event->getRequest()->isSecure();
        foreach ($this->pending as $slug => $value) {
            $event->getResponse()->headers->setCookie(
                '' === $value
                    ? Cookie::create(self::COOKIE_PREFIX.$slug, null, 1, '/', null, $secure, true, false, Cookie::SAMESITE_LAX)
                    : Cookie::create(self::COOKIE_PREFIX.$slug, $value, new \DateTimeImmutable(self::LIFETIME), '/', null, $secure, true, false, Cookie::SAMESITE_LAX),
            );
        }
        $this->pending = [];
    }
}
