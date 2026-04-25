<?php

declare(strict_types=1);

/**
 * E2E test: Security edge cases — rate limiting, expired tokens, enumeration.
 */
class SecurityCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->clearMailpit();
    }

    // -----------------------------------------------------------------------

    public function rapid_requests_do_not_send_duplicate_emails(AcceptanceTester $I): void
    {
        $email = 'ratelimit@example.com';

        // Send three requests in rapid succession
        for ($i = 0; $i < 3; $i++) {
            $I->amOnPage('/identification.php');
            $I->fillField('#mll-email', $email);
            $I->click('Send login link');
            $I->waitForText('Check your email', 5);
        }

        // Wait briefly for delivery
        $I->wait(2);

        // Only 1 email should have been delivered (rate limiting suppressed the rest)
        $I->sendGet('/api/v1/messages');
        $I->seeResponseCodeIs(200);
        $data = json_decode($I->grabResponse(), true);
        $I->assertLessThanOrEqual(1, $data['total'] ?? 0, 'Rate limiting should have suppressed duplicate emails');
    }

    public function response_is_identical_for_unknown_email(AcceptanceTester $I): void
    {
        // The confirmation message must be identical whether or not the email exists.
        // This prevents account enumeration.
        $emails = ['registered@example.com', 'totallyunknown' . time() . '@nope.invalid'];

        foreach ($emails as $email) {
            $I->clearMailpit();
            $I->amOnPage('/identification.php');
            $I->fillField('#mll-email', $email);
            $I->click('Send login link');
            $I->waitForText('Check your email', 5);
            // Both should show the same confirmation — no "email not found" message
            $I->dontSee('not found');
            $I->dontSee('not registered');
            $I->dontSee('no account');
        }
    }

    public function missing_token_parameter_shows_error(AcceptanceTester $I): void
    {
        $I->amOnUrl('http://piwigo/plugins/MagicLinkLogin/verify.php');
        $I->see('Invalid link');
    }

    public function handler_rejects_non_post_requests(AcceptanceTester $I): void
    {
        $I->sendGet('/plugins/MagicLinkLogin/magic_link_handler.php');
        $I->seeResponseCodeIs(405);
    }
}
