#!/usr/bin/env python3
"""Lists the English text the app can show, for translation on the server.

Writes backend/resources/app/strings.json, which GET /api/v1/app-strings translates with the
website's translation memory (lang/{code}.json, Admin > Languages, AI). Run it after changing app
text:  python3 mobile/tool/extract_strings.py

Every plain string literal in lib/ that looks like words for people is collected (interpolated
strings like 'Hello $name' are skipped). Extra lines are harmless: the app only looks them up.
"""
import json
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[1]
OUT = ROOT.parent / 'backend' / 'resources' / 'app' / 'strings.json'
SKIP_FILES = {'lib/util/i18n.dart', 'lib/util/version.dart'}



ESCAPES = {'n': '\n', 't': '\t', "'": "'", '"': '"', '\\': '\\', '$': '$'}


def unescape(body: str) -> str:
    return re.sub(r'\\(.)', lambda m: ESCAPES.get(m.group(1), m.group(1)), body)


def human(s: str) -> bool:
    """Words for people, not keys, paths, URLs, formats or code."""
    t = ' '.join(s.split())
    if t != s or len(t) < 2 or len(t) > 600 or not re.search(r'[A-Za-z]{2,}', t):
        return False
    if re.match(r'^(https?:|www\.|app:|assets/|/|\.|#|package:|dart:|[a-z]+://)', t):
        return False
    if re.fullmatch(r'[a-z0-9_.\-/:]+', t):          # keys like image_url, puja-requests, en
        return False
    if re.fullmatch(r'[A-Za-z]+[A-Z][A-Za-z0-9]*', t) and ' ' not in t:   # camelCase identifiers
        return False
    if re.fullmatch(r'[yMdEhHmsaZ\-/:,. \'T]+', t):   # date patterns such as d MMM yyyy, h:mm a
        return False
    if re.search(r'[{}<>;=]|\(\)|=>', t) and not re.search(r'\{\d\}', t):
        return False
    return True


def literals(src: str):
    """Yields (is_raw, body) for every string literal, skipping comments; handles nested ${...}."""
    out = []

    def string(i: int, depth: int) -> int:
        raw = src[i] == 'r'
        if raw:
            i += 1
        q = src[i]
        triple = src.startswith(q * 3, i)
        start = i + (3 if triple else 1)
        j = start
        while j < len(src):
            if not raw and src[j] == '\\':
                j += 2
                continue
            if (triple and src.startswith(q * 3, j)) or (not triple and src[j] == q):
                if depth == 0 and not triple:
                    out.append((raw, src[start:j]))
                return j + (3 if triple else 1)
            if not triple and src[j] == '\n':
                return j
            if not raw and src.startswith('${', j):
                j = code(j + 2, depth + 1, '}')
                continue
            j += 1
        return j

    def code(i: int, depth: int, close: str | None) -> int:
        level = 0
        while i < len(src):
            c = src[i]
            if src.startswith('//', i):
                k = src.find('\n', i)
                i = len(src) if k < 0 else k
            elif src.startswith('/*', i):
                k = src.find('*/', i + 2)
                i = len(src) if k < 0 else k + 2
            elif c in '\'"' or (c == 'r' and src[i + 1:i + 2] in ('\'', '"') and not (src[i - 1:i].isalnum() or src[i - 1:i] == '_')):
                i = string(i, depth)
            elif close and c == '{':
                level += 1
                i += 1
            elif close and c == '}':
                if level == 0:
                    return i + 1
                level -= 1
                i += 1
            else:
                i += 1
        return i

    code(0, 0, None)
    return out


# Text built at run time from API codes (StatusChip turns "no_show" into "No show", humanize() likewise).
EXTRA = [
    'Confirmed', 'Completed', 'Cancelled', 'No show', 'Held', 'Paid', 'Pending payment', 'Scheduled', 'Performed',
    'Awaiting details', 'Awaiting photos', 'Submitted', 'Failed', 'Needs new photos', 'Rejected', 'Accepted',
    'Requested', 'Shipped', 'Delivered', 'Refunded', 'Partially refunded', 'New', 'Contacted', 'Open', 'Closed',
    'In preparation', 'Ready', 'Withdrawn', 'Under review', 'Audio', 'Video', 'In person', 'Chat', 'Report',
]


def main() -> None:
    found = set(EXTRA)
    for path in sorted((ROOT / 'lib').rglob('*.dart')):
        rel = path.relative_to(ROOT).as_posix()
        if rel in SKIP_FILES:
            continue
        for raw, body in literals(path.read_text(encoding='utf-8')):
            if not raw and re.search(r'(?<!\\)\$', body):
                continue  # interpolated: cannot be looked up as a whole
            s = body if raw else unescape(body)
            if human(s):
                found.add(s)
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(sorted(found), ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
    print(f'{len(found)} strings -> {OUT.relative_to(ROOT.parent)}')


if __name__ == '__main__':
    main()
