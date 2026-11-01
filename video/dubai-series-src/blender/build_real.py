# Realistic "architectural city model" render of the Dubai Series map shots (look of ref/16.webp).
#   blender --background --factory-startup --python blender/build_real.py -- <ep> <outdir> <frames> [samples] [pct]
#   frames: "all" (every frame where the map is visible), "a-b" range, or "f1,f2,..." (30 fps frame indices)
# Real layout: OpenStreetMap buildings (heights where mapped, estimated elsewhere) on a stylised ground
# painted from the real roads / water (paint_ground.js). Rise timing, cameras, highlights: blender/data
# (export.js, from mg/scenes.js). Map units: 1 unit = 4 m, Burj Khalifa at (-120, 160) as in the overlay.
import bpy, bmesh, json, math, os, sys, time
import numpy as np
from mathutils import Vector, Matrix

argv = sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else []
EP = argv[0] if argv else '2'
OUT = os.path.abspath(argv[1] if len(argv) > 1 else f'blender/out/ep{EP}')
FSPEC = argv[2] if len(argv) > 2 else 'all'
SAMPLES = int(argv[3]) if len(argv) > 3 else 24
PCT = int(argv[4]) if len(argv) > 4 else 100
HERE = os.path.dirname(os.path.abspath(__file__))
REAL = os.path.join(HERE, 'real', 'ep' + EP)
FR = json.load(open(os.path.join(HERE, 'data', 'ep' + EP, 'frames.json')))
os.makedirs(OUT, exist_ok=True)
NAKHEEL_LOOK = EP == '5'                          # islands = Nakheel render surface + subtle relief, no box buildings
VZ = 1.15 if EP == '5r' else 1.6                                         # vertical exaggeration (map-model look; Burj tip meets its label pin)
# map units <-> real metres: m = MR + K * Rot(TH) * (u - MS)
REEL = EP == '5r'                                 # EP5 in the Palm Springs reel layout: real metres, full 9:16 frame, static map
if REEL:
    K, TH, MS, MR = 1.0, 0.0, (0.0, 0.0), (0.0, 0.0)
elif EP == '2':      # Burj Khalifa (real centroid) at (-120, 160), 1 unit = 4 m, north up
    K, TH, MS, MR = 4.0, 0.0, (-120.0, 160.0), (-186.0, 463.0)
else:              # Dubai Islands: least-squares fit of the five island centroids (Nakheel plan -> real aerial)
    K, TH, MS, MR = 4.333764, 0.2365397, (-8.800355, 19.568184), (586.6, 3006.2)
S = 1.0 / K                                      # map units per metre
def U(x, y):
    x, y = (x - MR[0]) / K, (y - MR[1]) / K; c, s = math.cos(-TH), math.sin(-TH)
    return (MS[0] + x * c - y * s, MS[1] + x * s + y * c)
def Mm(u, v):
    u, v = (u - MS[0]) * K, (v - MS[1]) * K; c, s = math.cos(TH), math.sin(TH)
    return (MR[0] + u * c - v * s, MR[1] + u * s + v * c)
ANCHOR_M = (-186.0, 463.0)

# ------------------------------------------------------------------ scene
bpy.ops.wm.read_factory_settings(use_empty=True)
sc = bpy.context.scene
for eng in ('BLENDER_EEVEE', 'BLENDER_EEVEE_NEXT'):
    try: sc.render.engine = eng; break
    except TypeError: pass
sc.render.resolution_x, sc.render.resolution_y, sc.render.resolution_percentage = 1080, 1920 if EP == '5r' else 1240, PCT
sc.render.image_settings.file_format = 'PNG'
ee = sc.eevee; ee.taa_render_samples = SAMPLES
for k, v in (('use_raytracing', True), ('use_shadows', True), ('use_gtao', True), ('gtao_distance', 4.0)):
    if hasattr(ee, k):
        try: setattr(ee, k, v)
        except Exception: pass
try: sc.view_settings.view_transform = 'AgX'; sc.view_settings.look = 'AgX - Medium High Contrast'
except Exception: pass
sc.view_settings.exposure = -0.55 if EP == '5r' else 0.1

def sock(coll, ident):
    for s in coll:
        if s.identifier == ident: return s
    return coll[ident]

class NT:
    def __init__(self, mat):
        if hasattr(mat, 'use_nodes'): mat.use_nodes = True
        self.t = mat.node_tree; self.n = self.t.nodes; self.l = self.t.links
        for x in list(self.n): self.n.remove(x)
    def node(self, typ, **kw):
        nd = self.n.new(typ)
        for k, v in kw.items(): setattr(nd, k, v)
        return nd
    def link(self, a, b): self.l.new(a, b)
    def math(self, op, a, b=None, clamp=False):
        nd = self.node('ShaderNodeMath', operation=op, use_clamp=clamp)
        for i, v in enumerate((a, b)):
            if v is None: continue
            if isinstance(v, (int, float)): nd.inputs[i].default_value = v
            else: self.link(v, nd.inputs[i])
        return nd.outputs[0]
    def mix(self, fac, a, b, kind='RGBA', blend='MIX'):
        nd = self.node('ShaderNodeMix', data_type=kind)
        if kind == 'RGBA': nd.blend_type = blend
        suf = 'Color' if kind == 'RGBA' else 'Float'
        for ident, v in (('Factor_Float', fac), ('A_' + suf, a), ('B_' + suf, b)):
            s = sock(nd.inputs, ident)
            if isinstance(v, (int, float)): s.default_value = v
            elif isinstance(v, (tuple, list)): s.default_value = tuple(v) + ((1.0,) if len(v) == 3 else ())
            else: self.link(v, s)
        return sock(nd.outputs, 'Result_' + suf)
    def attr(self, name): return self.node('ShaderNodeAttribute', attribute_name=name)
    def value(self, v):
        nd = self.node('ShaderNodeValue'); nd.outputs[0].default_value = v; return nd

