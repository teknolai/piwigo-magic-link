<?php
/**
 * MagicLinkLogin — Plugin lifecycle management.
 *
 * Piwigo calls the methods below when the plugin is installed, activated,
 * deactivated, updated, or uninstalled via the Plugin Manager.
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

class MagicLinkLogin_maintain extends PluginMaintain
{
    private $table;

    public function __construct($id)
    {
        parent::__construct($id);
        global $prefixeTable;
        $this->table = $prefixeTable . 'magic_link_tokens';
    }

    // -----------------------------------------------------------------------
    // Called once when the plugin is first installed
    // -----------------------------------------------------------------------
    public function install($plugin_version, &$errors = [])
    {
        pwg_query("
            CREATE TABLE IF NOT EXISTS `{$this->table}` (
                `id`          int(11)       NOT NULL AUTO_INCREMENT,
                `user_id`     int(11)       DEFAULT NULL,
                `email`       varchar(255)  NOT NULL,
                `token_hash`  varchar(64)   NOT NULL,
                `expires_at`  datetime      NOT NULL,
                `used`        tinyint(1)    NOT NULL DEFAULT 0,
                `ua_hash`     varchar(64)   DEFAULT NULL,
                `created_at`  datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_token_hash` (`token_hash`),
                KEY `idx_email_expires` (`email`, `expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // -----------------------------------------------------------------------
    // Called when the plugin is activated (also called after install)
    // -----------------------------------------------------------------------
    public function activate($plugin_version, &$errors = [])
    {
        // Ensure the table exists (safe to call on re-activation)
        $this->install($plugin_version, $errors);
    }

    // -----------------------------------------------------------------------
    // Called when the plugin is deactivated — leave data intact
    // -----------------------------------------------------------------------
    public function deactivate()
    {
        // Nothing to do — we keep the token table so tokens survive a temp disable
    }

    // -----------------------------------------------------------------------
    // Called when the plugin version changes — apply schema migrations here
    // -----------------------------------------------------------------------
    public function update($old_version, $new_version, &$errors = [])
    {
        // No migrations needed for 1.0.0
    }

    // -----------------------------------------------------------------------
    // Called when the plugin is fully uninstalled — clean up everything
    // -----------------------------------------------------------------------
    public function uninstall()
    {
        pwg_query("DROP TABLE IF EXISTS `{$this->table}`");
    }
}
