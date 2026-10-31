# python3 post.py <world.json> <in_dir> <out_dir> [frames]
# Aerial haze (distance to the ground plane along each camera ray) + tilt-shift blur bands,
# matching the depth-of-field band used by mg/index.html. Output keeps 1080x1240.
import json, sys, os, math
import numpy as np
from PIL import Image, ImageFilter

D = json.load(open(sys.argv[1])); IN, OUT = sys.argv[2], sys.argv[3]; os.makedirs(OUT, exist_ok=True)
W_, H_ = 1080, 1240
HAZE = np.array([214, 206, 192], np.float32) if D['kind'] == 'bb' else np.array([196, 214, 226], np.float32)
BLUR = 3.2 if D['kind'] == 'bb' else 4.5
uu, vv = np.meshgrid(np.arange(W_) + .5, np.arange(H_) + .5)

def ground_dist(c):
    yaw, pit = math.radians(c['yaw']), math.radians(c['pitch'])
    T = np.array([c['x'], c['y'], 0.]); dh = np.array([math.sin(yaw), math.cos(yaw), 0.])
    C = T - dh * c['dist'] * math.cos(pit) + np.array([0, 0, c['dist'] * math.sin(pit)])
    fwd = (T - C) / np.linalg.norm(T - C); right = np.cross(fwd, [0, 0, 1.]); right /= np.linalg.norm(right); up = np.cross(right, fwd)
    f = c.get('f', 1100); cx, cy = W_ / 2, H_ * c.get('cy', .5)
    d = fwd[None, None] + ((uu - cx) / f)[..., None] * right + (-(vv - cy) / f)[..., None] * up
    dz = d[..., 2]; tt = np.where(dz < -1e-4, -C[2] / np.minimum(dz, -1e-4), 1e6)
    return tt * np.linalg.norm(d, axis=-1)

frames = D['frames']
names = sorted(n for n in os.listdir(IN) if n.endswith('.png') and n[:5].isdigit())
if len(sys.argv) > 4: names = [f'{int(x):05d}.png' for x in sys.argv[4].split(',')]
for n in names:
    i = int(n[:5]); c = frames[i]['cam']
    im = np.asarray(Image.open(f'{IN}/{n}').convert('RGB'), np.float32)
    dist = ground_dist(c)
    k = 1 - np.exp(-np.maximum(dist - c['dist'] * .7, 0) / (c['dist'] * (5.5 if D['kind'] == 'bb' else 4.0)))
    k = np.clip(k, 0, .85)[..., None]
    im = im * (1 - k) + HAZE * k
    # gentle warm grade + vignette
    vig = 1 - .18 * (((uu - W_ / 2) / W_) ** 2 + ((vv - H_ * .55) / H_) ** 2)[..., None] * 2
    im = np.clip(im * vig, 0, 255)
    sharp = Image.fromarray(im.astype(np.uint8)); blur = sharp.filter(ImageFilter.GaussianBlur(BLUR))
    y = np.arange(H_) / H_
    m = np.clip(np.maximum((.36 - y) / .36, (y - .72) / .28), 0, 1) ** 1.2
    M = Image.fromarray((np.repeat(m[:, None], W_, 1) * 255).astype(np.uint8))
    Image.composite(blur, sharp, M).save(f'{OUT}/{n}')
    print('post', n)
