# Changelog

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
