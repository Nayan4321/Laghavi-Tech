# remove the dark matte ghost left of his arm (frames ~10-40): dark pixels in the box that differ from the clean plate -> plate
import sys, glob, os, numpy as np
from PIL import Image, ImageFilter
plate = np.asarray(Image.open(sys.argv[1]).convert('RGB'), np.float32) / 255
X0, X1, Y0, Y1 = 230, 420, 860, 1300
P = plate[Y0:Y1, X0:X1]
for f in sorted(glob.glob('fr/*.png')):
    n = int(os.path.basename(f)[:4]) - 1
    if not (3 <= n <= 52): continue
    im = np.asarray(Image.open(f).convert('RGB'), np.float32) / 255
    R = im[Y0:Y1, X0:X1]; lum = R @ np.array([.3, .59, .11], np.float32); plum = P @ np.array([.3, .59, .11], np.float32)
    ghost = ((lum < .32) & (plum - lum > .14)).astype(np.float32)
    m = Image.fromarray((ghost * 255).astype(np.uint8)).filter(ImageFilter.MaxFilter(7)).filter(ImageFilter.GaussianBlur(4))
    a = np.asarray(m, np.float32)[..., None] / 255
    im[Y0:Y1, X0:X1] = R * (1 - a) + P * a
    Image.fromarray((np.clip(im, 0, 1) * 255 + .5).astype(np.uint8)).save(f)
print('cleaned')
