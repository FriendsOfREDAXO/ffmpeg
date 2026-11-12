# Changelog

## 5.0.3 - 2026-11-12

### Bugfix
- Schnitt-Editor: **Das Video folgt beim Ziehen der roten Linie** (und der Abschnittsränder). Bisher stauten sich die Sprünge im Video, das Bild hinkte hinterher – vor allem bei größeren Videos, langsameren Servern und in Safari. Jetzt läuft immer nur ein Sprung, danach direkt zum zuletzt gewünschten Ziel (während des Ziehens per `fastSeek`, wo verfügbar); beim Loslassen springt das Video bildgenau an die gewählte Stelle.
- Schnitt- und Untertitel-Editor laden das Video direkt vom Webserver (Sprünge ohne PHP). Der PHP-Stream bleibt als Ausweg, falls `/media` nicht ausgeliefert wird, und gibt die Session sofort frei – vorher warteten alle Sprung-Anfragen aufeinander.

## 5.0.2 - 2026-11-12

### Bugfix
- Schnitt-Editor: **„Ergebnis abspielen“, „Schleife“ und „Ping-Pong testen“ spielen jetzt genau die Markierung.** Beim schnellen Schnitt begann die Vorschau bisher am Schlüsselbild vor der Markierung (im gelben Bereich) und sprang in der Schleife auch dorthin zurück. Was der schnelle Schnitt zusätzlich mitnimmt, zeigen weiterhin der gelbe Bereich und der Hinweis unter der Zeitleiste; „Neue Länge“ nennt die tatsächliche Länge.

## 5.0.1 - 2026-11-12

### Verbesserungen
- **Optimieren macht Videos nicht mehr größer**: Wie bei der automatischen Optimierung wird das Original beim „Optimieren“ (Button im Video-Konverter, im Medienpool und in MediaPlace) nur ersetzt, wenn die Datei kleiner wird. Sonst bleibt es unverändert und gilt als optimiert. Ausnahme: Das Original spielt in Browsern nicht zuverlässig ab (kein H.264, Farbformat nicht yuv420p oder ohne Fast Start) – dann wird es auch ersetzt, wenn es etwas größer wird. Schneiden mit „Original ersetzen“ und Aufträge aus der Warteschlange ersetzen weiterhin immer.
- Voreinstellung für „Optimieren“: Qualität **ausgewogen** statt hoch (von der Vorlage kaum zu unterscheiden, deutlich kleinere Dateien). Gespeicherte Einstellungen bleiben unverändert.
- Neu: `VideoInfo::isWebCompatible($filename)`.

## 5.0.0 - 2026-11-12

### Neue Features
- **Schnitt-Editor** statt des bisherigen Trimmers (nach dem Vorbild des AddOns video_editor für REDAXO 6): Zeitleiste mit Vorschaubildern, teilen, Abschnitte entfernen, Ränder ziehen, rückgängig, mehrere Teile auf einmal herausschneiden; **schnell** (ohne Neuberechnung, ab Schlüsselbild – die Zeitleiste zeigt, was mitkommt) oder **genau** (bildgenau); Ergebnis als neue Datei oder anstelle des Originals; läuft als Hintergrund-Auftrag. Alle bisherigen Funktionen bleiben: Ton entfernen, Ping-Pong-Schleife (und Ping-Pong-Vorschau), Bereichstest, Schleifen-Vorschau, Poster-Bild, Strg+S/Strg+E, Dateinamen `web_trimmed_…`.
- **Untertitel und Kapitel**: neue Seite „Untertitel“ mit Editor und Live-Vorschau, Import von WebVTT/SRT, alle Einträge verschieben; mehrere Spuren je Video (Untertitel, Untertitel für Gehörlose, Kapitel, je Sprache), gespeichert als gesäubertes WebVTT im Medienpool und mit dem Video verknüpft. Beim Schneiden werden sie mitgeschnitten. Für die Website: `VideoInfo::tracks()` / `VideoInfo::trackTags()`, Auslieferung als `text/vtt`.
- **Automatische Optimierung**: Neue Videos werden direkt nach dem Hochladen und per Cronjob auf eine Standardgröße (Full HD, HD oder Klein) gebracht und webtauglich gemacht. Bearbeitet wird nur, was es nötig hat (zu groß, zu hohe Datenrate, Codec/Farbformat, fehlender Fast Start). Ersetzt wird nur, wenn die Datei kleiner wird oder das Original in Browsern nicht zuverlässig abspielt; fehlt nur Fast Start, wird verlustfrei umsortiert. Der Cronjob wird beim Einschalten automatisch angelegt; Konsolenbefehl `ffmpeg:optimize [--wait]`.
- **Eigene Workflows**: Warteschlange `Queue::add()` (Aufträge laufen nacheinander) und Erweiterungspunkte `FFMPEG_JOB_COMMAND`, `FFMPEG_JOB_DONE`, `FFMPEG_AUTO_OPTIMIZE` – z. B. mehrere Fassungen nach jedem Upload, siehe README.
- **Einfache Einstellungen statt FFmpeg-Kommandos**: Größe, Qualität und Ton je Modus („Optimieren“ / „Webversion erstellen“), neue Ton-Option „Lautstärke angleichen“ (−16 LUFS). Frühere Einstellungen werden übernommen.
- Links „Video schneiden“ und „Untertitel“ in der Detailansicht jedes Videos im Medienpool.
- README auf Deutsch (`README.de.md`) und Englisch (`README.md`), die Hilfe im Backend zeigt die Sprache des Backends.

