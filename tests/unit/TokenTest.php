<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for token generation, hashing, and format validation.
 */
class TokenTest extends TestCase
{
    // -----------------------------------------------------------------------
    // mll_generate_token
    // -----------------------------------------------------------------------

    public function test_generate_token_returns_64_char_hex_string(): void
    {
        $token = mll_generate_token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_generate_token_is_different_each_call(): void
    {
        $a = mll_generate_token();
        $b = mll_generate_token();
        $this->assertNotSame($a, $b);
    }

    // -----------------------------------------------------------------------
    // mll_hash_token
    // -----------------------------------------------------------------------

    public function test_hash_token_returns_sha256_hex(): void
    {
        $hash = mll_hash_token('abc');
        $this->assertSame(hash('sha256', 'abc'), $hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_same_input_always_produces_same_hash(): void
    {
        $this->assertSame(mll_hash_token('x'), mll_hash_token('x'));
    }

    public function test_different_inputs_produce_different_hashes(): void
    {
        $this->assertNotSame(mll_hash_token('a'), mll_hash_token('b'));
    }

    // -----------------------------------------------------------------------
    // mll_is_valid_token_format
    // -----------------------------------------------------------------------

    public function test_valid_token_format_accepted(): void
    {
        $token = mll_generate_token(); // 64 hex chars
        $this->assertTrue(mll_is_valid_token_format($token));
    }

    public function test_too_short_token_rejected(): void
    {
        $this->assertFalse(mll_is_valid_token_format('abc123'));
    }

    public function test_non_hex_token_rejected(): void
    {
        // 64 chars but contains non-hex character 'g'
        $this->assertFalse(mll_is_valid_token_format(str_repeat('g', 64)));
    }

    public function test_empty_string_rejected(): void
    {
        $this->assertFalse(mll_is_valid_token_format(''));
    }

    public function test_uppercase_hex_rejected(): void
    {
        // We use lowercase hex; uppercase should be rejected
        $this->assertFalse(mll_is_valid_token_format(strtoupper(mll_generate_token())));
    }
}
