# -*- coding: utf-8 -*-
from PIL import Image
import glob, os

PASTES = r'C:\Users\adamd\AppData\Local\Temp\freebuff-desktop-pastes'

def blue_bbox(im, thr=40):
    im = im.convert('RGB')
    px = im.load()
    xs, ys = [], []
    for y in range(im.height):
        for x in range(im.width):
            r, g, b = px[x, y]
            if b > r + thr and b > 80:
                xs.append(x)
                ys.append(y)
    if not xs:
        return None
    w = max(xs) - min(xs) + 1
    h = max(ys) - min(ys) + 1
    return {'w': w, 'h': h, 'ratio': round(w / h, 3), 'bbox': (min(xs), min(ys), max(xs), max(ys))}

files = sorted(glob.glob(os.path.join(PASTES, 'paste-*.png')))
for p in files:
    im = Image.open(p)
    name = os.path.basename(p)
    print(name, im.size)
    left = im.crop((0, 0, im.width // 3, im.height))
    print('   lava tretina – modra znacka:', blue_bbox(left))

# porovnaj nase assety
def mark_ratio(path, crop_frac=None):
    im = Image.open(path).convert('RGBA')
    if crop_frac:
        im = im.crop((0, 0, int(im.width * crop_fratio) if False else int(im.width * crop_frac), im.height))
    r = blue_bbox(im)
    return r

print('NASCELE znacka:', mark_ratio('Png/ElvosolarLogoCele.png', 0.33))
print('LIVE3x znacka :', mark_ratio('Png/_live_logo_3x.png', 0.33))
print('CAST plne     :', blue_bbox(Image.open('Png/ElvosolarLogoCast.png').convert('RGBA')))