HAZE, HL = [], {}
HAZE_COL = (0.62, 0.55, 0.45) if EP == '2' else (0.70, 0.74, 0.77) if EP == '5r' else (0.58, 0.66, 0.74)
GOLD = (0.95, 0.70, 0.33)

def finish(T, shader_out):
    cam = T.node('ShaderNodeCameraData')
    near, far = T.value(1500), T.value(9000); HAZE.append((near, far))
    f = T.math('DIVIDE', T.math('SUBTRACT', cam.outputs['View Distance'], near.outputs[0]), T.math('SUBTRACT', far.outputs[0], near.outputs[0]), clamp=True)
    f = T.math('MULTIPLY', T.math('POWER', f, 0.9), 0.4 if EP == '5r' else 0.85)
    em = T.node('ShaderNodeEmission'); em.inputs['Color'].default_value = HAZE_COL + (1,)
    mx = T.node('ShaderNodeMixShader'); T.link(f, mx.inputs[0]); T.link(shader_out, mx.inputs[1]); T.link(em.outputs[0], mx.inputs[2])
    out = T.node('ShaderNodeOutputMaterial'); T.link(mx.outputs[0], out.inputs['Surface'])

def principled(T, **kw):
    p = T.node('ShaderNodeBsdfPrincipled')
    for k, v in kw.items():
        name = k.replace('_', ' ')
        if isinstance(v, (int, float)): p.inputs[name].default_value = v
        elif isinstance(v, tuple): p.inputs[name].default_value = v + ((1.0,) if len(v) == 3 else ())
        else: T.link(v, p.inputs[name])
    return p

IMGS, UVW = {}, {}
def ground_tex(T, ring_rect, path):
    """image sampled by world XY; ring_rect = (x0, y0, x1, y1) in real metres"""
    img = IMGS.get(path) or IMGS.setdefault(path, bpy.data.images.load(path))
    tx = T.node('ShaderNodeTexImage', image=img, interpolation='Cubic', extension='EXTEND')
    geo = T.node('ShaderNodeNewGeometry')
    m1 = T.node('ShaderNodeMapping', vector_type='POINT')          # map units -> metres
    m1.inputs['Scale'].default_value = (K, K, 1); m1.inputs['Rotation'].default_value = (0, 0, TH)
    c, s = math.cos(TH), math.sin(TH)
    m1.inputs['Location'].default_value = (MR[0] - K * (MS[0] * c - MS[1] * s), MR[1] - K * (MS[0] * s + MS[1] * c), 0)
    mp = T.node('ShaderNodeMapping', vector_type='POINT')          # metres -> texture 0..1
    x0, y0, x1, y1 = ring_rect
    mp.inputs['Location'].default_value = (-x0 / (x1 - x0), -y0 / (y1 - y0), 0)
    mp.inputs['Scale'].default_value = (1 / (x1 - x0), 1 / (y1 - y0), 1)
    T.link(geo.outputs['Position'], m1.inputs['Vector']); T.link(m1.outputs[0], mp.inputs['Vector']); T.link(mp.outputs[0], tx.inputs['Vector'])
    UVW[tx.name] = mp.outputs[0]
    return tx

_TJ = os.path.join(REAL, 'tiles.json')
TILES = json.load(open(_TJ)) if os.path.exists(_TJ) else {}
def ground_color(T, ring_rect, path):
    """colour socket of a ground painting; big paintings are stitched from GPU-sized tiles"""
    tiles = TILES.get(os.path.basename(path))
    if not tiles: return ground_tex(T, ring_rect, path).outputs['Color']
    col = None
    for tl in tiles:
        tx = ground_tex(T, tl['rect'], os.path.join(REAL, tl['file']))
        sp = T.node('ShaderNodeSeparateXYZ'); T.link(UVW[tx.name], sp.inputs[0])
        inside = T.math('MULTIPLY', T.math('MULTIPLY', T.math('GREATER_THAN', sp.outputs['X'], -1e-4), T.math('LESS_THAN', sp.outputs['X'], 1.0001)),
                        T.math('MULTIPLY', T.math('GREATER_THAN', sp.outputs['Y'], -1e-4), T.math('LESS_THAN', sp.outputs['Y'], 1.0001)))
        col = tx.outputs['Color'] if col is None else T.mix(inside, col, tx.outputs['Color'])
    return col

def ground_mat(name, rect, path):
    m = bpy.data.materials.new(name); T = NT(m)
    col = ground_color(T, rect, path)
    sep = T.node('ShaderNodeSeparateColor'); T.link(col, sep.inputs[0])
    # painted water is teal: blue clearly above red -> glossy, rippled water
    wat = T.math('MULTIPLY', T.math('SUBTRACT', sep.outputs['Blue'], sep.outputs['Red']), 12.0, clamp=True)
    geo = T.node('ShaderNodeNewGeometry')
    n = T.node('ShaderNodeTexNoise'); n.inputs['Scale'].default_value = 0.35; n.inputs['Detail'].default_value = 6
    T.link(geo.outputs['Position'], n.inputs['Vector'])
    bmp = T.node('ShaderNodeBump'); T.link(n.outputs['Fac'], bmp.inputs['Height'])
    T.link(T.math('MULTIPLY', wat, 0.06 if EP in ('5', '5r') else 0.12), bmp.inputs['Strength'])
    p = principled(T, Base_Color=T.mix(wat, col, T.mix(0.35, col, (0.0, 0.03, 0.05))), Roughness=T.mix(wat, 0.88, 0.05, 'FLOAT'))
    T.link(bmp.outputs['Normal'], p.inputs['Normal'])
    T.link(T.mix(wat, 0.5, 0.35 if EP in ('5', '5r') else 0.6, 'FLOAT'), p.inputs['Specular IOR Level'])
    finish(T, p.outputs[0]); return m

