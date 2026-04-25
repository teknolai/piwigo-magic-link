<?php

declare(strict_types=1);

/**
 * E2E test: Existing user logs in via magic link.
 *
 * Prerequisite: A Piwigo account with email "testuser@example.com" must exist.
 * Create it via Admin → Users before running these tests, or add a DB fixture.
 */
class LoginCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->clearMailpit();
    }

    // -----------------------------------------------------------------------

    public function existing_user_can_request_magic_link(AcceptanceTester $I): void
    {
        $I->amOnPage('/identification.php');
        $I->see('Passwordless login');
        $I->fillField('#mll-email', 'testuser@example.com');
        $I->click('Send login link');
        $I->waitForText('Check your email', 5);
        $I->dontSee('Send login link'); // form replaced by confirmation
    }

    public function existing_user_can_login_via_magic_link(AcceptanceTester $I): void
    {
        // Step 1: Request the link
        $I->amOnPage('/identification.php');
        $I->fillField('#mll-email', 'testuser@example.com');
        $I->click('Send login link');
        $I->waitForText('Check your email', 5);

        // Step 2: Grab the link from Mailpit
        $magicUrl = $I->grabMagicLinkFromLatestEmail();
        $I->assertStringContainsString('verify.php?token=', $magicUrl);

        // Step 3: Follow the link
        $I->amOnUrl($magicUrl);

        // Step 4: Verify we are logged in on the gallery homepage
        $I->seeInCurrentUrl('/index.php');
        $I->dontSee('Sign in');
        $I->dontSeeElement('#mll-block');
    }

    public function magic_link_cannot_be_used_twice(AcceptanceTester $I): void
    {
        // Request and use link once
        $I->amOnPage('/identification.php');
        $I->fillField('#mll-email', 'testuser@example.com');
        $I->click('Send login link');
        $I->waitForText('Check your email', 5);

        $magicUrl = $I->grabMagicLinkFromLatestEmail();
        $I->amOnUrl($magicUrl);
        $I->seeInCurrentUrl('/index.php'); // first use — logged in

        // Now try visiting the same URL again
        $I->amOnUrl($magicUrl);
        $I->see('Link expired or already used');
    }

    public function invalid_token_shows_error_page(AcceptanceTester $I): void
    {
        $I->amOnUrl('http://piwigo/plugins/MagicLinkLogin/verify.php?token=' . str_repeat('a', 64));
        $I->see('Link expired or already used');
        $I->see('Back to login');
    }

    public function garbage_token_shows_error_page(AcceptanceTester $I): void
    {
        $I->amOnUrl('http://piwigo/plugins/MagicLinkLogin/verify.php?token=not-a-token');
        $I->see('Invalid link');
        $I->see('Back to login');
    }
}
