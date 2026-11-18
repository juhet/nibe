# NIBE-dashboard 0.1

Een eerste, alleen-lezen basis voor het dashboard uit [DESIGN.txt](DESIGN.txt).
De pagina toont meetpunten en de laatst opgeslagen waarden uit MySQL. Er is
nog geen myUplink-koppeling of gegevensverzamelaar; die worden pas toegevoegd
nadat is gecontroleerd of de daadwerkelijke controller, firmware en het
myUplink-account toegang bieden, zoals beschreven in het ontwerp. De pagina
vult ontbrekende metingen niet aan met verzonnen waarden.

## Benodigd

- PHP 8 met PDO en de PDO MySQL-driver
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

Als de provider geen omgevingsvariabelen aanbiedt, vraag de provider om een
geheime configuratie buiten de webroot te laden. Maak geen `.env`-bestand in
deze repository of in de publiek bereikbare `httpdocs`-map. De deployworkflow
plaatst de repositorybestanden in `httpdocs/`; de mappen `app/` en `database/`
blokkeren directe webverzoeken met `.htaccess`.

Voor de latere myUplink-koppeling horen de OAuth-clientgegevens eveneens in de
omgeving van de hostingprovider: `NIBE_MYUPLINK_CLIENT_ID`,
`NIBE_MYUPLINK_CLIENT_SECRET` en `NIBE_MYUPLINK_REDIRECT_URI`. In versie 0.1
worden deze variabelen nog niet gebruikt en is er geen OAuth-login of
gegevensverzamelaar. Configureer nog geen NIBE-accountwachtwoord: de beoogde
koppeling gebruikt de door myUplink ondersteunde OAuth-toestemming met alleen
leesrechten. Het systeem en de meetpunten moeten via die koppeling worden
ontdekt; hardcode geen installatie- of meetpunt-ID's.

## Controleren

Na het importeren van het schema en het instellen van de databasevariabelen
opent de startpagina. Zonder opgeslagen meetpunten toont de pagina dat er nog
geen gegevens zijn. Bij ontbrekende of onbereikbare databaseconfiguratie wordt
een algemene melding getoond; technische foutdetails of inloggegevens worden
niet in de pagina weergegeven. Waarden worden als aangeleverd getoond en alle
tijden zijn UTC.
