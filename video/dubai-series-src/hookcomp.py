# EP2 opening: presenter over the Burj Khalifa backdrop; his wooden floor becomes the terrace deck.
import cv2, numpy as np, glob, os, sys
DECK = int(sys.argv[1]) if len(sys.argv) > 1 else 1512
plate = cv2.imread('burjplate.png').astype(np.float32) / 255
os.makedirs('hk/comp', exist_ok=True)
yy = np.arange(1920, dtype=np.float32)[:, None]
keep_floor = np.zeros_like(yy)                                       # photo plaza replaces his floor                    # soft seam at the deck edge
wrap_src = cv2.GaussianBlur(plate, (0, 0), 14)
for p in sorted(glob.glob('hk/frames/*.png'))[:190]:
    f = cv2.imread(p.replace('/frames/', '/sharp/')).astype(np.float32) / 255
    m = cv2.imread('hk/mat/' + os.path.basename(p), 0).astype(np.float32) / 255
    a = np.maximum(m, keep_floor)
    # match his indoor light to the daylight plate: slightly cooler and brighter
    fg = np.clip(f * np.array([.98, 1.02, 1.06], np.float32) * 1.06, 0, 1)
    edge = np.clip(cv2.GaussianBlur(m, (0, 0), 5) - m, 0, 1) * .22 + np.clip(m - cv2.erode(m, np.ones((5, 5), np.uint8)), 0, 1) * .15
    fg = fg + (wrap_src - fg) * edge[..., None] * (1 - keep_floor)[..., None]
    feet = np.where(m[1700:1900].max(1) > .5)[0]
    bg = plate.copy()
    if len(feet):                                                     # soft contact shadow under his shoes
        yb = 1700 + feet.max(); xs = np.where(m[yb - 10] > .5)[0]
        if len(xs):
            sh = np.zeros((1920, 1080), np.float32); cv2.ellipse(sh, (int(xs.mean()), yb), (int((xs.max() - xs.min()) * .75) + 20, 22), 0, 0, 360, .55, -1)
            sh = cv2.GaussianBlur(sh, (0, 0), 14); bg = bg * (1 - sh[..., None])
    out = fg * a[..., None] + bg * (1 - a[..., None])
    cv2.imwrite('hk/comp/' + os.path.basename(p), (np.clip(out, 0, 1) * 255).astype(np.uint8))
print('ok')
