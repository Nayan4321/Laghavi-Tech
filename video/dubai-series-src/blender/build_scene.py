# Blender scene builder + renderer for the Dubai Series map shots.
#   python build_scene.py -- <world.json> <texdir> <outdir> <frames: "all" | "i,j,k" | "a-b"> [samples]
# (or: blender --background --python build_scene.py -- ...)
# Builds the city/islands from the data exported by export_world.js, then for every requested frame
# sets the camera exactly like mg/index.html, raises buildings (ease-out-back overshoot) and renders.
import bpy, bmesh, json, math, sys, os, random
import numpy as np
from mathutils import Vector, geometry

argv = sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else sys.argv[1:]
WJ, TEX, OUT, FR = argv[:4]
SAMPLES = int(argv[4]) if len(argv) > 4 else 64
D = json.load(open(WJ)); KIND = D['kind']
os.makedirs(OUT, exist_ok=True)
rnd = random.Random(3)
HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, '..')

bpy.ops.wm.read_factory_settings(use_empty=True)
S = bpy.context.scene

# ---------------------------------------------------------------- render settings
S.render.engine = 'CYCLES'
cy = S.cycles
prefs = bpy.context.preferences.addons['cycles'].preferences
dev = 'CPU'
for kind in ('OPTIX', 'CUDA', 'HIP', 'METAL', 'ONEAPI'):
    try:
        prefs.compute_device_type = kind; prefs.get_devices()
        if any(d.type == kind for d in prefs.devices):
            for d in prefs.devices: d.use = True
            dev = 'GPU'; break
    except Exception: pass
cy.device = dev
print('render device', dev, prefs.compute_device_type)
cy.samples = SAMPLES; cy.use_denoising = True; cy.denoiser = 'OPENIMAGEDENOISE'
cy.use_adaptive_sampling = True; cy.adaptive_threshold = .03
cy.max_bounces = 4; cy.diffuse_bounces = 2; cy.glossy_bounces = 2; cy.transmission_bounces = 2; cy.transparent_max_bounces = 2
cy.caustics_reflective = False; cy.caustics_refractive = False
S.render.resolution_x, S.render.resolution_y = 1080, 1240
S.render.film_transparent = False
S.render.use_persistent_data = True
S.render.image_settings.file_format = 'PNG'; S.render.image_settings.color_depth = '8'
try:
    S.view_settings.view_transform = 'AgX'; S.view_settings.look = 'AgX - Medium High Contrast'
except Exception: pass
S.view_settings.exposure = -0.55

# ---------------------------------------------------------------- helpers
def node_mat(name):
    m = bpy.data.materials.new(name); m.use_nodes = True
    nt = m.node_tree; nt.nodes.clear()
    o = nt.nodes.new('ShaderNodeOutputMaterial'); return m, nt, o

def principled(nt, **kw):
    b = nt.nodes.new('ShaderNodeBsdfPrincipled')
    for k, v in kw.items():
        key = {'base': 'Base Color', 'rough': 'Roughness', 'metal': 'Metallic', 'spec': 'Specular IOR Level', 'coat': 'Coat Weight'}.get(k, k)
        b.inputs[key].default_value = v
    return b

def srgb(c):
    c = [x / 255 for x in c]
    return tuple(((x + .055) / 1.055) ** 2.4 if x > .04045 else x / 12.92 for x in c) + (1,)

def link(nt, a, b): nt.links.new(a, b)

def world_uv_tex(nt, img_path, rect, extension='EXTEND'):
    """Image texture mapped by world XY onto rect [x0,y0,x1,y1]."""
    geo = nt.nodes.new('ShaderNodeNewGeometry')
    sep = nt.nodes.new('ShaderNodeSeparateXYZ'); link(nt, geo.outputs['Position'], sep.inputs[0])
    x0, y0, x1, y1 = rect
    mu = nt.nodes.new('ShaderNodeMapRange'); mu.inputs[1].default_value = x0; mu.inputs[2].default_value = x1; mu.clamp = False
    mv = nt.nodes.new('ShaderNodeMapRange'); mv.inputs[1].default_value = y0; mv.inputs[2].default_value = y1; mv.clamp = False
    link(nt, sep.outputs[0], mu.inputs[0]); link(nt, sep.outputs[1], mv.inputs[0])
    cmb = nt.nodes.new('ShaderNodeCombineXYZ'); link(nt, mu.outputs[0], cmb.inputs[0]); link(nt, mv.outputs[0], cmb.inputs[1])
    tx = nt.nodes.new('ShaderNodeTexImage'); tx.image = bpy.data.images.load(img_path); tx.extension = extension; tx.interpolation = 'Cubic'
    link(nt, cmb.outputs[0], tx.inputs[0])
    return tx, mu, mv

def mesh_obj(name, verts, faces, mat=None):
    me = bpy.data.meshes.new(name); me.from_pydata(verts, [], faces); me.update()
    ob = bpy.data.objects.new(name, me); S.collection.objects.link(ob)
    if mat: me.materials.append(mat)
    return ob

