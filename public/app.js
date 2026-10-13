/* mybusmap front end. Talks only to api.php; no keys live here. */
(function () {
  'use strict';

  const REFRESH_MS = 15000;
  const STOP_ZOOM = 15;
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  /** Build a DOM element. Text is always set with textContent, never HTML. */
  function el(tag, attrs, ...children) {
    const e = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v == null || v === false) continue;
      if (k === 'class') e.className = v;
      else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
      else e.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) {
      if (c == null || c === false) continue;
      e.append(c instanceof Node ? c : document.createTextNode(String(c)));
    }
    return e;
  }

  const store = {
    get(key, fallback) {
      try {
        const v = localStorage.getItem('mbm.' + key);
        return v === null ? fallback : JSON.parse(v);
      } catch (e) {
        return fallback;
      }
    },
    set(key, value) {
      try { localStorage.setItem('mbm.' + key, JSON.stringify(value)); } catch (e) { /* private mode */ }
    },
  };

  async function api(action, params) {
    const qs = new URLSearchParams({ action, ...(params || {}) });
    let res, data;
    try {
      res = await fetch('api.php?' + qs, { headers: { Accept: 'application/json' } });
      data = await res.json();
    } catch (e) {
      throw new Error('Could not reach the server');
    }
    if (res.status === 401 && data.login) {
      await signIn();
      return api(action, params);
    }
    if (!res.ok || data.error) throw new Error(data.error || 'Request failed');
    return data;
  }

  // One name prompt, however many requests are waiting on it.
  let signingIn = null;
  function signIn() {
    if (signingIn) return signingIn;
    const box = $('#signin');
    const form = $('form', box);
    const input = $('input', box);
    const err = $('.error', box);
    box.hidden = false;
    input.focus();
    signingIn = new Promise((resolve) => {
      form.onsubmit = async (e) => {
        e.preventDefault();
        err.textContent = '';
        const button = $('button', form);
        button.disabled = true;
        try {
          const res = await fetch('api.php?action=login', {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: new URLSearchParams({ name: input.value }),
          });
          const data = await res.json();
          if (!res.ok || data.error) throw new Error(data.error || 'Request failed');
          box.hidden = true;
          signingIn = null;
          resolve();
        } catch (e2) {
          err.textContent = e2 instanceof SyntaxError ? 'Could not reach the server' : e2.message;
          input.select();
        } finally {
          button.disabled = false;
        }
      };
    });
    return signingIn;
  }

  const hhmm = (ts) => new Date(ts * 1000).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  const mins = (secs) => Math.max(1, Math.round(secs / 60));
  const key = (s) => s.noc + ':' + s.line;
  // Operators reuse numbers in different towns, so the route's long name tells
  // services apart. Entries without one (older saved lists) match any.
  const sameService = (a, b) => key(a) === key(b) && (!a.name || !b.name || a.name === b.name);

  function delayText(d) {
    if (d == null) return null;
    if (Math.abs(d) < 60) return 'on time';
    return d > 0 ? mins(d) + ' min late' : mins(-d) + ' min early';
  }

  function lineColour(k) {
    // FNV-1a hash spread by the golden angle, so similar line numbers get distinct colours.
    let h = 0x811c9dc5;
    for (const ch of k) h = Math.imul(h ^ ch.charCodeAt(0), 0x01000193) >>> 0;
    return 'hsl(' + Math.round((h % 1000) * 137.508) % 360 + ',65%,38%)';
  }

  function badge(line, mode, k) {
    const b = el('span', { class: 'badge' + (mode && mode !== 'bus' ? ' ' + mode : '') }, line);
    if (k) b.style.background = lineColour(k);
    return b;
  }

  let statusTimer;
  function status(msg, ms) {
    const s = $('#status');
    clearTimeout(statusTimer);
    if (!msg) { s.hidden = true; return; }
    s.textContent = msg;
    s.hidden = false;
    if (ms !== 0) statusTimer = setTimeout(() => { s.hidden = true; }, ms || 4000);
  }

  function debounce(fn, ms) {
    let t;
    return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  }

  /** Decode a Google encoded polyline into [[lat, lon], ...]. */
  function decodePolyline(str) {
    const pts = [];
    let i = 0, lat = 0, lon = 0;
    while (i < str.length) {
      for (const which of [0, 1]) {
        let shift = 0, result = 0, b;
        do {
          b = str.charCodeAt(i++) - 63;
          result |= (b & 0x1f) << shift;
          shift += 5;
        } while (b >= 0x20);
        const d = result & 1 ? ~(result >> 1) : result >> 1;
        if (which === 0) lat += d; else lon += d;
      }
      pts.push([lat / 1e5, lon / 1e5]);
    }
    return pts;
  }

  // ---------------------------------------------------------------- state

  let config;
  let tracked = [];                       // [{noc, line}]
  let favourites = store.get('favourites', []);   // [{id, name}]
  let showAll = false;
  let currentStop = null;
  let picking = null;                     // 'from' | 'to' while choosing on the map
  const places = { from: null, to: null };

  // ---------------------------------------------------------------- map

  const map = L.map('map', { zoomControl: false });
  // Keep Leaflet's idea of the map size right as the panel opens and closes.
  if (window.ResizeObserver) new ResizeObserver(() => map.invalidateSize()).observe(document.getElementById('map'));
  L.control.zoom({ position: 'topright' }).addTo(map);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      + ' | Bus data: <a href="https://www.bus-data.dft.gov.uk/">BODS</a>, <a href="https://naptan.api.dft.gov.uk/">NaPTAN</a>'
      + ' &copy; Crown copyright, <a href="https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/">OGL v3.0</a>',
  }).addTo(map);

  const routeLayer = L.layerGroup().addTo(map);
  // Stops on a shown route sit above the ordinary stop markers (shown from
  // STOP_ZOOM), so their times stay visible and tappable when zoomed in.
  map.createPane('routeStops').style.zIndex = 450;
  const stopLayer = L.layerGroup().addTo(map);
  const planLayer = L.layerGroup().addTo(map);
  // Bus legs of the selected journey, by trip id, so the bus you'd catch can be highlighted.
  let planTrips = new Map();
  const isPlanBus = (v) => v.trip != null && planTrips.has(String(v.trip));
  const vehicleLayer = L.layerGroup().addTo(map);
  let meMarker = null;
  const vehicleMarkers = new Map();       // id => {marker, v}

  function bboxParam(b) {
    return [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].map((n) => n.toFixed(5)).join(',');
  }
  const area = (b) => (b.getEast() - b.getWest()) * (b.getNorth() - b.getSouth());

  // ---------------------------------------------------------------- vehicles

  function busIcon(v) {
    const k = v.noc + ':' + v.line;
    const colour = lineColour(k);
    const svg = 'http://www.w3.org/2000/svg';
    const root = document.createElementNS(svg, 'svg');
    root.setAttribute('viewBox', '0 0 34 34');
    root.setAttribute('class', 'arrow');
    if (v.bearing != null) {
      const p = document.createElementNS(svg, 'path');
      p.setAttribute('d', 'M17 0 L24 9 L10 9 Z');
      p.setAttribute('fill', colour);
      p.setAttribute('stroke', '#fff');
      p.setAttribute('stroke-width', '1.5');
      p.setAttribute('transform', 'rotate(' + Number(v.bearing) + ' 17 17)');
      root.append(p);
    }
    if (isPlanBus(v)) {
      const ring = document.createElementNS(svg, 'circle');
      ring.setAttribute('cx', '17');
      ring.setAttribute('cy', '17');
      ring.setAttribute('r', '15.5');
      ring.setAttribute('class', 'plan-ring');
      root.append(ring);
    }
    const c = document.createElementNS(svg, 'circle');
    c.setAttribute('cx', '17');
    c.setAttribute('cy', '17');
    c.setAttribute('r', '11');
    c.setAttribute('fill', colour);
    c.setAttribute('stroke', '#fff');
    c.setAttribute('stroke-width', '2');
    root.append(c);
    const t = document.createElementNS(svg, 'text');
    t.setAttribute('x', '17');
    t.setAttribute('y', '17');
    t.setAttribute('class', 'label');
    t.setAttribute('font-size', v.line.length > 3 ? '8' : '11');
    t.textContent = v.line;
    root.append(t);
    return L.divIcon({ html: root, className: 'bus-icon', iconSize: [34, 34], iconAnchor: [17, 17], popupAnchor: [0, -14] });
  }

  function vehiclePopup(v) {
    const now = Date.now() / 1000;
    const delay = delayText(v.delay);
    const age = Math.max(0, Math.round(now - v.recorded));
    return el('div', { class: 'vehicle-popup' },
      isPlanBus(v) ? el('div', { class: 'yours' }, 'Your bus') : null,
      el('b', null, v.line), ' to ', v.dest || '?',
      v.origin ? el('div', null, 'From ' + v.origin) : null,
      delay ? el('div', { class: v.delay >= 120 ? 'late' : 'ok' }, delay) : el('div', null, 'No timetable match'),
      v.next ? el('div', null, 'Next: ' + v.next.name + (v.next.time ? ' (' + hhmm(v.next.time) + ')' : '')) : null,
      el('div', { style: 'color:#777;font-size:12px' }, 'Position ' + (age < 90 ? age + ' s' : mins(age) + ' min') + ' old'),
      config.timetable ? el('button', { type: 'button', onclick: () => showRoute(v.noc, v.line, v.route) }, 'Show route') : null);
  }

  function updateVehicles(list) {
    const seen = new Set();
    for (const v of list) {
      seen.add(v.id);
      const existing = vehicleMarkers.get(v.id);
      if (existing) {
        existing.marker.setLatLng([v.lat, v.lon]);
        if (existing.v.bearing !== v.bearing || existing.v.line !== v.line || existing.v.trip !== v.trip) existing.marker.setIcon(busIcon(v));
        existing.marker.setZIndexOffset(isPlanBus(v) ? 2000 : 1000);
        existing.v = v;
        if (existing.marker.isPopupOpen()) existing.marker.setPopupContent(vehiclePopup(v));
      } else {
        const marker = L.marker([v.lat, v.lon], { icon: busIcon(v), zIndexOffset: isPlanBus(v) ? 2000 : 1000 })
          .bindPopup(() => vehiclePopup(vehicleMarkers.get(v.id).v));
        marker.addTo(vehicleLayer);
        vehicleMarkers.set(v.id, { marker, v });
      }
    }
    for (const [id, m] of vehicleMarkers) {
      if (!seen.has(id)) {
        vehicleLayer.removeLayer(m.marker);
        vehicleMarkers.delete(id);
      }
    }
  }

  let refreshing = false;
  async function refreshVehicles() {
    if (!config || !config.live || refreshing) return;
    const params = {};
    const lines = new Set([...tracked.slice(0, 20), ...planTrips.values()].map(key));
    if (lines.size) params.lines = [...lines].join(',');
    const b = map.getBounds();
    const allOk = showAll && area(b) <= config.liveBbox;
    $('#all-note').hidden = !showAll || allOk;
    if (allOk) params.bbox = bboxParam(b);
    if (!params.lines && !params.bbox) { updateVehicles([]); return; }
    refreshing = true;
    try {
      const data = await api('vehicles', params);
      // BODS can only filter by number, so drop buses on a same-numbered service elsewhere.
      // A bus with no timetable match has no route name and is kept.
      // Untracked services on a planned journey only show the bus you'd catch.
      const list = data.vehicles.filter((v) => allOk || isPlanBus(v) ||
        tracked.some((t) => sameService(t, { noc: v.noc, line: v.line, name: v.route })));
      updateVehicles(list);
      updatePlanLive(list);
    } catch (e) {
      status(e.message);
    } finally {
      refreshing = false;
    }
  }

  // ---------------------------------------------------------------- services

  function saveTracked() {
    store.set('tracked', tracked);
    renderTracked();
    renderServices();
    refreshVehicles();
  }
  const isTracked = (s) => tracked.some((t) => sameService(t, s));

  function toggleTracked(s) {
    if (isTracked(s)) tracked = tracked.filter((t) => !sameService(t, s));
    else if (tracked.length >= 20) { status('You can track up to 20 services'); return; }
    else tracked.push({ noc: s.noc, line: s.line, operator: s.operator, name: s.name, mode: s.mode });
    saveTracked();
  }

  function serviceItem(s, actionLabel, action) {
    return el('li', null,
      badge(s.line, s.mode, key(s)),
      el('div', { class: 'grow' },
        el('div', { class: 'sub' }, s.name || ''),
        el('div', { class: 'sub' }, s.operator || s.noc)),
      config.timetable ? el('button', { class: 'link', type: 'button', onclick: () => showRoute(s.noc, s.line, s.name) }, 'Route') : null,
      el('button', { class: 'link', type: 'button', onclick: action }, actionLabel));
  }

  function renderTracked() {
    const ul = $('#tracked');
    ul.replaceChildren(...tracked.map((s) => serviceItem(s, 'Remove', () => toggleTracked(s))));
    if (!tracked.length) ul.append(el('li', { class: 'note' }, 'None yet. Add services from the list below or from a stop.'));
  }

  let areaServices = [];
  function renderServices() {
    const ul = $('#services');
    if (!config.timetable) {
      ul.replaceChildren(el('li', { class: 'note' }, 'Timetables have not been imported yet.'));
      return;
    }
    const f = $('#service-filter').value.trim().toLowerCase();
    const list = areaServices.filter((s) => !isTracked(s) && (!f ||
      s.line.toLowerCase().startsWith(f) || (s.name || '').toLowerCase().includes(f) || (s.operator || '').toLowerCase().includes(f)));
    ul.replaceChildren(...list.slice(0, 150).map((s) => serviceItem(s, 'Track', () => toggleTracked(s))));
    if (!list.length) ul.append(el('li', { class: 'note' }, areaServices.length ? 'No matches.' : 'Zoom in to list the services here.'));
  }

  async function loadServices() {
    if (!config.timetable) return renderServices();
    const b = map.getBounds();
    if (area(b) > 1) { areaServices = []; return renderServices(); }
    try {
      areaServices = (await api('services', { bbox: bboxParam(b) })).services;
      // Fill in names for tracked services that only have NOC:line (e.g. config defaults).
      let changed = false;
      for (const t of tracked) {
        const matches = t.name ? [] : areaServices.filter((x) => key(x) === key(t));
        const s = matches.length === 1 && matches[0];
        if (s) Object.assign(t, { operator: s.operator, name: s.name, mode: s.mode }), changed = true;
      }
      if (changed) { store.set('tracked', tracked); renderTracked(); }
    } catch (e) {
      areaServices = [];
    }
    renderServices();
  }

  async function showRoute(noc, line, name) {
    try {
      const r = await api('route', name ? { noc, line, name } : { noc, line });
      routeLayer.clearLayers();
      routeStops.clearLayers();
      const colour = lineColour(noc + ':' + line);
      const bounds = L.latLngBounds([]);
      routeLines = [];
      for (const s of r.shapes) {
        const pts = decodePolyline(s.polyline);
        const pl = L.polyline(pts, { color: colour, weight: 5, opacity: .75 }).addTo(routeLayer);
        bounds.extend(pl.getBounds());
        routeLines.push({ pts: pl.getLatLngs(), colour });
      }
      routeLayer.addLayer(routeArrows).addLayer(routeStops);
      for (const s of r.stops) {
        L.circleMarker([s.lat, s.lon], { pane: 'routeStops', radius: 4, color: colour, weight: 2, fillColor: '#fff', fillOpacity: 1 })
          .bindTooltip(s.name + (s.platform ? ' (' + s.platform + ')' : ''))
          .on('click', () => openStop(s.id))
          .addTo(routeStops);
      }
      if (bounds.isValid()) map.fitBounds(bounds, { padding: [20, 20] });
      drawRouteArrows();
      status(line + ' ' + (r.name || '') + ' (tap the map to clear)', 6000);
      map.closePopup();
    } catch (e) {
      status(e.message);
    }
  }

  // Direction arrows along the shown route, spaced on screen and redrawn after
  // each move for the area in view. Both directions are often drawn, and they
  // can use different roads and stops.
  const routeArrows = L.layerGroup();
  const routeStops = L.layerGroup({ pane: 'routeStops' });
  const ARROW_GAP = 90;
  let routeLines = [];
  function drawRouteArrows() {
    routeArrows.clearLayers();
    if (!routeLayer.hasLayer(routeArrows)) return;
    const z = map.getZoom();
    const b = map.getPixelBounds();
    const m = b.getSize().multiplyBy(0.25);
    const view = L.bounds(b.min.subtract(m), b.max.add(m));
    routeLines.forEach(({ pts, colour }, n) => {
      // Stagger the directions so arrows on a shared road don't overlap.
      let next = ARROW_GAP * (n % 2 ? 0.75 : 0.25);
      let walked = 0;
      for (let i = 1; i < pts.length; i++) {
        const a = map.project(pts[i - 1], z);
        const c = map.project(pts[i], z);
        const len = a.distanceTo(c);
        if (!len) continue;
        const d = c.subtract(a).divideBy(len);
        const nrm = L.point(-d.y, d.x);
        while (next <= walked + len) {
          const p = a.add(d.multiplyBy(next - walked));
          if (view.contains(p)) {
            const tri = [p.add(d.multiplyBy(7)), p.subtract(d.multiplyBy(5)).add(nrm.multiplyBy(6)),
              p.subtract(d.multiplyBy(5)).subtract(nrm.multiplyBy(6))].map((q) => map.unproject(q, z));
            L.polygon(tri, { color: '#fff', weight: 1.5, fillColor: colour, fillOpacity: 1, interactive: false })
              .addTo(routeArrows);
          }
          next += ARROW_GAP;
        }
        walked += len;
      }
    });
  }

  // ---------------------------------------------------------------- stops

  let stopsKey = '';
  async function loadStops() {
    if (!config.timetable || map.getZoom() < STOP_ZOOM) {
      stopLayer.clearLayers();
      stopsKey = '';
      return;
    }
    const b = map.getBounds().pad(0.2);
    if (area(b) > 0.01) return;
    const k = bboxParam(b);
    if (k === stopsKey) return;
    stopsKey = k;
    try {
      const data = await api('stops', { bbox: k });
      stopLayer.clearLayers();
      for (const s of data.stops) {
        L.circleMarker([s.lat, s.lon], { radius: 6, color: '#1d4e89', weight: 2, fillColor: '#fff', fillOpacity: .9 })
          .bindTooltip(s.name + (s.platform ? ' (' + s.platform + ')' : ''))
          .on('click', () => {
            if (picking) return setPlaceFromPoint(picking, s.lat, s.lon, s.name);
            openStop(s.id);
          })
          .addTo(stopLayer);
      }
    } catch (e) {
      stopsKey = '';
    }
  }

  function renderFavourites() {
    const ul = $('#favourites');
    ul.replaceChildren(...favourites.map((f) => el('li', { class: 'clickable', onclick: () => openStop(f.id) },
      el('div', { class: 'grow' }, f.name))));
    if (!favourites.length) ul.append(el('li', { class: 'note' }, 'Tap ☆ on a stop to keep it here.'));
  }

  /**
   * The part of a route shape this trip uses, from its first stop to its last.
   * Short workings and loops share their route's shape, so it is trimmed to fit.
   * Falls back to straight lines between stops when no shape follows the trip.
   */
  function tripLine(shapes, stops) {
    const first = L.latLng(stops[0].lat, stops[0].lon);
    const last = L.latLng(stops[stops.length - 1].lat, stops[stops.length - 1].lon);
    let best = null;
    for (const enc of shapes) {
      const pts = decodePolyline(enc).map((p) => L.latLng(p));
      const nearest = (target, from) => {
        let idx = from, dist = Infinity;
        for (let i = from; i < pts.length; i++) {
          const d = target.distanceTo(pts[i]);
          if (d < dist) { dist = d; idx = i; }
        }
        return [idx, dist];
      };
      const [i0, d0] = nearest(first, 0);
      const [i1, d1] = nearest(last, i0);
      if (i1 > i0 && d0 < 150 && d1 < 150 && (!best || d0 + d1 < best.score)) {
        best = { score: d0 + d1, pts: [first, ...pts.slice(i0, i1 + 1), last] };
      }
    }
    return best ? best.pts : stops.map((st) => L.latLng(st.lat, st.lon));
  }

  // The departures last shown at the open stop, for live delays of a shown trip.
  let lastDepartures = [];
  let shownTrip = null;
  const dayLabel = (ts) => {
    const day = (t) => new Date(t * 1000).toDateString();
    const now = Date.now() / 1000;
    if (day(ts) === day(now)) return '';
    if (day(ts) === day(now + 86400)) return 'tomorrow ';
    return new Date(ts * 1000).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }) + ' ';
  };

  /**
   * Where one bus goes after the stop it was picked at. q is {id, seq, at} for
   * a departure, plus step 'next' or 'prev' for the same service's bus after
   * or before it.
   */
  async function showTrip(q, fromStop) {
    try {
      const r = await api('trip', q);
      shownTrip = r;
      routeLayer.clearLayers();
      routeStops.clearLayers();
      const colour = lineColour(r.noc + ':' + r.line);
      const pts = tripLine(r.shapes, r.stops);
      const pl = L.polyline(pts, { color: colour, weight: 5, opacity: .75 }).addTo(routeLayer);
      routeLines = [{ pts, colour }];
      routeLayer.addLayer(routeArrows).addLayer(routeStops);
      const d = lastDepartures.find((x) => x.trip === r.trip && x.scheduled === r.at) || {};
      const delay = d.delay > 0 ? d.delay : 0;
      const stopName = (st) => st.name + (st.platform ? ' (' + st.platform + ')' : '');
      const markers = r.stops.map((st, i) => {
        const here = i === 0;
        return L.circleMarker([st.lat, st.lon], {
          pane: 'routeStops', radius: here ? 7 : 4, color: colour, weight: here ? 3 : 2, fillColor: '#fff', fillOpacity: 1,
        })
          .bindTooltip(hhmm(st.time + delay) + ' ' + stopName(st))
          .on('click', () => openStop(st.id))
          .addTo(routeStops);
      });
      // Stepping keeps the view, as the line is usually the same.
      if (!q.step) map.fitBounds(pl.getBounds(), { padding: [30, 30] });
      drawRouteArrows();
      const to = r.headsign || stopName(r.stops[r.stops.length - 1]);
      if (!q.step) status(r.line + ' to ' + to + ' from ' + fromStop + '.', 6000);
      map.closePopup();

      // The same stops and times as a list, since touch screens can't hover.
      $('#trip-back span').textContent = currentStop ? currentStop.name : fromStop;
      $('.trip-head').replaceChildren(badge(r.line, null, key(r)),
        el('div', null, el('b', null, 'to ' + to),
          el('small', null, r.operator + ' · ' + (d.delay == null ? 'timetable times'
            : d.delay >= 120 ? 'live, ' + Math.round(d.delay / 60) + ' min late' : 'live, on time'))));
      const step = (dir) => showTrip({ id: r.trip, seq: r.seq, at: r.at, step: dir }, fromStop);
      $('.trip-nav').replaceChildren(
        el('button', { class: 'link', hidden: r.at <= Date.now() / 1000, onclick: () => step('prev') }, '‹ Earlier'),
        el('span', null, 'Leaves ' + dayLabel(r.at + delay) + hhmm(r.at + delay)),
        el('button', { class: 'link', onclick: () => step('next') }, 'Later ›'));
      $('#trip-stops').replaceChildren(...r.stops.map((st, i) => el('li', {
        class: i === 0 ? 'here' : null,
        onclick: (e) => {
          for (const li of $$('#trip-stops li')) li.classList.toggle('active', li === e.currentTarget);
          map.setView([st.lat, st.lon], Math.max(map.getZoom(), 16));
          markers[i].openTooltip();
        },
      }, el('span', { class: 'time' }, hhmm(st.time + delay)),
      el('span', null, stopName(st), i === r.stops.length - 1 ? el('div', { class: 'last' }, 'Last stop') : null))));
      $('#stop-empty').hidden = true;
      $('#stop-detail').hidden = true;
      $('#trip-detail').hidden = false;
      $('#tab-stop').scrollTop = 0;
    } catch (e) {
      status(e.message);
    }
  }

  function updateFavButton() {
    const fav = currentStop && favourites.some((f) => f.id === currentStop.id);
    $('#stop-fav').textContent = fav ? '★' : '☆';
    $('#stop-fav').setAttribute('aria-label', fav ? 'Remove from favourites' : 'Add to favourites');
  }

  async function openStop(id, quiet) {
    showTab('stop');
    if (!quiet) {
      expandPanel();
      $('#stop-empty').hidden = true;
      $('#trip-detail').hidden = true;
      $('#stop-detail').hidden = false;
      if (!currentStop || currentStop.id !== id) {
        $('#stop-name').textContent = 'Loading…';
        $('#stop-services').replaceChildren();
        $('#departures tbody').replaceChildren();
        $('#stop-note').textContent = '';
      }
    }
    let data;
    try {
      data = await api('stop', { id });
    } catch (e) {
      if (!quiet) $('#stop-note').textContent = e.message;
      return;
    }
    const s = data.stop;
    currentStop = { id: s.id, name: s.name + (s.platform ? ' (' + s.platform + ')' : ''), lat: s.lat, lon: s.lon };
    $('#stop-name').textContent = currentStop.name;
    updateFavButton();
    if (!quiet) map.setView([s.lat, s.lon], Math.max(map.getZoom(), 16));

    $('#stop-services').replaceChildren(...data.services.map((sv) => {
      const b = badge(sv.line, sv.mode, key(sv));
      b.title = (sv.name || '') + ' – ' + sv.operator + (isTracked(sv) ? ' (tracked)' : ' (tap to track)');
      b.addEventListener('click', () => { if (!isTracked(sv)) toggleTracked(sv); showRoute(sv.noc, sv.line, sv.name); });
      return b;
    }));

    lastDepartures = data.departures;
    const rows = data.departures.map((d) => {
      const when = d.expected || d.scheduled;
      const wait = Math.round((when - data.time) / 60);
      const due = wait <= 0 ? 'Due' : wait < 60 ? wait + ' min' : hhmm(when);
      const live = d.delay != null;
      const late = live && d.delay >= 120;
      return el('tr', {
        class: 'clickable', title: 'Show where this bus goes',
        onclick: () => showTrip({ id: d.trip, seq: d.seq, at: d.scheduled }, s.name),
      },
        el('td', null, badge(d.line, null, key(d))),
        el('td', null, d.headsign || '', el('small', null, d.operator)),
        el('td', { class: 'time' },
          el('span', { class: live ? (late ? 'late' : 'live') : null }, due),
          el('small', null, live ? (late ? 'due ' + hhmm(d.scheduled) : 'live') : 'timetable')));
    });
    $('#departures tbody').replaceChildren(...rows);
    $('#stop-note').textContent = !rows.length ? 'No buses in the next 90 minutes.'
      : data.liveError ? 'Live times unavailable: ' + data.liveError
        : 'Tap a bus to see where it goes. Times shown as "live" use bus positions; others are from the timetable.';
  }

  // ---------------------------------------------------------------- search

  function stopResult(s, onPick) {
    // e.g. "Hamilton Drive (opp) · Balmoral Road, Ashwood Park · 430 m"
    const place = [s.street, s.locality].filter((w, i, a) => w && w !== s.name && a.indexOf(w) === i).join(', ');
    const parts = [place, s.dist != null ? (s.dist < 1000 ? s.dist + ' m' : (s.dist / 1000).toFixed(1) + ' km') : ''];
    const sub = parts.filter(Boolean).join(' · ');
    return el('li', { onclick: () => onPick(s) },
      s.name + (s.platform ? ' (' + s.platform + ')' : ''),
      sub ? el('small', null, ' · ' + sub) : null);
  }

  function attachSearch(input, list, onPick) {
    const run = debounce(async () => {
      const q = input.value.trim();
      if (q.length < 2 || !config.timetable) { list.hidden = true; return; }
      const c = map.getCenter();
      try {
        const data = await api('search', { q, near: c.lat.toFixed(5) + ',' + c.lng.toFixed(5) });
        list.replaceChildren(...data.stops.slice(0, 15).map((s) => stopResult(s, (picked) => {
          list.hidden = true;
          onPick(picked);
        })));
        if (!data.stops.length) list.append(el('li', { class: 'note' }, 'No stops found'));
        list.hidden = false;
      } catch (e) {
        list.hidden = true;
      }
    }, 300);
    input.addEventListener('input', run);
    input.addEventListener('keydown', (e) => { if (e.key === 'Escape') list.hidden = true; });
    document.addEventListener('click', (e) => { if (!list.contains(e.target) && e.target !== input) list.hidden = true; });
  }

  // ---------------------------------------------------------------- location & regions

  function locate() {
    return new Promise((resolve, reject) => {
      if (!navigator.geolocation) return reject(new Error('Location is not available on this device'));
      status('Finding you…', 0);
      navigator.geolocation.getCurrentPosition((p) => {
        status();
        const ll = [p.coords.latitude, p.coords.longitude];
        if (meMarker) meMarker.setLatLng(ll);
        else meMarker = L.circleMarker(ll, { radius: 8, color: '#fff', weight: 3, fillColor: '#1a73e8', fillOpacity: 1 }).addTo(map);
        resolve(ll);
      }, (err) => {
        status();
        reject(new Error(err.code === 1 ? 'Location permission was refused' : 'Could not find your location'));
      }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    });
  }

  function regionFor(ll) {
    return config.regions.find((r) => L.latLngBounds(r.bounds).contains(ll));
  }

  function setupRegions() {
    const sel = $('#region');
    if (config.regions.length < 2) return;
    sel.replaceChildren(el('option', { value: '' }, 'Choose…'),
      ...config.regions.map((r) => el('option', { value: r.code }, r.name)));
    sel.value = store.get('region', '');
    $('#region-row').hidden = false;
    sel.addEventListener('change', () => {
      const r = config.regions.find((x) => x.code === sel.value);
      store.set('region', sel.value);
      if (r) map.fitBounds(r.bounds);
    });
  }

  // ---------------------------------------------------------------- panel

  function showTab(name) {
    for (const b of $$('#tabs [data-tab]')) b.classList.toggle('active', b.dataset.tab === name);
    for (const t of $$('.tab')) t.hidden = t.id !== 'tab-' + name;
    store.set('tab', name);
  }
  function expandPanel() {
    if ($('#panel').classList.contains('collapsed')) {
      $('#panel').classList.remove('collapsed');
    }
  }

  // ---------------------------------------------------------------- planner

  function setPlace(end, place) {
    places[end] = place;
    const input = $('.place[data-end="' + end + '"] input');
    input.value = place ? place.label : '';
    input.classList.toggle('set', !!place);
  }

  function setPlaceFromPoint(end, lat, lon, label) {
    setPlace(end, { lat, lon, label: label || lat.toFixed(4) + ', ' + lon.toFixed(4) });
    stopPicking();
    showTab('plan');
    expandPanel();
  }

  function startPicking(end) {
    picking = end;
    $('#pick-hint').hidden = false;
    $('#panel').classList.add('collapsed');
  }
  function stopPicking() {
    picking = null;
    $('#pick-hint').hidden = true;
  }

  function describeLeg(leg) {
    if (leg.type === 'walk') return leg.secs >= 60 ? el('li', null, 'Walk ' + mins(leg.secs) + ' min') : null;
    return el('li', null,
      badge(leg.line, null, key(leg)),
      el('div', { class: 'grow' },
        hhmm(leg.dep) + ' from ' + leg.from.name + (leg.from.platform ? ' (' + leg.from.platform + ')' : ''),
        ' towards ' + (leg.headsign || '?') + ', ' + leg.stops + ' stop' + (leg.stops === 1 ? '' : 's') +
        ' to ' + leg.to.name + ' (' + hhmm(leg.arr) + ')',
        el('div', { class: 'leg-live', 'data-trip': String(leg.trip) })),
      trackLegButton(leg));
  }

  // Says where the bus for each leg of the selected journey is, once it has started that trip.
  function updatePlanLive(vehicles) {
    for (const note of $$('#plan-results .leg-live')) {
      const v = vehicles.find((x) => String(x.trip) === note.dataset.trip);
      note.replaceChildren(...(v ? [
        el('button', { class: 'link', type: 'button', onclick: (e) => {
          e.stopPropagation();
          map.setView([v.lat, v.lon], Math.max(map.getZoom(), 15));
          vehicleMarkers.get(v.id)?.marker.openPopup();
        } }, 'Your bus is live'),
        [delayText(v.delay), v.next ? 'next stop ' + v.next.name : null].filter(Boolean).join(', '),
      ] : []));
    }
  }

  // Lets you follow the buses on a planned journey without leaving the planner.
  function trackLegButton(leg) {
    const label = () => isTracked(leg) ? 'Tracked' : 'Track';
    const b = el('button', { class: 'link', type: 'button', title: 'Show this service\'s buses on the map' }, label());
    b.addEventListener('click', (e) => {
      e.stopPropagation();   // don't collapse the itinerary
      if (!isTracked(leg)) toggleTracked(leg);
      b.textContent = label();
    });
    return b;
  }

  function drawItinerary(it) {
    planLayer.clearLayers();
    planTrips = new Map(it.legs.filter((l) => l.type === 'bus').map((l) => [String(l.trip), l]));
    refreshVehicles();
    const bounds = L.latLngBounds([]);
    for (const end of ['from', 'to']) {
      if (places[end]) {
        const ll = [places[end].lat, places[end].lon];
        L.circleMarker(ll, { radius: 8, color: '#fff', weight: 3, fillColor: end === 'from' ? '#1e7b34' : '#b3261e', fillOpacity: 1 })
          .bindTooltip(end === 'from' ? 'Start' : 'Destination').addTo(planLayer);
        bounds.extend(ll);
      }
    }
    let prev = places.from ? [places.from.lat, places.from.lon] : null;
    for (const leg of it.legs) {
      if (leg.type !== 'bus') continue;
      const first = leg.path[0];
      if (prev && first) L.polyline([prev, first], { color: '#555', weight: 3, dashArray: '4 6' }).addTo(planLayer);
      const pl = L.polyline(leg.path, { color: lineColour(key(leg)), weight: 6, opacity: .85 }).addTo(planLayer);
      bounds.extend(pl.getBounds());
      L.circleMarker(first, { radius: 5, color: '#000', weight: 2, fillColor: '#fff', fillOpacity: 1 })
        .bindTooltip('Board ' + leg.line + ' at ' + leg.from.name + ', ' + hhmm(leg.dep)).addTo(planLayer);
      prev = leg.path[leg.path.length - 1];
    }
    if (prev && places.to) L.polyline([prev, [places.to.lat, places.to.lon]], { color: '#555', weight: 3, dashArray: '4 6' }).addTo(planLayer);
    if (bounds.isValid()) map.fitBounds(bounds, { padding: [30, 30] });
  }

  async function plan(e) {
    e.preventDefault();
    const note = $('#plan-note');
    const out = $('#plan-results');
    if (!places.from || !places.to) { note.textContent = 'Choose where you are going from and to.'; return; }
    note.textContent = 'Searching…';
    out.replaceChildren();
    planLayer.clearLayers();
    planTrips = new Map();
    const params = {
      from: places.from.lat.toFixed(5) + ',' + places.from.lon.toFixed(5),
      to: places.to.lat.toFixed(5) + ',' + places.to.lon.toFixed(5),
    };
    if ($('#plan-time').value) params.time = $('#plan-time').value;
    let data;
    try {
      data = await api('plan', params);
    } catch (err) {
      note.textContent = err.message;
      return;
    }
    note.textContent = data.message || '';
    const items = data.itineraries.map((it, i) => {
      const dur = mins(it.arrive - it.depart);
      const summary = it.walkOnly ? [el('span', null, 'Walk all the way')]
        : it.legs.filter((l) => l.type === 'bus').flatMap((l, j) => [j ? ' → ' : null, badge(l.line, null, key(l))]);
      const li = el('li', { class: 'itin clickable' },
        el('div', { class: 'head' },
          el('span', null, hhmm(it.depart) + ' – ' + hhmm(it.arrive)),
          el('span', null, dur + ' min')),
        el('div', { class: 'legs' }, summary,
          it.walkOnly ? null : el('span', null, ' · ' + (it.changes ? it.changes + ' change' : 'direct'))),
        el('ol', { hidden: true }, it.legs.map(describeLeg)));
      li.addEventListener('click', () => {
        for (const o of $$('#plan-results .itin')) {
          o.classList.toggle('selected', o === li);
          $('ol', o).hidden = o !== li;
        }
        drawItinerary(it);
      });
      if (i === 0) setTimeout(() => li.click());
      return li;
    });
    out.replaceChildren(...items);
  }

  function setupPlanner() {
    for (const box of $$('.place')) {
      const end = box.dataset.end;
      const input = $('input', box);
      attachSearch(input, $('.results', box), (s) =>
        setPlace(end, { lat: s.lat, lon: s.lon, label: s.name + (s.platform ? ' (' + s.platform + ')' : '') }));
      input.addEventListener('input', () => { places[end] = null; input.classList.remove('set'); });
      $('.here', box).addEventListener('click', async () => {
        try {
          const [lat, lon] = await locate();
          setPlace(end, { lat, lon, label: 'My location' });
        } catch (err) {
          status(err.message);
        }
      });
      $('.pick', box).addEventListener('click', () => startPicking(end));
    }
    $('#pick-cancel').addEventListener('click', stopPicking);
    $('#plan-form').addEventListener('submit', plan);
  }

  // ---------------------------------------------------------------- start up

  async function init() {
    try {
      config = await api('config');
    } catch (e) {
      status(e.message, 0);
      return;
    }
    const saved = store.get('view', null);
    map.setView(saved ? saved.c : config.center, saved ? saved.z : config.zoom);

    tracked = store.get('tracked', null) || config.services.map((s) => ({ noc: s.noc, line: s.line }));
    showAll = store.get('showAll', false);
    $('#show-all').checked = showAll;
    if (!config.live) status('Live bus positions are not configured on this server', 8000);

    setupRegions();
    setupPlanner();
    renderTracked();
    renderFavourites();

    for (const b of $$('#tabs [data-tab]')) {
      b.addEventListener('click', () => {
        // Tapping Stops while a stop is open goes back to the favourites list.
        if (b.dataset.tab === 'stop' && b.classList.contains('active') && currentStop) {
          currentStop = null;
          $('#stop-detail').hidden = true;
          $('#trip-detail').hidden = true;
          $('#stop-empty').hidden = false;
        }
        showTab(b.dataset.tab);
        expandPanel();
      });
    }
    $('#toggle').addEventListener('click', () => {
      $('#panel').classList.toggle('collapsed');
    });
    showTab(store.get('tab', 'buses'));

    $('#show-all').addEventListener('change', (e) => {
      showAll = e.target.checked;
      store.set('showAll', showAll);
      refreshVehicles();
    });
    $('#service-filter').addEventListener('input', renderServices);

    $('#trip-back').addEventListener('click', () => {
      routeLayer.clearLayers();
      if (currentStop) openStop(currentStop.id);
    });
    $('#stop-fav').addEventListener('click', () => {
      if (!currentStop) return;
      if (favourites.some((f) => f.id === currentStop.id)) favourites = favourites.filter((f) => f.id !== currentStop.id);
      else favourites.push({ id: currentStop.id, name: currentStop.name });
      store.set('favourites', favourites);
      updateFavButton();
      renderFavourites();
    });
    attachSearch($('#search-input'), $('#search-results'), (s) => {
      $('#search-input').value = '';
      openStop(s.id);
    });

    $('#locate').addEventListener('click', async () => {
      try {
        const ll = await locate();
        map.setView(ll, Math.max(map.getZoom(), 15));
        const r = regionFor(ll);
        if (r && config.regions.length > 1) { $('#region').value = r.code; store.set('region', r.code); }
        if (config.regions.length && !r) status('No timetable data has been imported for this area', 6000);
      } catch (e) {
        status(e.message);
      }
    });

    map.on('click', (e) => {
      if (picking) return setPlaceFromPoint(picking, e.latlng.lat, e.latlng.lng);
      routeLayer.clearLayers();
    });
    map.on('moveend', drawRouteArrows);
    const onMove = debounce(() => {
      const c = map.getCenter();
      store.set('view', { c: [c.lat, c.lng], z: map.getZoom() });
      loadServices();
      loadStops();
      if (showAll) refreshVehicles();
    }, 400);
    map.on('moveend', onMove);
    onMove();

    // First visit with nothing saved: centre on the user if they allow it.
    if (!saved && navigator.geolocation && config.regions.length) {
      locate().then((ll) => { if (regionFor(ll)) map.setView(ll, 14); }).catch(() => {});
    }

    refreshVehicles();
    setInterval(() => {
      if (document.visibilityState !== 'visible') return;
      refreshVehicles();
      if (currentStop && !$('#tab-stop').hidden && !$('#stop-detail').hidden) openStop(currentStop.id, true);
    }, REFRESH_MS);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refreshVehicles(); });
  }

  init();
}());
