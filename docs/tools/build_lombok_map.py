import json, math, os

TEMP = os.environ.get('TEMP', '/tmp')
d = json.load(open(os.path.join(TEMP, 'lombok_nominatim.json'), encoding='utf-8'))
ring = None
for r in d:
    g = r.get('geojson', {})
    if g.get('type') == 'Polygon':
        ring = g['coordinates'][0]
        break
assert ring, 'no polygon'

# ---------- Douglas-Peucker simplification (lon/lat degrees) ----------
def dp(points, eps):
    if len(points) < 3:
        return points
    def perp(p, a, b):
        ax, ay = a; bx, by = b; px, py = p
        dx, dy = bx - ax, by - ay
        if dx == dy == 0:
            return math.hypot(px - ax, py - ay)
        t = ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy)
        t = max(0, min(1, t))
        return math.hypot(px - (ax + t * dx), py - (ay + t * dy))
    dmax, idx = 0, 0
    for i in range(1, len(points) - 1):
        dist = perp(points[i], points[0], points[-1])
        if dist > dmax:
            dmax, idx = dist, i
    if dmax > eps:
        left = dp(points[:idx + 1], eps)
        right = dp(points[idx:], eps)
        return left[:-1] + right
    return [points[0], points[-1]]

if ring[0] == ring[-1]:
    ring = ring[:-1]
coast = dp(ring + [ring[0]], 0.0035)
if coast[0] == coast[-1]:
    coast = coast[:-1]
print('coast pts:', len(coast))

# ---------- projection ----------
lons = [p[0] for p in coast]; lats = [p[1] for p in coast]
# include the Gilis on the left
GILI = {'trawangan': (116.039, -8.350, 11), 'meno': (116.059, -8.352, 8), 'air': (116.083, -8.358, 8)}
minlon = min(min(lons), 116.02) - 0.03
maxlon = max(lons) + 0.02
minlat = min(lats) - 0.03
maxlat = max(lats) + 0.03
W, H = 880, 640
coslat = math.cos(math.radians((minlat + maxlat) / 2))
sx = W / ((maxlon - minlon) * coslat)
sy = H / (maxlat - minlat)
s = min(sx, sy)
ox = (W - (maxlon - minlon) * coslat * s) / 2
oy = (H - (maxlat - minlat) * s) / 2
def proj(lon, lat):
    x = ox + (lon - minlon) * coslat * s
    y = oy + (maxlat - lat) * s
    return round(x, 1), round(y, 1)

P = [proj(lon, lat) for lon, lat in coast]

def path_d(pts, close=True):
    parts = ['M' + str(pts[0][0]) + ',' + str(pts[0][1])]
    for x, y in pts[1:]:
        parts.append('L' + str(x) + ',' + str(y))
    if close:
        parts.append('Z')
    return ' '.join(parts)

# ---------- region junctions on the coast (lat, lon) ----------
def nearest_idx(lon, lat):
    best, bi = 1e9, 0
    for i, (clon, clat) in enumerate(coast):
        dd = (clon - lon) ** 2 + (clat - lat) ** 2
        if dd < best:
            best, bi = dd, i
    return bi

J1 = nearest_idx(116.04, -8.43)   # North/West, west coast
J2 = nearest_idx(116.58, -8.36)   # North/East, NE coast
J3 = nearest_idx(116.53, -8.73)   # East/South, east coast
J4 = nearest_idx(116.09, -8.86)   # South/West, SW coast

def coast_arc(i, j, must_contain):
    """vertices i..j along the ring, choosing the direction whose arc passes
    nearest to must_contain (lon,lat)"""
    n = len(coast)
    fwd = []
    k = i
    while True:
        fwd.append(k)
        if k == j: break
        k = (k + 1) % n
    bwd = []
    k = i
    while True:
        bwd.append(k)
        if k == j: break
        k = (k - 1) % n
    def mind(arc):
        return min((coast[k][0] - must_contain[0]) ** 2 + (coast[k][1] - must_contain[1]) ** 2 for k in arc)
    arc = fwd if mind(fwd) <= mind(bwd) else bwd
    return [P[k] for k in arc]

pp = lambda lon, lat: proj(lon, lat)

