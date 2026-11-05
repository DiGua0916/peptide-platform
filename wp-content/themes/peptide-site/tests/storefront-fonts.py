"""Independent read-only verification of shipped static web fonts."""
from pathlib import Path
import hashlib
import json
import re
from io import BytesIO
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont
from fontTools.pens.recordingPen import RecordingPen

brand = Path(__file__).resolve().parents[1] / 'assets' / 'brand'
manifest = json.loads((brand / 'web-fonts.json').read_text(encoding='utf-8'))
source_path = brand / manifest['source']
source_hash = hashlib.sha256(source_path.read_bytes()).hexdigest()
assert source_hash == manifest['sourceSha256']
css = (brand.parent / 'brand.css').read_text(encoding='utf-8')
functions = (brand.parents[1] / 'functions.php').read_text(encoding='utf-8')
regular = next(row['file'] for row in manifest['fonts'] if row['weight'] == 400)
assert regular in functions and 'Inter-Variable.ttf' not in functions, 'Preload must request the face CSS actually uses'
rows = []
def cmap(font):
    return {(table.platformID, table.platEncID, table.format, table.language): table.cmap for table in font['cmap'].tables}
for row in manifest['fonts']:
    weight = row['weight']
    file = brand / row['file']
    data = file.read_bytes()
    digest = hashlib.sha256(data).hexdigest()
    assert len(data) == row['bytes'] and digest == row['sha256']
    assert row['file'] == f'VialCoveInter-{weight}-{digest[:12]}.woff2'
    face = re.search(r'@font-face\s*\{[^}]*' + re.escape(row['file']) + r'[^}]*\}', css).group(0)
    assert re.search(r'font-weight:\s*' + str(weight) + r'\s*;', face)
    font = TTFont(file, recalcTimestamp=False)
    expected = instantiateVariableFont(TTFont(source_path, recalcTimestamp=False), {'wght': weight, 'opsz': 14}, inplace=True, optimize=True, updateFontNames=True)
    # Instancing can leave fractional TrueType coordinates in memory; compile
    # once to apply the format's normal integer quantization before comparing.
    expected.flavor = 'woff2'
    stream = BytesIO(); expected.save(stream)
    assert hashlib.sha256(stream.getvalue()).hexdigest() == digest, f'Non-reproducible bytes {weight}'
    expected = TTFont(BytesIO(stream.getvalue()), recalcTimestamp=False)
    assert not {'fvar', 'gvar', 'HVAR', 'MVAR', 'avar'}.intersection(font.keys()), font.keys()
    assert font['OS/2'].usWeightClass == weight
    assert font['post'].italicAngle == 0
    assert font.getGlyphOrder() == expected.getGlyphOrder()
    assert cmap(font) == cmap(expected)
    assert font['hmtx'].metrics == expected['hmtx'].metrics
    actual_set, expected_set = font.getGlyphSet(), expected.getGlyphSet()
    for name in font.getGlyphOrder():
        actual_pen, expected_pen = RecordingPen(), RecordingPen()
        actual_set[name].draw(actual_pen)
        expected_set[name].draw(expected_pen)
        assert actual_pen.value == expected_pen.value, f'Outline mismatch {weight}: {name}'
    rows.append({'weight': weight, 'file': row['file'], 'bytes': len(data), 'glyphs': len(font.getGlyphOrder()), 'cmapCodepoints': len(font.getBestCmap()), 'cmapTables': len(font['cmap'].tables), 'allOutlinesEqual': True, 'allAdvanceAndLsbEqual': True, 'allCmapEqual': True, 'reproducedSha256': digest, 'variableTablesPresent': [], 'fontSubfamily': font['name'].getDebugName(2)})
result = {'evidence': 'Independent fontTools parse + re-instantiation of bundled source. No browser rendering acceptance.', 'sourceSha256': source_hash, 'opticalSize': 14, 'licensePresent': (brand / manifest['license']).is_file(), 'fonts': rows}

print(json.dumps(result, indent=2))
