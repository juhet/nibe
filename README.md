# NIBE-dashboard

Een alleen-lezen dashboard uit [DESIGN.txt](DESIGN.txt). De pagina toont
verbindingsstatus, instelbare belangrijke meetpunten en een uitklapbaar
overzicht van alle ontdekte meetpunten met hun laatst opgeslagen waarden.
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
niet toe aan de URL. Zowel GET als HEAD start een verzameling; bij HEAD is
alleen de HTTP-status beschikbaar, zonder responsebody. Een geslaagde GET geeft
een JSON-status met het aantal
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
meer dan 24 uur oud is, worden als verouderd aangeduid. Een oude brontijd
kan ook een ongewijzigde waarde betekenen; het is geen bewijs dat de verbinding
uitgevallen is. Bij
ontbrekende of onbereikbare databaseconfiguratie wordt een algemene melding
getoond; technische foutdetails of inloggegevens worden niet in de pagina
weergegeven. Waarden worden als aangeleverd getoond en alle tijden zijn UTC.

Onder **Belangrijkste metingen** worden de acht bekende meetpunten voor het
opgegeven myUplink-apparaat automatisch aan hun rollen gekoppeld op basis van
apparaat-ID en bronpunt-ID. Als een meetpunt ontbreekt, blijft de rol niet
ingesteld; er worden geen waarden verzonnen. Rollen kunnen desgewenst worden
aangepast, waarbij keuzes alleen in de lokale browser worden bewaard. De
standaardgrafieken tonen de beschikbare gekoppelde meetpunten direct na het
laden en verdelen ze over afzonderlijke grafieken per eenheid.

Onder **Metingen vergelijken** kunnen maximaal acht meetpunten samen worden
geselecteerd (Ctrl of ⌘ voor meerdere), ook buiten de ingestelde rollen.
Kies 24 uur, 7 dagen, 30 dagen of een eigen periode van maximaal 30 dagen
in UTC en klik op **Toon grafiek**. De grafiek gebruikt de brontijd, of
de verzameltijd als de brontijd ontbreekt. Elke eenheid heeft een eigen
grafiek met dezelfde tijdas. Wijs een punt aan of selecteer het met Tab voor
de exacte waarde en tijd. Standaard worden alleen °C en Hz met lijnen
verbonden; per reeks is de lijn aan of uit te zetten. Gebruik geen lijnen
voor tellers of statuscodes. Lijnen zijn geen extra metingen en gaten van
meer dan 24 uur blijven open. Andere numerieke waarden worden standaard
als losse punten getoond; tekstwaarden blijven alleen als laatste waarde
zichtbaar. Er worden maximaal 3000 recentste waarnemingen per meetpunt
getoond; bij afkapping verschijnt een waarschuwing. Historie is alleen
beschikbaar vanaf de eigen verzameling en wordt niet bij myUplink aangevuld.

Voer de gerichte tests uit met `php tests/collector_test.php`,
`php tests/collector_http_test.php` en `php tests/dashboard_test.php`.
