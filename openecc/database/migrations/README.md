# Database migrations

`database/schema.sql` + `database/seed.sql` create a brand-new database at the
baseline schema (recorded in `schema_migrations` as `0000_baseline`).

Every later structure or reference-data change ships as one file here:

    NNNN_short_description.sql      e.g. 0001_add_widget_table.sql

Rules:

* Numbered in order, never edited after release, never renumbered.
* Must be safe to run exactly once. Use `CREATE TABLE IF NOT EXISTS` / checks where practical.
* `update.sh` applies pending files in order and records each one in `schema_migrations`, using
  `app/bin/migrate.php`. Fresh installs (`app/bin/install_db.php`) record every file here as already applied.
* Plain SQL statements only, each ending in `;`. No `DELIMITER`, `SOURCE` or other mariadb-client commands (so no
  triggers or stored procedures): the files are run statement by statement through PHP, on Linux and Windows.
  The smoke test checks that every file can be read this way.
* Fresh installs do not run these; when a release adds a migration, `schema.sql` is
  regenerated to include it and the new version is pre-recorded by the installer
  (the installer records every file present in this directory as already applied).