def fill_poly(poly, z):
    """Triangulate a (possibly concave) polygon at height z."""
    pts = [Vector((p[0], p[1], z)) for p in poly]
    if (pts[0] - pts[-1]).length < 1e-3: pts = pts[:-1]
    tris = geometry.tessellate_polygon([pts])
    return [tuple(v) for v in pts], [tuple(t) for t in tris]

def ease_back(t, c=1.25):
    return 1 + (c + 1) * (t - 1) ** 3 + c * (t - 1) ** 2

def ease_inout(t): return 4 * t ** 3 if t < .5 else 1 - (-2 * t + 2) ** 3 / 2
clamp = lambda x, a=0., b=1.: max(a, min(b, x))

# ---------------------------------------------------------------- materials
def mat_water(deep, shallow, name='Water', shallow_img=None, rect=None):
    m, nt, o = node_mat(name)
    b = principled(nt, rough=.04, spec=.6)
    b.inputs['IOR'].default_value = 1.33
    # ripples
    tc = nt.nodes.new('ShaderNodeNewGeometry')
    nz = nt.nodes.new('ShaderNodeTexNoise'); nz.inputs['Scale'].default_value = .35; nz.inputs['Detail'].default_value = 8; nz.inputs['Roughness'].default_value = .6
    link(nt, tc.outputs['Position'], nz.inputs['Vector'])
    bump = nt.nodes.new('ShaderNodeBump'); bump.inputs['Strength'].default_value = .12; bump.inputs['Distance'].default_value = .4
    link(nt, nz.outputs['Fac'], bump.inputs['Height']); link(nt, bump.outputs[0], b.inputs['Normal'])
    # colour: large-scale variation (deep patches) + optional shallows mask
    nz2 = nt.nodes.new('ShaderNodeTexNoise'); nz2.inputs['Scale'].default_value = .0025; nz2.inputs['Detail'].default_value = 3
    link(nt, tc.outputs['Position'], nz2.inputs['Vector'])
    ramp = nt.nodes.new('ShaderNodeValToRGB'); ramp.color_ramp.elements[0].color = srgb(deep); ramp.color_ramp.elements[1].color = srgb([int(c * 1.25) for c in deep])
    ramp.color_ramp.elements[0].position = .35; ramp.color_ramp.elements[1].position = .7
    link(nt, nz2.outputs['Fac'], ramp.inputs[0])
    col = ramp.outputs[0]
    if shallow_img:
        tx, *_ = world_uv_tex(nt, shallow_img, rect)
        mix = nt.nodes.new('ShaderNodeMix'); mix.data_type = 'RGBA'
        link(nt, tx.outputs['Color'], mix.inputs['Factor']); link(nt, col, mix.inputs[6]); mix.inputs[7].default_value = srgb(shallow)
        col = mix.outputs[2]
    link(nt, col, b.inputs['Base Color'])
    link(nt, b.outputs[0], o.inputs[0]); return m

def mat_facade(name, glass_col, frame_col, glass_metal=.85, band=.22, floor=3.2, mull=3.6, rough=.08, dark=False):
    """Curtain-wall tower: reflective glass with floor spandrels + mullions (driven by UV = perimeter, height)."""
    m, nt, o = node_mat(name)
    uv = nt.nodes.new('ShaderNodeUVMap')
    sep = nt.nodes.new('ShaderNodeSeparateXYZ'); link(nt, uv.outputs[0], sep.inputs[0])
    def stripe(inp, period, width):
        d = nt.nodes.new('ShaderNodeMath'); d.operation = 'DIVIDE'; d.inputs[1].default_value = period; link(nt, inp, d.inputs[0])
        f = nt.nodes.new('ShaderNodeMath'); f.operation = 'FRACT'; link(nt, d.outputs[0], f.inputs[0])
        l = nt.nodes.new('ShaderNodeMath'); l.operation = 'LESS_THAN'; l.inputs[1].default_value = width; link(nt, f.outputs[0], l.inputs[0])
        return l.outputs[0]
    sb = stripe(sep.outputs[1], floor, band); sm = stripe(sep.outputs[0], mull, .1)
    mx = nt.nodes.new('ShaderNodeMath'); mx.operation = 'MAXIMUM'; link(nt, sb, mx.inputs[0]); link(nt, sm, mx.inputs[1])
    # per-building tint (face attribute)
    at = nt.nodes.new('ShaderNodeAttribute'); at.attribute_name = 'tint'
    g = principled(nt, base=srgb(glass_col), rough=rough, metal=glass_metal)
    gm = nt.nodes.new('ShaderNodeMix'); gm.data_type = 'RGBA'; gm.blend_type = 'MULTIPLY'; gm.inputs['Factor'].default_value = 1
    gm.inputs[6].default_value = srgb(glass_col); link(nt, at.outputs['Color'], gm.inputs[7]); link(nt, gm.outputs[2], g.inputs['Base Color'])
    fr = principled(nt, base=srgb(frame_col), rough=.55)
    # roofs (normal z) are plain concrete
    geo = nt.nodes.new('ShaderNodeNewGeometry'); nsep = nt.nodes.new('ShaderNodeSeparateXYZ'); link(nt, geo.outputs['Normal'], nsep.inputs[0])
    roof = nt.nodes.new('ShaderNodeMath'); roof.operation = 'GREATER_THAN'; roof.inputs[1].default_value = .7; link(nt, nsep.outputs[2], roof.inputs[0])
    m2 = nt.nodes.new('ShaderNodeMath'); m2.operation = 'MAXIMUM'; link(nt, mx.outputs[0], m2.inputs[0]); link(nt, roof.outputs[0], m2.inputs[1])
    mix = nt.nodes.new('ShaderNodeMixShader'); link(nt, m2.outputs[0], mix.inputs[0]); link(nt, g.outputs[0], mix.inputs[1]); link(nt, fr.outputs[0], mix.inputs[2])
    link(nt, mix.outputs[0], o.inputs[0]); return m

