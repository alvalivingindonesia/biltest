import json, math, os
TEMP = os.environ.get('TEMP', '/tmp')
d = json.load(open(os.path.join(TEMP, 'lombok_nominatim.json'), encoding='utf-8'))
ring = None
for r in d:
    g = r.get('geojson', {})
    if g.get('type') == 'Polygon': ring = g['coordinates'][0]; break

def dp(points, eps):
    if len(points) < 3: return points
    def perp(p, a, b):
        ax, ay = a; bx, by = b; px, py = p; dx, dy = bx - ax, by - ay
        if dx == dy == 0: return math.hypot(px - ax, py - ay)
        t = ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy); t = max(0, min(1, t))
        return math.hypot(px - (ax + t * dx), py - (ay + t * dy))
    dmax, idx = 0, 0
    for i in range(1, len(points) - 1):
        di = perp(points[i], points[0], points[-1])
        if di > dmax: dmax, idx = di, i
    if dmax > eps: return dp(points[:idx + 1], eps)[:-1] + dp(points[idx:], eps)
    return [points[0], points[-1]]

if ring[0] == ring[-1]: ring = ring[:-1]
coast = dp(ring + [ring[0]], 0.0008)
if coast[0] == coast[-1]: coast = coast[:-1]

lons = [p[0] for p in coast]; lats = [p[1] for p in coast]
GILI = {'trawangan': (116.039, -8.350, 11), 'meno': (116.059, -8.352, 8), 'air': (116.083, -8.358, 8)}
minlon = min(min(lons), 116.02) - 0.03; maxlon = max(lons) + 0.02
minlat = min(lats) - 0.03; maxlat = max(lats) + 0.03
W, H = 880, 640
coslat = math.cos(math.radians((minlat + maxlat) / 2))
s = min(W / ((maxlon - minlon) * coslat), H / ((maxlat - minlat)))
ox = (W - (maxlon - minlon) * coslat * s) / 2; oy = (H - (maxlat - minlat) * s) / 2
def proj(lon, lat): return (round(ox + (lon - minlon) * coslat * s, 1), round(oy + (maxlat - lat) * s, 1))
pp = proj
P = [proj(lon, lat) for lon, lat in coast]

def path_d(pts, close=True):
    parts = ['M' + str(round(pts[0][0],1)) + ',' + str(round(pts[0][1],1))]
    for x, y in pts[1:]: parts.append('L' + str(round(x,1)) + ',' + str(round(y,1)))
    if close: parts.append('Z')
    return ' '.join(parts)

# all coastline-y crossings at column x
def coast_ys_at(x):
    ys = []
    n = len(P)
    for i in range(n):
        ax, ay = P[i]; bx, by = P[(i + 1) % n]
        if ax == bx: continue
        if (ax - x) * (bx - x) <= 0:
            t = (x - ax) / (bx - ax)
            ys.append(ay + t * (by - ay))
    return ys
# Trace straight DOWN from the marker to the first coastline below it (the local
# coast directly south), then sit just inside the land — like the user described.
def snap(x, y):
    ys = coast_ys_at(x)
    if not ys: return (round(x, 1), round(y, 1))
    below = [c for c in ys if c > y - 4]
    cy = min(below) if below else max(ys)
    return (round(x, 1), round(cy - 3, 1))

def nearest_idx(lon, lat):
    best, bi = 1e9, 0
    for i, (clon, clat) in enumerate(coast):
        dd = (clon - lon) ** 2 + (clat - lat) ** 2
        if dd < best: best, bi = dd, i
    return bi
J1 = nearest_idx(116.04, -8.43); J2 = nearest_idx(116.58, -8.36)
J3 = nearest_idx(116.53, -8.73); J4 = nearest_idx(116.09, -8.86)
def coast_arc(i, j, mc):
    n = len(coast); fwd = []; k = i
    while True:
        fwd.append(k)
        if k == j: break
        k = (k + 1) % n
    bwd = []; k = i
    while True:
        bwd.append(k)
        if k == j: break
        k = (k - 1) % n
    def mind(arc): return min((coast[k][0] - mc[0]) ** 2 + (coast[k][1] - mc[1]) ** 2 for k in arc)
    arc = fwd if mind(fwd) <= mind(bwd) else bwd
    return [P[k] for k in arc]

