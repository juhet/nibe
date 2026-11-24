# NIBE-dashboard

Een alleen-lezen dashboard uit [DESIGN.txt](DESIGN.txt). `index.php` is het
dagelijkse, visuele overzicht met systeemstatus, actuele waarden, energie en
onderbouwde inzichten. Via **Analyse** en **Technisch** zijn de bestaande
prestatie-inzichten, grafieken en meetpunten beschikbaar in `index_old.php`.
Handmatige instellingenwijzigingen kunnen lokaal in een apart logboek worden bijgehouden
via de technische pagina.
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
bijvoorbeeld via phpMyAdmin. Het schema maakt zes tabellen aan: `nibe_connections`,
`nibe_devices`, `nibe_measurement_points`, `nibe_observations` en
`nibe_collection_runs` en `nibe_setting_changes`. Iedere tabel gebruikt zo
dezelfde herkenbare `nibe_` prefix. Importeer het schema één keer in een lege
database. Bij een bestaande installatie voer je ook
[`database/settings_log.sql`](database/settings_log.sql) uit om alleen de
nieuwe logboektabel aan te maken.

Het instellingenlogboek is handmatig: gebruik **+ Instelling vastleggen** om
een wijziging toe te voegen, of bewerk/verwijder een bestaande regel. Datums
worden ingevoerd en opgeslagen in UTC. Deze gegevens worden alleen lokaal in
MySQL bewaard; de dashboardpagina schrijft nooit naar de warmtepomp.

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
| `NIBE_DASHBOARD_ROLE_MAP` | Optionele JSON-koppeling van extra prestatie-rollen aan lokale meetpunt-ID's |
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
meetpunten en laatste waarden. Meetpunten waarvan de bron- of verzameltijd meer
dan 24 uur oud is, worden als verouderd aangeduid; ontbrekende waarden worden
als leeg weergegeven. Een oude brontijd kan ook een ongewijzigde waarde
betekenen; het is geen bewijs dat de verbinding uitgevallen is. Bij
ontbrekende of onbereikbare databaseconfiguratie wordt een algemene melding
getoond; technische foutdetails of inloggegevens worden niet in de pagina
weergegeven. Waarden worden als aangeleverd getoond. Tijden worden in de
tijdzone van de browser weergegeven; databaseopslag en de interne API gebruiken
UTC.

Onder **Belangrijkste metingen** worden de bekende meetpunten voor het
opgegeven myUplink-apparaat automatisch gekoppeld op basis van apparaat-ID en
bronpunt-ID. Ontbrekende metingen blijven leeg; er worden geen waarden
verzonnen. De KPI's tonen compressorstarts afgeleid van waargenomen overgangen
van nul naar een positieve compressorfrequentie, het gemiddelde verschil tussen
aanvoer en retour voor metingen die maximaal vijf minuten uit elkaar liggen en
de gemiddelde, langste en kortste duur van afgeronde compressorritten. Delta T
nu en gemiddeld over 24 uur worden alleen getoond als de temperaturen dezelfde
eenheid hebben en bij elkaar passende metingen beschikbaar zijn; het
gemiddelde wordt voor 24 uur en 7 dagen getoond. Voor ΔT nu
moeten de metingen maximaal vijf minuten uiteen liggen en mag de recentste
maximaal 15 minuten oud zijn. De aanduiding “Live” betekent daarentegen alleen
dat een meting minder dan 24 uur oud is. Een nieuwe verzameling maakt een oude
brontijd niet opnieuw actueel.

Bij KPI's die niet beschikbaar zijn toont het dashboard tijdelijk een debugblok
met geslaagde en mislukte controles, waaronder de gebruikte tijden en grenzen.
Bij energie worden zowel de controles voor vermogen als voor de eventuele
tellermeting getoond.

Voor het bekende myUplink-apparaat worden elektrisch vermogen (bron-ID 32486
of 13) en de ontdooistatus (bron-ID 44703) automatisch gekoppeld wanneer ze
beschikbaar zijn. Elektrisch bijverwarmingsvermogen wordt gekoppeld aan
bron-ID 43084 of aan een uniek meetpunt met de naam `Additional heat power` en
eenheid W of kW. Warmtevermogen wordt gekoppeld aan een uniek meetpunt met
de naam `Heat capacity (EB101)` en een eenheid W of kW. Het eerder genoemde
meetpunt-ID 598 is een lokaal ID, geen bron-ID. Bij meerdere passende
meetpunten is een expliciete rolconfiguratie nodig. De prestatie-inzichten
tonen de actieve koppelingen; deze kunnen worden aangepast via de
rolconfiguratie hieronder.