def mat_plain(name, col, rough=.75, metal=0., tint=True, window=False):
    m, nt, o = node_mat(name)
    b = principled(nt, base=srgb(col), rough=rough, metal=metal)
    colsock = None
    if tint:
        at = nt.nodes.new('ShaderNodeAttribute'); at.attribute_name = 'tint'
        gm = nt.nodes.new('ShaderNodeMix'); gm.data_type = 'RGBA'; gm.blend_type = 'MULTIPLY'; gm.inputs['Factor'].default_value = 1
        gm.inputs[6].default_value = srgb(col); link(nt, at.outputs['Color'], gm.inputs[7]); colsock = gm.outputs[2]
    if window:   # punched windows on mid-rise blocks (walls only)
        uv = nt.nodes.new('ShaderNodeUVMap')
        mp = nt.nodes.new('ShaderNodeMapping'); mp.inputs['Scale'].default_value = (.3, .31, 1); link(nt, uv.outputs[0], mp.inputs[0])
        wv = nt.nodes.new('ShaderNodeTexBrick'); wv.offset = 0; link(nt, mp.outputs[0], wv.inputs['Vector'])
        wv.inputs['Scale'].default_value = 1; wv.inputs['Mortar Size'].default_value = .3
        geo = nt.nodes.new('ShaderNodeNewGeometry'); nsep = nt.nodes.new('ShaderNodeSeparateXYZ'); link(nt, geo.outputs['Normal'], nsep.inputs[0])
        wall = nt.nodes.new('ShaderNodeMath'); wall.operation = 'LESS_THAN'; wall.inputs[1].default_value = .5; link(nt, nsep.outputs[2], wall.inputs[0])
        inv = nt.nodes.new('ShaderNodeMath'); inv.operation = 'SUBTRACT'; inv.inputs[0].default_value = 1; link(nt, wv.outputs['Fac'], inv.inputs[1])
        f = nt.nodes.new('ShaderNodeMath'); f.operation = 'MULTIPLY'; link(nt, wall.outputs[0], f.inputs[0]); link(nt, inv.outputs[0], f.inputs[1])
        mx = nt.nodes.new('ShaderNodeMix'); mx.data_type = 'RGBA'; link(nt, f.outputs[0], mx.inputs['Factor'])
        if colsock: link(nt, colsock, mx.inputs[6])
        else: mx.inputs[6].default_value = srgb(col)
        mx.inputs[7].default_value = srgb((70, 82, 92)); colsock = mx.outputs[2]
        rf = nt.nodes.new('ShaderNodeMath'); rf.operation = 'MULTIPLY_ADD'; link(nt, f.outputs[0], rf.inputs[0]); rf.inputs[1].default_value = -.6; rf.inputs[2].default_value = rough
        link(nt, rf.outputs[0], b.inputs['Roughness'])
    if colsock: link(nt, colsock, b.inputs['Base Color'])
    link(nt, b.outputs[0], o.inputs[0]); return m

def mat_ground(tex_list):
    """tex_list: [(png, rect)], later entries override earlier ones inside their rect."""
    m, nt, o = node_mat('Ground')
    b = principled(nt, rough=.92)
    col = None
    for path, rect in tex_list:
        tx, mu, mv = world_uv_tex(nt, path, rect, 'CLIP' if col is not None else 'EXTEND')
        if col is None: col = tx.outputs['Color']; continue
        mix = nt.nodes.new('ShaderNodeMix'); mix.data_type = 'RGBA'
        link(nt, tx.outputs['Alpha'], mix.inputs['Factor']); link(nt, col, mix.inputs[6]); link(nt, tx.outputs['Color'], mix.inputs[7]); col = mix.outputs[2]
    link(nt, col, b.inputs['Base Color'])
    # fine sand grain bump
    nz = nt.nodes.new('ShaderNodeTexNoise'); nz.inputs['Scale'].default_value = 2.5
    geo = nt.nodes.new('ShaderNodeNewGeometry'); link(nt, geo.outputs['Position'], nz.inputs['Vector'])
    bump = nt.nodes.new('ShaderNodeBump'); bump.inputs['Strength'].default_value = .15; link(nt, nz.outputs['Fac'], bump.inputs['Height']); link(nt, bump.outputs[0], b.inputs['Normal'])
    link(nt, b.outputs[0], o.inputs[0]); return m