def facade_mat(name, floor, win, low=False, roof_img=None, hl_kind='office'):
    m = bpy.data.materials.new(name); T = NT(m)
    geo = T.node('ShaderNodeNewGeometry'); uv = T.node('ShaderNodeUVMap', uv_map='UVMap')
    sep = T.node('ShaderNodeSeparateXYZ'); T.link(geo.outputs['Position'], sep.inputs[0])
    nsep = T.node('ShaderNodeSeparateXYZ'); T.link(geo.outputs['Normal'], nsep.inputs[0])
    usep = T.node('ShaderNodeSeparateXYZ'); T.link(uv.outputs['UV'], usep.inputs[0])
    seed = T.attr('seed').outputs['Fac']
    col = T.attr('col').outputs['Color']
    side = T.math('LESS_THAN', T.math('ABSOLUTE', nsep.outputs['Z']), 0.5)
    fh = T.math('MULTIPLY', T.math('ADD', T.math('MULTIPLY', seed, 0.2), 0.92), floor)
    ww = T.math('MULTIPLY', T.math('ADD', T.math('FRACT', T.math('MULTIPLY', seed, 7.31)), 0.75), win)
    fy = T.math('DIVIDE', T.math('SUBTRACT', sep.outputs['Z'], T.attr('zb').outputs['Fac']), fh); fx = T.math('DIVIDE', usep.outputs['X'], ww)
    fl = T.math('FRACT', fy); cl = T.math('FRACT', fx)
    band = 0.0 if low else T.math('GREATER_THAN', T.math('FRACT', T.math('MULTIPLY', seed, 3.17)), 0.45)
    sp = 0.32 if low else T.mix(band, 0.30, 0.08, 'FLOAT')
    w1 = T.math('MULTIPLY', T.math('GREATER_THAN', fl, sp), T.math('LESS_THAN', fl, 0.95))
    colm = T.math('MULTIPLY', T.math('GREATER_THAN', cl, 0.14), T.math('LESS_THAN', cl, 0.86))
    if not low: colm = T.mix(band, colm, T.math('GREATER_THAN', cl, 0.04), 'FLOAT')
    gl = T.math('MULTIPLY', T.math('MULTIPLY', w1, colm), side)
    gl = T.math('MULTIPLY', gl, 0.85 if low else T.attr('glass').outputs['Fac'])
    cell = T.node('ShaderNodeCombineXYZ'); T.link(T.math('FLOOR', fx), cell.inputs['X']); T.link(T.math('FLOOR', fy), cell.inputs['Y']); T.link(T.math('MULTIPLY', seed, 91.0), cell.inputs['Z'])
    wn = T.node('ShaderNodeTexWhiteNoise', noise_dimensions='3D'); T.link(cell.outputs[0], wn.inputs['Vector'])
    hvo, hvb = T.value(0.0), T.value(0.0); HL.setdefault('office', []).append(hvo); HL.setdefault('burj', []).append(hvb)
    hla = T.attr('hl').outputs['Fac']                     # 1 = office tower, 2 = Burj Khalifa
    hfac = T.math('MAXIMUM', T.math('MULTIPLY', hvo.outputs[0], T.math('COMPARE', hla, 1.0)), T.math('MULTIPLY', hvb.outputs[0], T.math('COMPARE', hla, 2.0)))
    wall = T.mix(hfac, col, GOLD)
    gtint = T.attr('gtint').outputs['Color']
    gcol = T.mix(T.math('MULTIPLY', wn.outputs['Value'], 0.45), gtint, T.mix(0.5, gtint, (0.30, 0.33, 0.36)))
    if low: gcol = T.mix(T.math('GREATER_THAN', wn.outputs['Value'], 0.96), (0.05, 0.06, 0.07), (0.55, 0.47, 0.33))
    gcol = T.mix(hfac, gcol, (0.85, 0.62, 0.30))
    c = T.mix(gl, wall, gcol)
    roof = T.math('SUBTRACT', 1.0, side)
    if roof_img:
        roofc = T.mix(0.25, ground_color(T, *roof_img), (0.62, 0.58, 0.52))
    else:
        rn = T.node('ShaderNodeTexNoise'); rn.inputs['Scale'].default_value = 0.8; T.link(geo.outputs['Position'], rn.inputs['Vector'])
        roofc = T.mix(rn.outputs['Fac'], (0.42, 0.41, 0.39), (0.62, 0.61, 0.58))
    roofc = T.mix(hfac, roofc, GOLD)
    c = T.mix(roof, c, roofc)
    # darker towards the ground (ambient occlusion feel)
    ao = T.math('ADD', 0.7, T.math('MULTIPLY', T.math('DIVIDE', T.math('SUBTRACT', sep.outputs['Z'], T.attr('zb').outputs['Fac']), 6.0, clamp=True), 0.3))
    cm = T.node('ShaderNodeCombineColor'); [T.link(ao, cm.inputs[i]) for i in range(3)]
    c = T.mix(1.0, c, cm.outputs[0], blend='MULTIPLY')
    r = T.mix(roof, T.mix(gl, 0.75, 0.07, 'FLOAT'), 0.95, 'FLOAT')
    p = principled(T, Base_Color=c, Roughness=r, Metallic=T.math('MULTIPLY', gl, 0.55))
    finish(T, p.outputs[0]); return m

def simple_mat(name, color, rough=0.8, metal=0.0):
    m = bpy.data.materials.new(name); T = NT(m)
    finish(T, principled(T, Base_Color=color, Roughness=rough, Metallic=metal).outputs[0]); return m

def plane(name, rect_m, z, mat):
    """ground quad covering a rectangle given in real metres"""
    x0, y0, x1, y1 = rect_m
    V = [U(x0, y0) + (z,), U(x1, y0) + (z,), U(x1, y1) + (z,), U(x0, y1) + (z,)]
    me = bpy.data.meshes.new(name); me.from_pydata(V, [], [(0, 1, 2, 3)])
    ob = bpy.data.objects.new(name, me); sc.collection.objects.link(ob); me.materials.append(mat); return ob

