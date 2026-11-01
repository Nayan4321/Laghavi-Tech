// node blender/fetch_osm2.js <ep>  ->  blender/real/ep<ep>/osm2.json : roads (with class) + parks/greens, in local metres
// (same origin as fetch_real.py / img.json). Data (c) OpenStreetMap contributors, ODbL.
const fs = require('fs'), path = require('path');
const ep = process.argv[2] || '2';
const dir = path.join(__dirname, 'real', 'ep' + ep);
const M = JSON.parse(fs.readFileSync(path.join(dir, 'img.json')));
const LAT0 = M.lat0, LON0 = M.lon0, MLAT = 110574, MLON = 111320 * Math.cos(LAT0 * Math.PI / 180);
const half = ep === '2' ? 9000 : 9000, dlat = half / MLAT, dlon = half / MLON;
const bbox = `${LAT0 - dlat},${LON0 - dlon},${LAT0 + dlat},${LON0 + dlon}`;
const q = `[out:json][timeout:300];(way["highway"~"^(motorway|trunk|primary|secondary|tertiary|motorway_link|trunk_link|primary_link|secondary_link|residential|unclassified|service|living_street)$"](${bbox});` +
  `way["leisure"~"park|golf_course|pitch|garden"](${bbox});way["landuse"~"grass|recreation_ground|meadow"](${bbox});way["natural"~"scrub|wood|sand|beach"](${bbox});` +
  `way["natural"="coastline"](${bbox});way["waterway"~"canal|river"](${bbox}););out tags geom;`;
(async () => {
  let D;
  for (let k = 0; k < 5; k++) {
    try {
      const r = await fetch('https://overpass-api.de/api/interpreter', { method: 'POST', headers: { 'User-Agent': 'laghavi-tech-map-render/1.0', 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'data=' + encodeURIComponent(q) });
      D = await r.json(); break;
    } catch (e) { console.log('retry', e.message); await new Promise(o => setTimeout(o, 5000 * (k + 1))); }
  }
  const m = p => [+((p.lon - LON0) * MLON).toFixed(1), +((p.lat - LAT0) * MLAT).toFixed(1)];
  const out = D.elements.filter(e => e.geometry).map(e => ({ t: e.tags, p: e.geometry.map(m) }));
  fs.writeFileSync(path.join(dir, 'osm2.json'), JSON.stringify(out));
  console.log('elements', out.length);
})();
