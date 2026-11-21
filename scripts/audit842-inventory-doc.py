#!/usr/bin/env python3
"""Leezr 4.2 audit — writes 01-inventory.md from the collected data (re-runnable)."""
import json, collections, glob
D = 'docs/bmad/leezr-4-2-audit/data'
cat = json.load(open(f'{D}/catalog.json')); routes = json.load(open(f'{D}/api-routes.json')); pages = json.load(open(f'{D}/pages.json'))['pages']
m = json.load(open(f'{D}/matrix.json'))
out = []; w = out.append
w('# Leezr 4.2 — 01 · Inventaire\n')
w('> Audit du 2026-11-21 sur la base de recette isolée et marquée `leezr_audit842` (clone de la base locale), servie par `127.0.0.1:8842`. Données brutes : `data/` (pages.json, api-routes.json, catalog.json, crawl/, matrix.json, journeys.json, isolation.json). Régénérable : `scripts/audit842-*`.\n')
api = [r for r in routes if r['uri'].startswith('api/')]
sc = collections.Counter(p['scope'] for p in pages)
w('## 1. Chiffres clés\n')
w('| Élément | Nombre |\n|---|---|')
w(f"| Pages Vue (routes front) | {len(pages)} (Platform {sc['platform']}, Company {sc['company']}, publiques/auth {sc['public/auth']}) |")
w(f"| Routes API | {len(api)} (Platform {sum(1 for r in api if r['uri'].startswith('api/platform'))}, Company/publiques {sum(1 for r in api if not r['uri'].startswith('api/platform'))}) |")
w(f"| Modules déclarés | {len(cat['modules'])} (Platform {sum(1 for x in cat['modules'].values() if x['scope']=='admin')}, Company {sum(1 for x in cat['modules'].values() if x['scope']=='company')}) |")
w('| JobDomains | ' + ', '.join(f"{j['key']} ({'actif' if j['is_active'] else 'inactif'}, {'ouvert' if j['signup_available'] else 'fermé'} à l'inscription)" for j in cat['jobdomains']) + ' |')
w('| Plans | ' + ', '.join(f"{p['key']} (essai {p['trial_days']} j)" for p in cat['plans']) + ' |')
w('| Marchés | ' + ', '.join(x['key'] for x in cat['markets']) + ' |')
w(f"| Rôles Platform (base locale) | {len(cat['platform_roles'])} dont {sum(1 for r in cat['platform_roles'] if r['key'].startswith('e2e'))} résidus de campagnes E2E |")
w(f"| Permissions Platform | {len({p for x in cat['modules'].values() if x['scope']=='admin' for p in x['permissions']})} |")
w(f"| Permissions Company | {len({p for x in cat['modules'].values() if x['scope']=='company' for p in x['permissions']})} |")
w(f"| Sociétés dans le clone | {len(cat['companies'])} |\n")
w('## 2. Modules (manifestes)\n')
w('| Module | Portée | Type | Prérequis | Plan min. | Statut commercial | Entrées de menu | Nb permissions |\n|---|---|---|---|---|---|---|---|')
for k, x in sorted(cat['modules'].items(), key=lambda kv: (kv[1]['scope'], kv[0])):
    w(f"| `{k}` | {x['scope']} | {x['type']} | {', '.join(x['requires']) or '—'} | {x['minPlan'] or '—'} | {x['commercialStatus']} | {', '.join(n.get('key', '?') for n in x['navItems']) or '—'} | {len(x['permissions'])} |")
w('\n## 3. Menus effectivement servis (par profil testé)\n')
for f in sorted(glob.glob(f'{D}/crawl/nav-*.json')):
    tag = f.split('nav-')[1][:-5]; n = json.load(open(f))
    groups = [f"**{g.get('key')}** : {', '.join(i.get('title') or i.get('key') for i in g.get('items', []))}" for g in n.get('groups', [])]
    w(f"- `{tag}` — " + ' · '.join(groups))
w('\n## 4. Matrice de couverture (pages × profils)\n')
w("États : `OK` chargée sans erreur · `ERREUR-API` appel en échec (4xx hors 403) · `KO` erreur 5xx/JS · `403` refus constaté. « redirige » = alias historique ou page non permise. Les profils sont décrits dans 02/03 (§ Profils).\n")
w(open(f'{D}/matrix.md').read())
w('\n## 5. Pages non testées au navigateur, et pourquoi\n')
causes = {'/company/support/:id': 'ouverte par le parcours J5 (ticket créé puis affiché) — hors robot de navigation',
          '/company/workflows/:id': 'aucune règle d\'automatisation dans les données du clone',
          '/company/workforce/dsn/:id': 'aucune déclaration DSN dans les données',
          '/company/workforce/payroll/:id': 'aucune période de paie dans les données de la société 1',
          '/company/workforce/time/:id': 'redirigée vers la fiche personne (onglet temps)',
          '/platform/companies/create': 'testée par le parcours J1 (création complète réussie)',
          '/platform/support/:id': 'aucun ticket Platform avant le parcours J5',
          '/platform/presentation-lab': 'laboratoire `devOnly` (bloqué hors build de développement)',
          '/ui-lab': 'laboratoire `devOnly` (bloqué hors build de développement)'}
for k, v in sorted(m.items()):
    if not v['visits']:
        w(f"- `{k}` ({v['page']['scope']}) — {causes.get(k, 'non atteinte par le robot ; à couvrir dans la prochaine campagne')}")
open('docs/bmad/leezr-4-2-audit/01-inventory.md', 'w').write('\n'.join(out) + '\n')
print('ok')
