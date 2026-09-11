# EmailReporting — automatické testy

Tri vrstvy testov nad spracovaním prichádzajúcich mailov. Všetky bežia v CI
(vlastný krok v `.github/workflows/cd.yml`, ktorý púšťa `phpunit.xml` tohto
pluginu) a červený výsledok blokuje deploy na stage.

| vrstva | čo testuje | čo potrebuje | rýchlosť |
|---|---|---|---|
| `tests/unit` | parsovanie tela, citácie, podpisy, kódovania, MIME | nič | ms |
| `tests/integration` | celá cesta mail → poznámka v DB | nainštalovaný Mantis + DB | ~0,2 s |
| `tests/mailserver` | fetch cez IMAP tak ako cron | Mantis + DB + GreenMail | ~9 s |

## Kontext — čo sa tu chráni

Keď je zapnuté **Remove all replies from notes** (`mail_remove_replies`) alebo
**Strip signature from email body** (`mail_strip_signature`), telo mailu prejde cez
`EmailReplyParser` v `erp_parse_email_body()` (`core/mail_body_pure.php`).

Pôvodná implementácia zahadzovala **každý** citovaný fragment. Ak človek odpovedal
inline — vlastný text preložený citátmi z pôvodnej správy — citáty zmizli a poznámka
stratila zmysel (bug nahlásil jan.pekar, poznámka ~0519949). `erp_select_fragments()`
teraz zahodí len **koncový** citovaný blok; citácie obklopené vlastným textom ostávajú.

Fragmenty sa spájajú prázdnym riadkom. Bez neho markdown pripojí riadok za `> `
citáciou do tej istej citácie (lazy continuation) a vlastná odpoveď sa vykreslí
vnútri sivého bloku.

Známe obmedzenie: ak je za citáciou ešte firemný disclaimer, počíta sa ako vlastný
text a citácia nad ním sa zachová. Test to explicitne popisuje
(`test_disclaimer_below_the_quote_keeps_the_quote`). Je to konzervatívnejšie
zlyhanie — nechá viac, nikdy nezmaže obsah.

## Spustenie

Všetko sa púšťa z rootu Mantisu.

### Iba unit vrstva (bez Mantisu, bez DB)

```bash
vendor/bin/phpunit -c plugins/EmailReporting/phpunit.xml --testsuite unit
```

Integračná a mailserver vrstva sa v tomto režime samé preskočia, takže výsledok
zostáva zelený aj na čistom checkoute.

### Integračná vrstva

Potrebuje bežiaci Mantis a jeho DB. Lokálne teda vnútri kontejnera:

```bash
docker exec -w /var/www/html -e ERP_TESTS_MANTIS=1 mantis-web \
    vendor/bin/phpunit -c plugins/EmailReporting/phpunit.xml --testsuite integration
```

`ERP_TESTS_MANTIS=1` povie bootstrapu, aby naštartoval Mantis. Jediný prípad,
keď premenná netreba, je spustenie cez root `phpunit.xml` — ten si Mantis
naštartuje sám. Root konfigurácia ale tieto suity nezahŕňa zámerne: `phpunit.xml`
v roote je upstream súbor a každý zásah doň by sa musel pri upgrade MantisBT
prenášať ako patch.

### Mailserver vrstva

Najprv GreenMail na tej istej docker sieti ako Mantis:

```bash
docker run -d --name erp-greenmail --network mantis_default \
    -e GREENMAIL_OPTS='-Dgreenmail.setup.test.all -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.users=mantis:mantis@localhost' \
    greenmail/standalone:2.1.5
```

Potom:

```bash
docker exec -w /var/www/html -e ERP_TESTS_MANTIS=1 -e ERP_GREENMAIL_HOST=erp-greenmail mantis-web \
    vendor/bin/phpunit -c plugins/EmailReporting/phpunit.xml --testsuite mailserver
```

Keď na `ERP_GREENMAIL_HOST:3143` nič neodpovedá, celá vrstva sa preskočí.

Premenné: `ERP_GREENMAIL_HOST` (default `127.0.0.1`), `ERP_GREENMAIL_IMAP_PORT`
(3143), `ERP_GREENMAIL_SMTP_PORT` (3025).

## Ako je to spravené

- `tests/bootstrap.php` — rozhodne, či Mantis štartovať, alebo použiť stuby
  z `tests/stubs.php`. Každý test si bootstrap vyžiada sám, takže nezáleží,
  ktorou konfiguráciou sa phpunit spustil.
- `tests/ERPIntegrationCase.php` — prihlási sa ako vlastný jednorazový účet
  (žiadne heslá konkrétnej inštalácie), vytvorí si vlastný projekt a kategóriu,
  vypne odosielanie mailov a DNS kontrolu adries, a na konci všetko zmaže.
  Raw `.eml` posiela priamo do `process_single_email()` cez mock POP3 servera.
- `tests/ERPMailserverCase.php` — to isté, ale mail najprv doručí cez SMTP do
  GreenMailu a potom zavolá `process_mailbox()`, teda reálny Net_IMAP.
  Vie sa aj pozrieť, čo v mailboxe zostalo.
- `tests/fixtures/*.eml` — kompletné maily: quoted-printable, 8bit UTF-8,
  ISO-8859-2, windows-1250, multipart/alternative, multipart s prílohou,
  text/html s `<blockquote>`.

## Poznámky

- Testy reálne zakladajú issues a poznámky, ale vo vlastnom projekte a pod
  vlastným jednorazovým účtom, ktoré sa na konci mažú. Po behu by v DB nemalo
  zostať nič:

  ```sql
  select id, name from mantis_project_table where name like 'ERP tests%';
  select id, username from mantis_user_table where username like 'erp_tests_%';
  ```

  Ak niečo zostane, znamená to, že beh spadol na PHP fatal error a teardown sa
  nespustil. Zvyšky sa mažú cez API, nie SQL, aby odišli aj naviazané riadky:

  ```bash
  docker exec -w /var/www/html mantis-web php -r '
      $g_bypass_headers = 1; require "core.php";
      config_set_global( "enable_email_notification", OFF );
      project_delete( <id> ); user_delete( <id> );'
  ```

- HTML maily: `mail_parse_html` prevedie HTML na markdown len keď formátovací
  plugin hlási `process_markdown`. Na tejto inštalácii je použitý ImaticFormatting,
  ale `mail_api.php` sa pýta výhradne MantisCoreFormatting, takže sa prevod
  nespustí a do poznámky príde surové HTML. Testy tento stav popisujú
  (`test_html_body_is_kept_as_html_when_conversion_is_off`) a zvlášť testujú aj
  správne chovanie po zapnutí prevodu. Je to samostatný, starší problém.