# ------------------------------------------------------------------ ground rings
IMG = json.load(open(os.path.join(REAL, 'img.json')))
RINGS = sorted(IMG['rings'], key=lambda r: r['z'])            # coarse first (drawn lowest)
RING_RECT = {}
for i, r in enumerate(RINGS):
    rect = tuple(r['sw_m']) + tuple(r['ne_m']); RING_RECT[r['z']] = rect
    plane(f'ground_z{r["z"]}', rect, (-4.0 * (len(RINGS) - 1 - i) - 0.1 if REEL else -0.6 + i * 0.25), ground_mat(f'ground_z{r["z"]}', rect, os.path.join(REAL, f'ground_z{r["z"]}.png')))
fine = RINGS[-1]
W5 = json.load(open(os.path.join(HERE, 'data', 'ep5', 'world.json'))) if EP == '5' else None
SEA5 = np.array(W5['water'][0]) if W5 else None
def in_poly_np(pt, poly):
    x, y = pt; xi, yi = poly[:, 0], poly[:, 1]; xj, yj = np.roll(xi, 1), np.roll(yi, 1)
    return bool(np.sum(((yi > y) != (yj > y)) & (x < (xj - xi) * (y - yi) / (yj - yi + 1e-12) + xi)) % 2)
DXB_M = np.array([5808.0, -3627.0])

# ------------------------------------------------------------------ buildings from OSM
def num(v):
    try: return float(str(v).replace('m', '').replace(',', '.').split(';')[0].strip())
    except Exception: return None
osm = json.load(open(os.path.join(REAL, 'osm.json')))
E11 = [np.array(e['p']) for e in osm if e['t'].get('ref', '').startswith('E11') and e['t'].get('highway')]
def dist_e11(p):
    best = 1e9
    for pl in E11:
        a, b = pl[:-1], pl[1:]; d = b - a; l2 = (d ** 2).sum(1) + 1e-9
        t = np.clip(((p - a) * d).sum(1) / l2, 0, 1); q = a + d * t[:, None]
        best = min(best, np.sqrt(((p - q) ** 2).sum(1)).min())
    return best
parts = [e for e in osm if e['t'].get('building:part') and len(e['p']) > 3]
outl = [e for e in osm if e['t'].get('building') and not e['t'].get('building:part') and len(e['p']) > 3]
def pip(pt, poly):
    x, y = pt; c = False; n = len(poly)
    for i in range(n):
        x1, y1 = poly[i]; x2, y2 = poly[i - 1]
        if (y1 > y) != (y2 > y) and x < (x2 - x1) * (y - y1) / (y2 - y1 + 1e-12) + x1: c = not c
    return c
pc = [np.mean(e['p'], 0) for e in parts]
keep = []
for e in outl:
    P = np.array(e['p']); mn, mx = P.min(0), P.max(0)
    inside = any(mn[0] <= c[0] <= mx[0] and mn[1] <= c[1] <= mx[1] and pip(c, e['p']) for c in pc)
    if not inside: keep.append(e)
rng = np.random.default_rng(7)
BLD = []
BURJ_M = np.array(ANCHOR_M)
BB_BOX = (-2600, -1900, 2300, 150)           # Business Bay (metres, around the canal)
OFFICE_M = np.array([374.0, -806.0]); OFFICE_R = 520
for e in keep + parts:
    P = np.array(e['p'], float)
    if np.allclose(P[0], P[-1]): P = P[:-1]
    if len(P) < 3: continue
    area = 0.5 * np.sum(P[:, 0] * np.roll(P[:, 1], -1) - np.roll(P[:, 0], -1) * P[:, 1])
    if abs(area) < 25: continue
    if area < 0: P = P[::-1]
    t = e['t']; c = P.mean(0); A = abs(area)
    h = num(t.get('height')); mh = num(t.get('min_height')) or 0.0
    lv = num(t.get('building:levels'))
    if h is None and lv: h = lv * 3.4 + 2
    if EP in ('5', '5r'):                         # EP5: Deira mainland only (islands from the Nakheel plan); reel: everything real
        if SEA5 is not None and in_poly_np(U(*c), SEA5): continue
        if h is None:
            r = rng.random(); h = 7 + r * 7 if A < 450 else 14 + r * 26 if A < 2500 else 10 + r * 14
        BLD.append(dict(P=P, c=c, h=min(h, 400), mh=mh, A=A, dB=1e9, inBB=False, e11=False, part=bool(t.get('building:part')), burj=False,
                        apt=np.linalg.norm(c - DXB_M) < 2200 and A > 400))
        continue
    dB = np.linalg.norm(c - BURJ_M)
    inBB = BB_BOX[0] < c[0] < BB_BOX[2] and BB_BOX[1] < c[1] < BB_BOX[3] and dB > 700
    core = dB < 1300 or inBB
    nearE11 = dist_e11(c) < 380 if (core or abs(c[0]) < 7000) else False
    if h is None:
        r = rng.random()
        if (core or nearE11) and A > 700: h = 45 + r * 140
        elif (core or nearE11) and A > 250: h = 20 + r * 60
        elif A < 450: h = 6 + r * 5
        elif A < 4000: h = 10 + r * 18
        else: h = 9 + r * 10
    h = min(h, 830)
    BLD.append(dict(P=P, c=c, h=h, mh=mh, A=A, dB=dB, inBB=inBB, e11=nearE11, part=bool(t.get('building:part')),
                    burj=bool(t.get('building:part')) and dB < 120))
print('buildings', len(BLD), 'with burj parts', sum(b['burj'] for b in BLD), flush=True)