### Nicht mehr enthalten (Breaking Changes)
- Das Eingabefeld für eigene FFmpeg-Kommandos entfällt; gespeicherte Kommandos werden durch die passenden Optionen ersetzt.
- `Presets::all()` entfällt (`Presets::build()`, `Presets::command()`, `Presets::settings()` statt dessen).
- Die API `ffmpeg_convert` ist nur noch im Backend und mit CSRF-Token erreichbar (siehe Sicherheit). Eigene Aufrufe aus dem Backend brauchen `rex.ffmpeg_api` bzw. `Converter::getUrlParams()`; `Converter::startOverwriteJob()`/`pollJob()` (MediaPlace) bleiben unverändert.
- Benötigt ffmpeg ab 4.3.

### Sicherheit
- `ffmpeg_convert` war über das Frontend erreichbar und ohne CSRF-Schutz: Eine fremde Webseite hätte im Namen eines angemeldeten Redakteurs Konvertierungen starten und Originale überschreiben können. Jetzt: nur Backend, CSRF-Token, Recht `ffmpeg[]` und Medienpool-Recht für die Kategorie. Dasselbe gilt für die neuen APIs `ffmpeg_editor` und `ffmpeg_stream`; öffentlich ist nur `ffmpeg_track` (nur verknüpfte `.vtt`-Dateien, beachtet `rex_media::isPermitted()`).

### Verbesserungen
- **Videos werden nie vergrößert**: Die Auflösungs-Presets haben kleinere Videos bisher hochskaliert (z. B. 720p → 1080p), ebenso die Media-Manager-Effekte „Video zu Vorschau“ und „Video zu WebP“. Hochformat wird richtig begrenzt (1080 × 1920 statt 607 × 1080).
- **Fast Start immer**: alle Umwandlungen und der Schnitt schreiben den Index an den Dateianfang – Videos starten im Browser sofort.
- Immer browsertaugliches Farbformat (yuv420p), Stereo-Ton, nur die erste Bild- und Tonspur (Smartphone-Videos mit Metadaten-Spuren scheiterten sonst), H.264 High Profile, Lanczos-Skalierung; Argumente einzeln maskiert.

### Bugfixes
- Wurde der Browser während einer Konvertierung geschlossen, wurde das Ergebnis nie übernommen und der Auftrag nach 30 Minuten als „verwaist“ abgebrochen. Fertige Aufträge werden jetzt bei jedem Backend-Aufruf, vom Cronjob und vom Konsolenbefehl übernommen.
- Lange Konvertierungen (über 30 Minuten), die niemand im Browser verfolgte, galten als verwaist, obwohl ffmpeg noch lief.
- Poster-Bild: Der Eintrag in den Medienpool scheiterte ab REDAXO 5.18 (Pflichtfeld `revision`); das Bild landet jetzt in der Kategorie des Videos.

## 4.9.0 - 2026-11-06

