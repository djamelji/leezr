#!/usr/bin/env python3
"""ADR-862 — builds docs/bmad/leezr-5-design/captures/gallery/index.html from the captures and their metadata
(avant/*.json, apres/*.json written by tests/e2e/audit842-gallery862.spec.js). Local, static, no network."""
import html
import json
import pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent / 'docs/bmad/leezr-5-design/captures/gallery'

NOTES = {
    'p-dashboard': 'Cockpit V35 : salutation et « À traiter » en tête, tuiles KPI homogènes, santé en panneau sombre. « Facturé ce mois (HT) » garde son sens (corrigé en L7.12, le libellé L1 disait « Encaissé »).',
    'p-companies': 'Action « Nouvelle société » dans l’en-tête, une bande de 5 indicateurs à la place de 5 grandes cartes, segments sur la table.',
    'p-company': 'Nom et contexte en titre, retour vers la liste, 7 onglets sur toute la largeur sans flèches, bande d’indicateurs.',
    'p-jobdomains': 'Catalogue regroupé (Offre · Métiers · Données demandées), une phrase par page.',
    'p-jobdomain': 'En-tête avec retour vers le Catalogue et pastilles d’état.',
    'p-modules': 'Même famille Catalogue, groupes nommés.',
    'p-module': 'En-tête avec retour ; l’interrupteur global devient l’action de la page.',
    'p-billing': 'Facturation en 5 groupes, en-tête commun (L7 partiel).',
    'p-access': 'Famille « Accès Platform », une phrase par page, tiroirs avec fermeture nommée.',
    'p-operations': '7 onglets rangés en 4 groupes, titres internes en double retirés.',
    'p-catalog': 'Groupes nommés, titres internes en double retirés.',
    'c-dashboard': 'Accueil V22 : situation prouvée en tête, actions par module, « Moi » ensuite. Onglet du navigateur « Accueil · société ».',
    'c-people': 'Annuaire V22 avec aperçu, complétude du dossier par personne.',
    'c-person': 'En-tête V22 comme Personnes (nom en titre, retour dans le fil, état et rôle dessous, actions à droite) à la place de la carte à grand avatar ; onglets entiers sur téléphone ; activités déclarées sur la personne (conducteur occasionnel).',
    'c-planning': 'Titre propre « Planning » sous « Planning & temps » ; 3 actions d’en-tête au lieu de 5 (Exporter et Préférences dans « ⋯ »).',
    'c-time': 'Titre propre, filtres alignés, libellés à la place des indications grisées.',
    'c-leave': 'Navigation identique à Temps ; liste tactile sur téléphone.',
    'c-documents': 'En-tête commun ; « À traiter » d’abord ; sur téléphone, les quatre vues côte à côte avec leur libellé entier (la barre défilait et coupait la première).',
    'c-documents-compliance': 'Bande d’indicateurs, progression par type, export près des données, liste sur téléphone (le tableau était coupé).',
    'c-documents-templates': 'Type en clair (CDI), une action visible par modèle et les autres dans un menu nommé, avertissement juridique sans bandeau.',
    'c-documents-signatures': 'Supervision en mots simples, prestataire en clair, « Nouvelle demande » près de la recherche, liste tactile.',
    'c-payroll': 'En-tête commun (L7.10).',
    'c-shipments': 'En-tête commun ; liste des expéditions.',
}
VARIANTS = [('desktop-clair', 'Desktop · clair'), ('mobile-clair', 'Mobile · clair'), ('desktop-sombre', 'Desktop · sombre'), ('mobile-sombre', 'Mobile · sombre')]


def load(set_):
    rows = []
    for space in ('platform', 'company'):
        f = ROOT / set_ / f'{space}.json'
        rows += json.loads(f.read_text()) if f.exists() else []
    return rows


avant, apres = load('avant'), load('apres')
pages = []
for r in apres:
    if r['variant'] != 'desktop-clair':
        continue
    variants = [v for v, _ in VARIANTS if (ROOT / 'apres' / f"{r['id']}--{v}.jpg").exists()]
    before = {x['variant']: x for x in avant if x['id'] == r['id']}
    pages.append({'id': r['id'], 'space': r['space'], 'family': r['family'], 'path': r['path'], 'url': r['url'],
                  'title': r['title'], 'before_title': before.get('desktop-clair', {}).get('title', ''), 'variants': variants,
                  'note': NOTES.get(r['id'], '')})

data = json.dumps({'pages': pages, 'variants': VARIANTS}, ensure_ascii=False)
page = (pathlib.Path(__file__).resolve().parent / 'gallery862-template.html').read_text().replace('/*DATA*/null', data)
(ROOT / 'index.html').write_text(page)
print(f'{len(pages)} pages, index.html written')
