<?php

namespace FriendsOfRedaxo\FFmpeg\Cronjob;

use FriendsOfRedaxo\FFmpeg\AutoOptimizer;
use rex_cronjob;
use rex_i18n;

/**
 * Cronjob-Typ „FFmpeg: Videos automatisch optimieren“ -- ein Durchgang von AutoOptimizer::run():
 * fertige Konvertierung übernehmen, sonst das nächste Video im Hintergrund starten. Der Lauf selbst
 * dauert nur Sekundenbruchteile (ffmpeg läuft im Hintergrund weiter), daher auch als „Pseudo-Cron“
 * bei Seitenaufrufen (Umgebung Frontend/Backend) unbedenklich.
 *
 * Wird beim Einschalten der automatischen Optimierung selbst angelegt (alle 10 Minuten), siehe
 * AutoOptimizeCronjob::ensure().
 */
class AutoOptimizeCronjob extends rex_cronjob
{
    public function execute()
    {
        $result = AutoOptimizer::run();
        $this->setMessage($result['message']);

        return !in_array($result['status'], ['error', 'unavailable'], true);
    }

    public function getTypeName()
    {
        return rex_i18n::msg('ffmpeg_auto_cronjob_name');
    }

    public function getParamFields()
    {
        return [];
    }

    /**
     * Cronjob anlegen bzw. aktivieren, falls das cronjob-AddOn verfügbar ist.
     *
     * @return bool|null true = vorhanden/angelegt, null = cronjob-AddOn fehlt
     */
    public static function ensure(bool $active): ?bool
    {
        if (!\rex_addon::get('cronjob')->isAvailable()) {
            return null;
        }
        $table = \rex::getTable('cronjob');
        $existing = \rex_sql::factory()->getArray('SELECT id, status FROM ' . $table . ' WHERE type = ?', [self::class]);
        if ($existing) {
            \rex_sql::factory()->setQuery('UPDATE ' . $table . ' SET status = ? WHERE type = ?', [$active ? 1 : 0, self::class]);
        } elseif ($active) {
            $interval = (string) json_encode(['minutes' => [0, 10, 20, 30, 40, 50], 'hours' => 'all', 'days' => 'all', 'weekdays' => 'all', 'months' => 'all']);
            $sql = \rex_sql::factory();
            $sql->setTable($table);
            $sql->setValues([
                'name' => rex_i18n::msg('ffmpeg_auto_cronjob_name'),
                'description' => rex_i18n::msg('ffmpeg_auto_cronjob_description'),
                'type' => self::class,
                'parameters' => '[]',
                'interval' => $interval,
                'environment' => '|frontend|backend|script|',
                'execution_moment' => 0,
                'execution_start' => \rex_sql::datetime(0),
                'status' => 1,
            ]);
            $sql->addGlobalCreateFields('ffmpeg');
            $sql->addGlobalUpdateFields('ffmpeg');
            $sql->insert();
            $manager = \rex_cronjob_manager_sql::factory();
            $manager->setNextTime((int) $sql->getLastId(), $interval);
        }
        \rex_cronjob_manager_sql::factory()->saveNextTime();

        return true;
    }
}
