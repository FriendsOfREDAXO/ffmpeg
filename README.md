# REDAXO-AddOn: video_converter

ffmpeg Video-Converter für REDAXO cms 

![Screenshot](https://github.com/FriendsOfREDAXO/ffmpeg/blob/assets/shot.png?raw=true)

## Features
- Konvertierung von Video-Material mittels ffmpeg serverseitig
- Übernahme des konvertierten Videos in den Medienpool mit präfix web_
- Einstellungsseite um das ffmpeg-Kommando anzupassen 
- Löschen der Original-Datei (optional) 

## Anwendung

- Im Reiter Video-Konverter des Medienpools findet man eine Liste noch nicht bearbeiteter Videos. 
- Hier wählt man ein Video aus zur Konvertierung
- Nach Click auf `Video konvertieren`startet der Vorgang. (Das Fenster bitte nicht schließen) 

## Einstellungen

Man kann die Qualität und das Format selbst einstellen. Auch kürzen der Videos ist möglich. 

Beispiel 
```
ffmpeg -i INPUT -vcodec h264 OUTPUT.mp4
```
INPUT = Datei aus Auswahl 
OUTPUT.ext = Output mit Dateiendung 

Konvertierte Video erhalten automatisch das Prefix `web_`

Tipps: Wer mit ffmpeg-Befehlen nicht vertraut ist, findet unter <a href = "http://www.mackinger.at/ffmpeg/" target = "_blank"> dieses Tool </ a> verwenden, um einige ffmpeg-Befehle zu generieren.



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

