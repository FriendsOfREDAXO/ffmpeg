<?php

namespace FriendsOfRedaxo\FFmpeg\Command;

use FriendsOfRedaxo\FFmpeg\AutoOptimizer;
use FriendsOfRedaxo\FFmpeg\Job\VideoJob;
use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * php redaxo/bin/console ffmpeg:optimize [--wait]
 *
 * Ein Durchgang der automatischen Optimierung (wie der Cronjob). Mit --wait werden alle
 * wartenden Videos nacheinander abgearbeitet und der Befehl kehrt erst danach zurück -- z. B.
 * für einen nächtlichen System-Cronjob oder nach dem Einschalten für den vorhandenen Bestand.
 */
class OptimizeCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this->setName('ffmpeg:optimize')
            ->setDescription('Videos im Medienpool automatisch auf die Standardgröße optimieren (siehe Video-Tools → Einstellungen)')
            ->addOption('wait', null, InputOption::VALUE_NONE, 'Alle wartenden Videos nacheinander abarbeiten und auf jede Konvertierung warten');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $wait = (bool) $input->getOption('wait');

        do {
            $result = AutoOptimizer::run();
            $io->writeln($result['message']);
            if (in_array($result['status'], ['disabled', 'unavailable', 'error'], true)) {
                return 'error' === $result['status'] || 'unavailable' === $result['status'] ? 1 : 0;
            }
            if (!$wait || !in_array($result['status'], ['started', 'busy', 'finished'], true)) {
                break;
            }
            self::waitForActiveJob($io);
        } while (true);

        return 0;
    }

    private static function waitForActiveJob(SymfonyStyle $io): void
    {
        // nur warten -- abgeschlossen wird der Job im nächsten AutoOptimizer::run()
        while (($job = VideoJob::findActive()) && !$job->hasExited()) {
            sleep(3);
        }
    }
}
