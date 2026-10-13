# mybusmap

A live bus map for the UK. It shows where buses are now, lists the next
departures at any stop and plans simple local bus journeys.

- **Live positions** come from the DfT [Bus Open Data Service](https://data.bus-data.dft.gov.uk/) (BODS) SIRI-VM feed.
- **Timetables, stops and routes** come from the BODS regional GTFS files, imported into a local SQLite database.
- **Stop details** (street, locality, landmark and indicators such as "opp") come from the DfT [NaPTAN](https://naptan.api.dft.gov.uk/) API. No key is needed.
- **Maps** are © OpenStreetMap contributors, shown with [Leaflet](https://leafletjs.com/). No map API key is needed.

The browser only ever talks to `public/api.php`. The BODS API key lives in
`config.php`, outside the web root, and is never sent to the client.

## Licences and credits

- Bus timetables, live positions and stop data: Bus Open Data Service and
  NaPTAN, © Crown copyright, used under the
  [Open Government Licence v3.0](https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/).
- Map data © [OpenStreetMap](https://www.openstreetmap.org/copyright)
  contributors (ODbL). Tiles come from openstreetmap.org, whose
  [tile usage policy](https://operations.osmfoundation.org/policies/tiles/)
  allows light use like a small private site. A busy public site would need
  its own tile provider.

Both credits are shown in the map's corner.

## Layout

```
config.php.example   copy to config.php (git-ignored) and add your BODS key
bin/import-gtfs.php  downloads the GTFS timetables and builds data/gtfs.sqlite
bin/import-naptan.php  refreshes the NaPTAN stop details in the existing database
lib/                 server-side PHP (not web-accessible)
data/                SQLite database and live-data cache (git-ignored)
public/              web root: index.html, app.js, app.css, api.php
```

## Requirements

- PHP 8.2 or later with `pdo_sqlite`, `curl`, `zip`, `mbstring` and `simplexml`
- About 1 GB of free disk per region while importing (around 200 MB once built)
- A free BODS API key: <https://data.bus-data.dft.gov.uk/account/>

## Setup

```sh
cp config.php.example config.php
# edit config.php: set bods_api_key, gtfs_regions, access_names,
# access_secret and the defaults

# config.php holds the key: readable by you and the web server only
chgrp www-data config.php && chmod 640 config.php

# the web server needs to write the live-data cache
chgrp -R www-data data && chmod -R g+rwX data && chmod g+s data

# import timetables (takes a few minutes per region)
php bin/import-gtfs.php
```

## nginx

Point the server root at `public/`. Nothing outside that folder can be served.

```nginx
# In the http block (e.g. /etc/nginx/conf.d/mybusmap-limits.conf):
# each visitor IP gets 2 API requests a second on average.
limit_req_zone $binary_remote_addr zone=mybusmap_api:10m rate=2r/s;

server {
    listen 443 ssl;
    server_name bus.example.com;
    root /var/www/smgithub/mybusmap/public;
    index index.html;

    # Only api.php should run as PHP.
    location = /api.php {
        # A burst covers opening the page, which makes several requests at
        # once. Beyond that, requests get 429 instead of reaching PHP or BODS.
        limit_req zone=mybusmap_api burst=20 nodelay;
        limit_req_status 429;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ \.php$ { return 404; }
    location ~ /\.    { deny all; }

    location ~* \.(js|css)$ { expires 1h; }

    # Optional, but recommended
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' https://unpkg.com; style-src 'self' https://unpkg.com; img-src 'self' data: https://*.tile.openstreetmap.org https://tile.openstreetmap.org https://unpkg.com; connect-src 'self'; frame-ancestors 'none'" always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
}
```

OpenStreetMap's tile servers need a valid `Referer` header from browsers.
`strict-origin-when-cross-origin` sends one.

## Keeping timetables up to date

Operators change their timetables often. Re-import weekly, for example with
this crontab entry (it runs as a user that can write to `data/`):

```cron
# Mondays 03:30
30 3 * * 1  php /var/www/smgithub/mybusmap/bin/import-gtfs.php > /var/www/smgithub/mybusmap/data/import.log 2>&1
```

The new database is built alongside the old one and swapped in when it is
complete, so the site keeps working while the import runs. Options:

```
--regions=west_midlands,east_midlands   override gtfs_regions from config.php
--zip-dir=DIR                           use {region}.zip files you have already downloaded
--keep-zips                             keep the downloads in data/zips/
```

New operators, renumbered services and franchised or nationalised networks
are picked up automatically on the next import, because nothing about
specific services is hard-coded. The defaults in `config.php` only set what
first-time visitors see.

The importer also adds NaPTAN stop details, which takes about a minute.
If NaPTAN can't be reached, the import still finishes, and stop search then
matches stop names only. To add the details later without re-importing the
timetables, run `php bin/import-naptan.php`.

## Notes

- **Stop search** matches a stop's name, street, landmark and locality, so
  "balmoral rd" finds "Hamilton Drive" on Balmoral Road. Common
  abbreviations such as Rd, St, Ave and Stn match the full words. Matches on
  a stop's own name are listed first.

- **Shared service numbers:** some operators use the same number in
  different towns (National Express West Midlands runs a 16 in Birmingham
  and another between Stourbridge and Wolverhampton). These are listed
  separately by route name. A tracked service only shows its own buses,
  matched through their timetabled trip. A bus with no trip match can't be
  told apart, so it is still shown.

- **Caching:** live positions are cached for `live_cache_ttl` seconds, 10 by
  default, in `data/cache/`. However many people have the page open, BODS
  receives at most one request per query in that time.
- **"All buses in view"** only fetches by bounding box when the visible map
  area is at most `max_live_bbox`. Otherwise the user is asked to zoom in.
- **Delays and "next stop"** come from matching each vehicle to a timetabled
  trip. Where an operator publishes no trip reference, the bus still appears
  but without delay information.
- **Route shapes:** each direction is drawn separately, with arrows showing
  which way the bus goes, because the two directions often use different
  roads and stops. If an operator's GTFS has no shapes, the importer draws
  the route as straight lines between its stops.
- **Where a bus goes:** tapping a departure at a stop draws that bus's own
  journey from the stop onwards, so short workings stop where the bus does.
  The panel lists the stops still to come with their expected times (touch
  screens can't hover), and tapping one finds it on the map. Earlier and
  Later step through the same service's other buses from that stop, up to
  three days ahead.
- **The planner** covers walking plus up to one change, within 60 km, using
  timetabled times. Each bus leg has a Track button that adds that service
  to your tracked list, so its buses appear on the map. Once the bus you'd
  catch has started its trip, it is shown with an amber ring even if the
  service isn't tracked. The leg then shows its delay and next stop, and
  "Your bus is live" zooms to it.
- **Access by name:** if `access_names` in `config.php` lists any names,
  visitors are asked for their name once (in any case). The server then sets
  a cookie, signed with `access_secret`, that lasts a year. Every API request
  is refused without it. Removing a name signs that person out, and changing
  `access_secret` signs everyone out. The names keep casual visitors and
  crawlers out. They are not passwords. The page and its scripts are still
  public, but they hold no data or keys. `robots.txt` and a `noindex` tag also
  ask search engines to stay away.
- **User settings** (tracked services, favourite stops, last map view) are
  stored in the browser's localStorage. There are no accounts and no
  server-side user data.