# ---------------------------------------------------------------- sky + sun
def setup_light(sun_elev, sun_az, strength=4.5, warm=(1., .82, .62)):
    w = bpy.data.worlds.new('Sky'); S.world = w; w.use_nodes = True; nt = w.node_tree; nt.nodes.clear()
    out = nt.nodes.new('ShaderNodeOutputWorld'); bg = nt.nodes.new('ShaderNodeBackground')
    sky = nt.nodes.new('ShaderNodeTexSky')
    ok = False
    for t in ('MULTIPLE_SCATTERING', 'NISHITA', 'SINGLE_SCATTERING', 'HOSEK_WILKIE'):
        try: sky.sky_type = t; ok = True; break
        except Exception: pass
    try:
        sky.sun_elevation = math.radians(sun_elev); sky.sun_rotation = math.radians(sun_az); sky.sun_disc = False
        sky.air_density = 1.4; sky.aerosol_density = 2.5 if hasattr(sky, 'aerosol_density') else None
    except Exception: pass
    if hasattr(sky, 'dust_density'): sky.dust_density = 2.5
    link(nt, sky.outputs[0], bg.inputs[0]); bg.inputs[1].default_value = .3
    link(nt, bg.outputs[0], out.inputs[0])
    sd = bpy.data.lights.new('Sun', 'SUN'); sd.energy = strength; sd.color = warm; sd.angle = math.radians(1.2)
    so = bpy.data.objects.new('Sun', sd); S.collection.objects.link(so)
    # sun direction: elevation above horizon, azimuth measured from +y (north) clockwise
    so.rotation_euler = (math.radians(90 - sun_elev), 0, math.radians(-sun_az + 180))
    return so

# ---------------------------------------------------------------- buildings (one mesh, animated by numpy)
class Buildings:
    def __init__(self, blds, mats, matfn):
        verts, faces, fmat, ftint, uvs = [], [], [], [], []
        self.vb, self.vtop = [], []
        self.b = blds
        for bi, b in enumerate(blds):
            poly = b['poly']
            if len(poly) > 2 and poly[0] == poly[-1]: poly = poly[:-1]
            # ccw
            area = sum(poly[i][0] * poly[(i + 1) % len(poly)][1] - poly[(i + 1) % len(poly)][0] * poly[i][1] for i in range(len(poly)))
            if area < 0: poly = poly[::-1]
            n = len(poly); base = len(verts)
            mi, tint = matfn(b, bi)
            for p in poly: verts.append((p[0], p[1], 0)); self.vb.append(bi); self.vtop.append(0)
            for p in poly: verts.append((p[0], p[1], 0)); self.vb.append(bi); self.vtop.append(1)
            per = 0; H = b['h']
            for i in range(n):
                j = (i + 1) % n
                L = math.dist(poly[i], poly[j])
                faces.append((base + i, base + j, base + n + j, base + n + i)); fmat.append(mi); ftint.append(tint)
                uvs.append([(per, 0), (per + L, 0), (per + L, H), (per, H)]); per += L
            faces.append(tuple(base + n + i for i in range(n))); fmat.append(mi); ftint.append(tint)
            uvs.append([(p[0] * .2, p[1] * .2) for p in poly])
        me = bpy.data.meshes.new('Buildings'); me.from_pydata(verts, [], faces)
        for m in mats: me.materials.append(m)
        me.polygons.foreach_set('material_index', fmat)
        uvl = me.uv_layers.new(name='UVMap'); flat = [c for f in uvs for v in f for c in v]
        uvl.data.foreach_set('uv', flat)
        ca = me.color_attributes.new('tint', 'FLOAT_COLOR', 'FACE')
        ca.data.foreach_set('color', [c for t in ftint for c in (t[0], t[1], t[2], 1)])
        me.update()
        self.ob = bpy.data.objects.new('Buildings', me); S.collection.objects.link(self.ob)
        self.me = me
        self.vb = np.array(self.vb); self.vtop = np.array(self.vtop, bool)
        self.fmat = np.array(fmat, np.int32)
        self.face_b = np.array([self.vb[f[0]] for f in faces])
        self.co = np.array(verts, np.float32)
        self.h = np.array([b['h'] for b in blds], np.float32); self.z0 = np.array([b.get('z0', 0) for b in blds], np.float32)
        self.at = np.array([b['at'] + b.get('delay', 0) for b in blds], np.float32); self.dur = np.array([b.get('dur', .9) for b in blds], np.float32)
        print('buildings', len(blds), 'faces', len(faces))

    def update(self, t, hl=None, gold_index=None):
        p = np.clip((t - self.at) / self.dur, 0, 1)
        e = 1 + 2.25 * (p - 1) ** 3 + 1.25 * (p - 1) ** 2
        hidden = t < self.at - .9
        plot = (t >= self.at - .9) & (t < self.at)
        top = np.where(plot, .3, self.h * e)
        base = self.z0 * e
        hidden = hidden | (plot & (self.z0 > 0))
        z = np.where(self.vtop, base[self.vb] + top[self.vb], base[self.vb])
        z = np.where(hidden[self.vb], -60, z)
        co = self.co.copy(); co[:, 2] = z
        self.me.vertices.foreach_set('co', co.ravel())
        if gold_index is not None:
            fm = self.fmat.copy()
            if hl: fm[np.isin(self.face_b, hl)] = gold_index
            self.me.polygons.foreach_set('material_index', fm)
        self.me.update()