# rise timing (mirrors the setup() rules in mg/scenes.js, in map units)
for b in BLD:
    x, y = U(*b['c']); r = rng.random(); b['u'] = (x, y)
    tall = b['h'] >= 40
    b['office'] = False
    if REEL: b['at'], b['dur'], b['grp'] = -10.0, .8, 'static'; continue
    if EP == '5':
        if b.get('apt'): b['at'], b['dur'], b['grp'] = 19.3 + r * .4, .8, 'apt'
        else: b['at'], b['dur'], b['grp'] = 8.6 + math.hypot(x - 200, y + 500) / 1400 * 2.4 + r * .4, .8, 'deira'
        continue
    if b['burj']:b['at'], b['dur'], b['grp'] = 7.6, .9, 'burj'
    elif b['inBB'] and b['h'] >= 25: b['at'], b['dur'], b['grp'] = 21.0 + min(max((x + 700) / 1130, 0), 1) * 1.5 + r * .35, 1.0, 'bb'
    elif b['dB'] < 1000 and tall: b['at'], b['dur'], b['grp'] = 7.0 + r * 1.8, 1.0, 'dt'
    elif b['e11'] and tall: b['at'], b['dur'], b['grp'] = 6.8 + r * 2.2, 1.0, 'szr'
    elif abs(x + 100) < 880 and abs(y - 100) < 880: b['at'], b['dur'], b['grp'] = 6.5 + math.hypot(x + 100, y - 100) / 900 * 2.2 + r * .5, .8, 'fabric'
    else: b['at'], b['dur'], b['grp'] = 6.4 + math.hypot(x, y) / 4000 * 2.4 + r * .4, .7, 'far'
    b['office'] = b['grp'] == 'bb' and np.linalg.norm(b['c'] - OFFICE_M) < OFFICE_R

# palettes (linear RGB): towers white / silver / grey glass / dark blue / black; low-rise warm beige-cream
TOWER = [((.78, .78, .76), (.10, .14, .19), .28), ((.55, .57, .58), (.12, .15, .18), .26), ((.34, .36, .38), (.06, .08, .11), .18),
         ((.10, .13, .19), (.03, .05, .09), .14), ((.05, .05, .055), (.02, .02, .025), .08), ((.70, .66, .58), (.14, .15, .16), .06)]
LOWC = [(.72, .62, .46), (.80, .72, .57), (.66, .56, .41), (.86, .80, .68), (.76, .68, .55), (.90, .87, .80)] if EP == '2' else [(.78, .76, .72), (.86, .85, .82), (.70, .68, .64), (.92, .91, .88), (.80, .77, .70)]
def pick(pal):
    x = rng.random()
    for wall, glass, w in pal:
        x -= w
        if x < 0: return wall, glass
    return pal[0][0], pal[0][1]

class Extruded:
    """All footprints of one class in a single mesh; per frame the roof z of each building is set."""
    def __init__(self, name, blds, mat):
        V, Fc, UVs, mi = [], [], [], []
        self.bi_v, self.top_v = [], []
        attrs = {k: [] for k in ('col', 'gtint', 'seed', 'hl', 'glass', 'zb')}
        base = 0
        for k, b in enumerate(blds):
            P = np.array([U(*p) for p in b['P']]); n = len(P)
            V += [(p[0], p[1], 0) for p in P] + [(p[0], p[1], 1) for p in P]
            self.bi_v += [k] * (2 * n); self.top_v += [0] * n + [1] * n
            seg = np.linalg.norm(np.roll(P, -1, 0) - P, axis=1); cum = np.concatenate([[0], np.cumsum(seg)])
            off = rng.random() * 5
            for i in range(n):
                j = (i + 1) % n
                Fc.append((base + i, base + j, base + n + j, base + n + i)); mi.append(0)
                UVs += [(cum[i] + off, 0), (cum[i + 1] + off, 0), (cum[i + 1] + off, 1), (cum[i] + off, 1)]
            Fc.append(tuple(base + n + i for i in range(n))); mi.append(0); UVs += [(0, 0)] * n
            if b['h'] >= 40 or b['grp'] in ('bb', 'dt', 'szr', 'burj'):
                wall, glass = ((.80, .84, .87), (.12, .16, .20)) if b['burj'] else pick(TOWER)
                gl = 1.0 if not b['burj'] else 1.0
            else:
                wall, glass = LOWC[int(rng.random() * len(LOWC))], (.05, .06, .07); gl = .85
            s = rng.random()
            for kk, v in (('col', wall + (1,)), ('gtint', glass + (1,)), ('seed', s), ('hl', 2.0 if b['burj'] else 1.0 if b['office'] else 0.0), ('glass', gl), ('zb', b['mh'] * S * VZ)):
                attrs[kk] += [v] * (2 * n)
            base += 2 * n
        me = bpy.data.meshes.new(name); me.from_pydata(V, [], Fc)
        uv = me.uv_layers.new(name='UVMap'); uv.data.foreach_set('uv', np.array(UVs, np.float32).ravel())
        for k, arr in attrs.items():
            if k in ('col', 'gtint'):
                a = me.color_attributes.new(k, 'FLOAT_COLOR', 'POINT'); a.data.foreach_set('color', np.array(arr, np.float32).ravel())
            else:
                a = me.attributes.new(k, 'FLOAT', 'POINT'); a.data.foreach_set('value', np.array(arr, np.float32))
        me.materials.append(mat); me.update()
        self.ob = bpy.data.objects.new(name, me); sc.collection.objects.link(self.ob); self.me = me
        self.bi_v = np.array(self.bi_v); self.top_v = np.array(self.top_v, bool)
        co = np.zeros(len(V) * 3, np.float32); me.vertices.foreach_get('co', co); self.co = co
        self.at = np.array([b['at'] for b in blds]); self.dur = np.array([b['dur'] for b in blds])
        self.h = np.array([b['h'] * S * VZ for b in blds]); self.mh = np.array([b['mh'] * S * VZ for b in blds])
    def update(self, t):
        p = np.clip((t - self.at) / self.dur, 0, 1)
        e = np.where(t < self.at - .9, -1.0, np.where(p == 0, 0.0, 1 + 2.15 * (p - 1) ** 3 + 1.15 * (p - 1) ** 2))
        top = np.where(e == 0, np.minimum(.3, self.h), self.mh + (self.h - self.mh) * np.maximum(e, 0))
        bot = np.where(e == 0, 0.0, self.mh * np.minimum(1, np.maximum(e, 0)))
        hidden = e < 0
        z = np.where(self.top_v, top[self.bi_v], bot[self.bi_v])
        z = np.where(hidden[self.bi_v], -50.0, z)
        self.co[2::3] = z; self.me.vertices.foreach_set('co', self.co); self.me.update()