northEdge = [P[J1], pp(116.18, -8.50), pp(116.38, -8.49), pp(116.50, -8.45), P[J2]]
westEdge  = [P[J1], pp(116.16, -8.55), pp(116.18, -8.68), pp(116.13, -8.78), P[J4]]
southEdge = [P[J4], pp(116.18, -8.84), pp(116.33, -8.84), pp(116.44, -8.81), P[J3]]
ceEdge    = [pp(116.38, -8.49), pp(116.42, -8.62), pp(116.43, -8.74), pp(116.44, -8.81)]
north_poly = coast_arc(J1, J2, (116.30, -8.20)) + list(reversed(northEdge))[1:-1]
east_poly  = coast_arc(J2, J3, (116.72, -8.55)) + [southEdge[3]] + list(reversed(ceEdge))[1:] + [northEdge[3]]
south_poly = coast_arc(J3, J4, (116.30, -8.92)) + southEdge[1:-1]
west_poly  = coast_arc(J4, J1, (115.92, -8.75)) + westEdge[1:-1]
central_poly = [P[J1], northEdge[1], northEdge[2]] + ceEdge[1:] + [southEdge[2], southEdge[1], P[J4], westEdge[3], westEdge[2], westEdge[1]]
regions = {'north_lombok': north_poly, 'east_lombok': east_poly, 'central_lombok': central_poly, 'west_lombok': west_poly, 'south_lombok': south_poly}

def bbox(pts):
    xs = [p[0] for p in pts]; ys = [p[1] for p in pts]
    return [round(min(xs)), round(min(ys)), round(max(xs) - min(xs)), round(max(ys) - min(ys))]

out = {'viewBox': [0, 0, W, H], 'outline': path_d(P), 'regions': {}, 'areas': {}, 'places': {}, 'gili': []}
label_anchor = {'north_lombok': pp(116.32, -8.419), 'west_lombok': pp(116.075, -8.60),
    'central_lombok': pp(116.28, -8.715), 'east_lombok': pp(116.52, -8.555), 'south_lombok': pp(116.28, -8.875)}
for k, poly in regions.items():
    out['regions'][k] = {'d': path_d(poly), 'bbox': bbox(poly), 'label': list(label_anchor[k])}

gpts = []
for name, (lon, lat, r) in GILI.items():
    x, y = proj(lon, lat); gpts.append([x, y, r]); out['gili'].append([x, y, r])
gx = [g[0] for g in gpts]; gy = [g[1] for g in gpts]
out['regions']['gili_islands'] = {'circles': out['gili'],
    'bbox': [round(min(gx)) - 30, round(min(gy)) - 36, round(max(gx) - min(gx)) + 60, round(max(gy) - min(gy)) + 70],
    'label': [round(sum(gx) / 3), round(min(gy)) - 22]}

# Areas: (lon, lat, region, coastal). South coast areas snap to the coastline.
AREAS = {
    'selong_belanak': (116.158, -8.872, 'south_lombok', True),
    'mawi':           (116.166, -8.880, 'south_lombok', True),
    'mawun':          (116.232, -8.888, 'south_lombok', True),
    'are_guling':     (116.257, -8.892, 'south_lombok', True),
    'kuta':           (116.279, -8.885, 'south_lombok', True),
    'gerupuk':        (116.352, -8.900, 'south_lombok', True),
    'awang':          (116.385, -8.890, 'south_lombok', True),
    'ekas':           (116.460, -8.870, 'south_lombok', True),
    'senggigi':       (116.042, -8.493, 'west_lombok', False),
    'mataram':        (116.107, -8.583, 'west_lombok', False),
    'gerung':         (116.118, -8.685, 'west_lombok', False),
    'lembar':         (116.073, -8.725, 'west_lombok', False),
    'sekotong':       (115.975, -8.768, 'west_lombok', False),
    'praya':          (116.270, -8.705, 'central_lombok', False),
    'jonggat':        (116.220, -8.660, 'central_lombok', False),
    'batukliang':     (116.310, -8.610, 'central_lombok', False),
    'selong':         (116.531, -8.647, 'east_lombok', False),
    'labuhan_lombok': (116.658, -8.448, 'east_lombok', False),
    'bangsal':        (116.102, -8.404, 'north_lombok', False),
    'tanjung':        (116.157, -8.355, 'north_lombok', False),
    'senaru':         (116.404, -8.305, 'north_lombok', False),
    'gili_islands':   (116.059, -8.352, 'gili_islands', False),
}
for k, (lon, lat, r, coastal) in AREAS.items():
    x, y = proj(lon, lat)
    if coastal: x, y = snap(x, y)
    out['areas'][k] = {'p': [x, y], 'r': r}

