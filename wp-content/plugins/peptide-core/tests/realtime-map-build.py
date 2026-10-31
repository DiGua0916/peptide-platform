"""Real builder tests; no external downloads or third-party modules."""
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path

PLUGIN = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('builder', PLUGIN / 'tools/data/build-realtime-map.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
passed = 0


def check(name, condition):
    global passed
    assert condition, name
    passed += 1
    print('PASS ' + name)


def fixture(coordinates, kind='Polygon', code='ZZ'):
    return {'type': 'FeatureCollection', 'features': [{'type': 'Feature', 'properties': {'ISO_A2_EH': code}, 'geometry': {'type': kind, 'coordinates': coordinates}}]}


def run(data):
    raw = json.dumps(data).encode('utf-8')
    return builder.build(raw, hashlib.sha256(raw).hexdigest())


def rejects(name, data):
    try:
        run(data)
    except (ValueError, TypeError, KeyError):
        check(name, True)
        return
    check(name, False)


check('projection endpoints', builder.ring_paths([[-180, 90], [-170, 90], [-170, 80], [-180, 90]])[0].startswith('M0,0L20,0L20,20'))
cross = [[170, 10], [-170, 10], [-170, -10], [170, -10], [170, 10]]
paths = builder.ring_paths(cross)
check('antimeridian splits into two clipped parts', len(paths) == 2 and all('360,' not in path for path in paths))
check('antimeridian contains both map edges', any('720,' in path for path in paths) and any('M0,' in path or 'L0,' in path for path in paths))
polar = [[180, -80], [180, -90], [-180, -90], [-170, -75], [0, -70], [170, -75], [180, -80]]
polar_paths = builder.ring_paths(polar)
check('polar boundary keeps the complete cap at the map edge', len(polar_paths) == 1 and 'L720,360L0,360' in polar_paths[0])
outer = [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]]
hole = [[2, 2], [4, 2], [4, 4], [2, 4], [2, 2]]
output, provenance = run(fixture([outer, hole]))
payload = json.loads(output.decode('ascii').split('w.PcDataRealtimeCountries=', 1)[1].split(';}(window));', 1)[0])
check('holes retain independent subpaths and evenodd metadata', payload['paths']['ZZ'].count('Z') == 2 and provenance['rings'] == 2 and provenance['fill_rule'] == 'evenodd')
output, provenance = run(fixture([[outer], [hole]], 'MultiPolygon'))
check('all multipolygon parts retained', provenance['rings'] == 2 and b'ZZ' in output)
for value, name in [(math.nan, 'NaN'), (math.inf, 'Infinity'), (181, 'longitude range')]:
    invalid = [[0, 0], [value, 1], [1, 1], [0, 0]]
    rejects(name, fixture([invalid]))
rejects('latitude range', fixture([[[0, 0], [1, 91], [1, 1], [0, 0]]]))
rejects('unclosed ring', fixture([outer[:-1]]))
rejects('invalid ISO fails closed', fixture([outer], code='US\n'))
rejects('unsupported geometry', fixture([outer], kind='Point'))
_, p = run(fixture([outer], code='-99'))
check('non-ISO feature explicitly accounted', p['unmapped_features'] == 1 and p['mapped_features'] == 0)
raw = json.dumps(fixture([outer])).encode('utf-8')
try:
    builder.build(raw, '0' * 64)
except ValueError:
    check('digest mismatch rejected', True)
else:
    check('digest mismatch rejected', False)
input_path = Path(os.environ.get('PC031_MAP_INPUT', str(Path(__file__).resolve().parents[4] / 'plans/verification/031-execution/ne_110m_admin_0_countries.geojson')))
raw = input_path.read_bytes()
output, provenance = builder.build(raw, '6866c877d39cba9c357620878839b336d569f8c662d3cfab4cb1dbe2d39c977f')
check('reviewed full input counts', provenance['features'] == 177 and provenance['country_codes'] == 175 and provenance['unmapped_features'] == 2 and provenance['rings'] == 289)
check('generated asset byte-identical', output == (PLUGIN / 'assets/data-realtime-countries.js').read_bytes())
check('provenance byte-identical', json.dumps(provenance, indent=2) + '\n' == (PLUGIN / 'assets/maps/realtime-countries-provenance.json').read_text(encoding='utf-8'))
check('regeneration deterministic', builder.build(raw, provenance['input_sha256'])[0] == output)
print('TOTAL %d PASS, 0 FAIL' % passed)