fine_rect = RING_RECT[fine['z']]                 # (metres)
M_TALL = facade_mat('towers', 0.9, 0.42)
M_LOW = facade_mat('lowrise', 0.85, 0.65, low=True, roof_img=(fine_rect, os.path.join(REAL, f'ground_z{fine["z"]}.png')))
tall = [b for b in BLD if b['h'] >= 40 or b['grp'] in ('bb', 'dt', 'szr', 'burj')]
low = [b for b in BLD if not (b['h'] >= 40 or b['grp'] in ('bb', 'dt', 'szr', 'burj'))]
MESHES = [Extruded('towers', tall, M_TALL), Extruded('lowrise', low, M_LOW)]
print('towers', len(tall), 'low', len(low), flush=True)

# ------------------------------------------------------------------ Dubai Islands (Nakheel plan): islands, spits, trees, buildings
def uv_tex_mat(name, path, rect_u, rough=0.9):
    m = bpy.data.materials.new(name); T = NT(m)
    tx = T.node('ShaderNodeTexImage', image=bpy.data.images.load(path), interpolation='Cubic', extension='EXTEND')
    geo = T.node('ShaderNodeNewGeometry'); mp = T.node('ShaderNodeMapping', vector_type='POINT')
    x0, y0, x1, y1 = rect_u
    mp.inputs['Location'].default_value = (-x0 / (x1 - x0), -y0 / (y1 - y0), 0); mp.inputs['Scale'].default_value = (1 / (x1 - x0), 1 / (y1 - y0), 1)
    T.link(geo.outputs['Position'], mp.inputs['Vector']); T.link(mp.outputs[0], tx.inputs['Vector'])
    n = T.node('ShaderNodeTexNoise'); n.inputs['Scale'].default_value = 0.6; T.link(geo.outputs['Position'], n.inputs['Vector'])
    bw = T.node('ShaderNodeRGBToBW'); T.link(tx.outputs['Color'], bw.inputs[0])            # relief from the render's own light/shade (trees, blocks)
    b = T.node('ShaderNodeBump'); b.inputs['Strength'].default_value = 0.55; b.inputs['Distance'].default_value = 0.6; T.link(bw.outputs[0], b.inputs['Height'])
    hsv = T.node('ShaderNodeHueSaturation'); hsv.inputs['Saturation'].default_value = 1.12; hsv.inputs['Value'].default_value = 1.05; T.link(tx.outputs['Color'], hsv.inputs['Color'])
    p = principled(T, Base_Color=hsv.outputs['Color'], Roughness=rough); T.link(b.outputs['Normal'], p.inputs['Normal'])
    finish(T, p.outputs[0]); return m

def prism(name, poly, mats):
    bm = bmesh.new(); vs = [bm.verts.new((p[0], p[1], 0)) for p in poly]
    f = bm.faces.new(vs); bm.normal_update()
    if f.normal.z < 0: bmesh.ops.reverse_faces(bm, faces=[f])
    ext = bmesh.ops.extrude_face_region(bm, geom=[f])
    for v in [e for e in ext['geom'] if isinstance(e, bmesh.types.BMVert)]: v.co.z = 1.0
    top = [e for e in ext['geom'] if isinstance(e, bmesh.types.BMFace)]
    bmesh.ops.delete(bm, geom=[f], context='FACES_ONLY')
    for fc in bm.faces: fc.material_index = 0 if fc in top else 1
    bmesh.ops.triangulate(bm, faces=[x for x in bm.faces if x.material_index == 0], ngon_method='EAR_CLIP')
    me = bpy.data.meshes.new(name); bm.to_mesh(me); bm.free()
    ob = bpy.data.objects.new(name, me); sc.collection.objects.link(ob)
    for m in mats: me.materials.append(m)
    return ob

class Boxes:
    """instanced boxes / balls in one mesh (island buildings, trees); per frame base z + height per instance"""
    def __init__(self, name, kind, cx, cy, ang, w, d, mat, attrs):
        if kind == 'box':
            V0 = [(-.5, -.5, 0), (.5, -.5, 0), (.5, .5, 0), (-.5, .5, 0), (-.5, -.5, 1), (.5, -.5, 1), (.5, .5, 1), (-.5, .5, 1)]
            F0 = [(0, 3, 2, 1), (4, 5, 6, 7), (0, 1, 5, 4), (1, 2, 6, 5), (2, 3, 7, 6), (3, 0, 4, 7)]
        else:
            V0, F0, n, mm = [], [], 9, 4
            for j in range(mm + 1):
                ph = j / mm * math.pi; V0 += [(.5 * math.sin(ph) * math.cos(i / n * 6.283), .5 * math.sin(ph) * math.sin(i / n * 6.283), .5 - .5 * math.cos(ph)) for i in range(n)]
            F0 = [(j * n + i, j * n + (i + 1) % n, (j + 1) * n + (i + 1) % n, (j + 1) * n + i) for j in range(mm) for i in range(n)]
        V0 = np.array(V0); nv = len(V0); N = len(cx); self.nv = nv
        ca, sa = np.cos(ang), np.sin(ang); lx, ly = V0[:, 0][None] * w[:, None], V0[:, 1][None] * d[:, None]
        self.X = (cx[:, None] + lx * ca[:, None] - ly * sa[:, None]).ravel(); self.Y = (cy[:, None] + lx * sa[:, None] + ly * ca[:, None]).ravel()
        self.LZ = np.tile(V0[:, 2], N)
        me = bpy.data.meshes.new(name); me.from_pydata(np.stack([self.X, self.Y, self.LZ], 1).tolist(), [], [tuple(i * nv + j for j in f) for i in range(N) for f in F0])
        # facade U: distance along the face (box sides alternate w / d)
        uvs = []
        for i in range(N):
            for f in F0:
                for j in f: uvs.append(((V0[j, 0] * w[i] + V0[j, 1] * d[i]) + i * 3.1 % 5, 0))
        uv = me.uv_layers.new(name='UVMap'); uv.data.foreach_set('uv', np.array(uvs, np.float32).ravel())
        for k, (typ, per) in attrs.items():
            per = np.repeat(np.asarray(per, np.float32), nv, axis=0)
            if typ == 'COLOR': a = me.color_attributes.new(k, 'FLOAT_COLOR', 'POINT'); a.data.foreach_set('color', per.ravel())
            else: a = me.attributes.new(k, 'FLOAT', 'POINT'); a.data.foreach_set('value', per)
        me.materials.append(mat); me.update()
        self.ob = bpy.data.objects.new(name, me); sc.collection.objects.link(self.ob); self.me = me
        self.cx, self.cy = np.repeat(cx, nv), np.repeat(cy, nv); self.co = np.empty(N * nv * 3, np.float32)
    def update(self, z0, h, vis):
        v = np.repeat(vis, self.nv)
        self.co[0::3] = np.where(v, self.X, self.cx); self.co[1::3] = np.where(v, self.Y, self.cy)
        self.co[2::3] = np.where(v, np.repeat(z0, self.nv) + self.LZ * np.repeat(h, self.nv), -50.0)
        self.me.vertices.foreach_set('co', self.co); self.me.update()

