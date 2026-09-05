# EmailReporting — testy parsovania tela mailu

Testy pre orezávanie citovaných častí (`>`) v odpovediach, ktoré EmailReporting
zakladá ako poznámky.

## Kontext

Keď je zapnuté **Remove all replies from notes** (`mail_remove_replies`) alebo
**Strip signature from email body** (`mail_strip_signature`), telo mailu prejde cez
`EmailReplyParser` v `ERP_mailbox_api::parse_email_body()`.

Pôvodná implementácia zahadzovala **každý** citovaný fragment. Ak človek odpovedal
inline — vlastný text preložený citátmi z pôvodnej správy — citáty zmizli a poznámka
stratila zmysel. `selectFragments()` teraz zahodí len **koncový** citovaný blok;
citácie obklopené vlastným textom ostávajú.

Známe obmedzenie: ak je za citáciou ešte firemný disclaimer, počíta sa ako vlastný
text a citácia nad ním sa zachová. Patch je v takom prípade konzervatívnejší
(nechá viac), čo je bezpečnejšie ako mazať obsah.

## `test_fragments.php` — unit test

Bez Mantisu, bez DB, nič nezakladá. Overuje logiku výberu fragmentov.

```bash
php plugins/EmailReporting/tests/test_fragments.php
VERBOSE=1 php plugins/EmailReporting/tests/test_fragments.php   # pôvodné vs. nové správanie
```

Logika v tomto skripte je kópiou `selectFragments()` z `core/mail_api.php` —
pri zmene tej metódy treba upraviť aj test.

## `run_e2e.sh` — end-to-end test cez skutočný pipeline

Vygeneruje odpovede na zvolené issue, injektne ich do bežiaceho Mantisu
(`plugins/ImaticEmailReporting/tests/inject_email.php`), načíta založené poznámky
z DB a skontroluje ich.

```bash
plugins/EmailReporting/tests/run_e2e.sh --issue=1443 --from=meno@imatic.cz
plugins/EmailReporting/tests/run_e2e.sh --issue=1443 --dry-run   # nič nezaloží
```

Voľby: `--issue` (povinné), `--from`, `--container` (`mantis-web`), `--db`
(`mantis-postgres`), `--db-name` (`bugtracker`), `--db-user` (`postgres`),
`--label` (text pred číslom issue v subjecte), `--dry-run`.

Predpoklady:

- beží Docker prostredie projektu,
- v EmailReporting je zapnuté **Remove all replies from notes** a **Add notes**,
- odosielateľ nie je z „disposable" domény (`example.com` je odmietnutá,
  použi napr. `@imatic.cz`),
- issue existuje; poznámky sa doň reálne založia, po teste ich zmaž.

### Scenáre

| Mail | Očakávanie |
|---|---|
| `q1_trailing_quote` | klasická odpoveď — koncová citácia odrezaná |
| `q2_inline_quote` | inline odpoveď (reportovaný bug) — citácie zachované |
| `q3_inline_plus_trailing` | inline zachované, koncový blok odrezaný |
| `q4_html_blockquote` | HTML `<blockquote>` uprostred |

`q4` je informatívny: HTML sa na markdown prevádza len keď je načítaný plugin
`MantisCoreFormatting` (`core/mail_api.php`, `process_markdown`). Na inštalácii
s `ImaticFormatting` sa do poznámky dostane surový HTML a citácie sa vôbec
nespracujú — samostatný problém, nesúvisiaci s týmto patchom.

## `gen_eml.php`

Generátor `.eml` súborov, používa ho `run_e2e.sh`. Dá sa spustiť aj samostatne:

```bash
php plugins/EmailReporting/tests/gen_eml.php --issue=1443 --out=/tmp/eml --from=meno@imatic.cz
```

Subject má tvar `[Mantis 0001443]: ...`, čo zodpovedá nastaveniu
`mail_subject_id_regex = strict`.
