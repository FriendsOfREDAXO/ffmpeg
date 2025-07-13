# REDAXO-AddOn: FFMPEG Video Tools

Video Tools für REDAXO CMS – Konvertierung im Hintergrund, während du dich um die wichtigen Dinge kümmerst!

## Voraussetzungen

- PHP-Funktion `exec` auf dem Server (nichts geht ohne Kommandos!)
- ffmpeg installiert und startklar (die Konvertierungsmaschine)
- REDAXO 5.18.1 oder höher (für maximale Power)

## Killer-Features 🚀

### Video-Konverter
- **Magische Hintergrundverarbeitung** – starte die Konvertierung und mach einfach weiter mit deinem Leben
- **Browser-Schließen? Kein Problem!** Dein Video wird trotzdem fertig konvertiert
- **Smarte Medienpool-Integration** mit web_-Prefix für aufgeräumte Mediatheken
- **Metadaten-Magie** – Titel, Beschreibungen und Copyright wandern automatisch mit
- **Konvertierungs-Dämon** arbeitet weiter, selbst wenn deine Session längst abgelaufen ist
- **Kompressionsanzeige** zeigt dir, wie viel Speicherplatz du gerade gespart hast
- **Aufräum-Option** – alte Originale automatisch entsorgen nach erfolgreicher Optimierung

### 🆕 Video-Trimmer
- **Präzises Schneiden** mit Start/End-Markierungen direkt im Browser
- **Verlustfreies Trimming** durch FFmpeg Stream-Copy
- **Intuitive Bedienung** mit Video-Player und One-Click-Buttons
- **Keyboard-Shortcuts** für Profi-Workflow (Strg+S/E)
- **Automatischer Import** als `web_trimmed_*` in den Medienpool

### 🆕 Video-Informationen
- **Detaillierte technische Daten** (Auflösung, Bitrate, Codec, Dauer)
- **Seitenverhältnis-Erkennung** (16:9, 4:3, 9:16, etc.)
- **Audio/Video-Stream-Details** mit allen wichtigen Parametern
- **Optimierungsempfehlungen** basierend auf Video-Eigenschaften
- **Direkter Zugang** zu Trimmer und Konverter
- **Responsive Layout** mit Schutz vor Layout-Problemen bei langen Dateinamen

## Workflow, der rockt

### Video-Konverter
1. Video-Konverter im Medienpool öffnen – BAM, da ist die Video-Liste
2. Video anklicken, das eine Frischzellenkur braucht
3. "Video konvertieren" drücken und zurücklehnen
4. **PLOT TWIST**: Browser schließen und Kaffee holen – die Maschine arbeitet weiter!
5. Später zurückkommen, "Status prüfen" – wie von Zauberhand ist dein Video fertig

### 🆕 Video-Trimmer
1. **Medienpool → Video-Konverter → Video-Trimmer** aufrufen
2. Video aus der Liste **auswählen** 
3. Video abspielen und **Start-/Endpunkte** setzen:
   - Position im Player suchen und pausieren
   - "Aktuelle Position setzen" klicken (oder Strg+S/E)
4. **"Video schneiden"** klicken
5. Geschnittenes Video wird automatisch als `web_trimmed_dateiname.mp4` gespeichert

### 🆕 Video-Informationen
1. **Medienpool → Video-Konverter → Video-Informationen** aufrufen
2. Video aus der Liste **auswählen**
3. **Detaillierte Analyse** wird automatisch angezeigt:
   - Auflösung, Seitenverhältnis, Dauer
   - Video-/Audio-Codec-Details
   - Bitrate und Qualitäts-Metriken
   - Optimierungsempfehlungen
4. **Direkte Aktionen** verfügbar (Schneiden, Konvertieren)

## Power-Einstellungen

Hier wird's für Techies richtig interessant! Vollständige Kontrolle über die Konvertierungsparameter:

### Video-Konverter
```bash
ffmpeg -y -i INPUT -vcodec h264 -crf 23 -preset fast OUTPUT.mp4
```

### Video-Trimmer  
```bash
ffmpeg -y -ss START_TIME -t DURATION -i INPUT -c copy OUTPUT.mp4
```

### Video-Informationen
```bash
ffprobe -v quiet -print_format json -show_format -show_streams INPUT.mp4
```

Spiel mit den Optionen für unterschiedliche Szenarien:
- Max. Qualität für Produktvideos
- Ultraschnelles Laden für Landing Pages
- Platzsparende Archivierung für große Videokataloge
- Verlustfreies Schneiden für exakte Clips

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
