"""Build deterministic static web faces from the licensed, bundled Inter source.

Development only: fontTools + brotli. No network or runtime dependency.
Keep the complete source cmap/glyph set; do not subset away store languages.
"""
from pathlib import Path
import hashlib
import json

from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont

BRAND = Path(__file__).resolve().parents[1] / "assets" / "brand"
SOURCE = BRAND / "Inter-Variable.ttf"
WEIGHTS = (400, 500, 600, 700, 800)


def build():
    rows = []
    for weight in WEIGHTS:
        font = instantiateVariableFont(
            TTFont(SOURCE, recalcTimestamp=False), {"wght": weight, "opsz": 14},
            inplace=True, optimize=True, updateFontNames=True,
        )
        font.flavor = "woff2"
        # Buffer first: content-addressed names cannot retain an old variable
        # font cached under a reused URL after a release.
        from io import BytesIO
        stream = BytesIO()
        font.save(stream)
        data = stream.getvalue()
        digest = hashlib.sha256(data).hexdigest()
        name = f"VialCoveInter-{weight}-{digest[:12]}.woff2"
        (BRAND / name).write_bytes(data)
        rows.append({"weight": weight, "file": name, "bytes": len(data), "sha256": digest})
    result = {
        "version": 1, "family": "VialCoveInter", "source": SOURCE.name,
        "sourceSha256": hashlib.sha256(SOURCE.read_bytes()).hexdigest(),
        "opticalSize": 14, "license": "Inter_OFL.txt", "fonts": rows,
    }
    (BRAND / "web-fonts.json").write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    build()
