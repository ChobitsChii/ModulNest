# Production-Signing des Modulkatalogs

Der offizielle ModulNest-Katalog verwendet zwei getrennte Ed25519-Schlüssel:

- Root: `modulnest-root-2026-01`, Fingerprint `54348dc9dd52da97092ad0a65dcc433dc3f715c643e433637bc5030ba58e9d5e`
- Releases: `modulnest-release-2026-01`, Fingerprint `e328756b8b21755440df968fc341c1606bda3c8897bda91c1b3d2bfa254db93e`

Nur Public Keys, IDs und Fingerprints sind Teil des Core-Pakets. Private Keys
liegen außerhalb von Repository, Public Export und Webroot in einem nur für den
Release-Operator lesbaren Verzeichnis. Der Publisher verlangt in Production
unterschiedliche Root- und Release-Keys. Der signierte Root autorisiert den
Release-Key; der Loader weist Pakete mit nicht autorisierten Keys zurück.

## Rotation

1. Neues Release-Schlüsselpaar offline erzeugen und den Public Key mit einem
   durch den bisherigen Root signierten Katalog-Root ausrollen.
2. Erst in einer höheren Katalog-Sequence Pakete mit dem neuen Release-Key
   veröffentlichen.
3. Den alten Release-Key erst entfernen, wenn keine unterstützten Artefakte ihn
   mehr benötigen.
4. Eine Root-Key-Rotation benötigt vorab einen Core-Release, der den neuen Root
   vertraut. Anschließend wird eine höhere Katalog-Sequence mit beiden Root-
   Metadaten veröffentlicht und der alte Root kontrolliert ausgemustert.

Bei Verlust eines Release-Keys wird ein neuer Key über den unveränderten Root
autorisiert. Bei Verdacht auf Kompromittierung wird der betroffene Key aus einer
höheren Root-Sequence entfernt; bestehende Last-Known-Good-Daten bleiben für
Diagnose und Recovery erhalten. Private Schlüssel werden nicht aus Git oder
veröffentlichten Artefakten rekonstruiert.
