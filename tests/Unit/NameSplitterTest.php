<?php

namespace Bausteln\SnipeitOidc\Tests\Unit;

use Bausteln\SnipeitOidc\Support\NameSplitter;
use PHPUnit\Framework\TestCase;

class NameSplitterTest extends TestCase
{
    public function test_two_tokens_split_into_first_and_last(): void
    {
        $this->assertSame(['Andrin', 'Monn'], NameSplitter::split('Andrin Monn'));
    }

    public function test_multi_word_surname_stays_with_last_name(): void
    {
        $this->assertSame(['Andrin', 'von Monn'], NameSplitter::split('Andrin von Monn'));
        $this->assertSame(['Anna', 'Maria Müller'], NameSplitter::split('Anna Maria Müller'));
    }

    public function test_single_token_has_empty_last_name(): void
    {
        $this->assertSame(['Cher', ''], NameSplitter::split('Cher'));
    }

    public function test_surrounding_and_inner_whitespace_is_normalised(): void
    {
        $this->assertSame(['Andrin', 'Monn'], NameSplitter::split("  Andrin   Monn  "));
    }

    public function test_blank_input_returns_two_empties(): void
    {
        $this->assertSame(['', ''], NameSplitter::split('   '));
    }
}
