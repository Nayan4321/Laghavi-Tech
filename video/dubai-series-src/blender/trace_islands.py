# Trace the real Dubai Islands coastlines from the graded aerial (real/ep5r/ground_z15.png) -> islands_traced.json.
# blender --background --python blender/trace_islands.py -- <abs path to blender/real/ep5r>. Manual CUTS separate causeways;
# Elite Island main body was traced by hand (see islands_real.json / mg/islands5.js). Debug mask -> mask_dbg.png.
import bpy, json, sys, numpy as np
from collections import deque
D = sys.argv[sys.argv.index('--') + 1]
M = json.load(open(D + '/img.json')); r = [x for x in M['rings'] if x['z'] == 15][0]
img = bpy.data.images.load(D + '/ground_z15.png'); w, h = img.size
px = np.empty(w * h * 4, np.float32); img.pixels.foreach_get(px); px = px.reshape(h, w, 4)[::-1]
C = 2; g = px[:h // C * C, :w // C * C, :3].reshape(h // C, C, w // C, C, 3).mean((1, 3))
R, G, B = g[..., 0], g[..., 1], g[..., 2]; lum = .3 * R + .59 * G + .11 * B
land = ~((G > R + .04) & (lum < .45)); H, Wd = land.shape
sx, sy = (r['ne_m'][0] - r['sw_m'][0]) / Wd, (r['ne_m'][1] - r['sw_m'][1]) / H
def er(a, k):
    for _ in range(k):
        e = a.copy(); e[1:] &= a[:-1]; e[:-1] &= a[1:]; e[:, 1:] &= a[:, :-1]; e[:, :-1] &= a[:, 1:]; a = e
    return a
def di(a, k):
    for _ in range(k):
        e = a.copy(); e[1:] |= a[:-1]; e[:-1] |= a[1:]; e[:, 1:] |= a[:, :-1]; e[:, :-1] |= a[:, 1:]; a = e
    return a
# manual cuts (metres): causeways / necks joining islands to each other or to the mainland
CUTS = [((1250, 4450), (1350, 5150)),            # Oasis (C) | Shore (B) neck
        ((430, 2080), (760, 1860)),              # Central (A) | Shore (B) land neck west of the bay
        ((2350, 2480), (3150, 2760)),            # Elite (E) | mainland beach
        ((1490, 1560), (2100, 1560)), ((1490, 1600), (1700, 1750)),   # Shore (B) lobe | Central (A) NE corner (piers)
        ((-1950, 800), (-1650, 1150)),           # Golf (D) | Central (A)
        ((-2300, 150), (1300, 150))]             # Central (A) | Deira mainland (port strip)
for (xa, ya), (xb, yb) in CUTS:
    for t in np.linspace(0, 1, 4000):
        x, y = xa + (xb - xa) * t, ya + (yb - ya) * t; cy_, cx_ = int((r['ne_m'][1] - y) / sy), int((x - r['sw_m'][0]) / sx)
        land[max(0, cy_ - 2):cy_ + 3, max(0, cx_ - 2):cx_ + 3] = False
core = er(land, 4)
lab = np.zeros(land.shape, np.int32); n = 0; comps = []
tocell = lambda x, y: (int((r['ne_m'][1] - y) / sy), int((x - r['sw_m'][0]) / sx))
main = tocell(2000, -1500)
for y0 in range(H):
    for x0 in np.nonzero(core[y0] & (lab[y0] == 0))[0]:
        if lab[y0, x0]: continue
        n += 1; q = deque([(y0, x0)]); lab[y0, x0] = n; cnt = 0; edge = False
        while q:
            y, x = q.popleft(); cnt += 1
            if y in (0, H - 1) or x in (0, Wd - 1): edge = True
            for dy, dx in ((1, 0), (-1, 0), (0, 1), (0, -1)):
                yy, xx = y + dy, x + dx
                if 0 <= yy < H and 0 <= xx < Wd and core[yy, xx] and not lab[yy, xx]: lab[yy, xx] = n; q.append((yy, xx))
        comps.append((n, cnt, edge))
mainlab = lab[main]
NB = [(-1, 0), (-1, 1), (0, 1), (1, 1), (1, 0), (1, -1), (0, -1), (-1, -1)]
def trace(m):
    ys, xs = np.nonzero(m); i = np.lexsort((xs, ys))[0]; s = (ys[i], xs[i]); c = s; b = (s[0], s[1] - 1); pts = [s]
    for _ in range(400000):
        ib = NB.index((b[0] - c[0], b[1] - c[1]))
        for k in range(1, 9):
            idx = (ib + k) % 8; p = (c[0] + NB[idx][0], c[1] + NB[idx][1])
            if 0 <= p[0] < m.shape[0] and 0 <= p[1] < m.shape[1] and m[p]:
                pi = (idx - 1) % 8; b = (c[0] + NB[pi][0], c[1] + NB[pi][1]); c = p; break
        if c == s: break
        pts.append(c)
    return np.array(pts, float)
def rdp(P, eps):
    if len(P) < 3: return P
    a, b = P[0], P[-1]; ab = b - a; L = np.hypot(*ab) + 1e-9
    d = np.abs(ab[0] * (P[:, 1] - a[1]) - ab[1] * (P[:, 0] - a[0])) / L; i = int(np.argmax(d))
    if d[i] > eps: return np.vstack([rdp(P[:i + 1], eps)[:-1], rdp(P[i:], eps)])
    return np.vstack([a, b])
out = []
for k, cnt, edge in comps:
    area = cnt * sx * sy
    if area < 1e5 or edge: continue
    m0 = di(lab == k, 4) & land                                # restore the shape (opening), clipped to land
    seed = tuple(np.argwhere(lab == k)[0]); m = np.zeros_like(m0); q = deque([seed]); m[seed] = True
    while q:
        y, x = q.popleft()
        for dy, dx in ((1, 0), (-1, 0), (0, 1), (0, -1)):
            yy, xx = y + dy, x + dx
            if 0 <= yy < H and 0 <= xx < Wd and m0[yy, xx] and not m[yy, xx]: m[yy, xx] = True; q.append((yy, xx))
    pts = trace(m); P = np.stack([r['sw_m'][0] + (pts[:, 1] + .5) * sx, r['ne_m'][1] - (pts[:, 0] + .5) * sy], 1)
    j = int(np.argmax(np.hypot(*(P - P[0]).T))); P = np.vstack([rdp(P[:j + 1], 10.0)[:-1], rdp(np.vstack([P[j:], P[:1]]), 10.0)[:-1]])
    if abs(P[:, 0].mean()) > 9000 or P[:, 1].mean() < -4000 or P[:, 1].mean() > 12000: continue
    out.append({'main': bool(k == mainlab), 'area_km2': round(m.sum() * sx * sy / 1e6, 2), 'c': P.mean(0).round(0).tolist(), 'poly': P.round(1).tolist()})
    print('island', out[-1]['area_km2'], 'km2 centre', out[-1]['c'], 'pts', len(P), flush=True)
json.dump(out, open(D + '/islands_traced.json', 'w'))
# debug: save the land mask (after cuts) around Central/Shore
dbg = bpy.data.images.new('dbg', Wd, H); a = np.zeros((H, Wd, 4), np.float32); a[..., 3] = 1
a[..., 0] = land; a[..., 1] = core; a[..., 2] = (lab > 0) * ((lab * 37) % 255) / 255
dbg.pixels.foreach_set(a[::-1].ravel()); dbg.filepath_raw = D + '/mask_dbg.png'; dbg.file_format = 'PNG'; dbg.save()
print('cell m', sx, sy, 'origin', r['sw_m'], r['ne_m'])
