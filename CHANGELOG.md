# Changelog

## 4.2.0 - 2026-07-29

### Neu
- Video-Konverter-Oberfläche als tabellarisches Desktop-Layout mit klaren Spalten (Datei, Informationen, Aktionen, Status).
- Konvertierung direkt am jeweiligen Dateieintrag über "Dieses Video konvertieren".
- Vorschau-Modal für Original und Web-Version direkt aus der Liste, inklusive Loop-Schalter.
- Dateibezogener Konvertierungsstatus mit Donut-Anzeige und optional aufklappbarem Protokoll.
- Video-Trimmer mit direkt am Video eingebetteter Steuerleiste, Scrubber, Schnellbuttons, Start-/Ende-Markern und Bereichstest.

### Verbessert
- Deutlich reduzierte visuelle Unruhe durch kompaktere Abstände, konsistente Button-Gruppen und klarere Kartenstruktur.
- Statusdarstellung pro Zeile: Bereit, Konvertiert oder Laufend.
- Globaler Statusbereich als kompakter Technik-Block statt dominanter Fortschrittsleiste.
- Trimmer-, Tabellen- und Modal-Oberfläche für Light-, Dark- und Auto-Theme optimiert.
- Trimmer-Editor, Eingabefelder und Buttons erhalten nun konsistente Theme-Farben und bessere Lesbarkeit im Dark Mode.
- Vorschau- und Trimmer-Interaktionen laufen zuverlässig über REDAXO-Backend-Navigationen ohne kompletten Reload.

### Technisch
- Asset-Cache-Busting fuer CSS/JS in `boot.php`.
- JavaScript auf Donut-Fortschritt und zeilenbezogene Statussteuerung umgestellt.
- Trimmer-Logik für Scrubber, HUD-Buttons, Loop-Schalter und Auswahl-Wiedergabe erweitert.
