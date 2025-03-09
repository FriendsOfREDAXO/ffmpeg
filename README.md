# REDAXO-AddOn: FFMPEG Video Converter

ffmpeg Video-Converter für REDAXO cms 

![Screenshot](https://github.com/FriendsOfREDAXO/ffmpeg/blob/assets/shot.png?raw=true)

## Voraussetzungen
- exec sollte für PHP erlaubt sein
- ffmpeg muss auf dem Server zur Verfügung stehen

## Features
- Konvertierung von Video-Material mittels ffmpeg serverseitig
- Übernahme des konvertierten Videos in den Medienpool mit präfix web_
- Einstellungsseite um das ffmpeg-Kommando anzupassen 
- Löschen der Original-Datei (optional)
- **NEU:** Asynchrone Verarbeitung im Hintergrund - Browser kann geschlossen werden

## Anwendung

- Im Reiter Video-Konverter des Medienpools findet man eine Liste noch nicht bearbeiteter Videos. 
- Hier wählt man ein Video aus zur Konvertierung
- Nach Click auf `Video konvertieren` startet der Vorgang. 
- Der Konvertierungsprozess läuft im Hintergrund. Das Browser-Fenster kann geschlossen werden.
- Mit dem Button "Status prüfen" kann der aktuelle Status später abgefragt werden.

## Einstellungen

Man kann die Qualität und das Format selbst einstellen. Auch kürzen der Videos ist möglich. 

Beispiel 
```
ffmpeg -y -i INPUT -vcodec h264 OUTPUT.mp4
```
INPUT = Datei aus Auswahl 
OUTPUT.ext = Output mit Dateiendung
y = Bestätige jede Frage mit yes

Konvertierte Videos erhalten automatisch das Prefix `web_`

Tipps: Wer mit ffmpeg-Befehlen nicht vertraut ist, findet unter [http://www.mackinger.at/ffmpeg](http://www.mackinger.at/ffmpeg/) eine gute Hilfe. 

## Technische Details

- Der Konvertierungsprozess läuft asynchron im Hintergrund über eine REX-API
- Der Status wird über eine API-Schnittstelle abgefragt
- Nach Abschluss der Konvertierung wird das Video automatisch im Medienpool registriert

## Credits 
- Idee und Konzept: KLXM Crossmedia GmbH 
- Lead: Thomas Skerbis (@skerbis)
- Danke an: Joachim Dörr (@joachimdoerr)

## Based on: 
[PHP-FFmpeg-Command-Execution](https://github.com/Pedroxam/PHP-FFmpeg-Command-Execution)

## LIZENZ 
MIT 

## Hinweise: 
- FFMPEG muss auf dem Server zur Verfügung stehen 
- shell_exec muss für PHP zulässig sein
