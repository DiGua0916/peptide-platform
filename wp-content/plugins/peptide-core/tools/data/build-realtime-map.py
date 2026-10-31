"""Deterministic, offline Natural Earth -> ES5 numeric SVG paths. Python stdlib only."""
import argparse
import hashlib
import json
import math
import re
from pathlib import Path

VERSION = 1
SOURCE = 'https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_110m_admin_0_countries.geojson'
TERMS = 'https://www.naturalearthdata.com/about/terms-of-use/'


def ring_points(ring):
    if not isinstance(ring, list) or len(ring) < 4 or ring[0] != ring[-1]:
        raise ValueError('Expected a closed ring with at least four vertices')
    out = []
    for point in ring:
        if not isinstance(point, list) or len(point) != 2 or any(isinstance(v, bool) or not isinstance(v, (int, float)) or not math.isfinite(v) for v in point):
            raise ValueError('Non-finite or malformed coordinate')
        x, y = point
        if not -180 <= x <= 180 or not -90 <= y <= 90:
            raise ValueError('Coordinate outside longitude/latitude bounds')
        # A GeoJSON edge joining both longitudes at a pole is the projection's
        # intentional bottom/top boundary, not a seam through a country. Keeping
        # it preserves the polar cap (notably Antarctica) down to latitude90.
        if out and not (abs(y) == 90 and out[-1][1] == y):
            while x - out[-1][0] > 180:
                x -= 360
            while x - out[-1][0] < -180:
                x += 360
        out.append((x, y))
    return out


def clip(points, boundary, keep_left):
    if not points:
        return []
    result = []
    previous = points[-1]
    previous_in = previous[0] >= boundary if keep_left else previous[0] <= boundary
    for current in points:
        current_in = current[0] >= boundary if keep_left else current[0] <= boundary
        if current_in != previous_in:
            ratio = (boundary - previous[0]) / (current[0] - previous[0])
            result.append((boundary, previous[1] + ratio * (current[1] - previous[1])))
        if current_in:
            result.append(current)
        previous, previous_in = current, current_in
    return result


def number(value):
    return ('%.3f' % (0 if abs(value) < 0.0005 else value)).rstrip('0').rstrip('.')


def ring_paths(ring):
    points = ring_points(ring)
    paths = []
    # Unwrap first, then clip translated copies; a seam never crosses the world.
    low = math.floor((min(p[0] for p in points) + 180) / 360)
    high = math.floor((max(p[0] for p in points) + 180) / 360)
    for shift in range(low, high + 1):
        candidate = [(x - shift * 360, y) for x, y in points[:-1]]
        candidate = clip(clip(candidate, -180, True), 180, False)
        if len(candidate) < 3:
            continue
        area = sum(candidate[i - 1][0] * p[1] - p[0] * candidate[i - 1][1] for i, p in enumerate(candidate))
        if abs(area) < 1e-10:
            continue
        projected = [(2 * (x + 180), 2 * (90 - y)) for x, y in candidate]
        paths.append('M' + 'L'.join(number(x) + ',' + number(y) for x, y in projected) + 'Z')
    return paths


def build(raw, expected_sha):
    digest = hashlib.sha256(raw).hexdigest()
    if digest != expected_sha:
        raise ValueError('Input SHA256 mismatch')
    data = json.loads(raw)
    if not isinstance(data, dict) or data.get('type') != 'FeatureCollection' or not isinstance(data.get('features'), list):
        raise ValueError('Expected FeatureCollection')
    paths, mapped, unmapped, rings = {}, 0, 0, 0
    for feature in data['features']:
        if not isinstance(feature, dict) or feature.get('type') != 'Feature':
            raise ValueError('Malformed feature')
        geometry = feature.get('geometry')
        if not isinstance(geometry, dict) or geometry.get('type') not in ('Polygon', 'MultiPolygon'):
            raise ValueError('Unsupported geometry')
        polygons = [geometry['coordinates']] if geometry['type'] == 'Polygon' else geometry['coordinates']
        if not isinstance(polygons, list) or not polygons:
            raise ValueError('Empty geometry')
        feature_paths = []
        for polygon in polygons:
            if not isinstance(polygon, list) or not polygon:
                raise ValueError('Empty polygon')
            for ring in polygon:
                rings += 1
                feature_paths.extend(ring_paths(ring))
        code = feature.get('properties', {}).get('ISO_A2_EH')
        if not isinstance(code, str):
            raise ValueError('Missing ISO_A2_EH')
        if not re.fullmatch(r'[A-Z]{2}', code):
            if code != '-99':
                raise ValueError('Malformed ISO code')
            unmapped += 1
            continue
        mapped += 1
        paths.setdefault(code, []).extend(feature_paths)
    payload = {'width': 720, 'height': 360, 'projection': 'equirectangular', 'paths': {code: ''.join(paths[code]) for code in sorted(paths)}}
    output = ('/* Generated offline by build-realtime-map.py v1; Natural Earth public domain. */\n(function(w){"use strict";w.PcDataRealtimeCountries=' + json.dumps(payload, separators=(',', ':'), ensure_ascii=True) + ';}(window));\n').encode('ascii')
    provenance = {'builder_version': VERSION, 'source_url': SOURCE, 'terms_url': TERMS, 'license': 'public domain', 'input_review_date': '2026-10-31', 'input_sha256': digest, 'projection': {'name': 'equirectangular', 'width': 720, 'height': 360, 'rounding_decimals': 3, 'antimeridian': 'unwrap, translate, clip'}, 'features': len(data['features']), 'mapped_features': mapped, 'unmapped_features': unmapped, 'country_codes': len(paths), 'rings': rings, 'fill_rule': 'evenodd', 'output_sha256': hashlib.sha256(output).hexdigest()}
    return output, provenance


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--input', required=True)
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--provenance', required=True)
    args = parser.parse_args()
    output, provenance = build(Path(args.input).read_bytes(), args.sha256)
    Path(args.output).write_bytes(output)
    destination = Path(args.provenance)
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(json.dumps(provenance, indent=2) + '\n', encoding='utf-8')
    print('PASS map: %d features / %d codes; deterministic SHA256 %s' % (provenance['features'], provenance['country_codes'], provenance['output_sha256']))


if __name__ == '__main__':
    main()