### Neue Features
- **Poster-Bild erstellen**: Der Video-Trimmer kann jetzt die aktuelle Abspielposition als Standbild (JPEG) im Medienpool speichern (`<name>_poster.jpg`), z. B. für das Poster-Attribut eines Video-Elements.
- **Preset-Auswahl für zusätzliche Varianten** (#20): Beim Anlegen einer weiteren Webversion (siehe 4.8.3) kann jetzt ein Preset gewählt werden, das nur für diese eine Variante gilt – der Sinn einer weiteren Variante ist meist gerade eine andere Qualität/Größe als die erste.
- **Vier neue Auflösungs-Presets**: 1080p, 720p, 480p, 360p, zusätzlich zu den bestehenden Standard/Web/Mobile/Archive-Presets.
- **Getrennte Presets/Kommandos für „Optimieren“ und „Webversion erstellen“**: Beide Buttons hatten bisher ein gemeinsames Preset, obwohl sie fachlich unterschiedliche Ziele haben (Original ersetzen vs. schlanke Web-Variante). Jetzt zwei eigene Einstellungen; bestehende Installationen fallen automatisch auf ihr bisheriges `command` als Webversion-Preset zurück.

### Verbesserungen
- Einstellungsseite neu strukturiert: eigene Panels für Allgemein/Optimieren/Webversion statt unklarer gemeinsamer Fieldsets, Preset-Übersicht als aufklappbares Akkordeon, Medien-Kategorie an den Anfang.
- Video-Konverter-Liste: Status-Badges eindeutiger beschriftet („Webversion: X% kleiner“ statt nur „X% kleiner“), Preset-Hinweis direkt an den Buttons, welches Preset ein Klick tatsächlich verwendet.
- Lange Dateinamen (z. B. generierte Exporte mit UUID-Suffixen) brechen jetzt bündig in ihrer eigenen Spalte um, statt das Layout zu sprengen – sowohl in der Konverter-Liste als auch im Überschreiben-Dialog.

### Sicherheit
- FFmpeg-Fehlerausgabe im Poster-Dialog wird jetzt escaped, bevor sie in die Fehlermeldung eingebettet wird.

## 4.8.3 - 2026-11-05

### Bugfix
- „Webversion erstellen" scheiterte, wenn für ein Original bereits eine Webversion existierte: `finalizeNewFile()` rief immer `rex_media_service::addMedia()` (INSERT) auf, auch bei bereits vorhandenem Dateinamen (#21). Die Webversion wird jetzt in diesem Fall direkt ersetzt (wie bei „Original überschreiben"), bestehende Metadaten bleiben erhalten.

