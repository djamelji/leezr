#!/usr/bin/env python3
"""Leezr 4.2 audit — compact per-profile summary of a crawl (issues only)."""
import json, sys
d = json.load(open(f'docs/bmad/leezr-4-2-audit/data/crawl/crawl-{sys.argv[1]}.json'))
print('nav', d['navEntries'], 'pages', len(d['pages']), 'left', d['queueLeft'], 'landing', d['landing'])
for p in d['pages']:
    api = sorted({f"{e['status']} {e['method']} {e['url'].split('?')[0]}" for e in p['errors'] if e['kind'] == 'api'})
    js = [e['text'][:90] for e in p['errors'] if e['kind'] in ('js', 'console')]
    warn = sum(1 for e in p['errors'] if e['kind'] == 'warn')
    red = p['finalPath'] and p['finalPath'].split('?')[0] != p['path'].split('?')[0]
    flags = []
    if api: flags.append('API ' + ' | '.join(api[:4]))
    if js: flags.append('JS ' + js[0])
    if warn: flags.append(f'warn×{warn}')
    if p.get('rawKeys'): flags.append('RAW ' + ','.join(p['rawKeys'][:3]))
    if red: flags.append('→ ' + p['finalPath'][:45])
    if p.get('hung'): flags.append('HUNG')
    te = [t['label'] for t in p.get('tabResults', []) if t.get('errors')]
    if te: flags.append('tabs KO: ' + ','.join(te[:4]))
    if flags: print(f"- {p['path'][:55]}: " + ' ; '.join(flags)[:330])
print('mobile overflow:', [m['path'] for m in d['mobile'] if m.get('overflowX')])