# ---------------------------------------------------------------- camera
def make_camera():
    cd = bpy.data.cameras.new('Cam'); co = bpy.data.objects.new('Cam', cd); S.collection.objects.link(co); S.camera = co
    cd.sensor_fit = 'HORIZONTAL'; cd.sensor_width = 36; cd.clip_start = 2; cd.clip_end = 20000
    return co

def set_camera(co, c):
    yaw, pit = math.radians(c['yaw']), math.radians(c['pitch'])
    T = Vector((c['x'], c['y'], 0))
    C = Vector((c['x'] - math.sin(yaw) * c['dist'] * math.cos(pit), c['y'] - math.cos(yaw) * c['dist'] * math.cos(pit), c['dist'] * math.sin(pit)))
    co.location = C
    co.rotation_euler = (T - C).to_track_quat('-Z', 'Y').to_euler()
    co.data.lens = c.get('f', 1100) * 36 / 1080
    co.data.shift_x = 0; co.data.shift_y = (c.get('cy', .5) - .5)
    return C, T

# ================================================================ BUSINESS BAY
def build_bb():
    setup_light(15, 240, 3.6)
    gm = mat_ground([(f'{TEX}/ground_wide.png', [-4200, -4200, 4200, 4200]), (f'{TEX}/ground_detail.png', [-1100, -1100, 1100, 1100])])
    mesh_obj('Ground', [(-6000, -6000, 0), (6000, -6000, 0), (6000, 6000, 0), (-6000, 6000, 0)], [(0, 1, 2, 3)], gm)
    wat = mat_water((16, 72, 84), (40, 120, 120), 'Water')
    for k, w in enumerate(D['water']):
        v, f = fill_poly(w['poly'], .25 + k * .02); mesh_obj(f'Water{k}', v, f, wat)
    # raised highway decks for Sheikh Zayed Rd / Al Khail
    asph = mat_plain('Asphalt', (86, 88, 90), .6, tint=False)
    for k, r in enumerate(D['roads'][:2]):
        from_pts = r['pts']; w = r['w'] * 2.4
        L, R = [], []
        for i, p in enumerate(from_pts):
            a = from_pts[max(i - 1, 0)]; b = from_pts[min(i + 1, len(from_pts) - 1)]
            dx, dy = b[0] - a[0], b[1] - a[1]; l = math.hypot(dx, dy) or 1; dx /= l; dy /= l
            L.append((p[0] - dy * w / 2, p[1] + dx * w / 2, .9)); R.append((p[0] + dy * w / 2, p[1] - dx * w / 2, .9))
        n = len(L); verts = L + R
        faces = [(i, i + 1, n + i + 1, n + i) for i in range(n - 1)]
        mesh_obj(f'Road{k}', verts, faces, asph)

    mats = [
        mat_plain('LowRise', (226, 212, 186), .85, window=True),            # 0 beige/white low/mid-rise
        mat_facade('GlassSilver', (96, 110, 122), (200, 202, 200), rough=.04, band=.18),        # 1
        mat_facade('GlassWhite', (80, 98, 112), (232, 230, 222), band=.45, rough=.05),  # 2 white towers w/ glass bands
        mat_facade('GlassDark', (28, 44, 66), (60, 70, 82), rough=.05),     # 3 dark blue/black
        mat_plain('Gold', (242, 178, 80), .25, metal=.7, tint=False),        # 4 highlight
        mat_facade('Burj', (178, 190, 200), (232, 234, 236), band=.3, mull=2.2),  # 5
        mat_plain('Mall', (240, 238, 232), .5, tint=False),                  # 6
        mat_plain('Villa', (214, 190, 150), .9),                             # 7 sand villas (far fabric)
    ]
    blds = []
    for b in D['buildings']:
        if b['group'] == 'far':           # split the big far blocks into dense small low-rise blocks
            cx, cy = b['c']; poly = b['poly']
            ux = (poly[1][0] - poly[0][0], poly[1][1] - poly[0][1]); vy = (poly[3][0] - poly[0][0], poly[3][1] - poly[0][1])
            for i in range(3):
                for j in range(3):
                    if rnd.random() < .15: continue
                    a0, a1, b0, b1 = (i + .12) / 3, (i + .88) / 3, (j + .12) / 3, (j + .88) / 3
                    q = [(poly[0][0] + ux[0] * a + vy[0] * bb, poly[0][1] + ux[1] * a + vy[1] * bb) for a, bb in ((a0, b0), (a1, b0), (a1, b1), (a0, b1))]
                    blds.append(dict(b, poly=q, h=2 + rnd.random() * 6))
        else:
            blds.append(b)
            if b['group'] in ('bb', 'szr', 'dt') and b['h'] > 45 and rnd.random() < .55:   # setback crown
                cx_ = sum(p[0] for p in b['poly'][:4]) / 4; cy_ = sum(p[1] for p in b['poly'][:4]) / 4; k = .55 + rnd.random() * .25
                blds.append(dict(b, poly=[(cx_ + (p[0] - cx_) * k, cy_ + (p[1] - cy_) * k) for p in b['poly'][:4]], z0=b['h'], h=b['h'] * (.12 + rnd.random() * .2)))
    def matfn(b, i):
        g = b['group']; r = random.Random(i)
        t = 1 + (r.random() - .5) * .14
        tint = (t, t, t * (1 + (r.random() - .5) * .05))
        if g == 'burj': return 5, (1, 1, 1)
        if g in ('fabric', 'far'):
            k = r.random(); tint = (1 + (k - .5) * .2, 1 + (k - .5) * .16 - .02 * r.random(), 1 + (k - .5) * .1 - .06 * r.random())
            return (0 if b['h'] > 7 else 7), tint
        if g == 'far': return 7, tint
        if g == 'dt' and b['h'] < 10: return 6, (1, 1, 1)
        glass = b['col'][0] < 230
        if glass: return (3 if r.random() < .2 else 1), tint
        return 2, tint
    BB = Buildings(blds, mats, matfn)
    # Burj spire
    return BB, 4

