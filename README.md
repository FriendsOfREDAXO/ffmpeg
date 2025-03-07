# REDAXO-AddOn: FFMPEG Video Converter

FFMPEG Video-Converter für REDAXO CMS - konvertieren, kürzen und Poster aus Videos erstellen.

![Screenshot](https://github.com/FriendsOfREDAXO/ffmpeg/blob/assets/shot.png?raw=true)

## Voraussetzungen
- exec sollte für PHP erlaubt sein
- ffmpeg muss auf dem Server zur Verfügung stehen
- PHP 7.3 oder höher

## Features
- Konvertierung von Video-Material mittels ffmpeg serverseitig
- Erzeugung von Postern (Standbildern) aus Videos
- Erstellung gekürzter Vorschauversionen
- Übersicht aller optimierten Videos mit Löschfunktion
- Übernahme der konvertierten Videos in den Medienpool (mit entsprechendem Präfix)
- Vererbung von Metadaten vom Original
- Video-Vorschau direkt im Backend
- Einstellungsseite für die Anpassung der ffmpeg-Kommandos
- Löschen der Original-Datei (optional)

## Anwendung

### Videos konvertieren
- Im Reiter "Video-Konverter" des Medienpools finden Sie eine Liste von Videos
- Wählen Sie "Konvertieren" als Operation und selektieren Sie ein Video
- Nach Klick auf "Ausführen" startet der Vorgang (Das Fenster bitte nicht schließen)
- Die konvertierte Datei wird mit dem Präfix `web_` im Medienpool unter "Keine Kategorie" abgelegt

### Poster erstellen
- Wählen Sie "Poster erstellen" als Operation und selektieren Sie ein Video
- Geben Sie den gewünschten Zeitpunkt für das Standbild ein (z.B. 00:01:30 für 1 Minute 30 Sekunden)
- Nach Klick auf "Ausführen" wird das Standbild erzeugt
- Das generierte Poster wird mit dem Präfix `poster_` im Medienpool abgelegt

### Videos kürzen
- Wählen Sie "Kürzen" als Operation und selektieren Sie ein Video
- Definieren Sie Start- und Endzeit für den gewünschten Ausschnitt
- Nutzen Sie den Schieberegler zur visuellen Auswahl des Zeitbereichs
- Nach Klick auf "Ausführen" wird die gekürzte Version erstellt
- Das gekürzte Video wird mit dem Präfix `trim_` im Medienpool abgelegt

### Verwaltung
- Im Tab "Optimierte Videos" finden Sie alle konvertierten Videos mit Größenvergleich zum Original
- Im Tab "Poster" sehen Sie alle generierten Standbilder
- Im Tab "Gekürzte Videos" werden alle gekürzten Vorschauversionen angezeigt
- Alle generierten Dateien können direkt aus der Übersicht gelöscht werden

## Einstellungen

Sie können die Qualität und das Format für alle Operationen konfigurieren:

### Konvertierung
```
ffmpeg -y -i INPUT -vcodec h264 -crf 23 -preset veryfast OUTPUT.mp4
```

### Poster-Qualität
Wählen Sie zwischen verschiedenen JPEG-Qualitätsstufen oder PNG für höchste Qualität.

### Trim-Preset
Beeinflusst die Qualität und Verarbeitungsgeschwindigkeit bei gekürzten Videos.

## Parameter-Erklärung
- INPUT = Datei aus Auswahl 
- OUTPUT = Ausgabedatei (mit Dateiendung)
- -y = Bestätige jede Frage mit yes
- -crf = Qualitätsfaktor (niedrigere Werte = bessere Qualität)
- -preset = Kodierungsgeschwindigkeit (ultrafast, veryfast, slow, etc.)

## Generierte Dateien
- Konvertierte Videos: `web_dateiname.mp4`
- Poster: `poster_dateiname_timestamp.jpg`
- Gekürzte Videos: `trim_dateiname_startzeit-endzeit.mp4`

## Tipps
Wer mit ffmpeg-Befehlen nicht vertraut ist, findet unter [http://www.mackinger.at/ffmpeg/](http://www.mackinger.at/ffmpeg/) eine gute Hilfe.

## Credits 
- Idee und Konzept: KLXM Crossmedia GmbH 
- Lead: Thomas Skerbis (@skerbis)
- Danke an: Joachim Dörr (@joachimdoerr)

## Basierend auf
[PHP-FFmpeg-Command-Execution](https://github.com/Pedroxam/PHP-FFmpeg-Command-Execution)

## LIZENZ 
MIT 

## Hinweise
- FFMPEG muss auf dem Server zur Verfügung stehen 
- shell_exec muss für PHP zulässig sein