# Places: (lon, lat, parent_area, coastal). Best-effort coordinates; coastal snap.
PLACES = {
    # selong_belanak bays — positions per Jon's annotated map (west->east):
    # Torok (W) , Serangan , [Selong Belanak] , Mekarsari (S headland) , Lancing , Tampah (E)
    'torok':       (116.115, -8.870, 'selong_belanak', True),
    'serangan':    (116.133, -8.864, 'selong_belanak', True),
    'mekarsari':   (116.190, -8.900, 'selong_belanak', True),
    'lancing':     (116.210, -8.892, 'selong_belanak', True),
    'tampah':      (116.222, -8.888, 'selong_belanak', True),
    # mawi (inlet, just E of Selong Belanak)
    'semeti':      (116.178, -8.884, 'mawi', True),
    'rowok':       (116.175, -8.882, 'mawi', True),
    # kuta (east of Kuta toward Gerupuk) — spread along the coast so labels fit
    'seger':       (116.295, -8.898, 'kuta', True),
    'tanjung_aan': (116.305, -8.902, 'kuta', True),
    'merese':      (116.318, -8.905, 'kuta', True),
    'mertak':      (116.315, -8.901, 'kuta', True),
    'bumbang':     (116.334, -8.899, 'kuta', True),
    # awang
    'gunung_tunak':(116.420, -8.905, 'awang', True),
    # ekas (SE peninsula)
    'pantai_surga':   (116.478, -8.878, 'ekas', True),
    'kaliantan':      (116.505, -8.918, 'ekas', True),
    'tanjung_ringgit':(116.548, -8.905, 'ekas', True),
    'pink_beach':     (116.535, -8.902, 'ekas', True),
    'jerowaru':       (116.492, -8.800, 'ekas', False),
}
for k, (lon, lat, ak, coastal) in PLACES.items():
    x, y = proj(lon, lat)
    if coastal: x, y = snap(x, y)
    out['places'][k] = {'p': [x, y], 'a': ak}

# ---- Cluster zones: clip the south coastal polygon into 3 longitudinal strips ----
def clip_x(poly, keep_ge=None, keep_le=None):
    def run(poly, inside, interp):
        o = []; n = len(poly)
        for i in range(n):
            cur = poly[i]; prv = poly[i - 1]
            ci = inside(cur); pi = inside(prv)
            if ci:
                if not pi: o.append(interp(prv, cur))
                o.append(cur)
            elif pi:
                o.append(interp(prv, cur))
        return o
    p = poly
    if keep_ge is not None:
        xm = keep_ge
        p = run(p, lambda q: q[0] >= xm, lambda a, b: (xm, a[1] + (xm - a[0]) / (b[0] - a[0]) * (b[1] - a[1])))
    if keep_le is not None:
        xm = keep_le
        p = run(p, lambda q: q[0] <= xm, lambda a, b: (xm, a[1] + (xm - a[0]) / (b[0] - a[0]) * (b[1] - a[1])))
    return p