# ================================================================ DUBAI ISLANDS
def build_di():
    setup_light(24, 225, 4.0, (1, .88, .74))
    from PIL import Image, ImageDraw, ImageFilter
    # shallows mask: distance around islands (blurred island mask)
    N = 2048; R = [-1600, -1600, 1600, 1600]
    pt = lambda p: ((p[0] - R[0]) / (R[2] - R[0]) * N, (R[3] - p[1]) / (R[3] - R[1]) * N)
    im = Image.new('L', (N, N), 0); d = ImageDraw.Draw(im)
    for I in D['islands']: d.polygon([pt(p) for p in I['poly']], fill=255)
    for sp in D['spits']: d.polygon([pt(p) for p in sp], fill=255)
    sh = im.filter(ImageFilter.MaxFilter(9)).filter(ImageFilter.GaussianBlur(14))
    sh = Image.eval(sh, lambda v: min(255, int(v * 1.6)))
    sh.save(f'{TEX}/shallow.png')
    pl = np.asarray(Image.open(os.path.join(SRC, 'ref/nk_plan.png')).convert('RGB'))
    al = Image.fromarray(((pl.max(axis=2) > 14) * 255).astype(np.uint8)).filter(ImageFilter.MinFilter(9)).filter(ImageFilter.GaussianBlur(6))
    pa = Image.fromarray(pl).convert('RGBA'); pa.putalpha(al); pa.save(f'{TEX}/nk_plan_masked.png')
    gm = mat_ground([(f'{TEX}/ground_wide.png', R), (f'{TEX}/nk_plan_masked.png', [-1100, -900, 1100, 900])])
    mesh_obj('Ground', [(-6000, -6000, 0), (6000, -6000, 0), (6000, 6000, 0), (-6000, 6000, 0)], [(0, 1, 2, 3)], gm)
    wat = mat_water((10, 52, 92), (54, 170, 180), 'Sea', f'{TEX}/shallow.png', R)
    for k, w in enumerate(D['water']):
        poly = [(p[0] * 4 if abs(p[0]) >= 1599 else p[0], p[1] * 4 if p[1] >= 1599 else p[1]) for p in w['poly']] if k == 0 else w['poly']
        v, f = fill_poly(poly, .4 + k * .02); mesh_obj(f'Water{k}', v, f, wat)
    # islands: slabs topped with the rectified Nakheel render (trees, golf, villas baked in)
    top, nt, o = node_mat('IslandTop')
    b = principled(nt, rough=.85)
    tx, *_ = world_uv_tex(nt, os.path.join(SRC, 'ref/nk_plan.png'), [-1100, -900, 1100, 900])
    hsv = nt.nodes.new('ShaderNodeHueSaturation'); hsv.inputs['Saturation'].default_value = 1.08; hsv.inputs['Value'].default_value = .92
    link(nt, tx.outputs['Color'], hsv.inputs['Color']); link(nt, hsv.outputs[0], b.inputs['Base Color']); link(nt, b.outputs[0], o.inputs[0])
    sand = mat_plain('Sand', (226, 210, 176), .9, tint=False)
    isl = []
    for I in D['islands']:
        poly = I['poly']
        if poly[0] == poly[-1]: poly = poly[:-1]
        v, f = fill_poly(poly, 1); n = len(v)
        vb = [(x, y, 0) for x, y, _ in v]
        verts = v + vb; faces = f + [(i, (i + 1) % n, n + (i + 1) % n, n + i) for i in range(n)]
        ob = mesh_obj('Island' + I['tag'], verts, faces); ob.data.materials.append(top); ob.data.materials.append(sand)
        ob.data.polygons.foreach_set('material_index', [0] * len(f) + [1] * n)
        isl.append((ob, I))
    for k, sp in enumerate(D['spits']):
        v, f = fill_poly(sp, .7); mesh_obj(f'Spit{k}', v, f, sand)
    mats = [
        mat_plain('WhiteBlock', (240, 238, 232), .6, window=True),   # 0 Central A mid-rise
        mat_facade('GlassTower', (120, 146, 160), (236, 236, 232), band=.38),  # 1
        mat_plain('Villa', (226, 220, 206), .75),                    # 2 villas
        mat_plain('Deira', (214, 200, 176), .9, window=True),        # 3 mainland
        mat_plain('Runway', (70, 72, 74), .7, tint=False),           # 4
        mat_plain('Gold', (242, 178, 80), .25, metal=.7, tint=False),  # 5
        mat_plain('Terminal', (226, 226, 222), .4, tint=False),     # 6
    ]
    def matfn(b, i):
        r = random.Random(i); t = 1 + (r.random() - .5) * .12; tint = (t, t, t)
        g = b['group']
        if g == 'deira': return 3, tint
        if g == 'apt': return (4 if b['h'] < 1 else 6), (1, 1, 1)
        if b['h'] > 30: return 1, tint
        if b.get('island') == 0: return 0, tint
        return 2, tint
    BB = Buildings(D['buildings'], mats, matfn)
    BB.islands = isl
    trees_di(isl)
    return BB, 5

