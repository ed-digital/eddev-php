<?php

class EDDBMigrationManager {

  /**
   * Ensures a table exists, and has the correct structure.
   */
  static function ensureDatabaseTable($migrationId, $create) {
    global $wpdb;
    $newHash = md5($create);
    $lastHash = get_option("ed_table_migration_" . $migrationId);
    // dump("Hash", $newHash, $lastHash);
    // exit;
    if ($lastHash === $newHash) {
      // The table is already up to date — no need to run the migration.
      return;
    }

    include_once(__dir__ . "/../lib/dbStruct.php");
    if (!preg_match("/CREATE\s+TABLE\s+['`\"]?([0-9A-Za-z\_\.]+)['`\"]?/", $create, $match)) {
      throw new Error("Couldn't run table migration, no table name was supplied.");
    }
    $tableName = $match[1];

    // Get the current table structure
    $result = $wpdb->get_row("SHOW CREATE TABLE " . $tableName, ARRAY_A);
    if (!$result) {
      // The table doesn't exist. Just run the create.
      $wpdb->query($create);
      return;
    }

    // Get the old create table statement
    $oldCreate = $result['Create Table'];

    // Get the updates needed, if any
    $updater = new dbStructUpdater();
    $changes = $updater->getUpdates($oldCreate, $create);

    if (!$changes) {
      // No changes to make!
      return;
    }

    // Apply the changes
    foreach ($changes as $r) {
      if (!$wpdb->query($r)) {
        throw new Error("Migrating table structure failed (DB said \"" . $wpdb->last_error . "\")");
      }
    }
    update_option("ed_table_migration_" . $migrationId, $newHash);
  }
}