### Verbesserung
- Bisher ließ sich pro Original nur eine Webversion anlegen; ein erneuter Lauf bot nur „Überschreiben" oder „Abbrechen" (#20). Neuer Dialog mit einer dritten Option „Zusätzliche Variante anlegen" – der nächste freie Dateiname (`web_<name>_2.mp4`, `_3.mp4`, …) wird automatisch ermittelt.

## 4.8.2 - 2026-10-19

### Bugfix
- Server mit gesperrten Shell-Funktionen (`disable_functions`: shell_exec, exec, proc_open): Das Öffnen eines Videos im Medienpool, die Info-Seite und der Trimmer brachen mit „Call to undefined function shell_exec()“ ab (#18). Alle Aufrufe laufen jetzt über `FriendsOfRedaxo\FFmpeg\Shell`, das die erlaubte Funktion wählt; ist keine erlaubt, gilt FFmpeg als nicht verfügbar, Videos lassen sich im Medienpool normal bearbeiten, und die FFmpeg-Seiten zeigen einen Hinweis. Konvertierungen melden einen Fehler statt still zu scheitern.
- Info-Seite: Es werden nur noch Dateien aus dem Medienpool ausgewertet (kein Pfad im Parameter `video`); der Pfad wird für ffprobe maskiert.

## 4.8.1 - 2026-10-10

### Bugfix
- `rex_effect_video_to_preview` schrieb bei jeder neu erzeugten Vorschau vier Debug-Zeilen (Video Info, Position Debug, Raw Params Debug, Calculated start position) ins REDAXO-Systemlog. Diese Ausgaben erscheinen jetzt nur noch im Debug-Modus (`rex::isDebugMode()`); Fehler werden weiterhin immer protokolliert.

## 4.8.0 - 2026-08-29

### Neu
- `rex_effect_video_to_webp` kann jetzt auch ein einzelnes Standbild statt einer animierten Vorschau extrahieren (neuer Parameter "Animiert", Standard weiterhin "Ja" – bestehende Typen verhalten sich unverändert). Bei "Nein" wird nur ein einzelnes Frame an der gewählten Position extrahiert (`-frames:v 1` statt `-t`/`-loop 0`) – deutlich günstiger in Erzeugung und Dateigröße. Wird vom MediaPlace-Addon für dessen neue Einstellung "Video-Vorschau im Grid" (aus/Einzelbild/animiert) genutzt.

## 4.7.1 - 2026-08-29

### Bugfix
- Video-Vorschaubild-Generierung (`rex_effect_video_to_webp`/`rex_effect_video_to_preview`) schlug auf Servern mit einer Locale fehl, die Komma statt Punkt als Dezimaltrennzeichen nutzt (z.B. `de_DE`): `sprintf('%f', ...)` ist entgegen der Erwartung locale-abhängig, erzeugte dort einen für ffmpeg ungültigen Zeitstempel wie `-ss 1,318983` statt `-ss 1.318983` ("Invalid duration specification"). Auf `%F` (laut PHP-Doku ausdrücklich nicht locale-abhängig) umgestellt – bereits das Muster, das `pages/mediapool.ffmpeg.trimmer.php` an derselben Stelle schon korrekt nutzte.
- Schwerwiegender Folgefehler bei jedem ffmpeg-Fehlschlag (nicht nur beim oben genannten Locale-Bug, z.B. auch bei kaputten/beschädigten Videodateien): Beide Effekte fingen jede `rex_exception` ab, loggten sie und kehrten dann kommentarlos zurück – dabei blieb `$this->media` unverändert, also die **volle Originaldatei** (Video, oft mehrstellige MB, native Auflösung). Der Media-Manager cachte und lieferte diese Originaldatei daraufhin so aus, als wäre sie das erfolgreich generierte kleine Vorschaubild. Beide Effekte werfen bei einem Fehlschlag jetzt eine `rex_media_manager_not_found_exception`, die vom Media-Manager-Kern als "nicht gefunden" behandelt wird – kein Cache-Eintrag, kein Ausliefern der Originaldatei als falsches Vorschaubild.

## 4.7.0 - 2026-08-28

### Neu
- "Optimieren"-Button jetzt auch im **klassischen** Medienpool-Bearbeiten-Formular (`mediapool/media`), nicht mehr nur in der eigenen Video-Tools-Seite oder im MediaPlace-Addon. Gleicher Hook wie das cropper-Addon (`MEDIA_FORM_EDIT`), gleiche Job-Engine wie überall sonst. Zeigt "Erneut optimieren" + Kompressionsrate an, wenn die Datei bereits optimiert wurde, läuft inline per AJAX (kein Seitenwechsel), lädt die Seite nach Abschluss neu.

### Bugfix
- `VideoInfo` bekommt öffentliche `isAvailable()`/`isSupportedVideoFile()`-Methoden statt die ffmpeg-Verfügbarkeitsprüfung ein drittes Mal zu duplizieren.

## 4.6.0 - 2026-08-28

### Neu
- Der neue "Original ersetzen"-Modus (siehe 4.5.0) war bisher nur über die API bzw. das MediaPlace-Addon erreichbar – die eigene Video-Tools-Seite bot ihn nicht an. Pro Video jetzt zwei getrennte, eindeutig beschriftete Aktionen: **"Optimieren"** (ersetzt das Original, gleicher Dateiname – nur bei mp4-Quellen angeboten, da die Ausgabe-Extension zwingend identisch zur Quelle bleibt) und **"Webversion erstellen"** (bisheriges Verhalten, separate "web_"-Datei, für jedes Quellformat). Bei mp4-Quellen stehen beide Optionen nebeneinander zur Wahl.
- Eigener Status-Badge "Optimiert: X% kleiner" pro Video, unabhängig vom bestehenden Web-Versions-Badge – beide Zustände sind unabhängig voneinander möglich.

## 4.5.1 - 2026-08-28

### Neu
- Neue `OptimizedVideoRegistry`: merkt sich (Dateigröße + updatedate als Fingerabdruck, kein separates Flag), dass eine Datei per `overwrite`-Modus optimiert wurde – im bisherigen `new_file`-Modus erkennt die Video-Liste "bereits optimiert" an der separaten "web_"-Datei, die es beim Überschreiben-Modus nicht gibt. Wird automatisch ungültig, sobald sich die Datei danach ändert (erneuter Upload, o.ä.).

### Bugfix
- `formatDuration()` auf der Info-Seite löste eine PHP-Deprecation-Warnung aus (Modulo mit Float-Operand). Behoben – `lib/VideoInfo.php` hatte dieselbe Logik bereits korrekt implementiert, die Info-Seite nutzte stattdessen eine eigene, ältere Kopie.

## 4.5.0 - 2026-08-28

### Verbessert
- Job-Engine neu aufgebaut: Konvertierungsstatus ist jetzt global statt session-gebunden (zwei User sehen denselben "läuft bereits eine Konvertierung"-Zustand, statt sich unbemerkt gegenseitig zu überschreiben), Abschluss wird über einen echten ffmpeg-Exit-Code erkannt statt über Text-Suche im Log ("Konvertierung abgeschlossen", "was successfully added to rex_mediapool"). Bestehende Kommando-Konfiguration (INPUT/OUTPUT-Platzhalter) und die "web_"-Namenskonvention für optimierte Kopien bleiben unverändert.
- Konverter-Oberfläche nutzt jetzt einen einzelnen Polling-Endpunkt statt vier verschiedener (progress/done/status/check_all) – weniger Requests, ein aktiver Job wird beim Laden der Seite direkt eingebettet statt per zusätzlichem Request nachgeladen.

### Neu
- Neuer "Original ersetzen"-Modus (`mode=overwrite`) für die Konvertierung: ersetzt die Original-Mediendatei direkt (gleicher Dateiname, gleiche Datenbank-Zeile), statt wie bisher immer eine neue "web_"-Datei anzulegen – Titel/Kategorie/Metainfo-Felder bleiben dadurch automatisch erhalten. Wird vom MediaPlace-Addon für dessen "Video optimieren"-Button genutzt, steht aber auch für andere Integrationen offen.

### Bugfix
- `rex_effect_video_to_webp`/`rex_effect_video_to_preview` legten das Ziel-Cache-Verzeichnis (`var/cache/addons/media_manager/`) nicht selbst an – auf einer frischen Installation bzw. nach geleertem Cache brach ffmpeg mit "No such file or directory" ab, bevor überhaupt ein erstes Bild anderen Typs dort geschrieben wurde. Beide Effekte legen das Verzeichnis jetzt bei Bedarf selbst an.

## 4.4.0 - 2026-07-30

### Neu
- PingPong-Vorschau im Trimmer mit eigener Taste, unabhängig vom Schleifenmodus.
- Ton-Option im Trimmer mit wählbarem Audio-Export für normale Schnitte.

### Verbessert
- Aktiver Wiedergabemodus im Trimmer wird jetzt eindeutig im HUD und an den Buttons angezeigt.
- Bereichstest und PingPong-Vorschau lassen sich per erneutem Klick wieder abschalten.
- Nahtlos-Loop erzwingt Tonentfernung technisch sauber und erklärt den Zustand im Formular.

## 4.3.0 - 2026-07-29

### Neu
- Konfigurierbare Medien-Kategorie für den Import konvertierter Dateien im Medienpool.
- Video-Konverter-Oberfläche als tabellarisches Desktop-Layout mit klaren Spalten (Datei, Informationen, Aktionen, Status).
- Konvertierung direkt am jeweiligen Dateieintrag über "Dieses Video konvertieren".
- Vorschau-Modal für Original und Web-Version direkt aus der Liste, inklusive Loop-Schalter.
- Dateibezogener Konvertierungsstatus mit Donut-Anzeige und optional aufklappbarem Protokoll.
- Video-Trimmer mit direkt am Video eingebetteter Steuerleiste, Scrubber, Schnellbuttons, Start-/Ende-Markern und Bereichstest.

### Verbessert
- Deutlich reduzierte visuelle Unruhe durch kompaktere Abstände, konsistente Button-Gruppen und klarere Kartenstruktur.
- Statusdarstellung pro Zeile: Bereit, Konvertiert oder Laufend.
- Globaler Statusbereich als kompakter Technik-Block statt dominanter Fortschrittsleiste.
- Status-Prüfen-Aktion und globaler Statusblock im Konverter nach oben verlegt, damit Fortschritt und Aktionen direkt sichtbar sind.
- Inline-Protokoll je Video als aufklappbare Vollbreiten-Zeile umgesetzt.
- Nach Abschluss bleibt die Seite stehen (kein Auto-Reload), damit Logs und Status weiterhin einsehbar sind.
- Bereits konvertierte Videos können gezielt erneut konvertiert werden (dezentere Aktion "Erneut konvertieren").
- Trimmer-, Tabellen- und Modal-Oberfläche für Light-, Dark- und Auto-Theme optimiert.
- Trimmer-Editor, Eingabefelder und Buttons erhalten nun konsistente Theme-Farben und bessere Lesbarkeit im Dark Mode.
- Vorschau- und Trimmer-Interaktionen laufen zuverlässig über REDAXO-Backend-Navigationen ohne kompletten Reload.

### Technisch
- Asset-Cache-Busting fuer CSS/JS in `boot.php`.
- JavaScript auf Donut-Fortschritt und zeilenbezogene Statussteuerung umgestellt.
- Trimmer-Logik für Scrubber, HUD-Buttons, Loop-Schalter und Auswahl-Wiedergabe erweitert.
- JavaScript-Initialisierung gegen doppelte Event-Bindings abgesichert (einmalige Initialisierung), um doppelte Start-Requests zu vermeiden.
- Fehlerhafte Script-Auslieferung in den öffentlichen Addon-Assets korrigiert und synchronisiert.