def trees_di(isl):
    """3D tree canopy + palms scattered on the green parts of the islands (geometry-nodes instancing)."""
    from PIL import Image
    img = Image.open(os.path.join(SRC, 'ref/nk_plan.png')).convert('RGB'); A = np.asarray(img)
    H_, W_ = A.shape[:2]
    leaf = mat_plain('Leaf', (56, 104, 46), .8, tint=False)
    leaf2 = mat_plain('Leaf2', (82, 128, 58), .8, tint=False)
    trunk = mat_plain('Trunk', (110, 90, 70), .9, tint=False)
    col = bpy.data.collections.new('TreeProto')
    # broadleaf: lumpy ico crown
    bpy.ops.mesh.primitive_ico_sphere_add(subdivisions=2, radius=1, location=(0, 0, 1.5))
    cr = bpy.context.object; cr.scale = (1, 1, .8); cr.data.materials.append(leaf)
    for v in cr.data.vertices: v.co *= .85 + random.random() * .3
    bpy.ops.object.transform_apply(scale=True)
    # palm: thin trunk + star of drooping fronds
    bpy.ops.mesh.primitive_cylinder_add(vertices=6, radius=.12, depth=3, location=(0, 0, 1.5)); pt_ = bpy.context.object; pt_.data.materials.append(trunk)
    fr_v, fr_f = [], []
    for k in range(8):
        a = k / 8 * math.tau; ca, sa = math.cos(a), math.sin(a)
        base = len(fr_v)
        for s_ in range(5):
            u = s_ / 4; r_ = u * 1.8; z = 3 + .5 * u - 1.0 * u * u; w = .35 * math.sin(u * math.pi) + .02
            fr_v += [(ca * r_ - sa * w, sa * r_ + ca * w, z), (ca * r_ + sa * w, sa * r_ - ca * w, z)]
        for s_ in range(4): fr_f.append((base + 2 * s_, base + 2 * s_ + 1, base + 2 * s_ + 3, base + 2 * s_ + 2))
    pf = mesh_obj('PalmFronds', fr_v, fr_f, leaf2)
    for o in (cr, pt_): o.select_set(True)
    pf.select_set(True); bpy.context.view_layer.objects.active = pt_
    bpy.ops.object.select_all(action='DESELECT'); pt_.select_set(True); pf.select_set(True); bpy.context.view_layer.objects.active = pt_; bpy.ops.object.join()
    palm = pt_; palm.name = 'Palm'
    for o in (cr, palm):
        for c in o.users_collection: c.objects.unlink(o)
        col.objects.link(o)
    cr.name = 'Broadleaf'
    # points: green pixels of the plan inside islands
    rng = np.random.default_rng(4)
    pts = []
    for ob, I in isl:
        poly = I['poly']; xs = [p[0] for p in poly]; ys = [p[1] for p in poly]
        n = int((max(xs) - min(xs)) * (max(ys) - min(ys)) / 22)
        cand = np.c_[rng.uniform(min(xs), max(xs), n), rng.uniform(min(ys), max(ys), n)]
        inside = np.zeros(len(cand), bool); P = np.array(poly); j = len(P) - 1
        for i in range(len(P)):
            xi, yi = P[i]; xj, yj = P[j]
            c_ = ((yi > cand[:, 1]) != (yj > cand[:, 1])) & (cand[:, 0] < (xj - xi) * (cand[:, 1] - yi) / (yj - yi + 1e-9) + xi)
            inside ^= c_; j = i
        cand = cand[inside]
        px = ((cand[:, 0] + 1100) * 2).astype(int).clip(0, W_ - 1); py = ((900 - cand[:, 1]) * 2).astype(int).clip(0, H_ - 1)
        c = A[py, px].astype(int)
        green = (c[:, 1] > c[:, 0] + 12) & (c[:, 1] > c[:, 2] + 8) & (c[:, 1] < 200)
        lawn = (c[:, 1] > 150)   # bright fairways stay open
        cand = cand[green & ~lawn]
        for x, y in cand: pts.append((x, y, 1.0, I['k']))
    print('trees', len(pts))
    verts = [(x, y, z) for x, y, z, _ in pts]
    me = bpy.data.meshes.new('TreePts'); me.from_pydata(verts, [], []); me.update()
    kk = me.attributes.new('isl', 'FLOAT', 'POINT'); kk.data.foreach_set('value', [p[3] for p in pts])
    ob = bpy.data.objects.new('Trees', me); S.collection.objects.link(ob)
    ng = bpy.data.node_groups.new('Scatter', 'GeometryNodeTree')
    ng.interface.new_socket('Geometry', in_out='INPUT', socket_type='NodeSocketGeometry')
    ng.interface.new_socket('Geometry', in_out='OUTPUT', socket_type='NodeSocketGeometry')
    N_ = ng.nodes; L_ = ng.links
    gi = N_.new('NodeGroupInput'); go = N_.new('NodeGroupOutput')
    ci = N_.new('GeometryNodeCollectionInfo'); ci.inputs['Collection'].default_value = col; ci.inputs['Separate Children'].default_value = True; ci.inputs['Reset Children'].default_value = True
    iop = N_.new('GeometryNodeInstanceOnPoints'); iop.inputs['Pick Instance'].default_value = True
    rv = N_.new('FunctionNodeRandomValue'); rv.data_type = 'INT'; rv.inputs['Min'].default_value = 0; rv.inputs['Max'].default_value = 3
    rv.inputs['Max'].default_value = 1; L_.new(rv.outputs['Value'], iop.inputs['Instance Index'])
    rr = N_.new('FunctionNodeRandomValue'); rr.data_type = 'FLOAT_VECTOR'; rr.inputs['Min'].default_value = (0, 0, 0); rr.inputs['Max'].default_value = (0, 0, 6.28)
    L_.new(rr.outputs['Value'], iop.inputs['Rotation'])
    rs = N_.new('FunctionNodeRandomValue'); rs.data_type = 'FLOAT'; rs.inputs['Min'].default_value = 1.4; rs.inputs['Max'].default_value = 2.6
    ms = N_.new('ShaderNodeMath'); ms.operation = 'MULTIPLY'; L_.new(rs.outputs['Value'], ms.inputs[0]); gv = N_.new('ShaderNodeValue'); gv.outputs[0].default_value = 1; L_.new(gv.outputs[0], ms.inputs[1]); trees_di.grow = gv
    L_.new(ms.outputs[0], iop.inputs['Scale'])
    L_.new(gi.outputs['Geometry'], iop.inputs['Points']); L_.new(ci.outputs[0], iop.inputs['Instance'])
    L_.new(iop.outputs[0], go.inputs[0])
    mod = ob.modifiers.new('Scatter', 'NODES'); mod.node_group = ng
    trees_di.mod = mod; trees_di.ob = ob