# interior boundary polylines (projected)
northEdge = [P[J1], pp(116.18, -8.50), pp(116.38, -8.49), pp(116.50, -8.45), P[J2]]
westEdge  = [P[J1], pp(116.16, -8.55), pp(116.18, -8.68), pp(116.13, -8.78), P[J4]]
southEdge = [P[J4], pp(116.18, -8.84), pp(116.33, -8.84), pp(116.44, -8.81), P[J3]]
ceEdge    = [pp(116.38, -8.49), pp(116.42, -8.62), pp(116.43, -8.74), pp(116.44, -8.81)]

north_poly = coast_arc(J1, J2, (116.30, -8.20)) + list(reversed(northEdge))[1:-1]
east_poly  = coast_arc(J2, J3, (116.72, -8.55)) + [southEdge[3]] + list(reversed(ceEdge))[1:] + [northEdge[3]]
south_poly = coast_arc(J3, J4, (116.30, -8.92)) + southEdge[1:-1]
west_poly  = coast_arc(J4, J1, (115.92, -8.75)) + westEdge[1:-1][::-1] if False else coast_arc(J4, J1, (115.92, -8.75)) + westEdge[1:-1]
central_poly = northEdge[:3] [::-1]
# central: J1 -> northEdge(.18,.38) -> ceEdge down -> southEdge back to J4 -> westEdge back to J1
central_poly = [P[J1], northEdge[1], northEdge[2]] + ceEdge[1:] + [southEdge[2], southEdge[1], P[J4], westEdge[3], westEdge[2], westEdge[1]]

regions = {
    'north_lombok': north_poly,
    'east_lombok': east_poly,
    'central_lombok': central_poly,
    'west_lombok': west_poly,
    'south_lombok': south_poly,
}

def bbox(pts):
    xs = [p[0] for p in pts]; ys = [p[1] for p in pts]
    return [round(min(xs)), round(min(ys)), round(max(xs) - min(xs)), round(max(ys) - min(ys))]

out = {'viewBox': [0, 0, W, H], 'outline': path_d(P), 'regions': {}, 'areas': {}, 'gili': [], 'rinjani': None}

label_anchor = {
    'north_lombok': pp(116.32, -8.345),
    'west_lombok': pp(116.075, -8.60),
    'central_lombok': pp(116.28, -8.645),
    'east_lombok': pp(116.52, -8.555),
    'south_lombok': pp(116.28, -8.875),
}
for k, poly in regions.items():
    out['regions'][k] = {'d': path_d(poly), 'bbox': bbox(poly), 'label': list(label_anchor[k])}

# gili region
gpts = []
for name, (lon, lat, r) in GILI.items():
    x, y = proj(lon, lat)
    gpts.append([x, y, r])
    out['gili'].append([x, y, r])
gx = [g[0] for g in gpts]; gy = [g[1] for g in gpts]
out['regions']['gili_islands'] = {
    'circles': out['gili'],
    'bbox': [round(min(gx)) - 30, round(min(gy)) - 36, round(max(gx) - min(gx)) + 60, round(max(gy) - min(gy)) + 70],
    'label': [round(sum(gx) / 3), round(min(gy)) - 22],
}

AREAS = {
    'selong_belanak': (116.160, -8.868, 'south_lombok', 'diag'),
    'mawi':           (116.210, -8.882, 'south_lombok', 'diag'),
    'mawun':          (116.243, -8.886, 'south_lombok', 'diag'),
    'kuta':           (116.284, -8.889, 'south_lombok', 'diag'),
    'are_guling':     (116.318, -8.896, 'south_lombok', 'diag'),
    'tanjung_aan':    (116.305, -8.903, 'south_lombok', 'skip_dup'),
    'gerupuk':        (116.345, -8.901, 'south_lombok', 'diag'),
    'ekas':           (116.450, -8.851, 'south_lombok', 'left'),
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
# tanjung_aan too close to kuta+gerupuk: use 'top' placement instead of diag
for k, (lon, lat, r, lp) in AREAS.items():
    x, y = proj(lon, lat)
    out['areas'][k] = {'p': [x, y], 'r': r, 'lp': ('top' if lp == 'skip_dup' else lp)}

out['rinjani'] = list(pp(116.457, -8.411))

json.dump(out, open(os.path.join(TEMP, 'lombok_map.json'), 'w'), indent=1)
print('regions:', {k: len(v) for k, v in regions.items()})
print('outline chars:', len(out['outline']))
print('done ->', os.path.join(TEMP, 'lombok_map.json'))
