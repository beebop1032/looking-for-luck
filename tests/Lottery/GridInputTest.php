<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\EuromillionsGame;
use App\Lottery\GridInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GridInputTest extends TestCase
{
    public function testParsesSpacesAndCommas(): void
    {
        $input = new GridInput(new EuromillionsGame());

        self::assertSame('1-9-23-34-48|3-7', $input->parse('48 34 23 9 1 + 7 3')->signature());
        self::assertSame('1-9-23-34-48|3-7', $input->parse(' 1, 9, 23, 34, 48 +3,7 ')->signature());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'no stars' => ['1 9 23 34 48'];
        yield 'four numbers' => ['1 9 23 34 + 3 7'];
        yield 'out of range' => ['1 9 23 34 51 + 3 7'];
        yield 'duplicate number' => ['1 1 23 34 48 + 3 7'];
        yield 'text' => ['lucky numbers please'];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidInput(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new GridInput(new EuromillionsGame()))->parse($value);
    }

    public function testMultipleGridsOnlyWhenAllowed(): void
    {
        $input = new GridInput(new EuromillionsGame());

        self::assertCount(7, $input->parse('1 2 3 4 5 6 7 + 1 2 3', true)->main);

        $this->expectException(\InvalidArgumentException::class);
        $input->parse('1 2 3 4 5 6 7 + 1 2 3');
    }
}