ISL, ISLB, TREES = [], None, None
if W5:
    D5 = os.path.join(HERE, 'data', 'ep5')
    sand = simple_mat('sand', (0.80, 0.70, 0.50), 0.95)
    for i, isl in enumerate(W5['islands']):
        r = isl['rect']; ob = prism(f'island{i}', isl['poly'], [uv_tex_mat(f'isl{i}', os.path.join(D5, f'island{i}.png'), r), sand])
        ISL.append((isl, ob))
    for j, sp in enumerate(W5['spits']):
        ISL.append(({'at': W5['spitAt'], 'h': 1.2, 'dur': 1.2}, prism(f'spit{j}', sp, [sand, sand])))
    # island buildings (footprints traced from the Nakheel render; heights from the plan's urban / villa split)
    B5 = W5['buildings']; isl_parts = [p for p in W5['parts'] if B5[p['bi']]['group'] == 'isl']
    bi = np.array([p['bi'] for p in isl_parts])
    ang = np.array([B5[i]['ang'] for i in bi]); lx = np.array([p['lx'] for p in isl_parts]); ly = np.array([p['ly'] for p in isl_parts])
    cx = np.array([B5[i]['cx'] for i in bi]) + lx * np.cos(ang) - ly * np.sin(ang); cy = np.array([B5[i]['cy'] for i in bi]) + lx * np.sin(ang) + ly * np.cos(ang)
    isw = np.array([p['w'] for p in isl_parts]); isd = np.array([p['d'] for p in isl_parts])
    ISLB = None if NAKHEEL_LOOK else Boxes('island_buildings', 'box', cx, cy, ang, isw, isd, M_LOW,
                 {'col': ('COLOR', [[.93, .91, .86, 1] if rng.random() < .7 else [.86, .82, .74, 1] for _ in isl_parts]), 'gtint': ('COLOR', [[.05, .06, .07, 1]] * len(isl_parts)),
                  'seed': ('FLOAT', rng.random(len(isl_parts))), 'hl': ('FLOAT', np.zeros(len(isl_parts))), 'glass': ('FLOAT', np.full(len(isl_parts), .85)), 'zb': ('FLOAT', np.full(len(isl_parts), 3.0))})
    if ISLB: ISLB.bi = bi; ISLB.y0 = np.array([p['y0'] for p in isl_parts]); ISLB.hf = np.array([p['hf'] for p in isl_parts]); ISLB.abs = np.array([p['abs'] for p in isl_parts])
    if ISLB: ISLB.at = np.array([B5[i]['at'] for i in bi]); ISLB.dur = np.array([B5[i]['dur'] for i in bi]); ISLB.H = np.array([B5[i]['h'] for i in bi]) * 0.8
    # trees: rounded crowns on the islands (plus extra scattered palms for density)
    T5 = W5['trees']
    tx = [t['x'] for t in T5]; ty = [t['y'] for t in T5]; tk = [t['k'] for t in T5]; ts = [t['s'] for t in T5]; trr = [t['r'] for t in T5]
    for k, isl in enumerate(W5['islands']):
        P = np.array(isl['poly']); mn, mxx = P.min(0), P.max(0); n = 0
        while n < ((220 if k else 90) if NAKHEEL_LOOK else (900 if k else 350)):
            q = mn + rng.random(2) * (mxx - mn)
            if in_poly_np(q, P * 0.93 + P.mean(0) * 0.07): tx.append(q[0]); ty.append(q[1]); tk.append(k); ts.append(rng.random()); trr.append(1.2 + rng.random() * 1.6); n += 1
    tx, ty, tk, ts, trr = map(np.array, (tx, ty, tk, ts, trr))
    leaf = bpy.data.materials.new('leaf'); T = NT(leaf)
    finish(T, principled(T, Base_Color=T.attr('col').outputs['Color'], Roughness=0.9).outputs[0])
    tcol = np.array([[.035, .09, .03, 1], [.05, .12, .035, 1], [.03, .07, .03, 1], [.06, .13, .04, 1]])[rng.integers(0, 4, len(tx))]
    TREES = Boxes('trees', 'ball', tx, ty, ts * 6.28, trr * (1.0 if NAKHEEL_LOOK else 1.6), trr * (1.0 if NAKHEEL_LOOK else 1.6), leaf, {'col': ('COLOR', tcol)})
    TREES.k, TREES.s, TREES.r = tk, ts, trr

