<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Picks the interface language: ?lang=xx (remembered in a cookie), then the
 * cookie, then the browser's Accept-Language, then English.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public const COOKIE = 'lfl_lang';
    private const CHOSEN = '_lfl_chosen_locale';

    /**
     * @param list<string> $locales
     */
    public function __construct(
        #[Autowire('%app.locales%')]
        private readonly array $locales,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before Symfony's LocaleListener (priority 16).
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $chosen = $request->query->get('lang');
        if (\is_string($chosen) && \in_array($chosen, $this->locales, true)) {
            $request->attributes->set(self::CHOSEN, $chosen);
            $request->setLocale($chosen);

            return;
        }

        $cookie = $request->cookies->get(self::COOKIE);
        if (\is_string($cookie) && \in_array($cookie, $this->locales, true)) {
            $request->setLocale($cookie);

            return;
        }

        $request->setLocale($request->getPreferredLanguage($this->locales) ?? $this->locales[0]);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $chosen = $event->getRequest()->attributes->get(self::CHOSEN);
        if ($event->isMainRequest() && \is_string($chosen)) {
            $event->getResponse()->headers->setCookie(
                Cookie::create(self::COOKIE, $chosen, new \DateTimeImmutable('+1 year'), '/', null, null, true, false, Cookie::SAMESITE_LAX),
            );
        }
    }
}