# ================================================================ main
cam = make_camera()
BB, GOLD = build_bb() if KIND == 'bb' else build_di()
frames = D['frames']
if FR == 'all': idx = [i for i, f in enumerate(frames) if f['alpha'] > 0]
elif '-' in FR: a, b = map(int, FR.split('-')); idx = [i for i in range(a, b + 1) if frames[i]['alpha'] > 0]
else: idx = [int(x) for x in FR.split(',')]
import time
for i in idx:
    f = frames[i]; t = f['t']
    if not f['cam']: continue
    set_camera(cam, f['cam'])
    BB.update(t, f.get('hl'), GOLD)
    if KIND == 'di':
        for ob, I in BB.islands:
            p = clamp((t - I['at']) / I['dur']) if I['at'] is not None else 1
            ob.hide_render = p <= 0; ob.scale = (1, 1, max(.01, 1 - (1 - p) ** 3))
        # trees appear after their island
        g = max([clamp((t - I['at'] - .6) / 1.2) for _, I in BB.islands])
        trees_di.ob.hide_render = g <= 0
        trees_di.grow.outputs[0].default_value = max(g, .001)
    S.render.filepath = f'{OUT}/{i:05d}.png'
    t0 = time.time(); bpy.ops.render.render(write_still=True)
    print(f'frame {i} t={t:.2f} scene={f["scene"]} {time.time() - t0:.1f}s', flush=True)
if '--save' in sys.argv: bpy.ops.wm.save_as_mainfile(filepath=f'{OUT}/scene.blend')
