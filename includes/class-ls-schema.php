<?php
if (!defined('ABSPATH')) { exit; }
final class LS_Schema {
    public const VERSION = '1';
    public static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'ls_' . $name;
    }
    public static function definitions(string $prefix, string $charset): array {
        $id = 'bigint(20) unsigned NOT NULL AUTO_INCREMENT';
        return array(
            "CREATE TABLE {$prefix}ls_editions (id $id, slug varchar(80) NOT NULL, title varchar(190) NOT NULL, status varchar(20) NOT NULL DEFAULT 'draft', created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY slug (slug)) $charset;",
            "CREATE TABLE {$prefix}ls_releases (id $id, edition_id bigint(20) unsigned NOT NULL, version varchar(80) NOT NULL, status varchar(20) NOT NULL DEFAULT 'draft', published_at datetime NULL, PRIMARY KEY  (id), UNIQUE KEY edition_version (edition_id,version), KEY edition_id (edition_id)) $charset;",
            "CREATE TABLE {$prefix}ls_factions (id $id, release_id bigint(20) unsigned NOT NULL, slug varchar(100) NOT NULL, title varchar(190) NOT NULL, data longtext NULL, PRIMARY KEY  (id), UNIQUE KEY release_slug (release_id,slug)) $charset;",
            "CREATE TABLE {$prefix}ls_units (id $id, release_id bigint(20) unsigned NOT NULL, faction_id bigint(20) unsigned NOT NULL, slug varchar(100) NOT NULL, title varchar(190) NOT NULL, data longtext NULL, PRIMARY KEY  (id), UNIQUE KEY release_faction_slug (release_id,faction_id,slug), KEY faction_id (faction_id)) $charset;",
            "CREATE TABLE {$prefix}ls_detachments (id $id, release_id bigint(20) unsigned NOT NULL, slug varchar(100) NOT NULL, title varchar(190) NOT NULL, data longtext NULL, PRIMARY KEY  (id), UNIQUE KEY release_slug (release_id,slug)) $charset;",
            "CREATE TABLE {$prefix}ls_wargear (id $id, release_id bigint(20) unsigned NOT NULL, slug varchar(100) NOT NULL, title varchar(190) NOT NULL, data longtext NULL, PRIMARY KEY  (id), UNIQUE KEY release_slug (release_id,slug)) $charset;",
            "CREATE TABLE {$prefix}ls_armies (id $id, user_id bigint(20) unsigned NOT NULL, release_id bigint(20) unsigned NOT NULL, title varchar(190) NOT NULL, status varchar(20) NOT NULL DEFAULT 'private', roster longtext NOT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), KEY owner (user_id), KEY release_id (release_id)) $charset;",
            "CREATE TABLE {$prefix}ls_battles (id $id, army_id bigint(20) unsigned NOT NULL, user_id bigint(20) unsigned NOT NULL, snapshot longtext NOT NULL, state longtext NOT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), KEY army_id (army_id), KEY owner (user_id)) $charset;"
        );
    }
    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach (self::definitions($wpdb->prefix, $wpdb->get_charset_collate()) as $sql) { dbDelta($sql); }
        update_option('ls_schema_version', self::VERSION);
        if (!get_option('ls_installed_at')) { add_option('ls_installed_at', gmdate('Y-m-d H:i:s')); }
    }
}
