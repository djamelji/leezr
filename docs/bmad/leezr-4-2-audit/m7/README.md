# Leezr 4.2 — M7 : exports temps, planning et absences (ADR-844, FUN-06)

**Où** : espace « Planning & temps » (Planning, Temps & pointages, Absences) → bouton **Exporter** (avec le droit `workforce.export`, le même que l'export des collaborateurs). Aucune rubrique de menu ajoutée.

**Parcours** : période (cette semaine, semaine dernière, ce mois-ci, mois dernier, personnalisée) · collaborateurs (tous ceux que l'on peut consulter, ou une sélection) · site (planning) · données → **aperçu** (filtres appliqués, fuseau horaire, nombre de lignes, avertissements, 15 premières lignes) → **Télécharger (CSV)** par type de données.

**Fichier** : CSV UTF-8 avec BOM (ouverture directe dans Excel), séparateur `;`, en-têtes et statuts dans la langue de l'interface, durées en **minutes entières** (et `hh:mm` pour le temps pointé), décimales des jours avec une virgule. Streamé à la volée : **aucun fichier stocké** sur le serveur, donc aucun lien temporaire à protéger ; réponse `Cache-Control: no-store`. Au plus 366 jours et 10 000 lignes par fichier.

## Colonnes

### Pointages (`pointages_<du>_<au>.csv`) — source : pointages enregistrés
| Colonne | Contenu |
|---|---|
| Date · Matricule · Nom · Prénom · Service | identification (aucun e-mail, téléphone, IBAN, numéro national) |
| Arrivée · Départ | heure locale de la société (fuseau du marché) ; Départ vide = pointage en cours |
| Pauses (min) · Travaillé (min) · Travaillé (hh:mm) | tels qu'enregistrés par la pointeuse (aucun recalcul) |
| Statut · Origine | non commencé / en cours / en pause / terminé ; web, borne… |

### Pauses (`pauses_…csv`) — Date · Matricule · Nom · Prénom · Début · Fin · Type (repas, repos, personnelle) · Durée (min)

### Prévu / réalisé (`prevu-realise_…csv`) — source : feuilles de temps (une ligne par jour)
| Colonne | Contenu |
|---|---|
| Prévu (min) | prévu retenu par la feuille de temps |
| Travaillé (min) | pointé retenu par la feuille de temps |
| Écart réalisé − prévu (min) | Travaillé − Prévu (simple soustraction des deux colonnes) |
| Pauses (min) · Heures sup. identifiées (min) · Absence (min) · Type d'absence · Jour de repos · Anomalies | tels que calculés par le moteur des feuilles de temps (non recalculés) |
| Statut de la feuille de temps · Validé | brouillon / soumise / approuvée / refusée / verrouillée ; **Validé = oui** seulement si approuvée ou verrouillée |

Une journée n'apparaît que si une feuille de temps existe pour elle (rien n'est reconstitué).

### Planning (`planning_…csv`) — Date · Matricule · Nom · Prénom · Site · Début · Fin (fuseau du créneau) · Fuseau horaire · Durée du créneau (min = fin − début) · Statut (brouillon / publié / réalisé / annulé)

### Absences et congés (`absences_…csv`) — demandes qui chevauchent la période (brouillons exclus)
Matricule · Nom · Prénom · Type d'absence · Du · Au · Heure de début · Heure de fin · Jours · Heures (tels qu'enregistrés à la demande, aucun droit recalculé) · Statut (en attente / approuvée / prise / refusée / annulée) · Décision le.

## Sécurité

- Serveur : `workforce.export` ; chaque type de données exige son module actif ; périmètre de visibilité du domaine (`WorkforceAccess`) : « voir tout » du domaine, sinon soi + rapports directs — même en forçant des collaborateurs dans la requête.
- Isolation : requêtes filtrées par la société en plus du scope ; une autre société → refus.
- Journal `workforce.time_exported` à chaque téléchargement : type, période, nombre de personnes filtrées, site, nombre de lignes — jamais le contenu.

## Recette (`tests/e2e/audit842-m7-recipe.spec.js`, 3/3, téléchargements réels)

Équipe entière (5 fichiers ouverts, en-têtes et valeurs attendues, première ligne du fichier = première ligne de l'aperçu) ; un collaborateur ; période sans données (bouton désactivé, message) ; cheffe d'équipe (ne voit que son équipe, même en forçant l'API) ; membre sans droit d'export (pas de bouton, API refusée) ; autre société (refus). Exemples de fichiers produits : `m7/exemple-*.csv`.

## Limites

- XLSX non proposé : aucune bibliothèque tableur n'est présente dans le projet (pas de dépendance ajoutée) ; le CSV UTF-8 avec BOM s'ouvre directement dans Excel / LibreOffice.
- Filtre « site » : appliqué au planning seulement (les pointages et absences ne portent pas de site).
- Heures validées : celles des feuilles de temps approuvées ; les jours sans feuille de temps générée n'apparaissent pas dans « Prévu / réalisé ».
