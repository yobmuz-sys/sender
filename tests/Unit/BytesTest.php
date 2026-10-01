<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Bytes;
use PHPUnit\Framework\TestCase;

class BytesTest extends TestCase
{
    public function test_it_parses_ini_shorthand(): void
    {
        $this->assertSame(512 * 1024 * 1024, Bytes::fromIni('512M'));
        $this->assertSame(1024 * 1024 * 1024, Bytes::fromIni('1G'));
        $this->assertSame(2048, Bytes::fromIni('2k'));
        $this->assertSame(1024 * 1024, Bytes::fromIni('1048576'));
        $this->assertSame(0, Bytes::fromIni(''));
    }

    public function test_it_preserves_a_negative_value_as_unlimited(): void
    {
        $this->assertSame(-1, Bytes::fromIni('-1'));
    }

    public function test_it_humanizes_byte_counts(): void
    {
        $this->assertSame('1K', Bytes::humanize(1024));
        $this->assertSame('512M', Bytes::humanize(512 * 1024 * 1024));
        $this->assertSame('0B', Bytes::humanize(0));
        $this->assertSame('unlimited', Bytes::humanize(-1));
    }
}
