<?php

namespace FriendsOfRedaxo\FFmpeg\Job;

/**
 * Ersetzt die bisherige session-gebundene Status-Verfolgung (rex_session
 * 'ffmpeg_conversion_id'/'ffmpeg_conversion_status' + Log-Text-Matching wie
 * "Konvertierung abgeschlossen"/"was successfully added to rex_mediapool").
 *
 * Ein Job ist eine Datei data/job_<id>.json -- unabhaengig von der Session,
 * damit zwei verschiedene Backend-User denselben globalen "laeuft bereits
 * eine Konvertierung"-Zustand sehen (vorher: rein session-lokal, zwei User
 * konnten unbemerkt gleichzeitig ffmpeg-Prozesse starten). Es wird bewusst
 * KEINE echte Warteschlange gefuehrt (kein Backlog wartender Jobs) -- ein
 * zweiter Start-Versuch waehrend ein Job laeuft wird abgelehnt (busy), nicht
 * eingereiht. Echtes Queueing ist eine spaetere Ausbaustufe.
 *
 * Abschluss wird NICHT mehr per Text-Suche im ffmpeg-Log erkannt (fragil --
 * haengt an lokalisierten ffmpeg-Meldungen), sondern per Exit-Code-Datei, die
 * der Hintergrund-Shell-Aufruf selbst schreibt (siehe Converter::handleStart()).
 */
class VideoJob
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_FINALIZING = 'finalizing';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';

    /** Jobs aelter als das gelten nicht mehr als "aktiv" (verwaiste Prozesse). */
    private const STALE_SECONDS = 1800;

    public string $id;
    public string $mode; // 'new_file' | 'overwrite'
    public string $inputFile; // rex_media-Dateiname (nicht der volle Pfad)
    public string $outputPath; // voller Dateisystem-Pfad der ffmpeg-Ausgabe
    public string $logPath;
    public string $exitCodePath;
    public string $status;
    public int $progress = 0;
    public string $message = '';
    public string $userLogin = '';
    public int $createdAt;
    public int $updatedAt;
    /** @var array<string, mixed> */
    public array $extra = [];

    public static function create(string $mode, string $inputFile, string $outputPath, string $userLogin): self
    {
        $job = new self();
        $job->id = bin2hex(random_bytes(12));
        $job->mode = $mode;
        $job->inputFile = $inputFile;
        $job->outputPath = $outputPath;
        $job->logPath = self::dataPath('job_' . $job->id . '.log');
        $job->exitCodePath = self::dataPath('job_' . $job->id . '.exit');
        $job->status = self::STATUS_RUNNING;
        $job->userLogin = $userLogin;
        $job->createdAt = time();
        $job->updatedAt = $job->createdAt;

        return $job;
    }

    public static function load(string $id): ?self
    {
        if (!preg_match('/^[a-f0-9]+$/', $id)) {
            return null;
        }
        $path = self::dataPath('job_' . $id . '.json');
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) \rex_file::get($path), true);
        if (!is_array($data)) {
            return null;
        }

        $job = new self();
        $job->id = (string) ($data['id'] ?? $id);
        $job->mode = (string) ($data['mode'] ?? 'new_file');
        $job->inputFile = (string) ($data['inputFile'] ?? '');
        $job->outputPath = (string) ($data['outputPath'] ?? '');
        $job->logPath = (string) ($data['logPath'] ?? '');
        $job->exitCodePath = (string) ($data['exitCodePath'] ?? '');
        $job->status = (string) ($data['status'] ?? self::STATUS_ERROR);
        $job->progress = (int) ($data['progress'] ?? 0);
        $job->message = (string) ($data['message'] ?? '');
        $job->userLogin = (string) ($data['userLogin'] ?? '');
        $job->createdAt = (int) ($data['createdAt'] ?? time());
        $job->updatedAt = (int) ($data['updatedAt'] ?? $job->createdAt);
        $job->extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];

        return $job;
    }

    public function save(): void
    {
        $this->updatedAt = time();
        \rex_file::put(self::dataPath('job_' . $this->id . '.json'), (string) json_encode([
            'id' => $this->id,
            'mode' => $this->mode,
            'inputFile' => $this->inputFile,
            'outputPath' => $this->outputPath,
            'logPath' => $this->logPath,
            'exitCodePath' => $this->exitCodePath,
            'status' => $this->status,
            'progress' => $this->progress,
            'message' => $this->message,
            'userLogin' => $this->userLogin,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'extra' => $this->extra,
        ]));
    }

    public function isTerminal(): bool
    {
        return self::STATUS_DONE === $this->status || self::STATUS_ERROR === $this->status;
    }

    /**
     * Ein Job gilt als verwaist (Server-Neustart mitten in der Konvertierung
     * o.ae.), wenn er seit STALE_SECONDS nicht aktualisiert wurde und noch
     * nicht abgeschlossen ist -- verhindert, dass ein toter Job die globale
     * Sperre (findActive()) fuer immer haelt.
     */
    public function isStale(): bool
    {
        return !$this->isTerminal() && (time() - $this->updatedAt) > self::STALE_SECONDS;
    }

    /**
     * Global (nicht session-gebunden): gibt den aktuell laufenden Job zurueck,
     * falls einer existiert -- Grundlage fuer die "nur ein Job gleichzeitig"-
     * Sperre in Converter::handleStart().
     */
    public static function findActive(): ?self
    {
        foreach (self::allJobFiles() as $path) {
            $id = self::idFromJobFile($path);
            if (null === $id) {
                continue;
            }
            $job = self::load($id);
            if (!$job instanceof self) {
                continue;
            }
            if ($job->isTerminal()) {
                continue;
            }
            if ($job->isStale()) {
                $job->status = self::STATUS_ERROR;
                $job->message = 'Job abgebrochen (verwaist, keine Aktualisierung mehr).';
                $job->save();
                continue;
            }

            return $job;
        }

        return null;
    }

    /**
     * Aufraeumen alter Job-/Log-/Exit-Dateien -- gleiches Prinzip wie das
     * bisherige Aufraeumen der status_ und log Dateien in boot.php.
     */
    public static function cleanupOld(int $maxAgeSeconds): void
    {
        foreach (glob(self::dataPath('job_*.json')) ?: [] as $path) {
            if (filemtime($path) < time() - $maxAgeSeconds) {
                $id = self::idFromJobFile($path);
                @unlink($path);
                if (null !== $id) {
                    @unlink(self::dataPath('job_' . $id . '.log'));
                    @unlink(self::dataPath('job_' . $id . '.exit'));
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function allJobFiles(): array
    {
        $files = glob(self::dataPath('job_*.json')) ?: [];
        // Neueste zuerst -- bei mehreren (eigentlich nicht vorgesehenen)
        // gleichzeitig aktiven Jobs zaehlt der zuletzt gestartete.
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files;
    }

    private static function idFromJobFile(string $path): ?string
    {
        if (1 === preg_match('/job_([a-f0-9]+)\.json$/', $path, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function dataPath(string $file): string
    {
        return \rex_addon::get('ffmpeg')->getDataPath($file);
    }
}
