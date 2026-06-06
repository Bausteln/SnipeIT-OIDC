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

    public function test_three_token_noble_particle_surname_stays_with_last(): void
    {
        $this->assertSame(['Andrin', 'von Monn'], NameSplitter::split('Andrin von Monn'));
    }

    public function test_three_token_name_splits_after_first_token(): void
    {
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

    public function test_tab_separated_tokens_are_split(): void
    {
        $this->assertSame(['Andrin', 'Monn'], NameSplitter::split("Andrin\tMonn"));
    }

    public function test_blank_whitespace_input_returns_two_empties(): void
    {
        $this->assertSame(['', ''], NameSplitter::split('   '));
    }

    public function test_empty_string_returns_two_empties(): void
    {
        $this->assertSame(['', ''], NameSplitter::split(''));
    }
}
