<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\EventSubscriber\ConsoleLocaleSubscriber;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Translation\LocaleSwitcher;

final class ConsoleLocaleTest extends KernelTestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['LFL_LOCALE', 'LC_ALL', 'LC_MESSAGES'] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
        unset($_SERVER['LFL_LOCALE']);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            false === $value ? putenv($name) : putenv($name.'='.$value);
        }
        \Locale::setDefault('en');
        parent::tearDown();
    }

    public function testLocalePriority(): void
    {
        $subscriber = new ConsoleLocaleSubscriber(new LocaleSwitcher('en', []), ['en', 'fr', 'nl', 'es', 'de']);

        self::assertSame('en', $subscriber->resolve(null), 'English unless asked');

        putenv('LANG=fr_BE.UTF-8');
        self::assertSame('en', $subscriber->resolve(null), 'LANG alone does not switch: opt-in only');

        putenv('LC_MESSAGES=nl_BE.UTF-8');
        self::assertSame('nl', $subscriber->resolve(null));

        putenv('LC_ALL=de_DE.UTF-8');
        self::assertSame('de', $subscriber->resolve(null));

        putenv('LFL_LOCALE=es');
        self::assertSame('es', $subscriber->resolve(null));

        self::assertSame('fr', $subscriber->resolve('fr'), '--locale wins');
        self::assertSame('es', $subscriber->resolve('xx'), 'unknown languages are ignored');
        putenv('LANG');
    }

    public function testCommandsSpeakFrenchAndKeepTheHonestWording(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $tester->run(['command' => 'app:euromillions:predict', 'count' => '2', '--offline' => true, '--locale' => 'fr', '--no-ansi' => true]);
        $output = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $output);
        self::assertStringContainsString('2 grilles EuroMillions', $output);
        // The reality check survives translation.
        self::assertStringContainsString('Le filtre ne change pas vos chances', $output);
        self::assertStringContainsString('139 838 160', preg_replace('/\s+/u', ' ', $output) ?? '');

        $tester->run(['command' => 'app:euromillions:predict', 'count' => '1', '--offline' => true, '--no-ansi' => true]);
        self::assertStringContainsString('The filter does not change your odds', $tester->getDisplay(), 'English by default');
    }
}
