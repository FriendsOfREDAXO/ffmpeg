<?php

/**
 * Verknüpfungen zwischen einem Video und daraus entstandenen Medien (Untertitel-/Kapitelspuren),
 * siehe FriendsOfRedaxo\FFmpeg\Links. Läuft bei Installation und Update (update.php).
 */
rex_sql_table::get(rex::getTable('ffmpeg_link'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('source', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('target', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('kind', 'varchar(20)'))
    ->ensureColumn(new rex_sql_column('label', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('lang', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('role', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('createdate', 'datetime'))
    ->ensureIndex(new rex_sql_index('source', ['source']))
    ->ensureIndex(new rex_sql_index('target', ['target']))
    ->ensure();
