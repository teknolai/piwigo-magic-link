<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for email validation and username derivation.
 */
class EmailValidationTest extends TestCase
{
    // -----------------------------------------------------------------------
    // mll_validate_email
    // -----------------------------------------------------------------------

    /** @dataProvider validEmails */
    public function test_valid_emails_are_accepted(string $email): void
    {
        $this->assertTrue(mll_validate_email($email), "Expected '{$email}' to be valid");
    }

    public static function validEmails(): array
    {
        return [
            ['test@example.com'],
            ['erik.korme@silverfin.com'],
            ['user+tag@domain.co.uk'],
            ['  padded@example.com  '], // leading/trailing spaces trimmed
        ];
    }

    /** @dataProvider invalidEmails */
    public function test_invalid_emails_are_rejected(string $email): void
    {
        $this->assertFalse(mll_validate_email($email), "Expected '{$email}' to be invalid");
    }

    public static function invalidEmails(): array
    {
        return [
            [''],
            ['notanemail'],
            ['missing@'],
            ['@nodomain.com'],
            ['spaces in@email.com'],
            ['double@@domain.com'],
        ];
    }

    // -----------------------------------------------------------------------
    // mll_derive_username
    // -----------------------------------------------------------------------

    public function test_simple_email_gives_local_part(): void
    {
        $this->assertSame('erikkorme', mll_derive_username('erik.korme@silverfin.com'));
    }

    public function test_dots_and_dashes_stripped(): void
    {
        $this->assertSame('johndoe', mll_derive_username('john.doe@example.com'));
    }

    public function test_plus_tag_stripped(): void
    {
        $this->assertSame('testtag', mll_derive_username('test+tag@example.com'));
    }

    public function test_result_is_lowercase(): void
    {
        $username = mll_derive_username('CAPS@example.com');
        $this->assertSame(strtolower($username), $username);
    }

    public function test_result_truncated_to_30_chars(): void
    {
        $username = mll_derive_username('averylongemailaddressprefixhere@example.com');
        $this->assertLessThanOrEqual(30, strlen($username));
    }

    public function test_empty_local_part_falls_back_to_user(): void
    {
        // Edge case: local part has no alphanumeric chars
        $this->assertSame('user', mll_derive_username('---@example.com'));
    }

    // -----------------------------------------------------------------------
    // mll_unique_username
    // -----------------------------------------------------------------------

    public function test_no_collision_returns_base(): void
    {
        $this->assertSame('erik', mll_unique_username('erik', ['alice', 'bob']));
    }

    public function test_collision_appends_2(): void
    {
        $this->assertSame('erik-2', mll_unique_username('erik', ['erik']));
    }

    public function test_multiple_collisions_increment(): void
    {
        $this->assertSame('erik-3', mll_unique_username('erik', ['erik', 'erik-2']));
    }

    public function test_empty_existing_list(): void
    {
        $this->assertSame('newuser', mll_unique_username('newuser', []));
    }
}
