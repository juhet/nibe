# NIBE-dashboard

Een eerste, alleen-lezen dashboard uit [DESIGN.txt](DESIGN.txt). De pagina
toont ontdekte meetpunten als kaarten met de laatst opgeslagen waarden,
verbindingsstatus en een trend van numerieke waarnemingen uit de afgelopen
24 uur.
De afzonderlijke gegevensverzamelaar ontdekt systemen en meetpunten via
myUplink en slaat hun actuele waarden op. De pagina start zelf geen verzameling
en vult ontbrekende metingen niet aan met verzonnen waarden.

## Benodigd

- PHP 8.1 of nieuwer met PDO en de PDO MySQL-driver
- PHP cURL-extensie voor uitgaande HTTPS-verzoeken naar myUplink
- MySQL of MariaDB met InnoDB
- HTTPS op de website

## Database installeren

Maak een database en een databasegebruiker aan bij de hostingprovider.
Importeer daarna [`database/schema.sql`](database/schema.sql) in die database,
bijvoorbeeld via phpMyAdmin. Het schema maakt vijf tabellen aan: `nibe_connections`,
`nibe_devices`, `nibe_measurement_points`, `nibe_observations` en
`nibe_collection_runs`. Iedere tabel gebruikt zo dezelfde herkenbare `nibe_`
prefix. Importeer het schema één keer in een lege database.

## Gegevens en geheimen configureren

Stel de volgende omgevingsvariabelen in via het configuratiescherm van de
hostingprovider (PHP/app environment variables), niet in een bestand in de
website of in Git:

| Variabele | Waarde |
| --- | --- |
| `NIBE_DB_HOST` | Hostnaam van de MySQL-server |
| `NIBE_DB_NAME` | Naam van de database |
| `NIBE_DB_USER` | Databasegebruiker |
| `NIBE_DB_PASSWORD` | Wachtwoord van de databasegebruiker |
| `NIBE_INSTALLATION_LABEL` | Optionele naam die bovenaan het dashboard staat |
| `NIBE_COLLECTOR_TOKEN` | Lang, willekeurig geheim voor de collector-aanroep |
| `NIBE_MYUPLINK_CLIENT_ID` | Client-ID van de geregistreerde myUplink API-app |
| `NIBE_MYUPLINK_CLIENT_SECRET` | Client secret van de geregistreerde myUplink API-app |

Als de provider geen omgevingsvariabelen aanbiedt, vraag de provider om een
geheime configuratie buiten de webroot te laden. Maak geen `.env`-bestand in
deze repository of in de publiek bereikbare `httpdocs`-map. De deployworkflow
plaatst de repositorybestanden in `httpdocs/`; de mappen `app/` en `database/`
blokkeren directe webverzoeken met `.htaccess`.

Registreer een API-app via het myUplink-ontwikkelaarsportaal, vraag uitsluitend
de `READSYSTEM`-machtiging aan en stel de client ID en het client secret in
zoals hierboven beschreven. De collector vraagt voor iedere run zelf een
tijdelijk OAuth access token aan; dit token wordt alleen in het geheugen
gebruikt en niet opgeslagen. Configureer geen NIBE-accountwachtwoord.
Systeem-, apparaat- en meetpunt-ID's worden ontdekt via de API en zijn niet
hardgecodeerd.

## Gegevens verzamelen

De collector is een apart, alleen-lezen endpoint. Roep
`https://<website>/collect.php` periodiek aan met een
`Authorization`-header met het collector-token als Bearer-token. Voeg het token
niet toe aan de URL. Een geslaagde aanroep geeft een JSON-status met het aantal
gevonden apparaten, meetpunten en opgeslagen waarnemingen. Een mislukte
aanroep geeft een foutstatus; technische details en tokens worden niet in de
HTTP-respons getoond. Gelijktijdige runs worden geweigerd. Bij HTTP 429 volgt
maximaal één korte retry als `Retry-After` binnen vijf seconden valt; anders
wordt de run als mislukt geregistreerd zodat de volgende geplande aanroep
later opnieuw kan proberen.

Voor een handmatige aanroep in een browser verschijnt bij de eerste aanvraag
een aanmeldvenster. Gebruik `collector` als gebruikersnaam en het
`NIBE_COLLECTOR_TOKEN` als wachtwoord. Gebruik deze methode alleen via HTTPS;
deel het token niet in een URL. Apache kan de `Authorization`-header vóór PHP
weghalen. Voeg in dat geval aan de bestaande root-`.htaccess` de regel
`CGIPassAuth On` toe, of gebruik als de provider dit niet ondersteunt
`SetEnvIfNoCase Authorization "^(.+)$" HTTP_AUTHORIZATION=$1`. Behoud eventuele
andere instellingen in dat bestand en configureer geen aparte Apache
Basic-authenticatie voor `collect.php`; de applicatie controleert zelf de
aanmeldgegevens tegen `NIBE_COLLECTOR_TOKEN`. Stel de pollfrequentie af op de
API-quota en verversingsfrequentie. Het endpoint schrijft uitsluitend gegevens
naar de eigen database en wijzigt geen warmtepompinstellingen.

## Controleren

Na het importeren van het schema en het instellen van de databasevariabelen
opent de startpagina. Zonder opgeslagen meetpunten toont de pagina dat er nog
geen gegevens zijn. Na een geslaagde collector-aanroep toont zij de ontdekte
meetpunten en laatste waarden. Meetpunten waarvan de bron- of verzameltijd
meer dan 24 uur oud is, worden als verouderd aangeduid; niet-numerieke waarden
blijven zichtbaar, maar worden niet in de trendgrafieken opgenomen. Bij
ontbrekende of onbereikbare databaseconfiguratie wordt een algemene melding
getoond; technische foutdetails of inloggegevens worden niet in de pagina
weergegeven. Waarden worden als aangeleverd getoond en alle tijden zijn UTC.

Voer de gerichte tests uit met `php tests/collector_test.php` en
`php tests/dashboard_test.php`.