**Prestatie-inzichten** tonen COP alleen als gekoppelde warmte- en
elektrische vermogens beschikbaar met eenheden W of kW. COP nu vereist dat
beide metingen maximaal 15 minuten oud zijn en dat het elektrisch vermogen
groter is dan nul. De historische COP is de verhouding van de afzonderlijk
geïntegreerde warmte- en elektrische energie. Energie uit vermogen gebruikt
vijf minuten per opgeslagen meting, zonder ontbrekende metingen aan te vullen.
Als vermogens niet bruikbaar zijn, kunnen expliciet gekoppelde cumulatieve
energietellers in kWh of Wh worden gebruikt; tellers vereisen een meting binnen
zes uur van de periodegrens en een recente eindmeting. Uurwaarden en jaarwaarden
die periodiek worden gereset zijn geen cumulatieve tellers en mogen niet als
zodanig worden gekoppeld. Periodegrenzen voor vandaag zijn UTC.

Compressorstarts worden voor 24 uur en 7 dagen getoond. De frequentieverdeling
over de afgelopen 24 uur toont 0, 1–20, 20–30, 30–40, 40–50, 50–60 en 60 Hz of
hoger, inclusief percentage en waargenomen tijd per band. De defrostperiode is
instelbaar op 24 uur, 7 of 30 dagen; naast aantal, totale en gemiddelde duur
wordt het gemiddelde interval alleen bij minstens twee starts getoond.
Compressor- en defrostcijfers vereisen voldoende recente metingen en een
meting vóór de gekozen periode; anders blijven ze niet beschikbaar.
Frequentiebanden en defrosts worden berekend uit de waargenomen frequentie en
expliciet gekoppelde numerieke defrostwaarde (0 = uit, groter dan 0 = actief);
intervallen met gaten van meer dan een uur worden niet als bedrijfstijd gerekend.

Extra rollen kunnen optioneel worden gekoppeld via `NIBE_DASHBOARD_ROLE_MAP`,
een JSON-object waarvan de waarden de lokale **Meetpunt-ID's** zijn die in het
uitgeklapte meetpuntenoverzicht staan. Ondersteunde rollen zijn
`electric_power`, `heat_power`, `auxiliary_heat_power`, `electric_energy`,
`heat_energy` en `defrost`. Bijvoorbeeld:
`{"electric_power":"12","heat_power":"13","auxiliary_heat_power":"17","electric_energy":"14","heat_energy":"15","defrost":"16"}`.
Gebruik alleen meetpunten waarvan betekenis en eenheid bekend zijn; zonder
geldige invoer blijft de betreffende prestatie als niet beschikbaar getoond.
De bijverwarming toont het actuele vermogen, het aantal waargenomen starts
(overgang van nul naar positief vermogen) en het geschatte elektriciteitsverbruik
voor 24 uur, 7 dagen en 30 dagen. De energie gebruikt vijf minuten per
opgeslagen vermogensmeting; ontbrekende intervallen worden niet aangevuld.
De grafiek bevat ook graadminuten, compressorfrequentie en buitentemperatuur,
wanneer die metingen beschikbaar zijn.

Onder **Metingen vergelijken** worden de beschikbare standaardtemperaturen
getoond: tapwater, berekende toevoer, aanvoer, retour, temperatuur in huis en
buitentemperatuur. Kies 4 uur, 24 uur, 7 dagen, 30 dagen of een eigen periode
van maximaal 30 dagen in de lokale tijdzone; standaard wordt 24 uur getoond.
De grafiekknop bij een belangrijke meting toont of verbergt de ontwikkeling van
die meting over de afgelopen 24 uur.
Klik op **Toon grafiek** om de gekozen periode te laden; **Inzoomen** en
**Uitzoomen** halveren of verdubbelen de getoonde periode. De grafiek gebruikt
de brontijd, of de verzameltijd als de brontijd ontbreekt.
Elke eenheid heeft een eigen
grafiek met dezelfde tijdas en logische verdelingen op de y-as. Reeksen zijn
standaard met een lijn verbonden; de legenda schakelt zowel de lijn als de
meetpunten in of uit. Wijs de grafiek aan of selecteer een punt met Tab om
alle reeksen op dat tijdstip te vergelijken. Als een reeks daar geen exacte
meting heeft, wordt een lineaire schatting tussen waarnemingen getoond en
duidelijk als schatting aangeduid; waarden staan onder elkaar in vaste,
logische volgorde. Buiten waarnemingen of over gaten van meer dan 24 uur wordt
geen waarde geschat. Lijnen zijn geen extra metingen.
Temperatuurmetingen worden over gaten tot 24 uur verbonden; voor andere
meetpunten geldt een maximale lijnafstand van één uur.
Er worden maximaal 3000 recentste waarnemingen per meetpunt getoond; bij
afkapping verschijnt een waarschuwing. Historie is alleen beschikbaar vanaf
de eigen verzameling en wordt niet bij myUplink aangevuld.

Voer de gerichte tests uit met `php tests/collector_test.php`,
`php tests/collector_http_test.php`, `php tests/dashboard_test.php` en
`php tests/dashboard_ui_test.php`.
