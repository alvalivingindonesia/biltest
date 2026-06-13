import json, math, os
TEMP = os.environ.get('TEMP', '/tmp')
d = json.load(open(os.path.join(TEMP, 'lombok_nominatim.json'), encoding='utf-8'))
ring = None
for r in d:
    g = r.get('geojson', {})
    if g.get('type') == 'Polygon':
        ring = g['coordinates'][0]; break

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
    if dmax > eps:
        return dp(points[:idx + 1], eps)[:-1] + dp(points[idx:], eps)
    return [points[0], points[-1]]

if ring[0] == ring[-1]: ring = ring[:-1]
coast = dp(ring + [ring[0]], 0.0008)   # finer detail than before (was 0.0035)
if coast[0] == coast[-1]: coast = coast[:-1]
print('coast pts:', len(coast))

lons = [p[0] for p in coast]; lats = [p[1] for p in coast]
GILI = {'trawangan': (116.039, -8.350, 11), 'meno': (116.059, -8.352, 8), 'air': (116.083, -8.358, 8)}
minlon = min(min(lons), 116.02) - 0.03
maxlon = max(lons) + 0.02
minlat = min(lats) - 0.03
maxlat = max(lats) + 0.03
W, H = 880, 640
coslat = math.cos(math.radians((minlat + maxlat) / 2))
s = min(W / ((maxlon - minlon) * coslat), H / ((maxlat - minlat)))
ox = (W - (maxlon - minlon) * coslat * s) / 2
oy = (H - (maxlat - minlat) * s) / 2
def proj(lon, lat): return (round(ox + (lon - minlon) * coslat * s, 1), round(oy + (maxlat - lat) * s, 1))
pp = proj
P = [proj(lon, lat) for lon, lat in coast]

def path_d(pts, close=True):
    parts = ['M' + str(pts[0][0]) + ',' + str(pts[0][1])]
    for x, y in pts[1:]: parts.append('L' + str(x) + ',' + str(y))
    if close: parts.append('Z')
    return ' '.join(parts)

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

out = {'viewBox': [0, 0, W, H], 'outline': path_d(P), 'regions': {}, 'areas': {}, 'gili': [], 'rinjani': None}
label_anchor = {'north_lombok': pp(116.32, -8.345), 'west_lombok': pp(116.075, -8.60),
    'central_lombok': pp(116.28, -8.645), 'east_lombok': pp(116.52, -8.555), 'south_lombok': pp(116.28, -8.875)}
for k, poly in regions.items():
    out['regions'][k] = {'d': path_d(poly), 'bbox': bbox(poly), 'label': list(label_anchor[k])}

gpts = []
for name, (lon, lat, r) in GILI.items():
    x, y = proj(lon, lat); gpts.append([x, y, r]); out['gili'].append([x, y, r])
gx = [g[0] for g in gpts]; gy = [g[1] for g in gpts]
out['regions']['gili_islands'] = {'circles': out['gili'],
    'bbox': [round(min(gx)) - 30, round(min(gy)) - 36, round(max(gx) - min(gx)) + 60, round(max(gy) - min(gy)) + 70],
    'label': [round(sum(gx) / 3), round(min(gy)) - 22]}

# CORRECTED coordinates (real west->east; Are Guling is WEST of Kuta). lp alternates.
AREAS = {
    'selong_belanak': (116.158, -8.872, 'south_lombok', 'top'),
    'mawi':           (116.192, -8.886, 'south_lombok', 'bottom'),
    'are_guling':     (116.218, -8.894, 'south_lombok', 'top'),
    'mawun':          (116.243, -8.888, 'south_lombok', 'bottom'),
    'kuta':           (116.279, -8.882, 'south_lombok', 'top'),
    'gerupuk':        (116.335, -8.896, 'south_lombok', 'bottom'),
    'awang':          (116.385, -8.880, 'south_lombok', 'top'),
    'ekas':           (116.460, -8.858, 'south_lombok', 'bottom'),
    'senggigi':       (116.042, -8.493, 'west_lombok', 'right'),
    'mataram':        (116.107, -8.583, 'west_lombok', 'right'),
    'gerung':         (116.118, -8.685, 'west_lombok', 'right'),
    'lembar':         (116.073, -8.725, 'west_lombok', 'bottom'),
    'sekotong':       (115.975, -8.768, 'west_lombok', 'bottom'),
    'praya':          (116.270, -8.705, 'central_lombok', 'right'),
    'jonggat':        (116.220, -8.660, 'central_lombok', 'top'),
    'batukliang':     (116.310, -8.610, 'central_lombok', 'right'),
    'selong':         (116.531, -8.647, 'east_lombok', 'left'),
    'labuhan_lombok': (116.658, -8.448, 'east_lombok', 'left'),
    'bangsal':        (116.102, -8.404, 'north_lombok', 'top'),
    'tanjung':        (116.157, -8.355, 'north_lombok', 'top'),
    'senaru':         (116.404, -8.305, 'north_lombok', 'bottom'),
    'gili_islands':   (116.059, -8.352, 'gili_islands', 'bottom'),
}
for k, (lon, lat, r, lp) in AREAS.items():
    x, y = proj(lon, lat)
    out['areas'][k] = {'p': [x, y], 'r': r, 'lp': lp}
out['rinjani'] = list(pp(116.457, -8.411))

# ---- Cluster geometry (South Lombok), computed from marker pixels ----
clusters = {
    'selong_belanak_bays': ['selong_belanak', 'mawi'],
    'kuta_mandalika': ['are_guling', 'mawun', 'kuta', 'gerupuk'],
    'south_east': ['awang', 'ekas'],
}
cl_out = {}
allx = []; ally = []
for ck, mem in clusters.items():
    xs = [out['areas'][m]['p'][0] for m in mem]; ys = [out['areas'][m]['p'][1] for m in mem]
    allx += xs; ally += ys
    px, py = 46, 40
    bb = [round(min(xs) - px), round(min(ys) - py), round((max(xs) - min(xs)) + px * 2), round((max(ys) - min(ys)) + py * 2)]
    cl_out[ck] = {'members': mem, 'bbox': bb, 'cx': round(sum(xs) / len(xs), 1), 'miny': round(min(ys), 1), 'maxy': round(max(ys), 1)}
# clusterBox = framing of all south markers
clusterBox = [round(min(allx) - 40), round(min(ally) - 45), round((max(allx) - min(allx)) + 80), round((max(ally) - min(ally)) + 95)]
# Pills: triangle stagger (left high, middle low, right high)
pills = {
    'selong_belanak_bays': [cl_out['selong_belanak_bays']['cx'], round(cl_out['selong_belanak_bays']['miny'] - 42, 1)],
    'kuta_mandalika':      [cl_out['kuta_mandalika']['cx'],      round(cl_out['kuta_mandalika']['maxy'] + 24, 1)],
    'south_east':          [cl_out['south_east']['cx'],          round(cl_out['south_east']['miny'] - 42, 1)],
}
out['_clusters'] = cl_out
out['_clusterBox'] = clusterBox
out['_pills'] = pills

json.dump(out, open(os.path.join(TEMP, 'lombok_map.json'), 'w'), indent=1)
print('outline chars:', len(out['outline']))
print('south markers:')
for k in ['selong_belanak','mawi','are_guling','mawun','kuta','gerupuk','awang','ekas']:
    print(' ', k, out['areas'][k])
print('clusterBox:', clusterBox)
for ck in clusters:
    print(' ', ck, 'bbox', cl_out[ck]['bbox'], 'pill', pills[ck], 'members', clusters[ck])
print('done')