SPLIT1, SPLIT2 = 405, 512   # bays|kuta , kuta|south_east  (405 < mawun x411 keeps mawun in kuta)
CLUSTERS = {
    'selong_belanak_bays': dict(members=['selong_belanak', 'mawi'], keep_le=SPLIT1),
    'kuta_mandalika':      dict(members=['are_guling', 'mawun', 'kuta', 'gerupuk'], keep_ge=SPLIT1, keep_le=SPLIT2),
    'south_east':          dict(members=['awang', 'ekas'], keep_ge=SPLIT2),
}
# Frame a set of dots at the map's aspect ratio, coast near the BOTTOM and the
# extra height filled with land to the NORTH (so little/no water shows below).
ASPECT = W / H
def aspect_box_north(xs, ys, padx, water_below, top_pad, pad_right=0):
    # pad_right adds room east of the dots for the diagonal labels, which ascend
    # up-right from each marker.
    x0 = min(xs) - padx; x1 = max(xs) + padx + pad_right; w = x1 - x0
    ybot = max(ys) + water_below; ytop = min(ys) - top_pad
    h = max(w / ASPECT, ybot - ytop)
    w2 = h * ASPECT
    x0 -= (w2 - w) / 2
    y0 = ybot - h
    return [round(x0, 1), round(y0, 1), round(w2, 1), round(h, 1)]

cl_out = {}
for ck, cfg in CLUSTERS.items():
    zone = clip_x(south_poly, cfg.get('keep_ge'), cfg.get('keep_le'))
    axs = [out['areas'][a]['p'][0] for a in cfg['members']]
    ays = [out['areas'][a]['p'][1] for a in cfg['members']]
    dotxs = list(axs); dotys = list(ays)
    for pk, pv in out['places'].items():
        if pv['a'] in cfg['members']:
            dotxs.append(pv['p'][0]); dotys.append(pv['p'][1])
    # Tight cluster zoom: just this cluster's dots, coast at the bottom.
    zoom = aspect_box_north(dotxs, dotys, 14, 12, 34, pad_right=34)
    # Label sits over the zone, with a small high/low/high stagger so the three
    # adjacent labels don't collide (they're too wide for one row).
    LABEL_DX = {'selong_belanak_bays': 14, 'kuta_mandalika': 0, 'south_east': -6}
    LABEL_DY = {'selong_belanak_bays': -22, 'kuta_mandalika': 8, 'south_east': -22}
    lx = round(sum(axs) / len(axs) + LABEL_DX.get(ck, 0), 1)
    ly = round(sum(ays) / len(ays) + LABEL_DY.get(ck, 0), 1)
    cl_out[ck] = {'d': path_d(zone), 'zoom': zoom, 'label': [lx, ly], 'members': cfg['members']}

out['_clusters'] = cl_out
# South overview frames the cluster ZONES (area markers), coast at the bottom.
sxs = [out['areas'][a]['p'][0] for a in ['selong_belanak','mawi','are_guling','mawun','kuta','gerupuk','awang','ekas']]
sys_ = [out['areas'][a]['p'][1] for a in ['selong_belanak','mawi','are_guling','mawun','kuta','gerupuk','awang','ekas']]
out['_clusterBox'] = aspect_box_north(sxs, sys_, 30, 16, 26)

# Whole-island starting view — tight to the coast (+ Gili). No aspect padding:
# the SVG meet-fits it, and a tight box keeps the island filling the frame.
ixs = [p[0] for p in P] + [g[0] for g in out['gili']]
iys = [p[1] for p in P] + [g[1] for g in out['gili']]
ix0, iy0 = min(ixs) - 6, min(iys) - 6
iw, ih = (max(ixs) + 6) - ix0, (max(iys) + 6) - iy0
out['_island'] = [round(ix0, 1), round(iy0, 1), round(iw, 1), round(ih, 1)]

json.dump(out, open(os.path.join(TEMP, 'lombok_map.json'), 'w'), indent=1)
print('coast pts:', len(coast), 'outline chars:', len(out['outline']))
print('south areas:')
for k in ['selong_belanak','mawi','are_guling','mawun','kuta','gerupuk','awang','ekas']:
    print('  ', k, out['areas'][k]['p'])
print('places:')
for k,v in out['places'].items(): print('  ', k, v['p'], '<-', v['a'])
print('clusterBox', out['_clusterBox'])
for ck,c in cl_out.items(): print('  ', ck, 'zoom', c['zoom'], 'label', c['label'])
print('done')