def update_islands(t):
    for isl, ob in ISL:
        p = min(max((t - isl['at']) / isl['dur'], 0), 1)
        ob.hide_render = p <= 0; ob.scale = (1, 1, max(.01, isl['h'] * (1 - (1 - p) ** 3)))
    if ISLB:
        I = ISLB; p = np.clip((t - I.at) / I.dur, 0, 1); before = t < I.at - .9
        e = np.where(before, -1.0, np.where(p == 0, 0.0, 1 + 2.15 * (p - 1) ** 3 + 1.15 * (p - 1) ** 2))
        plot = e == 0
        y0 = np.where(plot, 0.0, I.y0 * I.H * e)
        h = np.where(plot, np.where((I.y0 == 0) & (I.abs == 0), .3, 0), np.where(I.abs > 0, I.abs * np.minimum(1, e), np.maximum(.3, I.hf * I.H * e)))
        vis = (e >= 0) & (h > 0) & ~((I.abs > 0) & (e < .95) & ~plot)
        I.update(3.0 + y0, h, vis)
    if TREES:
        at = np.array([W5['islands'][k]['at'] for k in TREES.k]); hI = np.array([W5['islands'][k]['h'] for k in TREES.k])
        p = np.clip((t - at - .6 - TREES.s * 1.4) / .6, 0, 1)
        TREES.update(hI, TREES.r * (0.8 if NAKHEEL_LOOK else 1.5) * p, p > 0)

# ------------------------------------------------------------------ light, sky, camera
sun_dir = np.array([0.52, -0.66, 0.54]); sun_dir /= np.linalg.norm(sun_dir)   # SE sun, matches the aerial's shadows
ld = bpy.data.lights.new('sun', 'SUN'); ld.energy = 4.2 if EP == '5r' else 5.2; ld.color = (1.0, 0.86, 0.68); ld.angle = math.radians(0.6)
sun = bpy.data.objects.new('sun', ld); sc.collection.objects.link(sun)
sun.rotation_euler = Vector(-sun_dir).to_track_quat('-Z', 'Y').to_euler()
world = bpy.data.worlds.new('sky'); sc.world = world; T = NT(world)
sky = T.node('ShaderNodeTexSky')
for st in ('MULTIPLE_SCATTERING', 'NISHITA', 'SINGLE_SCATTERING', 'HOSEK_WILKIE'):
    try: sky.sky_type = st; break
    except TypeError: pass
try: sky.sun_elevation = math.asin(sun_dir[2]); sky.sun_rotation = math.atan2(sun_dir[0], sun_dir[1]); sky.sun_disc = False
except Exception: pass
bg = T.node('ShaderNodeBackground'); bg.inputs['Strength'].default_value = 0.11; T.link(sky.outputs['Color'], bg.inputs['Color'])
hz = T.node('ShaderNodeBackground'); hz.inputs['Color'].default_value = HAZE_COL + (1,)
lp = T.node('ShaderNodeLightPath'); mx = T.node('ShaderNodeMixShader')
T.link(lp.outputs['Is Camera Ray'], mx.inputs[0]); T.link(bg.outputs[0], mx.inputs[1]); T.link(hz.outputs[0], mx.inputs[2])
wo = T.node('ShaderNodeOutputWorld'); T.link(mx.outputs[0], wo.inputs['Surface'])

camd = bpy.data.cameras.new('cam'); cam = bpy.data.objects.new('cam', camd); sc.collection.objects.link(cam); sc.camera = cam
camd.sensor_fit = 'HORIZONTAL'; camd.sensor_width = 36.0; camd.clip_start = 25.0 if REEL else 2.0; camd.clip_end = 40000.0
RW, RH = 1080, 1920 if REEL else 1240
def set_cam(c):
    yaw, pit = math.radians(c['yaw']), math.radians(c['pitch'])
    dh = np.array([math.sin(yaw), math.cos(yaw), 0])
    C = np.array([c['x'], c['y'], 0]) - dh * c['dist'] * math.cos(pit) + np.array([0, 0, c['dist'] * math.sin(pit)])
    fwd = np.array([c['x'], c['y'], 0.0]) - C; fwd /= np.linalg.norm(fwd)
    right = np.cross(fwd, [0, 0, 1]); right /= np.linalg.norm(right); up = np.cross(right, fwd)
    R = Matrix([[right[0], up[0], -fwd[0]], [right[1], up[1], -fwd[1]], [right[2], up[2], -fwd[2]]])
    cam.matrix_world = Matrix.Translation(Vector(C)) @ R.to_4x4()
    camd.lens = c.get('f', 1100) / RW * 36.0
    camd.shift_x = 0.0; camd.shift_y = (c.get('cy', .5) - .5) * RH / max(RW, RH)
    for near, far in HAZE:
        near.outputs[0].default_value = c['dist'] * (1.0 if REEL else 1.2); far.outputs[0].default_value = c['dist'] * (5 if REEL else 7)

def set_time(t, F):
    for M in MESHES: M.update(t)
    update_islands(t)
    for v in HL.get('office', []): v.outputs[0].default_value = 1.0 if F.get('hlOffice') else 0.0
    for v in HL.get('burj', []): v.outputs[0].default_value = 1.0 if F.get('hlBurj') else 0.0

# ------------------------------------------------------------------ frames
vis_frames = [f['f'] for f in FR if f['ma'] > 0 and 'cam' in f]
if FSPEC == 'all': todo = vis_frames
elif '-' in FSPEC and ',' not in FSPEC: a, b = map(int, FSPEC.split('-')); todo = [f for f in vis_frames if a <= f <= b]
else: todo = [int(x) for x in FSPEC.split(',')]
for k, fr in enumerate(todo):
    path = os.path.join(OUT, f'{fr:05d}.png')
    if os.path.exists(path) and FSPEC == 'all': continue
    F = FR[fr]
    if 'cam' not in F: continue
    t1 = time.time(); set_time(F['t'], F); set_cam(F['cam'])
    sc.render.filepath = path; bpy.ops.render.render(write_still=True)
    print(f'frame {fr} ({k + 1}/{len(todo)}) t={F["t"]:.2f} {F["scene"]} {time.time() - t1:.1f}s', flush=True)
