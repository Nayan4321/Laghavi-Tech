# python3 paint_ground.py <world.json> <outdir>
# Paints the ground albedo textures (sand, street grid, asphalt roads, parks, dense low-rise roofs)
# that sit under the 3D buildings. Two textures: wide (whole world) and detail (central area).
import json, sys, math, random
from PIL import Image, ImageDraw, ImageFilter
import numpy as np

W = json.load(open(sys.argv[1])); out = sys.argv[2]
rnd = random.Random(5)


def painter(rect, N):
    x0, y0, x1, y1 = rect
    sx, sy = N / (x1 - x0), N / (y1 - y0)
    return lambda p: ((p[0] - x0) * sx, (y1 - p[1]) * sy), sx


def noise(N, scale, seed):
    r = np.random.default_rng(seed)
    n = r.random((max(2, N // scale), max(2, N // scale))).astype(np.float32)
    return np.asarray(Image.fromarray((n * 255).astype(np.uint8)).resize((N, N), Image.BICUBIC), np.float32) / 255


def inpoly(x, y, poly):
    c = False; j = len(poly) - 1
    for i in range(len(poly)):
        xi, yi = poly[i]; xj, yj = poly[j]
        if (yi > y) != (yj > y) and x < (xj - xi) * (y - yi) / (yj - yi + 1e-9) + xi: c = not c
        j = i
    return c


def ribbon(pts, w):
    L, R = [], []
    for i in range(len(pts)):
        a = pts[max(i - 1, 0)]; b = pts[min(i + 1, len(pts) - 1)]
        dx, dy = b[0] - a[0], b[1] - a[1]; l = math.hypot(dx, dy) or 1; dx /= l; dy /= l
        L.append((pts[i][0] - dy * w / 2, pts[i][1] + dx * w / 2)); R.append((pts[i][0] + dy * w / 2, pts[i][1] - dx * w / 2))
    return L + R[::-1]


def paint_bb(rect, N, detail):
    pt, s = painter(rect, N)
    # warm desert sand with large/small variation
    n1, n2, n3 = noise(N, 512, 1), noise(N, 64, 2), noise(N, 8, 3)
    base = np.array([196, 160, 112], np.float32)
    v = (n1 - .5) * .22 + (n2 - .5) * .12 + (n3 - .5) * .08
    img = np.clip(base[None, None, :] * (1 + v[..., None]) + np.array([6, 0, -8]) * (n1[..., None] - .5) * 4, 0, 255)
    im = Image.fromarray(img.astype(np.uint8)); d = ImageDraw.Draw(im, 'RGBA')
    sea = W['water'][0]['poly']
    # dense low-rise roof blocks (the beige "carpet" of the reference), drawn as small boxes with a shadow edge
    step = 14 if detail else 22
    x0, y0, x1, y1 = rect
    gx = x0
    ang = math.radians(38)
    ca, sa = math.cos(ang), math.sin(ang)
    count = 0
    ys = np.arange(y0, y1, step); xs = np.arange(x0, x1, step)
    for gx in xs:
        for gy in ys:
            if rnd.random() < .18: continue
            # street grid every ~6 blocks
            u = gx * ca + gy * sa; w_ = -gx * sa + gy * ca
            if (u % 110) < 16 or (w_ % 95) < 14: continue
            p = (gx + rnd.uniform(-3, 3), gy + rnd.uniform(-3, 3))
            if abs(p[0]) < 900 and abs(p[1]) < 900 and not detail: continue
            if inpoly(p[0], p[1], sea): continue
            bw, bd = rnd.uniform(.45, .8) * step, rnd.uniform(.45, .8) * step
            c = rnd.choice([(214, 186, 140), (222, 196, 152), (206, 176, 128), (230, 208, 168), (200, 168, 120)])
            corners = [(-bw / 2, -bd / 2), (bw / 2, -bd / 2), (bw / 2, bd / 2), (-bw / 2, bd / 2)]
            P = [pt((p[0] + a * ca - b * sa, p[1] + a * sa + b * ca)) for a, b in corners]
            d.polygon([(q[0] + 2.2 * s * step / 14, q[1] + 1.4 * s * step / 14) for q in P], fill=(90, 70, 50, 90))
            d.polygon(P, fill=c + (255,))
            count += 1
    # parks
    for I in W['islands']:
        if I.get('park'):
            d.polygon([pt(p) for p in I['poly']], fill=(98, 134, 66, 255))
    # streets (central grid)
    for a, b in W.get('streets', []):
        d.line([pt(a), pt(b)], fill=(150, 136, 110, 110), width=max(1, int(3 * s)))
    # main roads: asphalt with light edge
    for r in W['roads']:
        w = r['w'] * 2.4
        d.polygon([pt(p) for p in ribbon(r['pts'], w + 3)], fill=(150, 146, 136, 255))
        d.polygon([pt(p) for p in ribbon(r['pts'], w)], fill=(62, 64, 66, 255))
        d.line([pt(p) for p in r['pts']], fill=(170, 170, 160, 255), width=max(1, int(.5 * s)))
    # water beds (dark), quay edge
    for wtr in W['water']:
        d.polygon([pt(p) for p in wtr['poly']], fill=(160, 150, 126, 255))
    im = im.filter(ImageFilter.GaussianBlur(.6))
    print('roof blocks', count)
    return im


def paint_di(rect, N):
    pt, s = painter(rect, N)
    n1, n2 = noise(N, 256, 1), noise(N, 16, 2)
    base = np.array([200, 186, 160], np.float32)
    img = np.clip(base * (1 + ((n1 - .5) * .2 + (n2 - .5) * .14)[..., None]), 0, 255)
    im = Image.fromarray(img.astype(np.uint8)); d = ImageDraw.Draw(im, 'RGBA')
    x0, y0, x1, y1 = rect
    for gx in np.arange(x0, x1, 12):
        for gy in np.arange(y0, y1, 12):
            if rnd.random() < .2: continue
            p = (gx, gy)
            c = rnd.choice([(214, 204, 186), (226, 218, 200), (196, 184, 162), (236, 230, 216)])
            q = pt(p); bw = rnd.uniform(4, 9) * s
            d.rectangle([q[0], q[1], q[0] + bw, q[1] + bw * rnd.uniform(.6, 1.2)], fill=c + (255,))
    for r in W['roads']:
        d.polygon([pt(p) for p in ribbon(r['pts'], r['w'] * 2.5)], fill=(70, 72, 74, 255))
    return im.filter(ImageFilter.GaussianBlur(.5))


if W['kind'] == 'bb':
    paint_bb([-4200, -4200, 4200, 4200], 6144, False).save(out + '/ground_wide.png')
    paint_bb([-1100, -1100, 1100, 1100], 6144, True).save(out + '/ground_detail.png')
else:
    paint_di([-1600, -1600, 1600, 1600], 4096).save(out + '/ground_wide.png')
