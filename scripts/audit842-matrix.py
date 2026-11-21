#!/usr/bin/env python3
"""Leezr 4.2 audit — builds the coverage matrix (pages × profiles) from the inventory and the browser crawls.
Read-only : inputs under docs/bmad/leezr-4-2-audit/data, output data/matrix.json + data/matrix.md."""
import json, glob, os, re, collections

D = 'docs/bmad/leezr-4-2-audit/data'
pages = json.load(open(f'{D}/pages.json'))['pages']
crawls = {}
for f in sorted(glob.glob(f'{D}/crawl/crawl-*.jsonl')):
    tag = os.path.basename(f)[6:-6]
    crawls[tag] = [json.loads(l) for l in open(f) if l.strip()]


def route_regex(path):
    parts = []
    for seg in path.strip('/').split('/'):
        if seg.endswith('(.*)'):
            parts.append('(?:/.*)?')
        elif seg.startswith(':') and seg.endswith('?'):
            parts.append('(?:/[^/]+)?')
        elif seg.startswith(':'):
            parts.append('/[^/]+')
        elif seg:
            parts.append('/' + re.escape(seg))
    return re.compile('^' + ''.join(parts) + '/?$')


routes = [(p, route_regex(p['path'])) for p in pages]


def match(path):
    path = (path or '').split('?')[0]
    best = None
    for p, rx in routes:
        if rx.match(path):
            rank = lambda q: (0 if '(.*)' in q['path'] else 1, sum(1 for seg in q['path'].split('/') if seg and not seg.startswith(':')), len(q['path']))
            if best is None or rank(p) > rank(best):
                best = p
    return best


matrix = {p['path']: {'page': p, 'visits': {}} for p in pages}
for tag, visits in crawls.items():
    for v in visits:
        target = match(v.get('finalPath') or v.get('path'))
        requested = match(v.get('path'))
        api = [e for e in v.get('errors', []) if e.get('kind') == 'api']
        js = [e for e in v.get('errors', []) if e.get('kind') in ('js', 'console')]
        warn = [e for e in v.get('errors', []) if e.get('kind') == 'warn']
        state = 'OK'
        if v.get('hung'):
            state = 'BLOQUÉ'
        elif any(e['status'] >= 500 for e in api) or js:
            state = 'KO'
        elif any(e['status'] == 403 for e in api) or v.get('forbidden'):
            state = '403'
        elif api:
            state = 'ERREUR-API'
        redirected = requested and target and requested['path'] != target['path']
        rec = {'requested': v.get('path'), 'final': v.get('finalPath'), 'state': state, 'redirected': bool(redirected), 'source': v.get('source'),
               'api_errors': [f"{e['status']} {e['method']} {e['url']}" for e in api][:6], 'js_errors': [e.get('text') for e in js][:4],
               'warnings': len(warn), 'tabs': v.get('tabs'), 'tab_errors': [t['label'] for t in v.get('tabResults', []) if t.get('errors')],
               'axe': v.get('axe'), 'rawKeys': v.get('rawKeys'), 'englishHints': v.get('englishHints'), 'empty': v.get('empty'), 'ms': v.get('ms'),
               'headings': v.get('headings', [])[:3], 'alerts': v.get('alerts', [])[:3], 'buttons': v.get('buttons', [])[:12]}
        for page_def in {id(x): x for x in (requested, target) if x}.values():
            if page_def['path'] in matrix:
                matrix[page_def['path']]['visits'].setdefault(tag, rec)

json.dump(matrix, open(f'{D}/matrix.json', 'w'), ensure_ascii=False, indent=1)

lines = ['| Page | Portée | Module | Permission | Profils testés | État | Problèmes |', '|---|---|---|---|---|---|---|']
for path, m in sorted(matrix.items(), key=lambda kv: (kv[1]['page']['scope'], kv[0])):
    p, vis = m['page'], m['visits']
    states = collections.Counter(r['state'] for r in vis.values())
    issues = set()
    for tag, r in vis.items():
        issues.update(r['api_errors'][:2]); issues.update(x for x in r['js_errors'][:1] if x)
        if r['redirected']:
            issues.add(f"redirige → {r['final']}")
    perm = (p.get('permission') or p.get('anyPermission') or '—').strip("'\"[] }")
    tested = ', '.join(sorted(vis)) if vis else 'NON TESTÉE'
    state = ' / '.join(f'{k}×{n}' for k, n in states.items()) if vis else '—'
    lines.append(f"| `{path}` | {p['scope']} | {(p.get('module') or '—').strip(chr(39))} | {perm[:40]} | {tested} | {state} | {'; '.join(sorted(issues))[:220] or '—'} |")
open(f'{D}/matrix.md', 'w').write('\n'.join(lines) + '\n')
print(len(matrix), 'pages ;', sum(1 for m in matrix.values() if m['visits']), 'visitées ;', len(crawls), 'profils')
