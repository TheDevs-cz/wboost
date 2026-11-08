"""Expand an OpenType variable font into one static font per named instance.

Usage: python3 -I expand_variable_font.py <fonttools.whl> <input font> <output dir>

Run by WBoost\\Web\\Services\\Font\\VariableFontExpander. Prints a JSON manifest
on stdout:

    {"faces": [{"file": "<abs path>", "postScriptName": "Inter-ExtraBold",
                "fullName": "Inter ExtraBold", "weight": 800, "italic": false}]}

Each output file is a plain static TTF (or CFF OTF for a CFF2 source) whose
name table is rewritten the way a foundry's own static cuts are named
(RIBBI: nameID 1/2 = "Inter" / "Bold"; anything else: nameID 1/2 =
"Inter ExtraBold" / "Regular" + typographic 16/17 = "Inter" / "ExtraBold"),
with OS/2 usWeightClass, fsSelection, head.macStyle and post.italicAngle set to
match. So the app's ordinary single-file upload path reads exactly what it reads
off a static cut uploaded one by one.

The input is untrusted user data: it is only ever parsed by fontTools, and this
script runs with `python3 -I` (isolated mode) from its own directory.
"""

import json
import os
import re
import sys

RIBBI = ("Regular", "Italic", "Bold", "Bold Italic")
WIN = (3, 1, 0x409)
MAC = (1, 0, 0)


def _load_fonttools(wheel):
    sys.path.insert(0, wheel)


def _name(font, name_id):
    if name_id is None or name_id == 0xFFFF:
        return None
    value = font["name"].getDebugName(name_id)
    return value.strip() if value and value.strip() else None


def _family(font):
    return _name(font, 16) or _name(font, 1) or "Font"


def _is_italic(subfamily, coordinates):
    if re.search(r"italic|oblique", subfamily, re.IGNORECASE):
        return True
    if coordinates.get("ital", 0) >= 0.5:
        return True
    return coordinates.get("slnt", 0) < 0


def _plan(font):
    """One entry per DISTINCT named instance (fvar order); the default
    instance alone when the font names none."""
    fvar = font["fvar"]
    defaults = {axis.axisTag: axis.defaultValue for axis in fvar.axes}
    planned = []
    seen = set()

    for instance in fvar.instances:
        subfamily = _name(font, instance.subfamilyNameID)
        if subfamily is None:
            continue
        coordinates = dict(defaults)
        coordinates.update(instance.coordinates)
        postscript = _name(font, getattr(instance, "postscriptNameID", 0xFFFF))
        key = subfamily.lower()
        if key in seen:
            continue
        seen.add(key)
        planned.append((subfamily, coordinates, postscript))

    if not planned:
        planned.append((_name(font, 17) or _name(font, 2) or "Regular", defaults, None))

    return planned


def _normalise_subfamily(subfamily, italic):
    words = subfamily.split()
    # "Italic" alone stays; "Regular Italic" reads "Italic" like a static cut.
    if italic and len(words) == 2 and words[0] == "Regular" and words[1] in ("Italic", "Oblique"):
        return words[1]
    return subfamily


def _set(name_table, name_id, value, platforms):
    name_table.removeNames(nameID=name_id)
    if value is None:
        return
    for platform in platforms:
        name_table.setName(value, name_id, *platform)


def _rename(font, family, subfamily, postscript, italic, weight):
    name_table = font["name"]
    platforms = [WIN]
    if any(record.platformID == 1 for record in name_table.names):
        platforms.append(MAC)

    is_ribbi = subfamily in RIBBI
    bold = subfamily in ("Bold", "Bold Italic")

    if is_ribbi:
        legacy_family, legacy_sub, typo_family, typo_sub = family, subfamily, None, None
    else:
        stem = re.sub(r"\s*\b(Italic|Oblique)\b", "", subfamily).strip()
        legacy_family = family + (" " + stem if stem else "")
        legacy_sub = "Italic" if italic else "Regular"
        typo_family, typo_sub = family, subfamily

    full = family + " " + subfamily
    if postscript is None:
        postscript = re.sub(r"[^A-Za-z0-9-]", "", family) + "-" + re.sub(r"[^A-Za-z0-9]", "", subfamily)
    postscript = postscript[:63]
    version = (_name(font, 5) or "").replace("Version ", "")

    _set(name_table, 1, legacy_family, platforms)
    _set(name_table, 2, legacy_sub, platforms)
    _set(name_table, 3, "%s;%s" % (version, postscript) if version else postscript, platforms)
    _set(name_table, 4, full, platforms)
    _set(name_table, 6, postscript, platforms)
    _set(name_table, 16, typo_family, platforms)
    _set(name_table, 17, typo_sub, platforms)
    for stale in (21, 22, 25):  # WWS + variations PostScript prefix
        _set(name_table, stale, None, platforms)

    os2 = font["OS/2"]
    os2.usWeightClass = weight
    selection = os2.fsSelection & ~(1 | (1 << 5) | (1 << 6))
    if italic:
        selection |= 1
    if bold:
        selection |= 1 << 5
    if not italic and not bold:
        selection |= 1 << 6
    os2.fsSelection = selection
    font["head"].macStyle = (1 if bold else 0) | (2 if italic else 0)

    return full, postscript


def _work(job):
    source, out_dir, family, subfamily, coordinates, postscript = job
    from fontTools.ttLib import TTFont
    from fontTools.varLib import instancer

    font = TTFont(source)
    is_cff = "CFF2" in font
    static = instancer.instantiateVariableFont(
        font, coordinates, inplace=True, static=True, downgradeCFF2=is_cff, updateFontNames=False,
    )
    for table in ("STAT", "avar", "fvar", "gvar", "HVAR", "VVAR", "MVAR", "cvar"):
        if table in static:
            del static[table]
    static.flavor = None

    italic = _is_italic(subfamily, coordinates)
    subfamily = _normalise_subfamily(subfamily, italic)
    weight = int(round(coordinates["wght"])) if "wght" in coordinates else int(static["OS/2"].usWeightClass)
    weight = max(1, min(1000, weight))
    if "slnt" in coordinates:
        static["post"].italicAngle = float(coordinates["slnt"])

    full, postscript = _rename(static, family, subfamily, postscript, italic, weight)
    path = os.path.join(out_dir, postscript + (".otf" if is_cff else ".ttf"))
    static.save(path)

    return {"file": path, "postScriptName": postscript, "fullName": full, "weight": weight, "italic": italic}


def main():
    if len(sys.argv) != 4:
        sys.stderr.write("usage: expand_variable_font.py <fonttools.whl> <font> <out dir>\n")
        return 2

    wheel, source, out_dir = sys.argv[1:4]
    _load_fonttools(wheel)
    from fontTools.ttLib import TTFont

    font = TTFont(source, lazy=True)
    if "fvar" not in font:
        sys.stderr.write("not a variable font\n")
        return 3

    family = _family(font)
    jobs = [(source, out_dir, family, sub, coords, ps) for sub, coords, ps in _plan(font)]
    font.close()

    workers = max(1, min(4, os.cpu_count() or 1, len(jobs)))
    if workers == 1:
        faces = [_work(job) for job in jobs]
    else:
        from concurrent.futures import ProcessPoolExecutor

        with ProcessPoolExecutor(max_workers=workers) as pool:
            faces = list(pool.map(_work, jobs))

    json.dump({"family": family, "faces": faces}, sys.stdout)
    return 0


if __name__ == "__main__":
    sys.exit(main())
