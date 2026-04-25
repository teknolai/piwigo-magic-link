<?php

declare(strict_types=1);

/**
 * E2E test: A brand-new email (not yet registered) gets auto-registered
 * and logged in via magic link.
 *
 * Uses a timestamp-based email to ensure it's unique on each test run.
 */
class NewUserCest
{
    private string $newEmail;

    public function _before(AcceptanceTester $I): void
    {
        $I->clearMailpit();
        // Unique email per run so we don't collide with previous test data
        $this->newEmail = 'newuser-' . time() . '@example.com';
    }

    // -----------------------------------------------------------------------

    public function new_user_gets_auto_registered_and_logged_in(AcceptanceTester $I): void
    {
        // Step 1: Request magic link for an unknown email
        $I->amOnPage('/identification.php');
        $I->fillField('#mll-email', $this->newEmail);
        $I->click('Send login link');
        $I->waitForText('Check your email', 5);

        // Step 2: Grab the link from Mailpit
        $magicUrl = $I->grabMagicLinkFromLatestEmail();

        // Step 3: Follow the link — should create account and log in
        $I->amOnUrl($magicUrl);
        $I->seeInCurrentUrl('/index.php');
        $I->dontSee('Sign in');
    }

    public function new_user_can_login_again_after_registration(AcceptanceTester $I): void
    {
        // First login (creates account)
        $I->amOnPage('/identification.php');
        $I->fillField('#mll-email', $this->newEmail);
        $I->click('Send login link');
        $I->waitForText('Check your email', 5);
        $I->amOnUrl($I->grabMagicLinkFromLatestEmail());
        $I->seeInCurrentUrl('/index.php');

        // Log out
        $I->amOnPage('/identification.php?action=logout');

        // Second login via magic link (account now exists)
        $I->clearMailpit();
        $I->amOnPage('/identification.php');
        $I->fillField('#mll-email', $this->newEmail);
        $I->click('Send login link');
        $I->waitForText('Check your email', 5);
        $I->amOnUrl($I->grabMagicLinkFromLatestEmail());
        $I->seeInCurrentUrl('/index.php');
        $I->dontSee('Sign in');
    }
}
