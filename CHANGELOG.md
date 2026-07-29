# Changelog

## 4.1.0 - 2026-07-29

### Neu
- Video-Konverter-Oberfläche als tabellarisches Desktop-Layout mit klaren Spalten (Datei, Informationen, Aktionen, Status).
- Konvertierung direkt am jeweiligen Dateieintrag über "Dieses Video konvertieren".
- Vorschau-Modal für Original und Web-Version direkt aus der Liste.
- Dateibezogener Konvertierungsstatus mit Donut-Anzeige und optional aufklappbarem Protokoll.

### Verbessert
- Deutlich reduzierte visuelle Unruhe durch kompaktere Abstände, konsistente Button-Gruppen und klarere Kartenstruktur.
- Statusdarstellung pro Zeile: Bereit, Konvertiert oder Laufend.
- Globaler Statusbereich als kompakter Technik-Block statt dominanter Fortschrittsleiste.

### Technisch
- Asset-Cache-Busting fuer CSS/JS in `boot.php`.
- JavaScript auf Donut-Fortschritt und zeilenbezogene Statussteuerung umgestellt.
