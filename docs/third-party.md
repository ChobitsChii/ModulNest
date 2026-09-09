# Drittanbieter-Komponenten

## highlight.js 11.11.1

Die lokale Markdown-Codeblock-Hervorhebung verwendet [highlight.js](https://highlightjs.org/) 11.11.1 unter der BSD-3-Clause-Lizenz. Die Abhängigkeit wird über `npm` verwaltet und mit `npm run build:markdown-assets` reproduzierbar nach `public/assets/js/markdown-highlight.js` gebündelt. Zur Laufzeit wird keine externe Quelle geladen.

## sodium_compat 2.x

Der Modulkatalog verifiziert Ed25519-Signaturen mit PHPs nativer Sodium-
Extension oder der Composer-Abhängigkeit `paragonie/sodium_compat` als
kompatiblem Fallback. Es werden ausschließlich öffentliche Trust-Store-Keys
konfiguriert; private Produktionsschlüssel gehören nie in dieses Repository.
