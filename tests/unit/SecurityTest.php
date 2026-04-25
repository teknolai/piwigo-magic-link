<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for security-critical helper functions:
 * URL safety, rate limiting logic.
 */
class SecurityTest extends TestCase
{
    // -----------------------------------------------------------------------
    // mll_is_internal_url
    // -----------------------------------------------------------------------

    public function test_url_matching_root_is_internal(): void
    {
        $this->assertTrue(
            mll_is_internal_url('https://mygallery.com/photos/album', 'https://mygallery.com/')
        );
    }

    public function test_root_url_itself_is_internal(): void
    {
        $this->assertTrue(
            mll_is_internal_url('https://mygallery.com/', 'https://mygallery.com/')
        );
    }

    public function test_external_url_is_rejected(): void
    {
        $this->assertFalse(
            mll_is_internal_url('https://evil.com/steal', 'https://mygallery.com/')
        );
    }

    public function test_protocol_relative_url_is_rejected(): void
    {
        $this->assertFalse(
            mll_is_internal_url('//evil.com', 'https://mygallery.com/')
        );
    }

    public function test_empty_url_is_rejected(): void
    {
        $this->assertFalse(
            mll_is_internal_url('', 'https://mygallery.com/')
        );
    }

    public function test_empty_root_is_rejected(): void
    {
        $this->assertFalse(
            mll_is_internal_url('https://mygallery.com/', '')
        );
    }

    public function test_case_insensitive_comparison(): void
    {
        $this->assertTrue(
            mll_is_internal_url('HTTPS://MYGALLERY.COM/page', 'https://mygallery.com/')
        );
    }

    // -----------------------------------------------------------------------
    // mll_is_rate_limited
    // -----------------------------------------------------------------------

    public function test_no_previous_token_is_not_rate_limited(): void
    {
        $this->assertFalse(mll_is_rate_limited(null));
    }

    public function test_token_issued_55_seconds_ago_is_rate_limited(): void
    {
        $this->assertTrue(mll_is_rate_limited(55, 60));
    }

    public function test_token_issued_61_seconds_ago_is_not_rate_limited(): void
    {
        $this->assertFalse(mll_is_rate_limited(61, 60));
    }

    public function test_token_issued_exactly_at_boundary_is_rate_limited(): void
    {
        // At exactly min_gap seconds the window has just barely not closed
        $this->assertTrue(mll_is_rate_limited(60, 60));
    }

    public function test_custom_min_gap_respected(): void
    {
        $this->assertTrue(mll_is_rate_limited(25, 30));
        $this->assertFalse(mll_is_rate_limited(31, 30));
    }
}
