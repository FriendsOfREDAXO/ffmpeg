# REDAXO-AddOn: FFMPEG Video Converter

Der ultimative Video-Turbo für REDAXO CMS – Konvertierung im Hintergrund, während du dich um die wichtigen Dinge kümmerst!

## Voraussetzungen

- PHP-Funktion `exec` auf dem Server (nichts geht ohne Kommandos!)
- ffmpeg installiert und startklar (die Konvertierungsmaschine)
- REDAXO 5.18.1 oder höher (für maximale Power)

## Killer-Features 🚀

- **Magische Hintergrundverarbeitung** – starte die Konvertierung und mach einfach weiter mit deinem Leben
- **Browser-Schließen? Kein Problem!** Dein Video wird trotzdem fertig konvertiert
- **Smarte Medienpool-Integration** mit web_-Prefix für aufgeräumte Mediatheken
- **Metadaten-Magie** – Titel, Beschreibungen und Copyright wandern automatisch mit
- **Konvertierungs-Dämon** arbeitet weiter, selbst wenn deine Session längst abgelaufen ist
- **Kompressionsanzeige** zeigt dir, wie viel Speicherplatz du gerade gespart hast
- **Aufräum-Option** – alte Originale automatisch entsorgen nach erfolgreicher Optimierung

## Workflow, der rockt

1. Video-Konverter im Medienpool öffnen – BAM, da ist die Video-Liste
2. Video anklicken, das eine Frischzellenkur braucht
3. "Video konvertieren" drücken und zurücklehnen
4. **PLOT TWIST**: Browser schließen und Kaffee holen – die Maschine arbeitet weiter!
5. Später zurückkommen, "Status prüfen" – wie von Zauberhand ist dein Video fertig

## Power-Einstellungen

Hier wird's für Techies richtig interessant! Vollständige Kontrolle über die Konvertierungsparameter:

```
ffmpeg -y -i INPUT -vcodec h264 -crf 23 -preset fast OUTPUT.mp4
```

Spiel mit den Optionen für unterschiedliche Szenarien:
- Max. Qualität für Produktvideos
- Ultraschnelles Laden für Landing Pages
- Platzsparende Archivierung für große Videokataloge

## Fortgeschrittene Superkräfte

### Metadaten-Teleportation

Beim Konvertieren werden automatisch alle wichtigen Informationen übertragen – nichts geht verloren! Der Titel bekommt den stylischen Zusatz "weboptimiert", damit du sofort weißt, welche Version die optimierte ist.

### Ewige Konvertierung

Selbst wenn dein Rechner einen Bluescreen hat, dein Browser abstürzt oder die Putzfrau den Server-Stecker zieht (ok, das letzte vielleicht nicht) – sobald alles wieder läuft, kann der Prozess wiederaufgenommen werden!

### Live-Status-Tracking

Mit echtzeitähnlichen Updates siehst du genau, was gerade passiert:
- 🔄 Konvertierung läuft... 65%
- 📥 Importiere...
- ✅ Fertig! (Mit genauer Kompressionsrate)

## Tuning-Tipps für Geschwindigkeitsfanatiker

Hier ein paar ffmpeg-Geheimtipps für Spezialfälle:

- Ultra-Fast: `-preset ultrafast -crf 28` (für schnelle Previews)
- HD-Qualität: `-vf scale=1920:1080 -crf 18` (Bildschirmfüllend scharf)
- Audio-Optimierung: `-acodec aac -ab 128k -ac 2` (Stereo-Sound in Webqualität)
- Mobile-Fokus: `-vf scale=640:360 -crf 26` (kleine Dateien für unterwegs)

## API-Referenz für Codenerds

Die interne Power-API für alle, die programmieren wie Neo in Matrix:

### RESTful-Endpunkte

```
index.php?rex-api-call=ffmpeg_converter&func=FUNKTION&video=DATEINAME
```

#### `start` – Die Konvertierungsmaschine anwerfen
- Parameter: `video` (welcher Film soll in die Mangel?)
- Gibt dir eine persönliche Konversions-ID für späteres Tracking

#### `progress` – Wie weit ist die Maschine?
- Liefert Fortschritt in Prozent und aktuelle Log-Zeilen
- Perfekt für Progress-Bars und Live-Updates

#### `done` – Abpfiff und Import
- Holt das fertige Video ins Medienzentrum
- Überträgt alle Metadaten in einem Rutsch

#### `status` – Systemcheck
- Zeigt aktive Prozesse und deren Status
- Perfekt zum automatischen Wiederaufnehmen nach Unterbrechungen

## Credits

- Idee und Konzept: KLXM Crossmedia GmbH
- Lead: Thomas Skerbis ([@skerbis](https://github.com/skerbis))
- First Version: ([@joachimdoerr](https://github.com/joachimdoerr))


## Autor

**Friends Of REDAXO**

* http://www.redaxo.org
* https://github.com/FriendsOfREDAXO


## Lizenz
MIT-Lizenz (mach damit, was du willst, aber auf eigene Gefahr!)
