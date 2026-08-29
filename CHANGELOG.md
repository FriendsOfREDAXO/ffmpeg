# Changelog

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
