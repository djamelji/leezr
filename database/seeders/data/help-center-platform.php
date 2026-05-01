<?php

// Help Center — Platform audience (admin governance)
// Content for platform administrators and operators

return [
    'group' => [
        'title' => 'Administration Plateforme',
        'slug' => 'administration-plateforme',
        'icon' => 'tabler-settings-cog',
    ],
    'topics' => [
        //
        // ─── Topic 1: Gestion clients ───────────────────────────────
        //
        [
            'title' => 'Gestion clients',
            'slug' => 'gestion-clients',
            'description' => 'Supervisez le portefeuille clients : filtrer les entreprises, intervenir sur les comptes et piloter le cycle de vie.',
            'icon' => 'tabler-buildings',
            'articles' => [
                [
                    'title' => 'Surveiller le portefeuille clients',
                    'slug' => 'vue-ensemble-entreprises',
                    'excerpt' => 'Filtrez les entreprises par statut ou plan et identifiez les comptes nécessitant une intervention.',
                    'content' => '<p>Dans ce guide, vous allez apprendre à surveiller le portefeuille clients depuis le Hub Entreprises et identifier les comptes à risque.</p>
<h2>Situation</h2>
<p>Vous devez obtenir une vision claire du portefeuille clients pour préparer un reporting ou détecter des essais expirés, des comptes inactifs ou des anomalies de facturation.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir le Hub Entreprises</strong> — Dans le menu latéral plateforme, cliquez sur « Entreprises ». Le tableau paginé affiche nom, plan, statut et date d\'inscription.</li>
<li><strong>Lire les KPI</strong> — Les cartes en haut de page indiquent le total des entreprises, les actives, les essais en cours et le taux de churn mensuel.</li>
<li><strong>Filtrer par statut</strong> — Utilisez les filtres « Actif », « Essai », « Suspendu » ou « Résilié » pour isoler un segment. La barre de recherche trouve une entreprise par nom.</li>
<li><strong>Trier les colonnes</strong> — Cliquez sur les en-têtes pour trier par date de création, plan ou nombre d\'utilisateurs.</li>
<li><strong>Exporter en CSV</strong> — Cliquez sur le bouton d\'export pour générer un fichier du portefeuille filtré.</li>
</ol>
<h2>Résultat</h2>
<p>Vous disposez d\'une vue filtrée du portefeuille avec les KPI à jour, prête pour le reporting ou l\'action ciblée.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Total anormalement bas</strong> — Vérifiez qu\'aucun filtre n\'est actif. Le badge sur le bouton filtre indique le nombre de filtres appliqués.</li>
<li><strong>Confusion suspendu/résilié</strong> — Suspendu = réversible, données conservées. Résilié = suppression programmée.</li>
<li><strong>Essais non convertis invisibles</strong> — Filtrez par « Essai » et triez par date croissante pour voir les plus anciens en premier.</li>
</ul>',
                ],
                [
                    'title' => 'Consulter la fiche détaillée d\'une entreprise',
                    'slug' => 'consulter-detail-entreprise',
                    'excerpt' => 'Accédez aux onglets Profil, Membres, Modules et Facturation d\'un compte client.',
                    'content' => '<p>Dans ce guide, vous allez naviguer dans la fiche détaillée d\'une entreprise pour diagnostiquer un problème ou préparer une intervention.</p>
<h2>Situation</h2>
<p>Un client contacte le support ou vous devez auditer un compte avant une action administrative (suspension, ajustement tarifaire, escalade).</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir la fiche</strong> — Depuis le Hub Entreprises, cliquez sur le nom de l\'entreprise. L\'en-tête affiche le nom, le statut et le plan actuel.</li>
<li><strong>Onglet Profil</strong> — Vérifiez les coordonnées, le SIRET, le secteur d\'activité et les paramètres régionaux (langue, devise, fuseau horaire).</li>
<li><strong>Onglet Membres</strong> — Identifiez les utilisateurs actifs, invités ou désactivés et leur dernière connexion.</li>
<li><strong>Onglet Modules</strong> — Consultez les modules activés, leur date d\'activation et les statistiques d\'utilisation.</li>
<li><strong>Onglet Facturation</strong> — Vérifiez le plan, le montant mensuel, les impayés et l\'historique des factures.</li>
</ol>
<h2>Résultat</h2>
<p>Vous avez une vision 360° du client pour répondre au support ou prendre une décision administrative éclairée.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Utilisateur désactivé signalé comme bug</strong> — Vérifiez dans l\'onglet Membres si l\'admin de l\'entreprise a désactivé le compte lui-même.</li>
<li><strong>Modification de profil refusée</strong> — Certaines données (SIRET, raison sociale) ne peuvent être modifiées que par le client. Réservez les modifications admin aux cas documentés.</li>
<li><strong>Impayé non visible</strong> — Consultez toujours l\'onglet Facturation avant toute intervention sur un compte.</li>
</ul>',
                ],
                [
                    'title' => 'Gérer les modules d\'une entreprise',
                    'slug' => 'gerer-modules-entreprise',
                    'excerpt' => 'Activez ou désactivez des modules pour un client et vérifiez l\'impact sur sa facturation.',
                    'content' => '<p>Dans ce guide, vous allez activer ou désactiver des modules pour une entreprise cliente et contrôler l\'impact tarifaire.</p>
<h2>Situation</h2>
<p>Un client demande l\'accès à un nouveau module, ou vous devez retirer un module suite à un changement de plan ou une politique commerciale.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir l\'onglet Modules</strong> — Depuis la fiche de l\'entreprise, allez dans l\'onglet Modules. Chaque module affiche son état (activé/désactivé).</li>
<li><strong>Activer un module</strong> — Basculez le toggle. Le dialogue de confirmation indique le surcoût mensuel ajouté au prochain cycle.</li>
<li><strong>Vérifier les dépendances</strong> — Si un module dépend d\'un autre, le système vous propose d\'activer les prérequis automatiquement.</li>
<li><strong>Désactiver un module</strong> — Basculez le toggle. Si des données existent, un avertissement s\'affiche. Les données sont conservées 30 jours.</li>
<li><strong>Contrôler la facturation</strong> — Allez dans l\'onglet Facturation pour confirmer que le nouveau montant mensuel est correct.</li>
</ol>
<h2>Résultat</h2>
<p>Le module est activé ou désactivé, la facturation est mise à jour et le client a accès (ou non) aux fonctionnalités.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Module désactivé sans prévenir le client</strong> — La désactivation bloque immédiatement l\'accès. Informez toujours le client avant.</li>
<li><strong>Erreurs après désactivation d\'un module parent</strong> — Désactiver un module dont d\'autres dépendent provoque des erreurs côté client. Respectez les avertissements de dépendance.</li>
<li><strong>Tarif inchangé après activation</strong> — Le surcoût s\'applique au prochain cycle de facturation, pas rétroactivement.</li>
</ul>',
                ],
                [
                    'title' => 'Gérer le cycle de vie d\'un client',
                    'slug' => 'cycle-vie-client',
                    'excerpt' => 'Pilotez les transitions essai, activation, suspension et résiliation d\'une entreprise.',
                    'content' => '<p>Dans ce guide, vous allez piloter les transitions de statut d\'une entreprise cliente à chaque étape de son cycle de vie.</p>
<h2>Situation</h2>
<p>Vous devez intervenir sur le statut d\'un client : prolonger un essai, traiter une suspension automatique, ou initier une résiliation demandée par le client.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Identifier le statut actuel</strong> — Ouvrez la fiche de l\'entreprise. Le badge en haut affiche le statut : Essai, Actif, Suspendu ou Résilié.</li>
<li><strong>Prolonger un essai</strong> — Dans la section Trial, modifiez la date de fin d\'essai. Utile pour les prospects engagés qui demandent plus de temps.</li>
<li><strong>Traiter une suspension</strong> — Un compte suspendu pour impayé peut être réactivé via « Réactiver » après vérification du paiement dans l\'onglet Facturation.</li>
<li><strong>Initier une résiliation</strong> — Sélectionnez « Résilier » dans le menu d\'actions. Les données entrent en rétention 90 jours avant suppression définitive.</li>
<li><strong>Documenter l\'intervention</strong> — Ajoutez une note interne avec le motif et la date. Chaque transition est tracée dans le journal d\'activité.</li>
</ol>
<h2>Résultat</h2>
<p>Le statut du client est mis à jour, l\'action est tracée dans le journal et les autres administrateurs peuvent consulter l\'historique.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Réactivation sans vérifier le paiement</strong> — Le compte sera re-suspendu au prochain cycle automatique. Toujours confirmer le règlement d\'abord.</li>
<li><strong>Résiliation au lieu de suspension</strong> — La résiliation déclenche la suppression programmée. Utilisez la suspension pour les cas réversibles.</li>
<li><strong>Essais expirés non traités</strong> — Filtrez régulièrement par statut « Essai » dans le Hub Entreprises pour identifier les essais dépassés.</li>
</ul>',
                ],
                [
                    'title' => 'Corriger les données d\'une entreprise',
                    'slug' => 'actions-administratives-entreprise',
                    'excerpt' => 'Modifiez le profil, ajustez les paramètres et documentez les interventions exceptionnelles sur un compte.',
                    'content' => '<p>Dans ce guide, vous allez corriger les données d\'une entreprise cliente et documenter vos interventions administratives.</p>
<h2>Situation</h2>
<p>Un client signale une erreur dans son profil, ou vous devez corriger des données suite à un changement de raison sociale, d\'adresse ou de paramètres régionaux.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir l\'onglet Profil</strong> — Depuis la fiche de l\'entreprise, cliquez sur le bouton d\'édition dans l\'onglet Profil.</li>
<li><strong>Corriger les données</strong> — Modifiez le nom, l\'adresse, le SIRET ou les paramètres régionaux (langue, devise, fuseau horaire).</li>
<li><strong>Enregistrer</strong> — La modification est tracée dans le journal d\'activité avec votre identité administrateur.</li>
<li><strong>Ajouter une note interne</strong> — Documentez le motif de la correction dans le champ de notes internes. Indispensable pour l\'audit.</li>
<li><strong>Vérifier l\'impact</strong> — Si vous avez modifié la devise ou le fuseau horaire, vérifiez que la facturation et les automatisations ne sont pas impactées.</li>
</ol>
<h2>Résultat</h2>
<p>Les données sont corrigées, l\'action est tracée et les autres administrateurs peuvent consulter le motif de l\'intervention.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Modification non tracée</strong> — Chaque action manuelle doit être accompagnée d\'une note interne. Sans documentation, le contexte est perdu pour les autres admins.</li>
<li><strong>Changement de devise impactant la facturation</strong> — Modifier la devise d\'un compte actif peut créer des incohérences. Coordonnez avec le Hub Facturation.</li>
<li><strong>SIRET modifié sans justificatif</strong> — Exigez un justificatif (Kbis) avant de modifier le SIRET d\'une entreprise active.</li>
</ul>',
                ],
            ],
        ],
        //
        // ─── Topic 2: Facturation & revenus ────────────────────────────
        //
        [
            'title' => 'Facturation & revenus',
            'slug' => 'facturation-revenus',
            'description' => 'Pilotez la facturation, les plans tarifaires, les paiements et les indicateurs de revenus depuis le Hub Facturation.',
            'icon' => 'tabler-report-money',
            'articles' => [
                [
                    'title' => 'Surveiller les revenus depuis le Hub Facturation',
                    'slug' => 'tableau-bord-financier',
                    'excerpt' => 'Lisez les KPI financiers et identifiez les tendances de MRR, churn et impayés.',
                    'content' => '<p>Dans ce guide, vous allez lire les indicateurs financiers du Hub Facturation pour piloter les revenus de la plateforme.</p>
<h2>Situation</h2>
<p>Vous préparez un reporting mensuel ou vous devez détecter rapidement une baisse de MRR, une hausse des impayés ou un pic de résiliations.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir le Hub Facturation</strong> — Dans le menu latéral plateforme, cliquez sur « Facturation ». Le tableau de bord s\'affiche avec les KPI principaux.</li>
<li><strong>Lire le MRR</strong> — La carte MRR (Monthly Recurring Revenue) affiche le revenu mensuel récurrent actuel et sa tendance sur les 3 derniers mois.</li>
<li><strong>Vérifier les impayés</strong> — La carte Impayés indique le montant total en souffrance et le nombre de comptes concernés. Cliquez pour voir le détail.</li>
<li><strong>Analyser le churn</strong> — Le taux de churn mensuel compare les résiliations au portefeuille actif. Une hausse soudaine nécessite une investigation.</li>
<li><strong>Consulter les prélèvements planifiés</strong> — L\'onglet Prélèvements planifiés liste les prochains débits avec leur date et montant.</li>
</ol>
<h2>Résultat</h2>
<p>Vous avez une vision claire des revenus, des risques financiers et des prélèvements à venir pour prendre des décisions éclairées.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>MRR en baisse sans résiliation visible</strong> — Vérifiez les changements de plan (downgrade) qui réduisent le revenu sans churn.</li>
<li><strong>Impayé non détecté</strong> — Les impayés apparaissent après l\'échec des tentatives de relance automatiques. Consultez ce KPI quotidiennement.</li>
<li><strong>Écart entre MRR et prélèvements</strong> — Les coupons, avoirs et ajustements manuels créent des écarts normaux. Vérifiez dans le détail.</li>
</ul>',
                ],
                [
                    'title' => 'Configurer les plans et tarifs',
                    'slug' => 'gerer-plans-tarifs',
                    'excerpt' => 'Créez, modifiez ou archivez des plans tarifaires et définissez les modules inclus.',
                    'content' => '<p>Dans ce guide, vous allez configurer les plans tarifaires proposés aux entreprises clientes.</p>
<h2>Situation</h2>
<p>Vous lancez une nouvelle offre commerciale, vous ajustez les prix existants, ou vous devez archiver un plan obsolète sans impacter les clients actuels.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir la gestion des plans</strong> — Depuis le Hub Facturation, allez dans l\'onglet « Plans ». La liste affiche tous les plans avec leur prix, fréquence et nombre de souscripteurs.</li>
<li><strong>Créer un plan</strong> — Cliquez sur « Nouveau plan ». Renseignez le nom, le prix mensuel, la fréquence de facturation et sélectionnez les modules inclus.</li>
<li><strong>Modifier un plan existant</strong> — Cliquez sur un plan pour l\'éditer. Les modifications de prix s\'appliquent aux nouveaux souscripteurs uniquement (les clients existants conservent leur tarif).</li>
<li><strong>Archiver un plan</strong> — Utilisez « Archiver » pour retirer un plan du catalogue sans impacter les clients actuels. Un plan archivé n\'est plus proposé aux nouveaux clients.</li>
<li><strong>Vérifier la cohérence</strong> — Après toute modification, vérifiez que les modules inclus correspondent à l\'offre commerciale et que le prix est cohérent avec la grille tarifaire.</li>
</ol>
<h2>Résultat</h2>
<p>Le catalogue de plans est à jour, les nouveaux clients voient l\'offre correcte et les clients existants ne sont pas impactés.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Prix modifié impactant les clients existants</strong> — Les modifications de prix ne s\'appliquent qu\'aux nouveaux souscripteurs. Pour ajuster un client existant, utilisez un changement de plan individuel.</li>
<li><strong>Plan archivé encore visible</strong> — Videz le cache après archivage si le plan apparaît encore dans le formulaire d\'inscription.</li>
<li><strong>Module oublié dans un plan</strong> — Vérifiez la liste des modules inclus après chaque modification. Un module manquant génère des tickets de support.</li>
</ul>',
                ],
                [
                    'title' => 'Traiter les paiements et prélèvements',
                    'slug' => 'traiter-paiements-prelevements',
                    'excerpt' => 'Suivez les prélèvements, relancez les paiements échoués et régularisez les impayés.',
                    'content' => '<p>Dans ce guide, vous allez suivre les prélèvements automatiques et intervenir sur les paiements en échec.</p>
<h2>Situation</h2>
<p>Un prélèvement a échoué, un client signale un débit inattendu, ou vous devez vérifier que les prélèvements du mois sont complets.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Consulter les prélèvements planifiés</strong> — Depuis le Hub Facturation, ouvrez l\'onglet « Prélèvements planifiés ». La liste affiche les débits à venir avec leur date, montant et statut.</li>
<li><strong>Identifier les échecs</strong> — Les prélèvements échoués sont marqués en rouge. Cliquez sur un échec pour voir le motif (carte expirée, fonds insuffisants, erreur technique).</li>
<li><strong>Relancer un prélèvement</strong> — Utilisez le bouton « Relancer » sur un paiement échoué. Le système retente le débit avec le moyen de paiement enregistré.</li>
<li><strong>Vérifier le moyen de paiement</strong> — Si la relance échoue, ouvrez la fiche de l\'entreprise pour vérifier que le moyen de paiement est à jour.</li>
<li><strong>Documenter l\'intervention</strong> — Ajoutez une note sur la fiche client avec le détail de l\'intervention (relance, contact client, régularisation).</li>
</ol>
<h2>Résultat</h2>
<p>Les paiements en échec sont identifiés, relancés ou escaladés, et chaque intervention est documentée.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Relance échouée deux fois</strong> — Après deux échecs, contactez le client pour mettre à jour son moyen de paiement avant de relancer.</li>
<li><strong>Débit en double</strong> — Vérifiez l\'historique des transactions dans l\'onglet Facturation du client avant de relancer. Un avoir peut être nécessaire.</li>
<li><strong>Suspension automatique après impayé</strong> — Le système suspend automatiquement après les tentatives de relance. Prévenez le client avant que la suspension ne prenne effet.</li>
</ul>',
                ],
                [
                    'title' => 'Émettre un avoir ou appliquer un coupon',
                    'slug' => 'emettre-avoir-appliquer-coupon',
                    'excerpt' => 'Créez un avoir pour rembourser partiellement un client ou appliquez un coupon de réduction.',
                    'content' => '<p>Dans ce guide, vous allez émettre un avoir ou appliquer un coupon sur le compte d\'une entreprise cliente.</p>
<h2>Situation</h2>
<p>Un client demande un remboursement partiel suite à une panne, ou vous devez appliquer une réduction commerciale accordée par l\'équipe commerciale.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir la facturation du client</strong> — Depuis la fiche de l\'entreprise, allez dans l\'onglet Facturation.</li>
<li><strong>Émettre un avoir</strong> — Cliquez sur « Créer un avoir ». Indiquez le montant, le motif et la facture de référence. L\'avoir est déduit du prochain prélèvement.</li>
<li><strong>Appliquer un coupon</strong> — Utilisez « Appliquer un coupon » et saisissez le code. Le système affiche la réduction (pourcentage ou montant fixe) et la durée d\'application.</li>
<li><strong>Vérifier l\'impact</strong> — Consultez le prochain prélèvement planifié pour confirmer que l\'avoir ou le coupon est bien pris en compte.</li>
<li><strong>Documenter le motif</strong> — Ajoutez une note interne précisant le contexte (ticket support, accord commercial, geste compensatoire).</li>
</ol>
<h2>Résultat</h2>
<p>L\'avoir ou le coupon est appliqué sur le compte, le prochain prélèvement reflète l\'ajustement et le motif est documenté.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Avoir supérieur au prochain prélèvement</strong> — L\'excédent est reporté sur les prélèvements suivants. Le solde d\'avoir est visible dans l\'onglet Facturation.</li>
<li><strong>Coupon expiré</strong> — Vérifiez la date de validité du coupon avant de l\'appliquer. Un coupon expiré est rejeté silencieusement.</li>
<li><strong>Double application</strong> — Le système empêche d\'appliquer deux fois le même coupon. Si le client insiste, vérifiez l\'historique des coupons appliqués.</li>
</ul>',
                ],
                [
                    'title' => 'Suivre les indicateurs de revenus',
                    'slug' => 'suivre-indicateurs-revenus',
                    'excerpt' => 'Analysez MRR, ARR, ARPU et churn pour piloter la croissance de la plateforme.',
                    'content' => '<p>Dans ce guide, vous allez analyser les indicateurs de revenus pour identifier les leviers de croissance et les risques financiers.</p>
<h2>Situation</h2>
<p>Vous préparez un comité de direction, un reporting investisseur, ou vous devez comprendre l\'évolution des revenus sur une période donnée.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir les analytics financiers</strong> — Depuis le Hub Facturation, consultez la section Indicateurs. Les graphiques affichent l\'évolution du MRR et de l\'ARR.</li>
<li><strong>Analyser le MRR</strong> — Le MRR se décompose en : nouveau MRR (nouveaux clients), expansion (upgrades), contraction (downgrades) et churn (résiliations).</li>
<li><strong>Calculer l\'ARPU</strong> — Le revenu moyen par utilisateur (ARPU) est affiché par plan. Une baisse d\'ARPU indique un glissement vers les plans moins chers.</li>
<li><strong>Surveiller le churn revenue</strong> — Le churn revenue mesure le revenu perdu par les résiliations. Comparez-le au nouveau MRR pour évaluer la croissance nette.</li>
<li><strong>Exporter les données</strong> — Utilisez l\'export pour générer un rapport CSV des indicateurs sur la période sélectionnée.</li>
</ol>
<h2>Résultat</h2>
<p>Vous disposez d\'une analyse complète des revenus avec les métriques SaaS standard, prête pour le reporting de direction.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>MRR en hausse mais churn élevé</strong> — La croissance masque les résiliations. Analysez la décomposition MRR pour voir le ratio acquisition/perte.</li>
<li><strong>ARPU biaisé par les comptes gratuits</strong> — Filtrez par plans payants uniquement pour obtenir un ARPU significatif.</li>
<li><strong>Données incomplètes sur le mois en cours</strong> — Les indicateurs du mois en cours sont partiels. Comparez toujours des mois complets pour les tendances.</li>
</ul>',
                ],
            ],
        ],
        //
        // ─── Topic 3: Modules & catalogue ──────────────────────────────
        //
        [
            'title' => 'Modules & catalogue',
            'slug' => 'modules-catalogue',
            'description' => 'Administrez le catalogue de modules, les métiers, les champs personnalisés et les types de documents.',
            'icon' => 'tabler-apps',
            'articles' => [
                [
                    'title' => 'Surveiller le catalogue de modules',
                    'slug' => 'vue-ensemble-catalogue',
                    'excerpt' => 'Consultez les modules disponibles, leur taux d\'adoption et leur état d\'activation global.',
                    'content' => '<p>Dans ce guide, vous allez surveiller le catalogue de modules pour comprendre quels modules sont adoptés et lesquels nécessitent une action.</p>
<h2>Situation</h2>
<p>Vous devez évaluer la pertinence du catalogue, identifier les modules sous-utilisés ou préparer le lancement d\'un nouveau module.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir le Hub Modules</strong> — Dans le menu latéral plateforme, cliquez sur « Modules ». La page liste tous les modules du catalogue avec leur statut et leurs statistiques.</li>
<li><strong>Lire les métriques d\'adoption</strong> — Pour chaque module : nombre d\'entreprises l\'ayant activé, pourcentage d\'adoption par rapport au portefeuille total et tendance mensuelle.</li>
<li><strong>Identifier les modules inactifs</strong> — Les modules avec un taux d\'adoption inférieur à 10 % méritent une analyse : problème d\'onboarding, manque de visibilité ou fonctionnalité non pertinente.</li>
<li><strong>Vérifier les dépendances</strong> — Consultez le graphe de dépendances pour comprendre quels modules nécessitent d\'autres modules comme prérequis.</li>
<li><strong>Comparer avec les plans</strong> — Vérifiez que chaque plan inclut les modules cohérents avec l\'offre commerciale.</li>
</ol>
<h2>Résultat</h2>
<p>Vous avez une vision claire de l\'adoption des modules et des actions à mener sur le catalogue.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Module activé mais non utilisé</strong> — Un taux d\'activation élevé avec une utilisation faible indique un problème d\'onboarding, pas de catalogue.</li>
<li><strong>Dépendance circulaire</strong> — Si deux modules dépendent mutuellement l\'un de l\'autre, signalez le problème à l\'équipe technique.</li>
<li><strong>Module manquant dans un plan</strong> — Comparez le catalogue avec les plans pour détecter les oublis de configuration.</li>
</ul>',
                ],
                [
                    'title' => 'Configurer un module',
                    'slug' => 'configurer-module',
                    'excerpt' => 'Modifiez les paramètres, les dépendances et la visibilité d\'un module du catalogue.',
                    'content' => '<p>Dans ce guide, vous allez configurer les paramètres d\'un module pour ajuster son comportement et sa disponibilité.</p>
<h2>Situation</h2>
<p>Vous lancez un nouveau module, vous modifiez les paramètres par défaut d\'un module existant, ou vous devez restreindre l\'accès à un module en bêta.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir la fiche du module</strong> — Depuis le Hub Modules, cliquez sur le module à configurer. La page affiche ses paramètres, dépendances et statistiques.</li>
<li><strong>Modifier les paramètres</strong> — Ajustez le nom affiché, la description, l\'icône et les paramètres par défaut. Ces valeurs s\'appliquent aux nouvelles activations.</li>
<li><strong>Gérer les dépendances</strong> — Ajoutez ou retirez des dépendances. Un module dépendant ne peut être activé que si ses prérequis sont actifs.</li>
<li><strong>Définir la visibilité</strong> — Marquez le module comme « Disponible », « Bêta » ou « Masqué ». Seuls les modules disponibles apparaissent dans le catalogue client.</li>
<li><strong>Enregistrer et vérifier</strong> — Après la sauvegarde, vérifiez que le module apparaît correctement dans le catalogue et que les dépendances sont cohérentes.</li>
</ol>
<h2>Résultat</h2>
<p>Le module est configuré selon vos spécifications, visible ou masqué selon le statut choisi et ses dépendances sont à jour.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Module bêta visible par tous</strong> — Vérifiez que le statut est bien « Bêta » et non « Disponible ». Le statut Bêta restreint l\'accès aux comptes autorisés.</li>
<li><strong>Dépendance ajoutée sur un module déjà activé seul</strong> — Les entreprises ayant déjà le module sans le prérequis ne sont pas impactées rétroactivement. Traitez ces cas manuellement.</li>
<li><strong>Description non mise à jour côté client</strong> — Videz le cache après modification des textes pour que les changements soient visibles immédiatement.</li>
</ul>',
                ],
                [
                    'title' => 'Gérer les métiers (jobdomains)',
                    'slug' => 'gerer-metiers-jobdomains',
                    'excerpt' => 'Créez et organisez les métiers pour structurer l\'offre par secteur d\'activité.',
                    'content' => '<p>Dans ce guide, vous allez gérer les métiers (jobdomains) qui structurent l\'offre Leezr par secteur d\'activité.</p>
<h2>Situation</h2>
<p>Vous ouvrez Leezr à un nouveau secteur d\'activité, vous devez renommer un métier existant, ou vous réorganisez la hiérarchie des métiers.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir le Hub Métiers</strong> — Dans le menu latéral plateforme, cliquez sur « Métiers ». La liste affiche tous les métiers avec le nombre d\'entreprises associées.</li>
<li><strong>Créer un métier</strong> — Cliquez sur « Nouveau métier ». Renseignez le nom, le slug, la description et l\'icône. Le métier devient disponible au choix lors de l\'inscription.</li>
<li><strong>Modifier un métier</strong> — Cliquez sur un métier pour l\'éditer. Modifiez le nom ou la description sans impacter les entreprises déjà associées.</li>
<li><strong>Associer des modules par défaut</strong> — Définissez les modules activés par défaut pour les nouvelles entreprises de ce métier. Cela accélère l\'onboarding.</li>
<li><strong>Archiver un métier</strong> — Archivez un métier obsolète. Les entreprises existantes conservent leur association mais le métier n\'est plus proposé aux nouvelles inscriptions.</li>
</ol>
<h2>Résultat</h2>
<p>Le catalogue de métiers est à jour, les nouveaux clients trouvent leur secteur d\'activité et les modules par défaut accélèrent l\'onboarding.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Métier sans entreprise associée</strong> — Un métier à 0 entreprise est soit nouveau, soit non pertinent. Évaluez avant de le conserver ou l\'archiver.</li>
<li><strong>Doublon de métier</strong> — Deux métiers similaires (ex: « Transport » et « Transport routier ») créent de la confusion. Fusionnez en conservant le plus utilisé.</li>
<li><strong>Modules par défaut inadaptés</strong> — Vérifiez régulièrement que les modules par défaut correspondent aux besoins réels du secteur en analysant l\'utilisation.</li>
</ul>',
                ],
                [
                    'title' => 'Configurer les champs personnalisés',
                    'slug' => 'configurer-champs-personnalises',
                    'excerpt' => 'Définissez des champs sur mesure pour adapter les formulaires aux besoins de chaque métier.',
                    'content' => '<p>Dans ce guide, vous allez créer et configurer des champs personnalisés pour enrichir les formulaires métier.</p>
<h2>Situation</h2>
<p>Un secteur d\'activité nécessite des informations spécifiques non couvertes par les champs standards (numéro de licence, certificat, code métier, etc.).</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir la gestion des champs</strong> — Depuis le Hub Modules, accédez à « Champs personnalisés ». La liste affiche tous les champs créés avec leur type, module et métier associé.</li>
<li><strong>Créer un champ</strong> — Cliquez sur « Nouveau champ ». Choisissez le type (texte, nombre, date, liste déroulante, fichier), le module cible et le métier associé.</li>
<li><strong>Configurer la validation</strong> — Définissez les règles : obligatoire/optionnel, longueur min/max, format attendu (email, téléphone, code postal).</li>
<li><strong>Positionner dans le formulaire</strong> — Indiquez l\'ordre d\'affichage et le groupe de champs. Le champ apparaît dans le formulaire du module ciblé pour les entreprises du métier concerné.</li>
<li><strong>Tester</strong> — Connectez-vous sur un compte test du métier concerné et vérifiez que le champ apparaît correctement dans le formulaire.</li>
</ol>
<h2>Résultat</h2>
<p>Le champ personnalisé est disponible dans les formulaires du module ciblé pour les entreprises du métier sélectionné.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Champ invisible dans le formulaire</strong> — Vérifiez que le métier de l\'entreprise test correspond au métier associé au champ et que le module cible est activé.</li>
<li><strong>Validation trop stricte</strong> — Un format de validation inadapté bloque la saisie côté client. Testez avec des données réelles avant de déployer.</li>
<li><strong>Champ obligatoire ajouté rétroactivement</strong> — Rendre un champ obligatoire après coup bloque les formulaires existants tant que le champ n\'est pas rempli. Préférez « optionnel » pour les champs ajoutés sur des modules déjà en production.</li>
</ul>',
                ],
                [
                    'title' => 'Gérer les types de documents',
                    'slug' => 'gerer-types-documents',
                    'excerpt' => 'Créez et configurez les types de documents disponibles par métier.',
                    'content' => '<p>Dans ce guide, vous allez gérer les types de documents disponibles dans le module Documents pour chaque métier.</p>
<h2>Situation</h2>
<p>Un nouveau secteur nécessite des types de documents spécifiques (CMR, lettre de voiture, bon de livraison) ou un type existant doit être modifié.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir la gestion des types</strong> — Depuis le Hub Modules, accédez à « Types de documents ». La liste affiche tous les types avec leur métier, le nombre de documents créés et le statut.</li>
<li><strong>Créer un type</strong> — Cliquez sur « Nouveau type ». Renseignez le nom, le slug, le métier associé et les champs requis (expéditeur, destinataire, date, référence, etc.).</li>
<li><strong>Configurer le template</strong> — Définissez le modèle de numérotation (préfixe, compteur, format de date) et les sections du document.</li>
<li><strong>Associer aux métiers</strong> — Sélectionnez les métiers pour lesquels ce type est disponible. Un type peut être transversal (tous les métiers) ou spécifique.</li>
<li><strong>Activer le type</strong> — Basculez le statut sur « Actif ». Le type apparaît immédiatement dans le module Documents des entreprises concernées.</li>
</ol>
<h2>Résultat</h2>
<p>Le type de document est disponible pour les entreprises des métiers sélectionnés, avec le template et la numérotation configurés.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Type de document invisible</strong> — Vérifiez que le type est actif et que le métier de l\'entreprise est dans la liste des métiers associés.</li>
<li><strong>Numérotation en doublon</strong> — Chaque type doit avoir un préfixe de numérotation unique. Un doublon provoque des collisions de référence.</li>
<li><strong>Champ requis manquant dans le template</strong> — Ajoutez les champs obligatoires (expéditeur, date) avant d\'activer le type pour éviter les documents incomplets.</li>
</ul>',
                ],
            ],
        ],
        //
        // ─── Topic 4: Opérations & monitoring ──────────────────────────
        //
        [
            'title' => 'Opérations & monitoring',
            'slug' => 'operations-monitoring',
            'description' => 'Supervisez la santé système, les alertes, l\'utilisation et les automatisations depuis le Hub Opérations.',
            'icon' => 'tabler-activity',
            'articles' => [
                [
                    'title' => 'Surveiller la santé système',
                    'slug' => 'tableau-bord-systeme',
                    'excerpt' => 'Contrôlez l\'état des services, les métriques de performance et les indicateurs d\'infrastructure.',
                    'content' => '<p>Dans ce guide, vous allez contrôler la santé de la plateforme depuis le tableau de bord System Health.</p>
<h2>Situation</h2>
<p>Vous effectuez une vérification quotidienne, un client signale des lenteurs, ou une alerte système a été déclenchée.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir System Health</strong> — Dans le menu latéral plateforme, cliquez sur « System Health ». Le tableau de bord affiche l\'état des services critiques.</li>
<li><strong>Vérifier les services</strong> — Chaque service (base de données, queue, cache Redis, email SMTP) affiche un indicateur vert/rouge et son temps de réponse.</li>
<li><strong>Consulter les métriques</strong> — Les graphiques montrent l\'utilisation CPU, mémoire et disque sur les dernières 24 heures.</li>
<li><strong>Examiner les jobs en queue</strong> — Le compteur de jobs en attente et en échec permet de détecter un engorgement du système de queues.</li>
<li><strong>Agir sur les anomalies</strong> — Un service en rouge nécessite une investigation immédiate. Consultez les logs système pour identifier la cause.</li>
</ol>
<h2>Résultat</h2>
<p>Vous avez un diagnostic complet de l\'infrastructure et pouvez intervenir rapidement en cas d\'anomalie.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Queue bloquée avec jobs en échec</strong> — Consultez les logs de queue pour identifier le job en erreur. Relancez-le ou supprimez-le si non critique.</li>
<li><strong>Redis déconnecté</strong> — Vérifiez que le service Redis est actif sur le serveur. Sans Redis, le cache et les sessions sont impactés.</li>
<li><strong>Disque plein</strong> — Les logs et les fichiers temporaires peuvent saturer le disque. Nettoyez les logs anciens et les fichiers de cache.</li>
</ul>',
                ],
                [
                    'title' => 'Gérer les alertes plateforme',
                    'slug' => 'centre-alertes',
                    'excerpt' => 'Consultez, triez et traitez les alertes système, facturation et sécurité.',
                    'content' => '<p>Dans ce guide, vous allez gérer les alertes plateforme pour traiter les incidents et les événements importants.</p>
<h2>Situation</h2>
<p>Des alertes s\'accumulent dans le centre d\'alertes, vous devez trier les urgences et traiter les incidents en cours.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir le Centre d\'alertes</strong> — Dans le menu latéral plateforme, cliquez sur « Alertes ». La page liste toutes les alertes triées par date avec leur niveau de sévérité.</li>
<li><strong>Filtrer par sévérité</strong> — Utilisez les filtres « Critique », « Avertissement » et « Info » pour prioriser. Les alertes critiques nécessitent une action immédiate.</li>
<li><strong>Consulter le détail</strong> — Cliquez sur une alerte pour voir son contexte : type d\'événement, entité concernée, horodatage et historique de la timeline.</li>
<li><strong>Traiter une alerte</strong> — Marquez l\'alerte comme « En cours » pendant le traitement, puis « Résolue » une fois l\'incident corrigé.</li>
<li><strong>Vérifier les escalades</strong> — Les alertes non traitées dans le délai configuré sont automatiquement escaladées. Consultez la timeline pour voir les escalades passées.</li>
</ol>
<h2>Résultat</h2>
<p>Les alertes sont triées par priorité, les incidents critiques sont traités et l\'historique des résolutions est documenté.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Alertes en masse après un déploiement</strong> — Un pic d\'alertes après une mise à jour est souvent temporaire. Vérifiez les logs de déploiement avant d\'escalader.</li>
<li><strong>Alerte critique non notifiée</strong> — Vérifiez que les notifications par email sont configurées dans les paramètres de la plateforme.</li>
<li><strong>Alerte résolue qui réapparaît</strong> — La cause n\'est pas traitée. Investiguez la source plutôt que de résoudre l\'alerte à répétition.</li>
</ul>',
                ],
                [
                    'title' => 'Surveiller l\'utilisation de la plateforme',
                    'slug' => 'monitoring-utilisation',
                    'excerpt' => 'Analysez les métriques d\'utilisation par entreprise, module et période.',
                    'content' => '<p>Dans ce guide, vous allez analyser les métriques d\'utilisation pour identifier les tendances et les anomalies.</p>
<h2>Situation</h2>
<p>Vous devez évaluer l\'engagement des clients, détecter les comptes inactifs ou dimensionner l\'infrastructure en fonction de l\'usage réel.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir le monitoring d\'utilisation</strong> — Dans le menu latéral plateforme, cliquez sur « Utilisation ». Le tableau de bord affiche les métriques globales et par module.</li>
<li><strong>Consulter l\'utilisation globale</strong> — Le graphique principal montre le nombre de connexions, de documents créés et d\'actions effectuées sur la période sélectionnée.</li>
<li><strong>Analyser par module</strong> — Chaque module affiche son taux d\'utilisation : nombre d\'actions, utilisateurs actifs et tendance. Un module activé mais non utilisé signale un problème d\'adoption.</li>
<li><strong>Identifier les comptes inactifs</strong> — La liste des entreprises triée par dernière activité met en évidence les comptes dormants nécessitant une relance.</li>
<li><strong>Détecter les pics d\'utilisation</strong> — Les pics de charge permettent de planifier le dimensionnement de l\'infrastructure et d\'anticiper les besoins.</li>
</ol>
<h2>Résultat</h2>
<p>Vous avez une vision claire de l\'engagement client et des métriques d\'utilisation pour piloter les actions commerciales et techniques.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Entreprise active mais utilisation à zéro</strong> — Le client paie mais n\'utilise pas la plateforme. Contactez-le pour comprendre la situation et proposer un accompagnement.</li>
<li><strong>Pic d\'utilisation non expliqué</strong> — Un pic soudain peut indiquer un import massif de données ou un usage anormal. Vérifiez les logs d\'activité de l\'entreprise concernée.</li>
<li><strong>Données d\'utilisation en retard</strong> — Les snapshots d\'utilisation sont collectés périodiquement. Un retard peut indiquer un problème avec la tâche planifiée de collecte.</li>
</ul>',
                ],
                [
                    'title' => 'Gérer les automatisations planifiées',
                    'slug' => 'gestion-automatisations',
                    'excerpt' => 'Consultez, déclenchez et dépannez les tâches planifiées de la plateforme.',
                    'content' => '<p>Dans ce guide, vous allez gérer les tâches planifiées qui automatisent les opérations récurrentes de la plateforme.</p>
<h2>Situation</h2>
<p>Une tâche planifiée a échoué, vous devez vérifier que toutes les automatisations fonctionnent, ou vous devez déclencher manuellement une tâche.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir les automatisations</strong> — Dans le Hub Opérations, consultez la section « Automatisations ». La liste affiche toutes les tâches planifiées avec leur fréquence et leur dernière exécution.</li>
<li><strong>Vérifier les statuts</strong> — Chaque tâche affiche « Succès », « Échec » ou « En cours ». Les échecs sont marqués en rouge avec l\'horodatage de la dernière erreur.</li>
<li><strong>Consulter les logs</strong> — Cliquez sur une tâche pour voir son historique d\'exécution, les durées et les éventuels messages d\'erreur.</li>
<li><strong>Déclencher manuellement</strong> — Utilisez le bouton « Exécuter maintenant » pour relancer une tâche en échec ou forcer une exécution immédiate.</li>
<li><strong>Vérifier la planification</strong> — Confirmez que la fréquence (quotidienne, horaire, etc.) correspond aux besoins opérationnels.</li>
</ol>
<h2>Résultat</h2>
<p>Toutes les tâches planifiées fonctionnent correctement, les échecs sont identifiés et corrigés, et les exécutions manuelles sont possibles.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Tâche en échec depuis plusieurs jours</strong> — Consultez les logs pour identifier l\'erreur. Les causes fréquentes : timeout, service externe indisponible, données corrompues.</li>
<li><strong>Exécution manuelle qui échoue aussi</strong> — Le problème est structurel, pas temporaire. Escaladez à l\'équipe technique avec les logs d\'erreur.</li>
<li><strong>Deux tâches en conflit</strong> — Si deux tâches accèdent aux mêmes données simultanément, vérifiez qu\'elles ne se chevauchent pas dans la planification.</li>
</ul>',
                ],
                [
                    'title' => 'Surveiller l\'IA et la consommation de tokens',
                    'slug' => 'suivi-temps-reel',
                    'excerpt' => 'Contrôlez l\'utilisation de l\'IA, les coûts de tokens et les limites par entreprise.',
                    'content' => '<p>Dans ce guide, vous allez surveiller la consommation de tokens IA et contrôler les coûts associés.</p>
<h2>Situation</h2>
<p>Vous devez vérifier les coûts IA du mois, identifier les entreprises grandes consommatrices ou ajuster les limites de consommation.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir le Hub IA</strong> — Dans le menu latéral plateforme, cliquez sur « IA ». Le tableau de bord affiche la consommation globale de tokens et les coûts associés.</li>
<li><strong>Analyser par entreprise</strong> — L\'onglet « Utilisation » détaille la consommation de chaque entreprise : tokens utilisés, coût et pourcentage de la limite.</li>
<li><strong>Vérifier les limites</strong> — Chaque plan définit une limite mensuelle de tokens. Les entreprises approchant leur limite sont signalées par un indicateur orange.</li>
<li><strong>Consulter l\'historique</strong> — Le graphique d\'évolution montre la tendance de consommation sur les derniers mois, utile pour le dimensionnement budgétaire.</li>
<li><strong>Ajuster les paramètres</strong> — Dans l\'onglet « Opérations », configurez les modèles IA utilisés, les limites par défaut et les alertes de dépassement.</li>
</ol>
<h2>Résultat</h2>
<p>Vous avez une vision claire des coûts IA, les entreprises à forte consommation sont identifiées et les limites sont correctement configurées.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Coût IA en forte hausse</strong> — Identifiez l\'entreprise responsable dans l\'onglet Utilisation. Un pic peut indiquer un usage automatisé non prévu.</li>
<li><strong>Entreprise bloquée par la limite de tokens</strong> — Vérifiez si la limite du plan est atteinte. Augmentez temporairement ou proposez un upgrade de plan.</li>
<li><strong>Modèle IA trop coûteux</strong> — Vérifiez dans l\'onglet Opérations quel modèle est configuré. Un modèle plus économique peut suffire pour certaines tâches.</li>
</ul>',
                ],
            ],
        ],
        //
        // ─── Topic 5: Support & SLA ────────────────────────────────────
        //
        [
            'title' => 'Support & SLA',
            'slug' => 'support-sla',
            'description' => 'Traitez les tickets de support, respectez les SLA et gérez les escalades.',
            'icon' => 'tabler-headset',
            'articles' => [
                [
                    'title' => 'Surveiller les tickets de support',
                    'slug' => 'vue-ensemble-tickets',
                    'excerpt' => 'Consultez la file de tickets, filtrez par statut et identifiez les urgences.',
                    'content' => '<p>Dans ce guide, vous allez surveiller la file de tickets de support pour prioriser les interventions.</p>
<h2>Situation</h2>
<p>Vous prenez votre poste et devez évaluer la charge de support, ou un pic de tickets signale un problème systémique.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir le Hub Support</strong> — Dans le menu latéral plateforme, cliquez sur « Support ». La page liste tous les tickets avec leur statut, priorité et date de création.</li>
<li><strong>Lire les KPI</strong> — Les cartes en haut affichent : tickets ouverts, en cours, temps moyen de résolution et taux de respect du SLA.</li>
<li><strong>Filtrer par priorité</strong> — Utilisez les filtres « Urgent », « Haute », « Normale » et « Basse » pour prioriser votre file de traitement.</li>
<li><strong>Trier par ancienneté</strong> — Les tickets les plus anciens en statut « Ouvert » sont les plus à risque de violation SLA. Traitez-les en priorité.</li>
<li><strong>Identifier les tendances</strong> — Un pic de tickets sur le même sujet indique un problème systémique à traiter à la source.</li>
</ol>
<h2>Résultat</h2>
<p>Vous avez une vue claire de la charge de support, les urgences sont identifiées et les tendances détectées.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Tickets non assignés qui s\'accumulent</strong> — Configurez l\'assignation automatique ou traitez la file non assignée en début de journée.</li>
<li><strong>Même problème signalé par plusieurs clients</strong> — Créez un ticket interne « incident » et liez-y les tickets clients pour un traitement groupé.</li>
<li><strong>SLA proche de l\'expiration</strong> — Les tickets approchant le délai SLA sont signalés. Traitez-les immédiatement ou escaladez.</li>
</ul>',
                ],
                [
                    'title' => 'Assigner et traiter un ticket',
                    'slug' => 'assigner-traiter-ticket',
                    'excerpt' => 'Prenez en charge un ticket, communiquez avec le client et clôturez l\'incident.',
                    'content' => '<p>Dans ce guide, vous allez prendre en charge un ticket de support du début à la résolution.</p>
<h2>Situation</h2>
<p>Un ticket vous est assigné ou vous le prenez en charge depuis la file. Vous devez diagnostiquer le problème, communiquer avec le client et résoudre l\'incident.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir le ticket</strong> — Cliquez sur le ticket dans la liste. La page détail affiche la description du problème, les informations du client et l\'historique des échanges.</li>
<li><strong>S\'assigner le ticket</strong> — Cliquez sur « Prendre en charge ». Le statut passe à « En cours » et le chrono SLA continue.</li>
<li><strong>Diagnostiquer</strong> — Consultez la fiche de l\'entreprise cliente (lien direct depuis le ticket) pour vérifier le contexte : plan, modules, facturation, activité récente.</li>
<li><strong>Répondre au client</strong> — Utilisez le formulaire de réponse pour communiquer. Chaque message est envoyé par email au client et ajouté à l\'historique.</li>
<li><strong>Résoudre et clôturer</strong> — Une fois le problème résolu, changez le statut à « Résolu ». Ajoutez une note interne documentant la solution pour référence future.</li>
</ol>
<h2>Résultat</h2>
<p>Le ticket est résolu, le client est informé et la solution est documentée pour les futurs incidents similaires.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Client qui ne répond pas</strong> — Après 48h sans réponse, envoyez un rappel. Après 7 jours, clôturez le ticket avec le statut « Fermé sans réponse ».</li>
<li><strong>Problème non reproductible</strong> — Demandez au client des captures d\'écran et les étapes exactes de reproduction. Vérifiez les logs d\'activité de son compte.</li>
<li><strong>Ticket nécessitant une intervention technique</strong> — Escaladez via le bouton « Escalader » avec une description technique du problème pour l\'équipe de développement.</li>
</ul>',
                ],
                [
                    'title' => 'Surveiller les SLA',
                    'slug' => 'suivre-sla',
                    'excerpt' => 'Contrôlez le respect des délais SLA et identifiez les violations avant qu\'elles ne surviennent.',
                    'content' => '<p>Dans ce guide, vous allez surveiller le respect des SLA pour maintenir la qualité de service.</p>
<h2>Situation</h2>
<p>Vous devez vérifier que les engagements de temps de réponse et de résolution sont respectés, ou préparer un reporting SLA.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Consulter le taux SLA</strong> — Dans le Hub Support, la carte « Taux SLA » affiche le pourcentage de tickets résolus dans les délais sur la période en cours.</li>
<li><strong>Identifier les tickets à risque</strong> — Les tickets approchant l\'échéance SLA sont marqués en orange. Ceux en violation sont en rouge.</li>
<li><strong>Analyser les violations</strong> — Cliquez sur un ticket en violation pour comprendre la cause : temps d\'attente client, diagnostic long, escalade tardive.</li>
<li><strong>Prendre des mesures correctives</strong> — Réassignez les tickets à risque, ajustez les priorités ou renforcez temporairement l\'équipe support.</li>
<li><strong>Générer un rapport SLA</strong> — Exportez les métriques SLA pour le reporting : taux de respect, temps moyen de réponse, temps moyen de résolution par priorité.</li>
</ol>
<h2>Résultat</h2>
<p>Les SLA sont sous contrôle, les violations sont analysées et les mesures correctives sont en place.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>SLA violé par un ticket en attente client</strong> — Le chrono SLA continue même si le client ne répond pas. Passez le ticket en statut « En attente client » pour mettre en pause le SLA.</li>
<li><strong>Taux SLA en chute après un incident</strong> — Un incident systémique génère beaucoup de tickets simultanés. Traitez l\'incident source pour réduire le flux.</li>
<li><strong>Délais SLA inadaptés</strong> — Si les violations sont systématiques sur une priorité, réévaluez les délais configurés dans les paramètres support.</li>
</ul>',
                ],
                [
                    'title' => 'Analyser les tickets récurrents',
                    'slug' => 'analyser-tickets-recurrents',
                    'excerpt' => 'Identifiez les problèmes répétitifs pour les corriger à la source et réduire le volume de support.',
                    'content' => '<p>Dans ce guide, vous allez identifier les tickets récurrents pour corriger les problèmes à la source.</p>
<h2>Situation</h2>
<p>Le volume de tickets augmente, vous soupçonnez des problèmes récurrents ou vous devez prioriser les améliorations produit basées sur le support.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Consulter les catégories</strong> — Dans le Hub Support, analysez la répartition des tickets par catégorie (facturation, accès, module, bug technique).</li>
<li><strong>Identifier les patterns</strong> — Recherchez les tickets avec des sujets similaires sur les 30 derniers jours. Regroupez-les par thème.</li>
<li><strong>Quantifier l\'impact</strong> — Comptez le nombre de tickets, les entreprises touchées et le temps total de traitement pour chaque problème récurrent.</li>
<li><strong>Prioriser les correctifs</strong> — Classez les problèmes par impact (nombre de tickets × temps de résolution). Les plus coûteux en temps doivent être corrigés en priorité.</li>
<li><strong>Créer un ticket interne</strong> — Pour chaque problème identifié, créez un ticket interne ou une demande d\'amélioration à destination de l\'équipe technique.</li>
</ol>
<h2>Résultat</h2>
<p>Les problèmes récurrents sont identifiés, quantifiés et transmis pour correction. Le volume de support diminuera progressivement.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Catégorisation incohérente des tickets</strong> — Si les agents utilisent des catégories différentes pour le même problème, l\'analyse est faussée. Standardisez les catégories.</li>
<li><strong>Correctif appliqué mais tickets qui continuent</strong> — Le correctif ne couvre pas tous les cas. Réanalysez les tickets post-correctif pour affiner la solution.</li>
<li><strong>Problème récurrent sans solution technique</strong> — Créez un article dans le Centre d\'aide pour que les clients puissent se dépanner eux-mêmes.</li>
</ul>',
                ],
                [
                    'title' => 'Gérer les escalades de tickets',
                    'slug' => 'gerer-escalades',
                    'excerpt' => 'Traitez les tickets escaladés et configurez les règles d\'escalade automatique.',
                    'content' => '<p>Dans ce guide, vous allez gérer les tickets escaladés et configurer les règles d\'escalade pour les incidents critiques.</p>
<h2>Situation</h2>
<p>Un ticket a été escaladé automatiquement ou manuellement, ou vous devez configurer les seuils d\'escalade pour améliorer la réactivité.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Consulter les tickets escaladés</strong> — Dans le Hub Support, filtrez par « Escaladé ». Ces tickets nécessitent une attention immédiate d\'un administrateur senior.</li>
<li><strong>Analyser le motif d\'escalade</strong> — Consultez la timeline du ticket pour comprendre pourquoi il a été escaladé : dépassement SLA, demande de l\'agent, ou règle automatique.</li>
<li><strong>Prendre en charge</strong> — Assignez-vous le ticket escaladé. Le client et l\'agent initial sont notifiés de la prise en charge par un senior.</li>
<li><strong>Résoudre et documenter</strong> — Traitez le ticket avec une attention particulière. Documentez la résolution et les leçons apprises pour éviter de futures escalades similaires.</li>
<li><strong>Configurer les règles d\'escalade</strong> — Dans les paramètres support, définissez les seuils de temps par priorité après lesquels un ticket est automatiquement escaladé.</li>
</ol>
<h2>Résultat</h2>
<p>Les tickets escaladés sont traités en priorité, les règles d\'escalade sont configurées et les incidents critiques sont résolus rapidement.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Escalades trop fréquentes</strong> — Les seuils sont peut-être trop bas. Ajustez les délais d\'escalade en fonction du temps moyen de résolution réel.</li>
<li><strong>Escalade sans contexte</strong> — L\'agent initial doit documenter ses actions avant d\'escalader. Exigez un résumé dans la note d\'escalade.</li>
<li><strong>Ticket escaladé non pris en charge</strong> — Configurez une notification d\'alerte si un ticket escaladé n\'est pas pris en charge dans les 30 minutes.</li>
</ul>',
                ],
            ],
        ],
        //
        // ─── Topic 6: Configuration plateforme ─────────────────────────
        //
        [
            'title' => 'Configuration plateforme',
            'slug' => 'configuration-plateforme',
            'description' => 'Configurez les paramètres généraux, l\'email, la sécurité, les marchés et les feature flags.',
            'icon' => 'tabler-adjustments',
            'articles' => [
                [
                    'title' => 'Configurer les paramètres généraux',
                    'slug' => 'parametres-generaux',
                    'excerpt' => 'Ajustez le nom de la plateforme, les devises, les langues et les paramètres globaux.',
                    'content' => '<p>Dans ce guide, vous allez configurer les paramètres généraux qui définissent le comportement global de la plateforme.</p>
<h2>Situation</h2>
<p>Vous initialisez la plateforme, vous ajoutez une nouvelle devise ou langue, ou vous modifiez les paramètres par défaut (durée d\'essai, timezone).</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir les paramètres</strong> — Dans le menu latéral plateforme, cliquez sur « Paramètres ». L\'onglet « Général » affiche les paramètres globaux de la plateforme.</li>
<li><strong>Modifier les informations générales</strong> — Ajustez le nom de la plateforme, l\'URL, le fuseau horaire par défaut et la durée d\'essai (en jours).</li>
<li><strong>Configurer les devises</strong> — Ajoutez ou retirez des devises disponibles. La devise par défaut est utilisée pour les nouveaux comptes.</li>
<li><strong>Gérer les langues</strong> — Activez les langues supportées (français, anglais). La langue par défaut est appliquée aux nouveaux comptes et aux emails système.</li>
<li><strong>Enregistrer</strong> — Les modifications prennent effet immédiatement. Les comptes existants conservent leurs paramètres individuels.</li>
</ol>
<h2>Résultat</h2>
<p>Les paramètres globaux sont à jour et s\'appliquent aux nouveaux comptes et aux comportements système par défaut.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Changement de timezone non visible</strong> — Le changement de timezone par défaut n\'affecte que les nouveaux comptes. Les comptes existants conservent leur timezone individuelle.</li>
<li><strong>Devise retirée alors qu\'un client l\'utilise</strong> — Ne retirez jamais une devise utilisée par des comptes actifs. Vérifiez le nombre de comptes par devise avant de la retirer.</li>
<li><strong>Durée d\'essai modifiée rétroactivement</strong> — La nouvelle durée s\'applique uniquement aux futures inscriptions. Les essais en cours ne sont pas modifiés.</li>
</ul>',
                ],
                [
                    'title' => 'Configurer l\'envoi d\'emails',
                    'slug' => 'configuration-email',
                    'excerpt' => 'Paramétrez les credentials SMTP, vérifiez la délivrabilité et diagnostiquez les envois.',
                    'content' => '<p>Dans ce guide, vous allez configurer l\'envoi d\'emails de la plateforme et diagnostiquer les problèmes de délivrabilité.</p>
<h2>Situation</h2>
<p>Les emails de la plateforme (notifications, factures, relances) ne sont pas délivrés, tombent en spam, ou vous devez configurer un nouveau service SMTP.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir la configuration email</strong> — Dans les paramètres plateforme, allez dans l\'onglet « Email ». Les credentials SMTP actuels et le statut de délivrabilité sont affichés.</li>
<li><strong>Configurer les credentials SMTP</strong> — Renseignez le serveur, le port, le nom d\'utilisateur et le mot de passe. Les credentials sont chiffrés en base de données.</li>
<li><strong>Vérifier les DNS</strong> — Contrôlez que les enregistrements SPF, DKIM et DMARC sont configurés pour le domaine d\'envoi. L\'outil de diagnostic intégré vérifie ces enregistrements.</li>
<li><strong>Envoyer un email test</strong> — Utilisez le bouton « Envoyer un test » pour vérifier la configuration. L\'email test inclut les en-têtes de diagnostic.</li>
<li><strong>Consulter les logs d\'envoi</strong> — L\'historique des envois affiche le statut de chaque email : envoyé, délivré, bounced ou en erreur.</li>
</ol>
<h2>Résultat</h2>
<p>L\'envoi d\'emails est configuré, les DNS sont validés et la délivrabilité est vérifiée par un email test.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Emails en spam chez Gmail</strong> — Vérifiez les enregistrements SPF, DKIM et DMARC. Les en-têtes manquants sont la cause principale de classification en spam.</li>
<li><strong>Erreur d\'authentification SMTP</strong> — Vérifiez le nom d\'utilisateur et le mot de passe. Certains fournisseurs nécessitent un mot de passe d\'application dédié.</li>
<li><strong>Emails non envoyés (queue bloquée)</strong> — Vérifiez dans System Health que le worker de queue email est actif et qu\'il n\'y a pas de jobs en échec.</li>
</ul>',
                ],
                [
                    'title' => 'Configurer les paramètres de sécurité',
                    'slug' => 'parametres-securite',
                    'excerpt' => 'Définissez les règles de mots de passe, les sessions et les politiques d\'accès.',
                    'content' => '<p>Dans ce guide, vous allez configurer les paramètres de sécurité pour protéger les comptes et les données de la plateforme.</p>
<h2>Situation</h2>
<p>Vous renforcez la politique de sécurité, vous devez ajuster la durée des sessions, ou un audit de sécurité exige des changements de configuration.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir les paramètres de sécurité</strong> — Dans les paramètres plateforme, allez dans l\'onglet « Sécurité ». Les règles de sécurité actuelles sont affichées.</li>
<li><strong>Configurer la politique de mots de passe</strong> — Définissez la longueur minimale, la complexité requise (majuscules, chiffres, caractères spéciaux) et la durée de validité.</li>
<li><strong>Gérer les sessions</strong> — Configurez la durée d\'expiration des sessions (inactivité et durée maximale). Les sessions expirées forcent la reconnexion.</li>
<li><strong>Activer la double authentification</strong> — Activez l\'option 2FA pour les administrateurs plateforme. Les utilisateurs sont invités à configurer leur second facteur à la prochaine connexion.</li>
<li><strong>Configurer les restrictions d\'accès</strong> — Définissez les plages IP autorisées pour l\'accès à l\'interface plateforme si nécessaire.</li>
</ol>
<h2>Résultat</h2>
<p>Les paramètres de sécurité sont renforcés, les sessions sont contrôlées et les accès sont protégés selon la politique définie.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Utilisateurs bloqués par la politique de mot de passe</strong> — Si vous renforcez la politique, les utilisateurs devront changer leur mot de passe à la prochaine connexion. Prévenez-les.</li>
<li><strong>Sessions trop courtes</strong> — Une durée d\'expiration trop courte génère des plaintes. Trouvez un équilibre entre sécurité (30 min) et confort (4h).</li>
<li><strong>IP bloquée par erreur</strong> — Si vous configurez des restrictions IP et que votre propre IP change, vous pouvez perdre l\'accès. Conservez toujours un accès de secours.</li>
</ul>',
                ],
                [
                    'title' => 'Gérer les marchés et zones géographiques',
                    'slug' => 'gestion-marches',
                    'excerpt' => 'Configurez les marchés disponibles, les devises associées et les paramètres régionaux.',
                    'content' => '<p>Dans ce guide, vous allez configurer les marchés géographiques pour adapter l\'offre Leezr à chaque zone.</p>
<h2>Situation</h2>
<p>Vous lancez Leezr dans un nouveau pays, vous ajoutez une devise ou vous ajustez les paramètres fiscaux d\'un marché existant.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir la gestion des marchés</strong> — Dans les paramètres plateforme, allez dans l\'onglet « Marchés ». La liste affiche tous les marchés configurés avec leur devise, langue et nombre de clients.</li>
<li><strong>Créer un marché</strong> — Cliquez sur « Nouveau marché ». Renseignez le pays, la devise, la langue par défaut et les paramètres fiscaux (TVA, format de facturation).</li>
<li><strong>Configurer les taux de change</strong> — Si plusieurs devises sont utilisées, vérifiez que les taux de change sont à jour. Les taux sont rafraîchis automatiquement par la tâche planifiée FX.</li>
<li><strong>Associer les plans</strong> — Définissez les plans tarifaires disponibles pour chaque marché. Les prix peuvent varier selon le marché.</li>
<li><strong>Activer le marché</strong> — Basculez le statut sur « Actif ». Le marché apparaît dans le formulaire d\'inscription et les nouveaux clients peuvent s\'y inscrire.</li>
</ol>
<h2>Résultat</h2>
<p>Le nouveau marché est disponible, les paramètres régionaux sont configurés et les clients peuvent s\'inscrire dans la zone.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Taux de change obsolète</strong> — Vérifiez dans les automatisations que la tâche de mise à jour des taux FX s\'exécute correctement.</li>
<li><strong>TVA incorrecte sur les factures</strong> — Vérifiez le taux de TVA configuré pour le marché. Un taux erroné impacte toutes les factures du marché.</li>
<li><strong>Marché activé sans plans associés</strong> — Un marché sans plans empêche les clients de souscrire. Associez au moins un plan avant d\'activer.</li>
</ul>',
                ],
                [
                    'title' => 'Gérer les feature flags',
                    'slug' => 'feature-flags',
                    'excerpt' => 'Activez ou désactivez des fonctionnalités en temps réel sans déploiement.',
                    'content' => '<p>Dans ce guide, vous allez utiliser les feature flags pour activer ou désactiver des fonctionnalités sans redéployer la plateforme.</p>
<h2>Situation</h2>
<p>Vous lancez une fonctionnalité en bêta restreinte, vous devez désactiver d\'urgence une fonctionnalité défaillante, ou vous préparez un lancement progressif.</p>
<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir les feature flags</strong> — Dans le menu latéral plateforme, cliquez sur « Feature Flags ». La liste affiche tous les flags avec leur état (actif/inactif) et leur portée.</li>
<li><strong>Activer un flag</strong> — Basculez le toggle du flag souhaité. L\'activation prend effet immédiatement sans redéploiement.</li>
<li><strong>Configurer la portée</strong> — Un flag peut être global (toutes les entreprises), limité à certaines entreprises, ou limité à un pourcentage de clients (rollout progressif).</li>
<li><strong>Désactiver d\'urgence</strong> — En cas de problème, désactivez immédiatement le flag. La fonctionnalité est retirée en temps réel pour tous les utilisateurs concernés.</li>
<li><strong>Documenter l\'usage</strong> — Ajoutez une description au flag pour que les autres administrateurs comprennent sa fonction et les conditions de son activation.</li>
</ol>
<h2>Résultat</h2>
<p>La fonctionnalité est activée ou désactivée en temps réel selon la portée configurée, sans nécessiter de déploiement.</p>
<h2>Problèmes fréquents</h2>
<ul>
<li><strong>Flag activé mais fonctionnalité invisible</strong> — Vérifiez que le code vérifie bien le flag et que le cache front-end est rafraîchi. Un rechargement de page peut être nécessaire.</li>
<li><strong>Rollout progressif incohérent</strong> — Le pourcentage de rollout est basé sur l\'ID de l\'entreprise. Une même entreprise verra toujours le même état (activé ou non).</li>
<li><strong>Flag obsolète non nettoyé</strong> — Les flags qui sont actifs pour tous depuis longtemps doivent être nettoyés : retirez la vérification du code et supprimez le flag.</li>
</ul>',
                ],
            ],
        ],
    ],
];
