# Download real map data for an episode: Esri World Imagery tiles (stitched per zoom ring) and
# OpenStreetMap buildings / water. Run with Blender's Python (needs numpy + image IO):
#   blender --background --factory-startup --python blender/fetch_real.py -- <ep>
# Output: blender/real/ep<ep>/{img_z<zoom>.png, img.json, osm.json}. Imagery: Esri, Maxar, Earthstar
# Geographics. Building data: (c) OpenStreetMap contributors (ODbL).
import bpy, json, math, os, sys, time, urllib.request, urllib.parse
from concurrent.futures import ThreadPoolExecutor
import numpy as np

argv = sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else []
EP = argv[0] if argv else '2'
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, 'real', 'ep' + EP); os.makedirs(os.path.join(OUT, 'tiles'), exist_ok=True)
UA = {'User-Agent': 'laghavi-tech-map-render/1.0'}
CFG = {
    '2': dict(lat=25.1930, lon=55.2760, rings=[(17, 3600), (15, 12000), (13, 40000)], bld=6500),
    '5': dict(lat=25.2860, lon=55.3080, rings=[(16, 6000), (14, 18000), (12, 60000)], bld=7000),
    '5r': dict(lat=25.2860, lon=55.3080, rings=[(17, 4700), (15, 13000), (13, 42000)], bld=7000),   # EP5 reel look: sharper core
}[EP]
LAT0, LON0 = CFG['lat'], CFG['lon']
MLAT, MLON = 110574.0, 111320.0 * math.cos(math.radians(LAT0))

def tile_xy(lat, lon, z):
    n = 2 ** z
    return ((lon + 180) / 360 * n, (1 - math.log(math.tan(math.radians(lat)) + 1 / math.cos(math.radians(lat))) / math.pi) / 2 * n)
def tile_ll(x, y, z):
    n = 2 ** z
    return (math.degrees(math.atan(math.sinh(math.pi * (1 - 2 * y / n)))), x / n * 360 - 180)
def to_m(lat, lon): return ((lon - LON0) * MLON, (lat - LAT0) * MLAT)

def get(url, path=None, data=None, tries=5):
    for k in range(tries):
        try:
            req = urllib.request.Request(url, data=data, headers=UA)
            with urllib.request.urlopen(req, timeout=180) as r:
                b = r.read()
            if path: open(path, 'wb').write(b)
            return b
        except Exception as e:
            print('retry', url[:90], e, flush=True); time.sleep(2 * (k + 1))
    raise RuntimeError('failed ' + url)

# ------------------------------------------------------------------ imagery
meta = []
for z, half in CFG['rings']:
    dlat, dlon = half / MLAT, half / MLON
    x0, y0 = tile_xy(LAT0 + dlat, LON0 - dlon, z); x1, y1 = tile_xy(LAT0 - dlat, LON0 + dlon, z)
    x0, y0, x1, y1 = int(x0), int(y0), int(x1), int(y1)
    jobs = [(x, y) for y in range(y0, y1 + 1) for x in range(x0, x1 + 1)]
    print(f'z{z}: {len(jobs)} tiles', flush=True)
    def dl(xy):
        x, y = xy; p = os.path.join(OUT, 'tiles', f'{z}_{x}_{y}.jpg')
        if not os.path.exists(p) or os.path.getsize(p) < 100:
            get(f'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', p)
        return p
    with ThreadPoolExecutor(8) as ex: list(ex.map(dl, jobs))
    nx, ny = x1 - x0 + 1, y1 - y0 + 1
    big = np.zeros((ny * 256, nx * 256, 4), np.float32)
    for (x, y) in jobs:
        img = bpy.data.images.load(os.path.join(OUT, 'tiles', f'{z}_{x}_{y}.jpg'))
        w, h = img.size
        px = np.empty(w * h * 4, np.float32); img.pixels.foreach_get(px); px = px.reshape(h, w, 4)
        if (w, h) != (256, 256): px = np.ones((256, 256, 4), np.float32) * px.mean((0, 1))
        r = (y1 - y) * 256; c = (x - x0) * 256                      # blender pixels are bottom-up
        big[r:r + 256, c:c + 256] = px
        bpy.data.images.remove(img)
    out = bpy.data.images.new(f'z{z}', nx * 256, ny * 256, alpha=False)
    out.pixels.foreach_set(big.ravel()); out.filepath_raw = os.path.join(OUT, f'img_z{z}.png'); out.file_format = 'PNG'; out.save()
    bpy.data.images.remove(out)
    la0, lo0 = tile_ll(x0, y1 + 1, z); la1, lo1 = tile_ll(x1 + 1, y0, z)       # SW, NE corners
    meta.append({'z': z, 'file': f'img_z{z}.png', 'sw_m': to_m(la0, lo0), 'ne_m': to_m(la1, lo1), 'px': [nx * 256, ny * 256]})
    print('saved', meta[-1], flush=True)
json.dump({'lat0': LAT0, 'lon0': LON0, 'rings': meta}, open(os.path.join(OUT, 'img.json'), 'w'))

# ------------------------------------------------------------------ OpenStreetMap buildings + water
half = CFG['bld']; dlat, dlon = half / MLAT, half / MLON
bbox = f'{LAT0 - dlat},{LON0 - dlon},{LAT0 + dlat},{LON0 + dlon}'
q = f'[out:json][timeout:300];(way["building"]({bbox});way["building:part"]({bbox});relation["building"]({bbox});' \
    f'way["natural"="water"]({bbox});relation["natural"="water"]({bbox});way["highway"~"motorway|trunk"]({bbox}););out tags geom;'
raw = get('https://overpass-api.de/api/interpreter', data=urllib.parse.urlencode({'data': q}).encode())
D = json.loads(raw)
els = []
for e in D['elements']:
    rings = []
    if e['type'] == 'way' and 'geometry' in e:
        rings.append([to_m(p['lat'], p['lon']) for p in e['geometry']])
    elif e['type'] == 'relation':
        for m in e.get('members', []):
            if m.get('role') == 'outer' and 'geometry' in m:
                rings.append([to_m(p['lat'], p['lon']) for p in m['geometry']])
    for r in rings:
        if len(r) >= 2: els.append({'t': e.get('tags', {}), 'p': [[round(a, 2), round(b, 2)] for a, b in r]})
json.dump(els, open(os.path.join(OUT, 'osm.json'), 'w'))
print('osm elements', len(els), flush=True)
