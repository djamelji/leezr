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
            'description' => 'Gérez le portefeuille d\'entreprises clientes : consultation, configuration des modules, suivi du cycle de vie et actions administratives.',
            'icon' => 'tabler-buildings',
            'articles' => [
                [
                    'title' => 'Vue d\'ensemble des entreprises',
                    'slug' => 'vue-ensemble-entreprises',
                    'excerpt' => 'Consultez la liste complète des entreprises, filtrez par statut ou plan, et suivez les indicateurs clés du portefeuille client.',
                    'content' => '<h2>Contexte</h2>
<p>La page Entreprises du hub Entreprises est le point d\'entrée principal pour superviser l\'ensemble du portefeuille client Leezr. Elle affiche la liste de toutes les entreprises enregistrées avec leurs indicateurs clés : nombre total, entreprises actives, en période d\'essai et résiliées.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder à la liste</strong> — Depuis le menu latéral plateforme, cliquez sur « Entreprises ». La page affiche un tableau paginé avec le nom, le plan, le statut, la date d\'inscription et le nombre de membres.</li>
<li><strong>Utiliser les filtres</strong> — En haut du tableau, utilisez les filtres par statut (actif, essai, suspendu, résilié), par plan tarifaire ou par date d\'inscription. La barre de recherche permet de trouver une entreprise par nom ou identifiant.</li>
<li><strong>Lire les KPI</strong> — Les cartes de statistiques en haut de page affichent le total des entreprises, le nombre d\'actives, les essais en cours et le taux de churn mensuel. Ces chiffres se mettent à jour en temps réel.</li>
<li><strong>Trier les résultats</strong> — Cliquez sur les en-têtes de colonnes pour trier par nom, date de création, plan ou nombre d\'utilisateurs. Le tri combiné avec les filtres permet d\'identifier rapidement les comptes nécessitant une attention.</li>
<li><strong>Exporter les données</strong> — Utilisez le bouton d\'export pour générer un fichier CSV du portefeuille filtré, utile pour les rapports de direction ou les audits périodiques.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous souhaitez identifier les entreprises en période d\'essai depuis plus de 10 jours qui n\'ont toujours pas activé de module. Depuis la page Entreprises, sélectionnez le filtre « Essai » et triez par date d\'inscription croissante. Les entreprises les plus anciennes en essai apparaissent en premier. Vous pouvez ensuite cliquer sur chacune pour vérifier l\'activation des modules et décider d\'une action de relance.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Confondre entreprises suspendues et résiliées</strong> — Une entreprise suspendue conserve ses données et peut être réactivée. Une entreprise résiliée a terminé son cycle et ses données sont en attente de suppression.</li>
<li><strong>Ignorer les filtres actifs</strong> — Si le compteur total semble bas, vérifiez qu\'aucun filtre n\'est appliqué. Le badge sur le bouton filtre indique le nombre de filtres actifs.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous disposez d\'une vue complète et filtrable de tout le portefeuille client, avec des indicateurs à jour pour piloter les actions commerciales et le suivi opérationnel.</p>',
                ],
                [
                    'title' => 'Consulter le détail d\'une entreprise',
                    'slug' => 'consulter-detail-entreprise',
                    'excerpt' => 'Accédez à la fiche complète d\'une entreprise : profil, membres, modules activés, facturation et historique d\'activité.',
                    'content' => '<h2>Contexte</h2>
<p>La fiche détaillée d\'une entreprise centralise toutes les informations nécessaires pour comprendre la situation d\'un client : son profil, ses utilisateurs, les modules qu\'il utilise, sa facturation et son historique d\'activité. C\'est la page de référence pour toute action administrative sur un compte.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Ouvrir la fiche</strong> — Depuis la liste des entreprises, cliquez sur le nom d\'une entreprise. La page détail s\'ouvre avec un en-tête montrant le nom, le logo, le statut et le plan actuel.</li>
<li><strong>Consulter l\'onglet Profil</strong> — L\'onglet profil affiche les coordonnées, le SIRET, l\'adresse, le secteur d\'activité (métier) et les paramètres régionaux (langue, devise, fuseau horaire).</li>
<li><strong>Vérifier les membres</strong> — L\'onglet Membres liste tous les utilisateurs du compte avec leur rôle, leur date de dernière connexion et leur statut (actif, invité, désactivé). Vous pouvez identifier les comptes dormants.</li>
<li><strong>Examiner les modules</strong> — L\'onglet Modules montre les modules activés et désactivés pour cette entreprise, avec la date d\'activation et les statistiques d\'utilisation de chaque module.</li>
<li><strong>Vérifier la facturation</strong> — L\'onglet Facturation affiche le plan, le montant mensuel, l\'historique des paiements, les factures et les éventuels impayés ou avoirs en cours.</li>
</ol>

<h2>Exemple concret</h2>
<p>Un client appelle pour signaler qu\'un de ses chauffeurs ne peut plus se connecter. Vous recherchez l\'entreprise dans la liste, ouvrez sa fiche et allez dans l\'onglet Membres. Vous constatez que le compte du chauffeur a le statut « désactivé » depuis 3 jours. Vous vérifiez l\'historique d\'activité et découvrez que l\'administrateur de l\'entreprise a désactivé le compte par erreur. Vous informez le client de la procédure de réactivation côté entreprise.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Modifier directement les données du profil</strong> — En tant qu\'admin plateforme, vous pouvez voir les données mais certaines modifications doivent être faites par le client lui-même. Réservez les modifications admin aux cas exceptionnels documentés.</li>
<li><strong>Ne pas vérifier le contexte de facturation avant d\'intervenir</strong> — Avant toute action sur un compte, consultez toujours l\'onglet Facturation pour vérifier qu\'il n\'y a pas d\'impayé ou de litige en cours.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous avez une vision 360° du client permettant de répondre à toute demande de support ou de prendre une décision administrative éclairée.</p>',
                ],
                [
                    'title' => 'Gérer les modules d\'une entreprise',
                    'slug' => 'gerer-modules-entreprise',
                    'excerpt' => 'Activez ou désactivez des modules pour une entreprise cliente, et comprenez l\'impact sur sa facturation et ses fonctionnalités.',
                    'content' => '<h2>Contexte</h2>
<p>Chaque entreprise cliente dispose d\'un ensemble de modules qui composent son offre fonctionnelle. En tant qu\'administrateur plateforme, vous pouvez activer ou désactiver des modules individuellement pour répondre à une demande client, corriger une situation ou appliquer une politique commerciale.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder aux modules de l\'entreprise</strong> — Depuis la fiche de l\'entreprise, ouvrez l\'onglet Modules. La liste affiche tous les modules du catalogue avec leur état (activé/désactivé) pour cette entreprise.</li>
<li><strong>Activer un module</strong> — Cliquez sur le toggle d\'activation du module souhaité. Un dialogue de confirmation s\'affiche indiquant l\'impact tarifaire : le montant mensuel supplémentaire sera ajouté au prochain cycle de facturation.</li>
<li><strong>Vérifier les dépendances</strong> — Certains modules dépendent d\'autres modules. Le système vous avertit si une dépendance manque et propose d\'activer automatiquement les modules requis.</li>
<li><strong>Désactiver un module</strong> — Le toggle de désactivation déclenche une vérification : si des données existent dans le module, un avertissement s\'affiche. La désactivation prend effet immédiatement mais les données sont conservées pendant 30 jours.</li>
<li><strong>Vérifier l\'impact</strong> — Après la modification, consultez l\'onglet Facturation pour confirmer que le nouveau montant mensuel est correct et que le prochain prélèvement reflète les changements.</li>
</ol>

<h2>Exemple concret</h2>
<p>Une entreprise de transport en période d\'essai souhaite tester le module Documents en plus du module Expéditions déjà actif. Vous ouvrez sa fiche, allez dans l\'onglet Modules et activez « Documents ». Le système confirme l\'activation et affiche le nouveau tarif mensuel incluant les deux modules. Le client peut immédiatement accéder à la gestion documentaire depuis son espace.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Désactiver un module sans prévenir le client</strong> — Toujours informer le client avant de désactiver un module, surtout s\'il contient des données actives. La désactivation bloque immédiatement l\'accès aux fonctionnalités.</li>
<li><strong>Ignorer les dépendances entre modules</strong> — Désactiver un module dont d\'autres dépendent peut provoquer des erreurs dans l\'interface client. Le système vous avertit, ne passez pas outre sans vérifier.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le module est activé ou désactivé pour l\'entreprise, la facturation est mise à jour automatiquement et le client a accès (ou non) aux fonctionnalités correspondantes.</p>',
                ],
                [
                    'title' => 'Comprendre le cycle de vie client',
                    'slug' => 'cycle-vie-client',
                    'excerpt' => 'Maîtrisez les étapes du parcours client — de l\'essai gratuit à la résiliation — et les événements qui déclenchent chaque transition.',
                    'content' => '<h2>Contexte</h2>
<p>Chaque entreprise cliente traverse un cycle de vie structuré dans Leezr : inscription, période d\'essai, activation, éventuelle suspension et résiliation. Comprendre ces étapes et leurs déclencheurs est essentiel pour intervenir au bon moment et maintenir un portefeuille client sain.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Inscription (trial)</strong> — L\'entreprise s\'inscrit via le formulaire d\'enregistrement. Elle entre automatiquement en période d\'essai gratuit. Le statut est « Essai » et un compte administrateur est créé. Le funnel d\'onboarding est initialisé.</li>
<li><strong>Activation (active)</strong> — Lorsque l\'entreprise souscrit à un plan payant et que le premier paiement est validé, le statut passe à « Actif ». L\'entreprise a accès complet aux modules inclus dans son plan.</li>
<li><strong>Suspension (suspended)</strong> — Si un paiement échoue après les tentatives de relance automatiques, ou si un administrateur plateforme décide de suspendre manuellement le compte, le statut passe à « Suspendu ». L\'accès aux fonctionnalités est bloqué mais les données sont conservées.</li>
<li><strong>Réactivation</strong> — Une entreprise suspendue peut être réactivée lorsque le paiement en souffrance est régularisé. Le statut repasse à « Actif » et l\'accès est rétabli immédiatement.</li>
<li><strong>Résiliation (churned)</strong> — Après une période de suspension prolongée ou sur demande explicite du client, le compte est résilié. Les données entrent en période de rétention (90 jours) avant suppression définitive.</li>
</ol>

<h2>Exemple concret</h2>
<p>Une entreprise s\'est inscrite il y a 14 jours et n\'a toujours pas souscrit de plan. Le système génère automatiquement une alerte « essai expirant ». Vous consultez la fiche du client et constatez qu\'il a activé 3 modules et créé 12 documents. Vous décidez de lui accorder 7 jours supplémentaires d\'essai en modifiant la date de fin de trial, puis vous créez un ticket de suivi pour l\'équipe commerciale.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Confondre suspension et résiliation</strong> — La suspension est réversible et conserve toutes les données. La résiliation déclenche le processus de suppression. Ne résiliez jamais un compte sans avoir tenté la suspension et la relance.</li>
<li><strong>Ne pas surveiller les essais expirés</strong> — Les entreprises en essai qui dépassent la durée sans souscrire restent dans un état ambigu. Mettez en place un suivi régulier via les filtres de la liste des entreprises.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous comprenez chaque état du cycle de vie, les transitions automatiques et manuelles, et vous savez intervenir à chaque étape pour maximiser la rétention client.</p>',
                ],
                [
                    'title' => 'Actions administratives sur une entreprise',
                    'slug' => 'actions-administratives-entreprise',
                    'excerpt' => 'Effectuez les opérations d\'administration avancées : modification de profil, suspension, réactivation et ajustements manuels.',
                    'content' => '<h2>Contexte</h2>
<p>Certaines situations nécessitent une intervention directe de l\'administrateur plateforme sur le compte d\'une entreprise : correction de données, suspension pour impayé, réactivation après régularisation ou ajustements exceptionnels. Ces actions sont tracées et auditables.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Modifier le profil</strong> — Depuis l\'onglet Profil de la fiche entreprise, cliquez sur le bouton d\'édition. Vous pouvez corriger le nom, l\'adresse, le SIRET ou les paramètres régionaux. Chaque modification est enregistrée dans le journal d\'activité avec l\'identité de l\'administrateur.</li>
<li><strong>Suspendre un compte</strong> — Dans le menu d\'actions de la fiche entreprise, sélectionnez « Suspendre ». Indiquez le motif (impayé, abus, demande client). La suspension prend effet immédiatement : les utilisateurs de l\'entreprise voient un message d\'accès restreint à leur prochaine connexion.</li>
<li><strong>Réactiver un compte</strong> — Pour un compte suspendu, sélectionnez « Réactiver ». Le système vérifie que les conditions de réactivation sont remplies (paiement régularisé, fin de la période de suspension). Si validé, l\'accès est rétabli immédiatement.</li>
<li><strong>Ajuster la période d\'essai</strong> — Pour prolonger ou raccourcir un essai, modifiez la date de fin de trial dans la section correspondante. Cette action est utile pour les prospects engagés qui demandent plus de temps d\'évaluation.</li>
<li><strong>Ajouter une note interne</strong> — Utilisez le champ de notes internes pour documenter les interactions, les accords particuliers ou les points d\'attention. Ces notes sont visibles uniquement par les administrateurs plateforme.</li>
</ol>

<h2>Exemple concret</h2>
<p>Un client appelle en urgence : son entreprise a été suspendue automatiquement suite à un échec de prélèvement, mais il a changé de carte bancaire depuis. Vous ouvrez la fiche de l\'entreprise, vérifiez dans l\'onglet Facturation que le nouveau moyen de paiement est enregistré, relancez le prélèvement manuellement, puis réactivez le compte. Vous ajoutez une note interne documentant l\'incident et la résolution.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Réactiver sans vérifier le paiement</strong> — Toujours confirmer que l\'impayé est régularisé avant de réactiver. Sinon le compte sera re-suspendu au prochain cycle de vérification automatique.</li>
<li><strong>Ne pas documenter les actions exceptionnelles</strong> — Chaque action manuelle doit être accompagnée d\'une note interne. Sans documentation, les autres administrateurs ne peuvent pas comprendre le contexte des décisions prises.</li>
</ul>

<h2>Résultat attendu</h2>
<p>L\'action administrative est effectuée, tracée dans le journal d\'activité, et documentée par une note interne. Le client est informé du changement le cas échéant.</p>',
                ],
            ],
        ],

        //
        // ─── Topic 2: Facturation & revenus ─────────────────────────
        //
        [
            'title' => 'Facturation & revenus',
            'slug' => 'facturation-revenus',
            'description' => 'Pilotez la facturation, les prélèvements, les plans tarifaires et les indicateurs de revenus de la plateforme.',
            'icon' => 'tabler-report-money',
            'articles' => [
                [
                    'title' => 'Tableau de bord financier',
                    'slug' => 'tableau-bord-financier',
                    'excerpt' => 'Suivez les indicateurs financiers clés de la plateforme : MRR, ARR, taux de churn, revenus par plan et taux de succès des paiements.',
                    'content' => '<h2>Contexte</h2>
<p>Le tableau de bord financier du hub Facturation offre une vue consolidée des revenus de la plateforme. Il permet de suivre en temps réel la santé financière de Leezr et d\'identifier les tendances qui nécessitent une action commerciale ou opérationnelle.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder au tableau de bord</strong> — Depuis le menu latéral plateforme, cliquez sur « Facturation ». Le tableau de bord s\'affiche avec les cartes de KPI en haut de page : MRR (Monthly Recurring Revenue), ARR (Annual Recurring Revenue), taux de churn et taux de succès des paiements.</li>
<li><strong>Analyser le MRR</strong> — La carte MRR affiche le revenu récurrent mensuel actuel avec la variation par rapport au mois précédent. Un graphique en ligne montre l\'évolution sur les 12 derniers mois. Identifiez les tendances haussières ou baissières.</li>
<li><strong>Examiner les revenus par plan</strong> — Le graphique en barres empilées décompose le MRR par plan tarifaire. Identifiez quel plan génère le plus de revenus et repérez les déséquilibres dans la répartition.</li>
<li><strong>Vérifier le taux de paiement</strong> — La carte de taux de succès des paiements indique le pourcentage de prélèvements réussis sur le mois en cours. Un taux inférieur à 95 % signale un problème à investiguer dans l\'onglet Prélèvements.</li>
<li><strong>Filtrer par période</strong> — Utilisez le sélecteur de période pour afficher les données sur 30, 90 ou 365 jours. Comparez les périodes pour identifier les saisonnalités ou les impacts de changements tarifaires.</li>
</ol>

<h2>Exemple concret</h2>
<p>En début de mois, vous consultez le tableau de bord et constatez que le MRR a baissé de 3 % par rapport au mois précédent. En analysant la décomposition par plan, vous identifiez que 5 entreprises du plan « Pro » ont résilié. Vous croisez cette information avec le taux de churn et décidez de lancer une analyse des tickets de support de ces clients pour comprendre les motifs de résiliation.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Confondre MRR et revenus facturés</strong> — Le MRR représente le revenu récurrent normalisé sur un mois. Les revenus facturés incluent les paiements ponctuels, les avoirs et les régularisations. Les deux indicateurs sont complémentaires mais distincts.</li>
<li><strong>Ne pas investiguer un taux de paiement en baisse</strong> — Un taux de succès de paiement qui descend sous 95 % peut indiquer un problème technique avec le prestataire de paiement ou une vague de cartes expirées. Agissez rapidement.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous avez une vision claire de la santé financière de la plateforme et pouvez identifier les actions prioritaires pour maintenir ou accélérer la croissance des revenus.</p>',
                ],
                [
                    'title' => 'Gérer les plans et tarifs',
                    'slug' => 'gerer-plans-tarifs',
                    'excerpt' => 'Créez et configurez les plans tarifaires, définissez les prix et sélectionnez les modules inclus dans chaque offre.',
                    'content' => '<h2>Contexte</h2>
<p>Les plans tarifaires définissent l\'offre commerciale de Leezr. Chaque plan associe un ensemble de modules, un prix mensuel et des conditions spécifiques. La gestion des plans se fait depuis l\'onglet Plans du hub Facturation et impacte directement la facturation de toutes les entreprises souscrites.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder à la gestion des plans</strong> — Depuis le hub Facturation, cliquez sur l\'onglet « Plans ». La liste affiche tous les plans existants avec leur nom, prix, nombre d\'entreprises souscrites et statut (actif, archivé).</li>
<li><strong>Créer un nouveau plan</strong> — Cliquez sur « Nouveau plan ». Remplissez le nom, la description, le prix mensuel et le prix annuel (avec remise éventuelle). Sélectionnez les modules inclus dans le plan depuis le catalogue.</li>
<li><strong>Configurer les limites</strong> — Pour chaque plan, définissez les limites : nombre maximum d\'utilisateurs, volume de stockage, nombre de documents mensuels. Ces limites sont vérifiées automatiquement par le système.</li>
<li><strong>Définir la visibilité</strong> — Un plan peut être « public » (visible sur la page de tarification) ou « privé » (attribué manuellement par un admin). Les plans privés sont utiles pour les offres négociées ou les migrations.</li>
<li><strong>Archiver un plan</strong> — Pour retirer un plan de la vente sans impacter les clients existants, archivez-le. Les entreprises déjà souscrites conservent leur plan, mais aucune nouvelle souscription n\'est possible.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous lancez une nouvelle offre « Starter » destinée aux petites entreprises de transport avec moins de 5 chauffeurs. Vous créez le plan avec les modules Expéditions et Documents inclus, fixez le prix à 49 €/mois et limitez à 5 utilisateurs. Vous le configurez en « public » pour qu\'il apparaisse sur la page de tarification. Les entreprises en essai voient désormais cette option lors de la souscription.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Modifier le prix d\'un plan actif sans communiquer</strong> — Le changement de prix s\'applique au prochain cycle de facturation pour tous les clients du plan. Prévenez les clients concernés avant toute modification tarifaire.</li>
<li><strong>Supprimer un plan au lieu de l\'archiver</strong> — Ne supprimez jamais un plan qui a des entreprises souscrites. L\'archivage préserve l\'intégrité des données de facturation.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le plan est créé ou modifié, visible (ou non) pour les prospects, et les modules inclus sont correctement configurés. La facturation des entreprises souscrites est automatiquement mise à jour.</p>',
                ],
                [
                    'title' => 'Traiter les paiements et prélèvements',
                    'slug' => 'traiter-paiements-prelevements',
                    'excerpt' => 'Gérez les prélèvements programmés, les tentatives de paiement échouées et les encaissements manuels.',
                    'content' => '<h2>Contexte</h2>
<p>Les prélèvements automatiques sont le mécanisme principal de facturation dans Leezr. L\'onglet Prélèvements du hub Facturation permet de suivre les prélèvements programmés, d\'identifier les échecs et de lancer des actions de recouvrement manuelles lorsque nécessaire.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Consulter les prélèvements programmés</strong> — L\'onglet Prélèvements affiche la liste des prélèvements à venir avec la date d\'exécution, le montant, l\'entreprise concernée et le statut (programmé, en cours, réussi, échoué). Filtrez par statut pour isoler les problèmes.</li>
<li><strong>Analyser un échec de paiement</strong> — Cliquez sur un prélèvement échoué pour voir le détail : code d\'erreur du prestataire de paiement, nombre de tentatives effectuées, prochain essai automatique prévu. Les raisons courantes sont : carte expirée, fonds insuffisants, limite dépassée.</li>
<li><strong>Relancer manuellement un prélèvement</strong> — Pour un prélèvement échoué, cliquez sur « Relancer ». Le système tente immédiatement un nouveau prélèvement avec le moyen de paiement enregistré. Vérifiez auprès du client que ses informations bancaires sont à jour avant de relancer.</li>
<li><strong>Enregistrer un paiement manuel</strong> — Si le client paie par virement bancaire ou tout autre moyen hors plateforme, utilisez « Enregistrer un paiement » pour marquer la facture comme payée et documenter le mode de règlement.</li>
<li><strong>Configurer les règles de relance</strong> — Dans les paramètres de facturation, définissez le nombre de tentatives automatiques (par défaut 3), l\'intervalle entre les tentatives (par défaut 3 jours) et l\'action en cas d\'échec définitif (suspension automatique ou alerte admin).</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous remarquez dans l\'onglet Prélèvements que 8 prélèvements ont échoué ce mois-ci, soit le double du mois précédent. En examinant les codes d\'erreur, vous constatez que 6 d\'entre eux sont des erreurs « carte expirée ». Vous contactez les clients concernés par email pour qu\'ils mettent à jour leur moyen de paiement, puis vous planifiez une relance manuelle pour la semaine suivante.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Relancer sans vérifier le moyen de paiement</strong> — Si la carte est expirée, relancer ne servira à rien et peut déclencher des frais bancaires. Contactez d\'abord le client pour mettre à jour ses informations.</li>
<li><strong>Ne pas suivre les impayés récurrents</strong> — Un client avec des échecs de paiement répétés nécessite une intervention proactive. Configurez des alertes pour les clients avec plus de 2 échecs consécutifs.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Les prélèvements sont suivis, les échecs sont traités rapidement, et le taux de recouvrement est maximisé grâce à des relances ciblées et des règles automatiques.</p>',
                ],
                [
                    'title' => 'Émettre un avoir ou appliquer un coupon',
                    'slug' => 'emettre-avoir-appliquer-coupon',
                    'excerpt' => 'Créez des avoirs pour compenser des erreurs de facturation et gérez les coupons de réduction pour vos campagnes commerciales.',
                    'content' => '<h2>Contexte</h2>
<p>Les avoirs et les coupons sont des outils financiers essentiels pour la gestion commerciale de la plateforme. Les avoirs permettent de corriger des erreurs de facturation ou de compenser un incident de service. Les coupons permettent de proposer des réductions pour l\'acquisition ou la rétention de clients.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Émettre un avoir</strong> — Depuis la fiche de l\'entreprise, onglet Facturation, sélectionnez la facture concernée et cliquez sur « Émettre un avoir ». Indiquez le montant (total ou partiel), le motif et la méthode de remboursement (crédit sur le compte ou remboursement direct).</li>
<li><strong>Créer un coupon</strong> — Depuis l\'onglet Coupons du hub Facturation, cliquez sur « Nouveau coupon ». Définissez le code, le type de réduction (pourcentage ou montant fixe), la durée de validité, le nombre maximum d\'utilisations et les plans éligibles.</li>
<li><strong>Appliquer un coupon manuellement</strong> — Pour appliquer un coupon à une entreprise spécifique, ouvrez sa fiche, onglet Facturation, et cliquez sur « Appliquer un coupon ». Saisissez le code du coupon. La réduction sera appliquée au prochain cycle de facturation.</li>
<li><strong>Suivre l\'utilisation des coupons</strong> — La liste des coupons affiche le nombre d\'utilisations, le montant total de réduction accordé et les entreprises bénéficiaires. Utilisez ces données pour mesurer l\'efficacité de vos campagnes promotionnelles.</li>
<li><strong>Désactiver un coupon</strong> — Pour stopper une promotion, désactivez le coupon. Les entreprises qui l\'ont déjà appliqué conservent leur réduction jusqu\'à la fin de la période définie, mais aucune nouvelle utilisation n\'est possible.</li>
</ol>

<h2>Exemple concret</h2>
<p>Suite à une panne de 4 heures du module Expéditions, vous décidez de compenser les clients impactés. Vous identifiez les 15 entreprises qui ont utilisé le module pendant la période de panne. Pour chacune, vous émettez un avoir partiel correspondant à un prorata de 4 heures sur leur abonnement mensuel. Vous documentez l\'incident et les avoirs dans une note interne.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Émettre un avoir sans justification documentée</strong> — Chaque avoir doit avoir un motif clair et traçable. En cas d\'audit, les avoirs sans justification posent problème.</li>
<li><strong>Créer un coupon sans date d\'expiration</strong> — Un coupon sans limite de durée peut être utilisé indéfiniment. Définissez toujours une date de fin, même lointaine, pour garder le contrôle.</li>
</ul>

<h2>Résultat attendu</h2>
<p>L\'avoir est émis et visible dans l\'historique de facturation de l\'entreprise, ou le coupon est créé et prêt à être utilisé. Toutes les opérations sont tracées pour la comptabilité.</p>',
                ],
                [
                    'title' => 'Suivre les indicateurs de revenus',
                    'slug' => 'suivre-indicateurs-revenus',
                    'excerpt' => 'Analysez les tendances de revenus, le revenu moyen par client (ARPU), la valeur vie client (LTV) et les cohortes d\'abonnement.',
                    'content' => '<h2>Contexte</h2>
<p>Au-delà du MRR brut, les indicateurs avancés de revenus permettent de comprendre la dynamique économique de la plateforme. L\'ARPU (Average Revenue Per User), la LTV (Lifetime Value) et l\'analyse par cohortes aident à prendre des décisions stratégiques sur les prix, les investissements et les actions de rétention.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Consulter l\'ARPU</strong> — Dans le tableau de bord financier, la carte ARPU affiche le revenu moyen par entreprise cliente active. Suivez son évolution : un ARPU en hausse indique une montée en gamme des clients, en baisse il signale un problème de rétention sur les plans premium.</li>
<li><strong>Analyser la LTV</strong> — La LTV estimée combine l\'ARPU et la durée moyenne d\'abonnement. Une LTV élevée justifie des investissements plus importants en acquisition. Comparez la LTV par plan pour identifier les offres les plus rentables.</li>
<li><strong>Examiner les cohortes</strong> — Le graphique de cohortes regroupe les entreprises par mois d\'inscription et montre leur taux de rétention dans le temps. Identifiez les mois avec une rétention anormalement basse pour investiguer les causes (changement de prix, bug produit, saisonnalité).</li>
<li><strong>Décomposer le MRR</strong> — L\'analyse du MRR se décompose en : nouveau MRR (nouvelles souscriptions), expansion (upgrades), contraction (downgrades) et churn (résiliations). Cette décomposition révèle si la croissance vient de l\'acquisition ou de l\'expansion.</li>
<li><strong>Exporter les rapports</strong> — Générez des rapports financiers périodiques au format CSV pour les partager avec la direction ou les intégrer dans vos outils de reporting externes. Les rapports incluent tous les indicateurs avec l\'historique sur la période sélectionnée.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous préparez le bilan trimestriel. En consultant l\'analyse par cohortes, vous remarquez que la cohorte du mois de janvier a un taux de rétention de 60 % après 3 mois, contre 80 % pour les autres mois. En croisant avec les tickets de support, vous découvrez qu\'un bug dans le module Expéditions déployé en janvier a frustré les nouveaux clients. Cette information permet de prioriser les actions de rétention ciblées sur cette cohorte.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Se focaliser uniquement sur le MRR brut</strong> — Le MRR peut croître tout en masquant un churn élevé compensé par de l\'acquisition. Analysez toujours la décomposition du MRR pour comprendre les dynamiques sous-jacentes.</li>
<li><strong>Comparer des cohortes de tailles très différentes</strong> — Une cohorte de 5 entreprises n\'est pas statistiquement comparable à une cohorte de 50. Tenez compte de la taille de l\'échantillon dans vos analyses.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous disposez d\'une analyse approfondie des revenus permettant de prendre des décisions stratégiques éclairées sur les prix, l\'acquisition et la rétention client.</p>',
                ],
            ],
        ],

        //
        // ─── Topic 3: Modules & catalogue ────────────────────────────
        //
        [
            'title' => 'Modules & catalogue',
            'slug' => 'modules-catalogue',
            'description' => 'Administrez le catalogue de modules, les métiers (jobdomains), les champs personnalisés et les types de documents disponibles sur la plateforme.',
            'icon' => 'tabler-apps',
            'articles' => [
                [
                    'title' => 'Vue d\'ensemble du catalogue',
                    'slug' => 'vue-ensemble-catalogue',
                    'excerpt' => 'Consultez tous les modules disponibles, leur statut d\'activation global et les statistiques d\'utilisation par les entreprises clientes.',
                    'content' => '<h2>Contexte</h2>
<p>Le catalogue de modules est le cœur fonctionnel de Leezr. Chaque module représente une brique métier (Expéditions, Documents, Facturation client, etc.) que les entreprises peuvent activer selon leur plan. Le hub Catalogue permet aux administrateurs de superviser l\'ensemble des modules et leur adoption.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder au catalogue</strong> — Depuis le menu latéral plateforme, cliquez sur « Catalogue ». La page affiche une grille de cartes représentant chaque module avec son icône, son nom, le nombre d\'entreprises qui l\'utilisent et son statut (actif, en maintenance, désactivé).</li>
<li><strong>Analyser l\'adoption</strong> — Chaque carte de module affiche le taux d\'adoption (pourcentage d\'entreprises actives qui ont activé ce module). Identifiez les modules sous-utilisés qui pourraient nécessiter une meilleure mise en avant ou une refonte.</li>
<li><strong>Vérifier les dépendances</strong> — Certains modules dépendent d\'autres. Le catalogue affiche les dépendances sous forme de badges. Par exemple, le module « Livraisons » peut nécessiter le module « Expéditions » comme prérequis.</li>
<li><strong>Filtrer par catégorie</strong> — Utilisez les filtres pour afficher les modules par catégorie métier (logistique, finance, RH, documents) ou par statut. Cela facilite la gestion quand le catalogue s\'enrichit.</li>
<li><strong>Consulter les statistiques détaillées</strong> — Cliquez sur un module pour ouvrir sa fiche détaillée avec les statistiques d\'utilisation : nombre d\'entreprises, tendance d\'adoption sur 6 mois, revenus générés par ce module et incidents récents.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous lancez un nouveau module « Gestion de flotte » et souhaitez suivre son adoption. Dans le catalogue, vous voyez qu\'après 2 semaines, 12 entreprises sur 150 l\'ont activé (8 % d\'adoption). En comparant avec le lancement du module Documents (25 % après 2 semaines), vous identifiez un besoin de communication supplémentaire. Vous créez un coupon de lancement pour inciter les premiers adopteurs.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Mettre un module en maintenance sans prévenir</strong> — Le passage en maintenance bloque l\'accès pour toutes les entreprises. Planifiez les maintenances hors heures de pointe et notifiez les clients via le système d\'alertes.</li>
<li><strong>Ignorer les modules à faible adoption</strong> — Un module peu utilisé coûte en maintenance. Analysez régulièrement si les modules à faible adoption doivent être améliorés, regroupés ou retirés.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous avez une vue complète du catalogue de modules avec les indicateurs d\'adoption et d\'utilisation nécessaires pour piloter la stratégie produit.</p>',
                ],
                [
                    'title' => 'Configurer un module',
                    'slug' => 'configurer-module',
                    'excerpt' => 'Ajustez les paramètres d\'un module : tarification, dépendances, description marketplace et options de configuration.',
                    'content' => '<h2>Contexte</h2>
<p>Chaque module du catalogue possède des paramètres de configuration qui déterminent son comportement, son prix et sa présentation aux clients. La configuration d\'un module se fait depuis sa fiche détaillée dans le hub Catalogue et impacte toutes les entreprises qui l\'utilisent.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder à la configuration</strong> — Depuis le catalogue, cliquez sur un module puis sur l\'onglet « Configuration ». L\'interface affiche les sections : Général, Tarification, Dépendances et Description.</li>
<li><strong>Configurer la tarification</strong> — Définissez le prix mensuel du module lorsqu\'il est vendu en complément (hors plan). Si le module est inclus dans certains plans, indiquez-le dans la section des plans associés. Le prix peut être fixe ou basé sur l\'utilisation (par tranche).</li>
<li><strong>Gérer les dépendances</strong> — Spécifiez les modules prérequis. Quand un client tente d\'activer ce module, le système vérifie automatiquement que les dépendances sont satisfaites et propose de les activer si nécessaire.</li>
<li><strong>Rédiger la description marketplace</strong> — La description apparaît dans la page modules côté entreprise. Rédigez un titre accrocheur, une description détaillée des fonctionnalités et les bénéfices pour le client. Ajoutez des captures d\'écran si disponibles.</li>
<li><strong>Définir les options avancées</strong> — Certains modules ont des options de configuration globales : limites par défaut, fonctionnalités activables, intégrations tierces. Ces options s\'appliquent à toutes les entreprises sauf override individuel.</li>
</ol>

<h2>Exemple concret</h2>
<p>Le module « Documents » a été lancé avec un prix de 15 €/mois en complément. Après 3 mois, les retours montrent que le prix est un frein pour les petites entreprises. Vous modifiez la tarification en passant à 9 €/mois pour les plans Starter et maintenez 15 €/mois pour les plans Pro. Vous mettez à jour la description marketplace pour mettre en avant les fonctionnalités de gestion d\'expiration automatique.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Modifier la tarification sans impact assessment</strong> — Avant de changer un prix, vérifiez le nombre d\'entreprises impactées et l\'impact sur le MRR. Utilisez le tableau de bord financier pour simuler l\'effet.</li>
<li><strong>Créer des dépendances circulaires</strong> — Le module A ne peut pas dépendre de B si B dépend déjà de A. Le système bloque ces cas, mais vérifiez la logique métier de vos dépendances.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le module est configuré avec les bons paramètres de tarification, dépendances et description. Les entreprises voient la version à jour dans leur espace modules.</p>',
                ],
                [
                    'title' => 'Gérer les métiers (jobdomains)',
                    'slug' => 'gerer-metiers-jobdomains',
                    'excerpt' => 'Créez et configurez les métiers du transport et de la logistique, associez-leur des types de documents et des champs spécifiques.',
                    'content' => '<h2>Contexte</h2>
<p>Les métiers (jobdomains) structurent l\'offre de Leezr par secteur d\'activité : transport routier, messagerie, déménagement, logistique urbaine, etc. Chaque métier détermine les types de documents requis, les champs personnalisés disponibles et les réglementations applicables. La gestion des métiers se fait depuis le hub Catalogue.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder à la gestion des métiers</strong> — Depuis le hub Catalogue, cliquez sur l\'onglet « Métiers ». La liste affiche tous les métiers configurés avec leur nom, le nombre d\'entreprises associées et le nombre de types de documents liés.</li>
<li><strong>Créer un nouveau métier</strong> — Cliquez sur « Nouveau métier ». Renseignez le nom, la description, l\'icône et le code interne. Le code est utilisé dans les règles métier et ne peut pas être modifié après création.</li>
<li><strong>Associer des types de documents</strong> — Dans l\'onglet Documents du métier, sélectionnez les types de documents applicables : permis de conduire, carte de qualification, visite médicale, attestation de capacité, etc. Définissez lesquels sont obligatoires.</li>
<li><strong>Configurer les champs spécifiques</strong> — Chaque métier peut avoir des champs personnalisés qui s\'ajoutent aux formulaires des entreprises de ce secteur. Par exemple, le métier « Transport de matières dangereuses » nécessite le champ « Numéro ADR ».</li>
<li><strong>Définir les réglementations</strong> — Associez les réglementations applicables au métier : durée de validité des documents, fréquence des contrôles, obligations de formation. Ces règles alimentent les alertes automatiques d\'expiration.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous ajoutez le métier « Logistique urbaine » pour répondre à la demande de livreurs de dernière mile. Vous créez le métier avec le code « URBAN_LOGISTICS », associez les types de documents « Permis de conduire », « Assurance RC » et « Vignette Crit\'Air ». Vous ajoutez un champ personnalisé « Zone de livraison autorisée ». Les entreprises qui sélectionnent ce métier à l\'inscription voient automatiquement ces champs et documents requis.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Modifier un code de métier existant</strong> — Le code est utilisé dans les règles automatiques et les API. Le modifier casserait les intégrations existantes. Créez plutôt un nouveau métier et migrez les entreprises.</li>
<li><strong>Ne pas tester les formulaires après ajout de champs</strong> — Après avoir ajouté des champs personnalisés à un métier, vérifiez le rendu dans l\'interface entreprise pour vous assurer que les formulaires restent utilisables.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le métier est créé avec ses types de documents, champs personnalisés et réglementations. Les nouvelles entreprises de ce secteur héritent automatiquement de cette configuration.</p>',
                ],
                [
                    'title' => 'Configurer les champs personnalisés',
                    'slug' => 'configurer-champs-personnalises',
                    'excerpt' => 'Définissez des champs personnalisés par module ou entité : types de champs, validation, valeurs par défaut et règles d\'affichage.',
                    'content' => '<h2>Contexte</h2>
<p>Les champs personnalisés permettent d\'adapter Leezr aux besoins spécifiques de chaque secteur ou client sans modifier le code. Ils peuvent être ajoutés à différentes entités (membres, véhicules, expéditions, documents) et sont configurés depuis le hub Catalogue, section Champs personnalisés.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder à la gestion des champs</strong> — Depuis le hub Catalogue, cliquez sur « Champs personnalisés ». La liste affiche tous les champs définis, regroupés par entité cible (Membre, Véhicule, Expédition, Document). Chaque champ affiche son type, son caractère obligatoire et le nombre de métiers qui l\'utilisent.</li>
<li><strong>Créer un champ</strong> — Cliquez sur « Nouveau champ ». Sélectionnez l\'entité cible, puis le type de champ : texte, nombre, date, sélection (liste déroulante), case à cocher, fichier. Donnez-lui un nom technique (snake_case) et un libellé affiché.</li>
<li><strong>Configurer la validation</strong> — Définissez les règles de validation : champ obligatoire ou optionnel, longueur minimale/maximale, format (email, téléphone, numéro), plage de valeurs autorisées. Les règles sont appliquées côté serveur et côté client.</li>
<li><strong>Définir les options d\'affichage</strong> — Configurez l\'ordre d\'affichage dans le formulaire, le placeholder (texte d\'aide), la valeur par défaut et la visibilité conditionnelle (afficher uniquement si un autre champ a une certaine valeur).</li>
<li><strong>Associer aux métiers</strong> — Sélectionnez les métiers pour lesquels ce champ est pertinent. Un champ associé au métier « Transport frigorifique » n\'apparaîtra que dans les formulaires des entreprises de ce secteur.</li>
</ol>

<h2>Exemple concret</h2>
<p>Les entreprises de transport frigorifique ont besoin de tracer la température de consigne pour chaque expédition. Vous créez un champ personnalisé « temperature_consigne » de type nombre sur l\'entité Expédition, avec une plage de -30 à +25 °C et le caractère obligatoire. Vous l\'associez au métier « Transport frigorifique ». Désormais, les entreprises de ce secteur voient ce champ dans leur formulaire de création d\'expédition.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Rendre un champ obligatoire rétroactivement</strong> — Si vous rendez un champ existant obligatoire, les enregistrements antérieurs qui n\'ont pas cette donnée provoqueront des erreurs de validation à la modification. Prévoyez une migration ou un remplissage par défaut.</li>
<li><strong>Utiliser des noms techniques non normalisés</strong> — Le nom technique doit être en snake_case sans caractères spéciaux. Il est utilisé dans les exports et les API. Un nom mal formé causera des problèmes d\'intégration.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le champ personnalisé est créé, validé et associé aux bons métiers. Il apparaît automatiquement dans les formulaires des entreprises concernées sans intervention technique supplémentaire.</p>',
                ],
                [
                    'title' => 'Gérer les types de documents',
                    'slug' => 'gerer-types-documents',
                    'excerpt' => 'Administrez le catalogue de types de documents : champs requis, règles d\'expiration et association aux métiers.',
                    'content' => '<h2>Contexte</h2>
<p>Les types de documents définissent les pièces administratives et réglementaires que les entreprises doivent gérer dans Leezr : permis de conduire, carte de qualification professionnelle, certificat ADR, visite médicale, etc. Chaque type de document a des champs requis, des règles d\'expiration et une association à un ou plusieurs métiers.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder au catalogue de documents</strong> — Depuis le hub Catalogue, cliquez sur « Types de documents ». La liste affiche tous les types configurés avec leur nom, le nombre de métiers associés, le nombre de documents enregistrés dans le système et la règle d\'expiration.</li>
<li><strong>Créer un type de document</strong> — Cliquez sur « Nouveau type ». Renseignez le nom (ex : « Carte de qualification conducteur »), la description, la catégorie (identité, réglementaire, véhicule, entreprise) et le code interne.</li>
<li><strong>Définir les champs requis</strong> — Sélectionnez les champs obligatoires lors de l\'upload : numéro du document, date de délivrance, date d\'expiration, organisme émetteur, fichier numérisé. Certains champs peuvent être optionnels selon le contexte.</li>
<li><strong>Configurer les règles d\'expiration</strong> — Définissez la durée de validité standard (ex : 5 ans pour une carte de qualification) et les seuils d\'alerte : première alerte à 90 jours avant expiration, rappel à 30 jours, alerte urgente à 7 jours. Ces alertes sont envoyées automatiquement aux entreprises.</li>
<li><strong>Associer aux métiers</strong> — Liez le type de document aux métiers concernés et indiquez s\'il est obligatoire ou recommandé pour chaque métier. Un document obligatoire bloque certaines opérations (ex : affectation d\'un chauffeur) si absent ou expiré.</li>
</ol>

<h2>Exemple concret</h2>
<p>La réglementation impose un nouveau certificat de formation éco-conduite pour les chauffeurs de poids lourds. Vous créez le type de document « Certificat éco-conduite » avec les champs : numéro de certificat, date de formation, date d\'expiration (validité 3 ans), organisme de formation. Vous l\'associez comme obligatoire aux métiers « Transport routier » et « Transport longue distance ». Les entreprises de ces secteurs reçoivent une notification les informant du nouveau document à renseigner.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Oublier de configurer les alertes d\'expiration</strong> — Un type de document sans alerte d\'expiration ne protège pas les entreprises contre les documents périmés. Configurez toujours au moins un seuil d\'alerte.</li>
<li><strong>Rendre obligatoire un document sans période de grâce</strong> — Si vous ajoutez un document obligatoire, laissez aux entreprises le temps de le collecter. Utilisez le statut « recommandé » pendant une période de transition avant de passer en « obligatoire ».</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le type de document est créé avec ses champs, ses règles d\'expiration et ses associations métier. Les entreprises concernées sont notifiées et le système surveille automatiquement les expirations.</p>',
                ],
            ],
        ],

        //
        // ─── Topic 4: Opérations & monitoring ────────────────────────
        //
        [
            'title' => 'Opérations & monitoring',
            'slug' => 'operations-monitoring',
            'description' => 'Surveillez la santé du système, gérez les alertes, suivez l\'utilisation et contrôlez les automatisations de la plateforme.',
            'icon' => 'tabler-activity',
            'articles' => [
                [
                    'title' => 'Tableau de bord système (System Health)',
                    'slug' => 'tableau-bord-systeme',
                    'excerpt' => 'Surveillez en temps réel l\'état des files d\'attente, du cache, du stockage, de la messagerie et du planificateur de tâches.',
                    'content' => '<h2>Contexte</h2>
<p>Le tableau de bord System Health est le centre de contrôle technique de la plateforme Leezr. Il affiche l\'état en temps réel des composants critiques : files d\'attente (queues), cache Redis, stockage disque, délivrabilité email et exécution du planificateur de tâches. C\'est la première page à consulter en cas d\'incident.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder au System Health</strong> — Depuis le hub Opérations dans le menu latéral plateforme, cliquez sur « Santé système ». Le tableau de bord affiche des cartes d\'état pour chaque composant avec un code couleur : vert (nominal), orange (dégradé), rouge (critique).</li>
<li><strong>Vérifier les files d\'attente</strong> — La carte Queue affiche le nombre de jobs en attente, en cours et échoués pour chaque queue (default, ai). Un nombre élevé de jobs en attente indique un problème de traitement. Vérifiez que les workers sont actifs.</li>
<li><strong>Surveiller le cache</strong> — La carte Cache affiche le taux de hit (pourcentage de requêtes servies par le cache), la mémoire utilisée et le nombre de clés. Un taux de hit inférieur à 80 % suggère un problème de configuration ou des clés qui expirent trop vite.</li>
<li><strong>Contrôler le stockage</strong> — La carte Stockage montre l\'espace disque utilisé et disponible. Une alerte automatique se déclenche quand l\'utilisation dépasse 85 %. Identifiez les répertoires les plus volumineux (logs, uploads, backups) pour agir.</li>
<li><strong>Vérifier le planificateur</strong> — La carte Scheduler liste les tâches planifiées avec leur dernière exécution, le prochain run prévu et le statut. Une tâche en « overdue » n\'a pas été exécutée à l\'heure prévue, ce qui peut indiquer un problème de cron.</li>
</ol>

<h2>Exemple concret</h2>
<p>Un client signale que ses emails de notification ne partent plus. Vous ouvrez le System Health et constatez que la carte Email affiche un statut rouge avec 45 emails bloqués en queue. La carte Queue confirme que la queue « default » a 120 jobs en attente. Vous vérifiez le worker et découvrez qu\'il s\'est arrêté suite à une erreur mémoire. Vous le redémarrez et les emails partent progressivement.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Ignorer les alertes orange</strong> — Un statut « dégradé » orange est un avertissement précoce. Si vous attendez qu\'il passe en rouge, l\'impact utilisateur est déjà réel. Traitez les orange en priorité pendant les heures de bureau.</li>
<li><strong>Ne pas vérifier le scheduler régulièrement</strong> — Les tâches planifiées (facturation, nettoyage, synchronisation) sont critiques mais silencieuses. Une tâche en échec peut passer inaperçue pendant des jours si vous ne consultez pas le System Health.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous avez une vision instantanée de la santé de tous les composants techniques et pouvez détecter et résoudre les problèmes avant qu\'ils n\'impactent les utilisateurs.</p>',
                ],
                [
                    'title' => 'Centre d\'alertes',
                    'slug' => 'centre-alertes',
                    'excerpt' => 'Gérez les alertes de la plateforme : types d\'alertes, détection automatique, règles d\'escalade et historique des incidents.',
                    'content' => '<h2>Contexte</h2>
<p>Le centre d\'alertes centralise toutes les notifications opérationnelles de la plateforme. Il distingue trois niveaux de gravité : critique (action immédiate requise), avertissement (attention nécessaire) et information (suivi). Les alertes sont générées automatiquement par les systèmes de monitoring et peuvent être escaladées selon des règles configurables.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Consulter les alertes actives</strong> — Depuis le hub Opérations, cliquez sur « Alertes ». La page affiche les alertes actives triées par gravité. Les alertes critiques apparaissent en premier avec un badge rouge. Le compteur dans la barre de navigation indique le nombre d\'alertes non acquittées.</li>
<li><strong>Acquitter une alerte</strong> — Cliquez sur une alerte pour voir son détail : source, horodatage, description technique, impact estimé. Cliquez sur « Acquitter » pour indiquer que vous avez pris connaissance du problème. L\'alerte reste visible mais ne déclenche plus de notifications.</li>
<li><strong>Résoudre une alerte</strong> — Après avoir traité la cause de l\'alerte, cliquez sur « Résoudre » et ajoutez un commentaire décrivant l\'action corrective. L\'alerte passe en statut « Résolu » et rejoint l\'historique.</li>
<li><strong>Configurer les règles d\'escalade</strong> — Dans les paramètres du centre d\'alertes, définissez les règles d\'escalade : si une alerte critique n\'est pas acquittée sous 15 minutes, un email est envoyé à l\'équipe de garde. Après 30 minutes, un SMS est envoyé au responsable technique.</li>
<li><strong>Consulter l\'historique</strong> — L\'onglet Historique affiche toutes les alertes passées avec leur durée de résolution. Utilisez ces données pour identifier les problèmes récurrents et améliorer la stabilité de la plateforme.</li>
</ol>

<h2>Exemple concret</h2>
<p>À 14h32, une alerte critique « Queue AI saturée — 500+ jobs en attente » apparaît. Vous l\'acquittez immédiatement, puis vérifiez dans le System Health que le worker AI s\'est arrêté. Vous le redémarrez via la console. En 10 minutes, la queue se vide. Vous résolvez l\'alerte avec le commentaire « Worker AI redémarré — cause : OOM kill par le système ». Vous créez ensuite un ticket technique pour augmenter la mémoire allouée au worker.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Acquitter sans investiguer</strong> — Acquitter une alerte ne la résout pas. C\'est juste une prise de connaissance. Assurez-vous de traiter la cause racine avant de marquer comme résolu.</li>
<li><strong>Ne pas configurer les escalades</strong> — Sans règle d\'escalade, une alerte critique le week-end peut rester non traitée pendant des heures. Configurez toujours une chaîne d\'escalade avec des délais raisonnables.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Les alertes sont traitées rapidement, les escalades fonctionnent correctement et l\'historique permet d\'améliorer continuellement la fiabilité de la plateforme.</p>',
                ],
                [
                    'title' => 'Monitoring d\'utilisation',
                    'slug' => 'monitoring-utilisation',
                    'excerpt' => 'Suivez les métriques d\'utilisation par entreprise : appels API, consommation de stockage, utilisateurs actifs et tendances.',
                    'content' => '<h2>Contexte</h2>
<p>Le monitoring d\'utilisation permet de suivre la consommation des ressources par chaque entreprise cliente. Ces données sont essentielles pour détecter les anomalies, anticiper les besoins en infrastructure et identifier les clients à fort potentiel de croissance ou à risque de churn.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder au monitoring</strong> — Depuis le hub Opérations, cliquez sur « Utilisation ». Le tableau de bord affiche les métriques agrégées : nombre total d\'utilisateurs actifs, volume de stockage consommé, nombre de requêtes API et tendance sur 30 jours.</li>
<li><strong>Filtrer par entreprise</strong> — Utilisez le sélecteur d\'entreprise pour voir les métriques d\'un client spécifique. Le graphique affiche l\'évolution quotidienne des utilisateurs actifs, du stockage et des appels API pour cette entreprise.</li>
<li><strong>Identifier les anomalies</strong> — Le système détecte automatiquement les pics anormaux : une entreprise qui triple soudainement son utilisation API peut indiquer une intégration défaillante ou une activité suspecte. Ces anomalies sont signalées par un badge d\'alerte.</li>
<li><strong>Analyser les tendances</strong> — L\'onglet Tendances affiche les prévisions de consommation basées sur l\'historique. Si le stockage global approche de la capacité, vous pouvez planifier une extension d\'infrastructure avant l\'impact.</li>
<li><strong>Exporter les données</strong> — Générez des rapports de consommation par entreprise pour la facturation basée sur l\'usage ou pour les audits de performance. Les exports incluent les métriques quotidiennes sur la période sélectionnée.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous remarquez qu\'une entreprise consomme 12 Go de stockage alors que la moyenne est de 2 Go. En filtrant sur cette entreprise, vous constatez que le stockage a bondi de 3 Go à 12 Go en une semaine. En analysant les fichiers, vous découvrez que l\'entreprise upload des scans de documents en haute résolution non compressés. Vous la contactez pour proposer un paramètre de compression automatique et envisagez de revoir les limites de stockage du plan.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Ignorer les pics d\'utilisation API</strong> — Un pic peut indiquer un script défaillant qui boucle et surcharge le serveur. Investiguez rapidement pour protéger la performance globale de la plateforme.</li>
<li><strong>Ne pas corréler utilisation et facturation</strong> — Si votre modèle de pricing inclut des limites d\'utilisation, vérifiez régulièrement que les entreprises qui dépassent sont correctement facturées ou notifiées.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous avez une visibilité complète sur la consommation de chaque entreprise et pouvez anticiper les besoins d\'infrastructure et les ajustements tarifaires.</p>',
                ],
                [
                    'title' => 'Gestion des automatisations',
                    'slug' => 'gestion-automatisations',
                    'excerpt' => 'Contrôlez les tâches planifiées, les jobs de queue, les règles d\'automatisation et consultez les logs d\'exécution.',
                    'content' => '<h2>Contexte</h2>
<p>Leezr s\'appuie sur de nombreuses automatisations pour fonctionner : tâches planifiées (facturation, nettoyage, synchronisation), jobs de queue (envoi d\'emails, traitement IA, génération de documents) et règles métier automatiques (alertes d\'expiration, relances de paiement). La page Automatisations permet de superviser et contrôler tous ces mécanismes.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Consulter les tâches planifiées</strong> — La section Scheduler affiche toutes les tâches cron avec leur fréquence (chaque minute, horaire, quotidien, hebdomadaire), leur dernière exécution, la durée et le statut. Les tâches en échec sont mises en évidence.</li>
<li><strong>Exécuter manuellement une tâche</strong> — Pour chaque tâche planifiée, un bouton « Exécuter maintenant » permet de lancer un run immédiat. C\'est utile pour les tests ou pour rattraper une exécution manquée. L\'exécution manuelle est tracée dans les logs.</li>
<li><strong>Superviser les queues</strong> — La section Queues affiche le nombre de jobs par queue (default, ai), le taux de traitement par minute et les jobs échoués avec leur message d\'erreur. Vous pouvez relancer les jobs échoués individuellement ou en lot.</li>
<li><strong>Consulter les logs d\'exécution</strong> — Chaque tâche et chaque job a un historique d\'exécution consultable. Les logs affichent la durée, le résultat, les éventuelles erreurs et les données traitées. Filtrez par date ou par statut pour investiguer un problème.</li>
<li><strong>Configurer les notifications d\'échec</strong> — Dans les paramètres, définissez qui reçoit les notifications quand une tâche planifiée échoue ou quand le nombre de jobs échoués dépasse un seuil. Ces alertes sont intégrées au centre d\'alertes.</li>
</ol>

<h2>Exemple concret</h2>
<p>Le matin, vous vérifiez les automatisations et constatez que la tâche « Collecte des snapshots d\'utilisation » a échoué cette nuit avec l\'erreur « Connection to Redis timed out ». Vous vérifiez dans le System Health que Redis fonctionne (il a été redémarré automatiquement après un pic mémoire). Vous relancez la tâche manuellement et elle s\'exécute correctement. Vous ajoutez un commentaire dans l\'historique pour documenter l\'incident transitoire.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Relancer un job échoué sans comprendre la cause</strong> — Si le job a échoué à cause d\'une erreur de données, le relancer produira le même résultat. Lisez le message d\'erreur et corrigez la cause avant de relancer.</li>
<li><strong>Modifier la fréquence d\'une tâche sans évaluer l\'impact</strong> — Certaines tâches sont interdépendantes. Par exemple, la collecte de métriques doit tourner avant le calcul des alertes. Vérifiez les dépendances temporelles avant de modifier les horaires.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Toutes les automatisations sont supervisées, les échecs sont détectés et traités rapidement, et les logs fournissent une traçabilité complète de l\'activité automatique de la plateforme.</p>',
                ],
                [
                    'title' => 'Suivi temps réel',
                    'slug' => 'suivi-temps-reel',
                    'excerpt' => 'Surveillez les connexions SSE, les utilisateurs actifs et les événements en temps réel du tableau de bord plateforme.',
                    'content' => '<h2>Contexte</h2>
<p>Leezr utilise les Server-Sent Events (SSE) pour fournir des mises à jour en temps réel aux utilisateurs : notifications, alertes, progression des traitements IA et actualisation des tableaux de bord. La page de suivi temps réel permet de surveiller ces connexions et l\'activité en direct sur la plateforme.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder au suivi temps réel</strong> — Depuis le hub Opérations, cliquez sur « Temps réel ». Le tableau de bord affiche le nombre de connexions SSE actives, la liste des utilisateurs connectés et un flux d\'événements en direct.</li>
<li><strong>Surveiller les connexions SSE</strong> — La carte SSE affiche le nombre total de connexions actives et la répartition par type (notifications, alertes, dashboard). Un nombre anormalement élevé peut indiquer des reconnexions en boucle causées par un problème réseau ou serveur.</li>
<li><strong>Identifier les utilisateurs actifs</strong> — La liste des utilisateurs connectés affiche le nom, l\'entreprise, la page visitée et la durée de la session. Cela permet de comprendre les patterns d\'utilisation et d\'identifier les sessions anormalement longues.</li>
<li><strong>Lire le flux d\'événements</strong> — Le flux en direct affiche les événements système au fil de l\'eau : connexions, déconnexions, actions utilisateur, alertes générées, jobs traités. Filtrez par type d\'événement ou par entreprise pour cibler votre surveillance.</li>
<li><strong>Détecter les problèmes de performance</strong> — Si le temps de réponse des événements SSE augmente, cela peut signaler une surcharge du serveur. Comparez le nombre de connexions actives avec les métriques système (CPU, mémoire) pour diagnostiquer.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous recevez un signalement qu\'un client ne reçoit plus les notifications en temps réel dans son tableau de bord. Vous ouvrez le suivi temps réel et recherchez les connexions de cette entreprise. Vous constatez qu\'aucun utilisateur de cette entreprise n\'a de connexion SSE active, alors que 3 sont connectés. Vous vérifiez les logs et découvrez que leur navigateur bloque les connexions SSE à cause d\'un proxy entreprise. Vous les guidez vers la configuration réseau à ajuster.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Confondre connexions SSE et utilisateurs actifs</strong> — Un utilisateur peut avoir plusieurs connexions SSE ouvertes (plusieurs onglets). Le nombre de connexions est toujours supérieur ou égal au nombre d\'utilisateurs actifs.</li>
<li><strong>Ne pas surveiller les reconnexions en boucle</strong> — Un client avec un problème réseau peut générer des centaines de tentatives de reconnexion par minute, surchargeant le serveur. Configurez un rate limiting sur les connexions SSE.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous avez une visibilité en temps réel sur l\'activité de la plateforme et pouvez détecter et diagnostiquer les problèmes de connectivité et de performance immédiatement.</p>',
                ],
            ],
        ],

        //
        // ─── Topic 5: Support & SLA ──────────────────────────────────
        //
        [
            'title' => 'Support & SLA',
            'slug' => 'support-sla',
            'description' => 'Gérez les tickets de support, suivez les engagements SLA, analysez les tendances et maîtrisez les escalades d\'incidents.',
            'icon' => 'tabler-headset',
            'articles' => [
                [
                    'title' => 'Vue d\'ensemble des tickets',
                    'slug' => 'vue-ensemble-tickets',
                    'excerpt' => 'Consultez le tableau de bord des tickets de support avec les KPI clés : tickets ouverts, temps de réponse moyen et conformité SLA.',
                    'content' => '<h2>Contexte</h2>
<p>Le hub Support centralise la gestion de toutes les demandes clients. Le tableau de bord affiche les métriques clés pour piloter l\'activité du support : nombre de tickets ouverts, temps de réponse moyen, taux de conformité SLA et répartition par priorité. C\'est l\'outil quotidien de l\'équipe de support plateforme.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder au support</strong> — Depuis le menu latéral plateforme, cliquez sur « Support ». Le tableau de bord affiche les cartes KPI en haut : tickets ouverts, temps moyen de première réponse, taux de résolution et conformité SLA globale.</li>
<li><strong>Analyser la répartition</strong> — Le graphique en donut montre la répartition des tickets par priorité (critique, haute, normale, basse) et par statut (ouvert, en cours, en attente client, résolu). Identifiez les déséquilibres : trop de tickets critiques signale un problème systémique.</li>
<li><strong>Filtrer et rechercher</strong> — Utilisez les filtres pour afficher les tickets par entreprise, par agent assigné, par priorité ou par date. La barre de recherche permet de trouver un ticket par son numéro ou par mots-clés dans le titre et la description.</li>
<li><strong>Trier par urgence</strong> — Le tri par défaut place les tickets proches de leur deadline SLA en premier. Les tickets dont le SLA va être violé dans l\'heure sont surlignés en rouge pour action immédiate.</li>
<li><strong>Exporter les métriques</strong> — Générez un rapport de performance du support pour une période donnée. Le rapport inclut les volumes, les temps de réponse, les taux de satisfaction et la conformité SLA par agent et par priorité.</li>
</ol>

<h2>Exemple concret</h2>
<p>En début de semaine, vous consultez le tableau de bord et constatez que le taux de conformité SLA est descendu à 78 % (objectif : 95 %). En filtrant par priorité « haute », vous découvrez que 5 tickets haute priorité sont restés sans réponse pendant le week-end car l\'agent de garde n\'était pas disponible. Vous les assignez immédiatement et décidez de revoir la rotation des gardes pour couvrir les week-ends.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Se focaliser sur le nombre de tickets ouverts uniquement</strong> — Le volume seul ne suffit pas. Un petit nombre de tickets mais avec des SLA violés est plus problématique qu\'un grand nombre de tickets traités dans les temps.</li>
<li><strong>Ne pas revoir régulièrement les métriques</strong> — Les tendances de support révèlent les problèmes produit récurrents. Une revue hebdomadaire des métriques permet d\'anticiper et de corriger les causes racines.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous avez une vue claire de l\'activité du support, des performances de l\'équipe et des tickets nécessitant une attention prioritaire pour maintenir la conformité SLA.</p>',
                ],
                [
                    'title' => 'Assigner et traiter un ticket',
                    'slug' => 'assigner-traiter-ticket',
                    'excerpt' => 'Suivez le workflow d\'assignation des tickets : prise en charge, niveaux de priorité, notes internes et communication client.',
                    'content' => '<h2>Contexte</h2>
<p>Le traitement d\'un ticket suit un workflow structuré : réception, triage, assignation, investigation, réponse et résolution. Chaque étape est tracée et contribue aux métriques SLA. La page de détail d\'un ticket offre tous les outils nécessaires pour traiter efficacement la demande.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Trier le ticket entrant</strong> — Quand un nouveau ticket arrive, évaluez la priorité en fonction de l\'impact et de l\'urgence. Critique : service complètement indisponible. Haute : fonctionnalité majeure dégradée. Normale : question ou demande. Basse : suggestion ou amélioration.</li>
<li><strong>Assigner à un agent</strong> — Depuis la page du ticket, sélectionnez l\'agent le plus qualifié dans le menu d\'assignation. Tenez compte de la charge actuelle de chaque agent (visible dans le tableau de bord) et de son expertise technique.</li>
<li><strong>Ajouter des notes internes</strong> — Utilisez l\'espace de notes internes pour documenter votre investigation, les hypothèses et les actions techniques effectuées. Ces notes sont visibles uniquement par l\'équipe plateforme, jamais par le client.</li>
<li><strong>Répondre au client</strong> — La réponse client est rédigée dans la section « Réponse publique ». Soyez clair, empathique et fournissez une solution ou un plan d\'action avec une estimation de délai. Chaque réponse remet à zéro le timer SLA de suivi.</li>
<li><strong>Résoudre le ticket</strong> — Quand le problème est résolu, changez le statut en « Résolu » avec un résumé de la solution. Le client reçoit une notification et peut rouvrir le ticket s\'il n\'est pas satisfait. Après 7 jours sans réponse, le ticket passe automatiquement en « Fermé ».</li>
</ol>

<h2>Exemple concret</h2>
<p>Un ticket arrive avec le titre « Import CSV échoue systématiquement ». Vous le triez en priorité « normale » et l\'assignez à l\'agent technique de garde. L\'agent ajoute une note interne : « Testé avec le fichier du client — erreur d\'encodage UTF-8 détectée sur la colonne adresse ». Il répond au client avec des instructions pour convertir le fichier en UTF-8 et propose de traiter l\'import manuellement en attendant une correction automatique du parseur.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Répondre sans investiguer</strong> — Une réponse générique (« Avez-vous essayé de vous reconnecter ? ») frustre le client et gaspille un cycle de SLA. Investiguez la cause avant de répondre, même si cela prend plus de temps.</li>
<li><strong>Oublier les notes internes</strong> — Si un ticket est réassigné, l\'agent suivant perd le contexte sans notes internes. Documentez toujours vos actions et trouvailles, même pour les tickets simples.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le ticket est traité selon le workflow standard, la communication client est professionnelle et documentée, et les métriques SLA sont respectées.</p>',
                ],
                [
                    'title' => 'Suivre les SLA',
                    'slug' => 'suivre-sla',
                    'excerpt' => 'Maîtrisez les règles SLA par niveau de priorité, la détection des violations et les mécanismes d\'escalade automatique.',
                    'content' => '<h2>Contexte</h2>
<p>Les SLA (Service Level Agreements) définissent les engagements de temps de réponse et de résolution pour chaque niveau de priorité. Leezr surveille automatiquement le respect de ces engagements et déclenche des alertes et escalades quand un SLA est sur le point d\'être violé ou l\'a été.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Consulter les règles SLA</strong> — Depuis les paramètres du support, consultez le tableau des SLA : priorité critique = première réponse sous 1h, résolution sous 4h. Haute = réponse sous 4h, résolution sous 24h. Normale = réponse sous 8h, résolution sous 72h. Basse = réponse sous 24h, résolution sous 1 semaine.</li>
<li><strong>Surveiller les compteurs</strong> — Sur chaque ticket, un compteur affiche le temps restant avant violation du SLA de réponse et de résolution. Le compteur passe en orange à 50 % du temps écoulé et en rouge à 80 %. Les heures comptées sont les heures ouvrées (lundi-vendredi, 9h-18h).</li>
<li><strong>Recevoir les alertes de violation imminente</strong> — Le système envoie une notification à l\'agent assigné quand un ticket atteint 80 % du temps SLA. Si l\'agent n\'a pas agi à 100 %, une alerte est envoyée au responsable support avec le détail du ticket.</li>
<li><strong>Analyser la conformité</strong> — L\'onglet SLA du tableau de bord support affiche le taux de conformité par période, par agent et par priorité. Le graphique de tendance montre l\'évolution sur les 3 derniers mois. Visez un taux de conformité supérieur à 95 %.</li>
<li><strong>Ajuster les seuils</strong> — Si les SLA sont systématiquement violés pour un niveau de priorité, réévaluez les seuils en fonction de la capacité réelle de l\'équipe. Des SLA irréalistes démotivent l\'équipe et faussent les métriques.</li>
</ol>

<h2>Exemple concret</h2>
<p>Le rapport mensuel montre que le SLA de première réponse pour les tickets « haute priorité » est respecté à 88 % contre l\'objectif de 95 %. En analysant les violations, vous constatez qu\'elles surviennent principalement entre 17h et 18h, quand l\'équipe est réduite. Vous décidez de décaler les horaires d\'un agent pour couvrir cette tranche et de mettre en place une notification Slack supplémentaire à 75 % du SLA pour les tickets haute priorité.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Compter les heures non ouvrées dans le SLA</strong> — Par défaut, les SLA comptent uniquement les heures ouvrées. Un ticket créé le vendredi à 17h ne consomme pas de temps SLA pendant le week-end. Vérifiez que la configuration est correcte.</li>
<li><strong>Ne pas distinguer SLA de réponse et SLA de résolution</strong> — Répondre dans les temps ne suffit pas. Le SLA de résolution mesure le temps total jusqu\'à la résolution effective. Un ticket avec une réponse rapide mais une résolution lente viole quand même le SLA.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Les SLA sont suivis en temps réel, les violations sont détectées et escaladées automatiquement, et les rapports de conformité permettent d\'optimiser les performances du support.</p>',
                ],
                [
                    'title' => 'Analyser les tickets récurrents',
                    'slug' => 'analyser-tickets-recurrents',
                    'excerpt' => 'Identifiez les problèmes récurrents, les lacunes de la base de connaissances et les recherches infructueuses des utilisateurs.',
                    'content' => '<h2>Contexte</h2>
<p>L\'analyse des tickets récurrents est une démarche proactive qui vise à réduire le volume de support en identifiant les problèmes fréquents et en améliorant la documentation. Si le même type de question revient régulièrement, c\'est soit un problème produit à corriger, soit un manque dans le centre d\'aide à combler.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Consulter les catégories de tickets</strong> — L\'onglet Analyse du hub Support affiche les tickets regroupés par catégorie et sous-catégorie. Identifiez les catégories avec le plus de volume : si « Import de données » représente 30 % des tickets, c\'est un axe d\'amélioration prioritaire.</li>
<li><strong>Identifier les patterns</strong> — Recherchez les tickets avec des titres ou descriptions similaires. Le système suggère automatiquement des regroupements basés sur l\'analyse textuelle. Un cluster de tickets similaires indique un problème récurrent à traiter à la racine.</li>
<li><strong>Analyser les recherches infructueuses</strong> — La section « Recherches sans résultat » du centre d\'aide liste les termes recherchés par les utilisateurs qui n\'ont retourné aucun article. Ces termes révèlent les sujets manquants dans la documentation.</li>
<li><strong>Corréler avec les mises à jour produit</strong> — Vérifiez si les pics de tickets coïncident avec des déploiements. Un déploiement suivi d\'un pic de tickets sur la même fonctionnalité signale une régression ou un changement d\'interface mal communiqué.</li>
<li><strong>Créer des actions correctives</strong> — Pour chaque pattern identifié, décidez de l\'action : corriger le bug produit, ajouter un article au centre d\'aide, améliorer l\'interface utilisateur ou ajouter un tooltip contextuel. Documentez les actions et suivez leur impact.</li>
</ol>

<h2>Exemple concret</h2>
<p>En analysant les tickets du dernier trimestre, vous constatez que 18 tickets portent sur « Impossible de supprimer un membre ». En investiguant, vous découvrez que la suppression est bloquée quand le membre a des expéditions en cours, mais le message d\'erreur ne l\'explique pas clairement. Vous remontez le problème UX à l\'équipe produit et rédigez un article « Pourquoi je ne peux pas supprimer un membre » pour le centre d\'aide en attendant la correction.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Traiter chaque ticket isolément</strong> — Sans analyse de patterns, vous résolvez le symptôme encore et encore sans traiter la cause. Prenez le temps de regrouper et d\'analyser régulièrement.</li>
<li><strong>Ne pas mesurer l\'impact des corrections</strong> — Après avoir ajouté un article ou corrigé un bug, vérifiez dans les semaines suivantes que le volume de tickets sur ce sujet a effectivement diminué.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Les problèmes récurrents sont identifiés, les actions correctives sont lancées et le volume de tickets diminue progressivement grâce à l\'amélioration continue du produit et de la documentation.</p>',
                ],
                [
                    'title' => 'Gérer les escalades',
                    'slug' => 'gerer-escalades',
                    'excerpt' => 'Maîtrisez la chaîne d\'escalade : niveaux de support, notifications, réponse aux incidents critiques et post-mortem.',
                    'content' => '<h2>Contexte</h2>
<p>L\'escalade est le mécanisme qui assure qu\'un incident non résolu au premier niveau est transmis à un niveau supérieur avec plus de compétences ou d\'autorité. Leezr définit 3 niveaux d\'escalade avec des règles automatiques et manuelles pour garantir qu\'aucun incident critique ne reste sans traitement.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Comprendre les niveaux d\'escalade</strong> — Niveau 1 : agent de support (questions fonctionnelles, configuration). Niveau 2 : ingénieur technique (bugs, problèmes de données, intégrations). Niveau 3 : responsable technique ou direction (incidents majeurs, perte de données, panne généralisée).</li>
<li><strong>Escalader manuellement</strong> — Depuis le ticket, cliquez sur « Escalader » et sélectionnez le niveau cible. Ajoutez un résumé de ce que vous avez investigué et pourquoi l\'escalade est nécessaire. Le ticket est automatiquement réassigné et la priorité peut être relevée.</li>
<li><strong>Recevoir les escalades automatiques</strong> — Le système escalade automatiquement quand : le SLA est violé (escalade du même niveau vers le superviseur), le ticket est rouvert 3 fois (escalade au niveau supérieur), ou le client utilise le bouton « Urgent » (notification immédiate au niveau 2).</li>
<li><strong>Gérer un incident critique</strong> — Pour un incident de niveau 3 (panne généralisée), activez le protocole d\'incident : créez un canal de communication dédié, publiez une page de statut, communiquez avec les clients impactés et documentez chaque action en temps réel.</li>
<li><strong>Réaliser le post-mortem</strong> — Après la résolution d\'un incident majeur, rédigez un rapport post-mortem : chronologie de l\'incident, cause racine, actions correctives, mesures préventives. Partagez le rapport avec l\'équipe et archivez-le dans la base de connaissances.</li>
</ol>

<h2>Exemple concret</h2>
<p>Un client signale que toutes ses factures affichent un montant de 0 €. L\'agent de niveau 1 vérifie les paramètres du client : tout semble correct. Il escalade au niveau 2 avec la note « Vérification configuration OK — semble être un bug calcul ». L\'ingénieur de niveau 2 identifie une régression dans le dernier déploiement qui affecte le calcul des factures pour les clients avec des coupons actifs. Il escalade au niveau 3 car 12 entreprises sont impactées. Le correctif est déployé en urgence en 2 heures et un post-mortem documente l\'incident.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Escalader trop tard</strong> — Si vous êtes bloqué depuis plus de 30 minutes sur un ticket critique, escaladez immédiatement. Le temps perdu à chercher seul coûte plus cher que l\'escalade précoce.</li>
<li><strong>Escalader sans documenter</strong> — Un ticket escaladé sans contexte oblige l\'agent suivant à tout reprendre de zéro. Résumez toujours vos actions et découvertes avant d\'escalader.</li>
</ul>

<h2>Résultat attendu</h2>
<p>La chaîne d\'escalade fonctionne efficacement, les incidents critiques sont traités rapidement avec communication client et les post-mortems alimentent l\'amélioration continue.</p>',
                ],
            ],
        ],

        //
        // ─── Topic 6: Configuration plateforme ───────────────────────
        //
        [
            'title' => 'Configuration plateforme',
            'slug' => 'configuration-plateforme',
            'description' => 'Configurez les paramètres globaux de la plateforme : général, email, sécurité, marchés et feature flags.',
            'icon' => 'tabler-adjustments',
            'articles' => [
                [
                    'title' => 'Paramètres généraux',
                    'slug' => 'parametres-generaux',
                    'excerpt' => 'Configurez le nom de la plateforme, la langue par défaut, le fuseau horaire et le mode maintenance.',
                    'content' => '<h2>Contexte</h2>
<p>Les paramètres généraux définissent l\'identité et le comportement global de la plateforme Leezr. Ils s\'appliquent à l\'ensemble des entreprises clientes et à l\'interface d\'administration. La configuration se fait depuis le hub Paramètres, onglet Général.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accéder aux paramètres</strong> — Depuis le menu latéral plateforme, cliquez sur « Paramètres ». L\'onglet Général s\'affiche par défaut avec les sections : Identité, Localisation, Maintenance et Divers.</li>
<li><strong>Configurer l\'identité</strong> — Définissez le nom de la plateforme affiché dans les emails, les factures et l\'interface. Ajoutez le logo et le favicon. Ces éléments sont utilisés dans toute la communication sortante.</li>
<li><strong>Définir la localisation</strong> — Sélectionnez la langue par défaut (français ou anglais) et le fuseau horaire principal. La langue par défaut est utilisée pour les nouveaux utilisateurs et les communications système. Le fuseau horaire affecte l\'affichage des dates et les calculs SLA.</li>
<li><strong>Activer le mode maintenance</strong> — Le mode maintenance affiche une page dédiée à tous les utilisateurs sauf les administrateurs plateforme. Utilisez-le pendant les déploiements majeurs ou les interventions techniques planifiées. Définissez un message personnalisé et une estimation de durée.</li>
<li><strong>Paramètres divers</strong> — Configurez la durée de rétention des données après résiliation (par défaut 90 jours), le format des numéros de facture et les paramètres de pagination par défaut.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous préparez un déploiement majeur qui nécessite 30 minutes d\'indisponibilité. Avant de commencer, vous activez le mode maintenance avec le message « Leezr est en cours de mise à jour. Service rétabli vers 14h30. Merci de votre patience. ». Vous effectuez le déploiement en toute sérénité, puis désactivez le mode maintenance. Les utilisateurs sont automatiquement redirigés vers leur page précédente.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Oublier de désactiver le mode maintenance</strong> — Après un déploiement, vérifiez immédiatement que le mode maintenance est désactivé. Configurez un rappel automatique si le mode maintenance est actif depuis plus d\'une heure.</li>
<li><strong>Changer le fuseau horaire en production</strong> — Modifier le fuseau horaire affecte l\'affichage de toutes les dates historiques et les calculs SLA en cours. Ne le changez que lors de la configuration initiale ou avec une migration de données.</li>
</ul>

<h2>Résultat attendu</h2>
<p>La plateforme est configurée avec les bons paramètres d\'identité, de localisation et de maintenance. Les changements sont immédiatement reflétés dans l\'interface et les communications.</p>',
                ],
                [
                    'title' => 'Configuration email',
                    'slug' => 'configuration-email',
                    'excerpt' => 'Paramétrez le serveur SMTP, les templates d\'email, le monitoring de délivrabilité et les enregistrements DMARC/SPF.',
                    'content' => '<h2>Contexte</h2>
<p>La configuration email est critique pour la communication de la plateforme : notifications, factures, alertes et communications support transitent par email. L\'onglet Email des paramètres plateforme permet de configurer le transport SMTP, les templates et de surveiller la délivrabilité.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Configurer le serveur SMTP</strong> — Dans l\'onglet Email, renseignez l\'hôte SMTP, le port, le protocole de chiffrement (TLS recommandé), le nom d\'utilisateur et le mot de passe. Testez la connexion avec le bouton « Tester l\'envoi » qui envoie un email de test à l\'adresse de votre choix.</li>
<li><strong>Vérifier les enregistrements DNS</strong> — La section Authentification affiche l\'état des enregistrements SPF, DKIM et DMARC pour le domaine d\'envoi. Un badge vert indique que l\'enregistrement est présent et valide. Les enregistrements manquants dégradent la délivrabilité et augmentent le risque de classification en spam.</li>
<li><strong>Personnaliser les templates</strong> — Les templates email sont pré-configurés mais personnalisables : nom de l\'expéditeur, adresse de réponse, pied de page, couleurs. Prévisualisez chaque template avant de sauvegarder pour vérifier le rendu sur différents clients email.</li>
<li><strong>Surveiller la délivrabilité</strong> — La section Monitoring affiche les statistiques d\'envoi : nombre d\'emails envoyés, taux de livraison, taux de rebond et taux de plainte spam. Un taux de rebond supérieur à 5 % nécessite une investigation (adresses invalides, blacklistage).</li>
<li><strong>Vérifier les blacklists</strong> — Le système vérifie automatiquement si l\'IP d\'envoi apparaît sur les principales listes noires (Spamhaus, Barracuda, etc.). En cas de listing, une alerte est générée avec les instructions de délistage.</li>
</ol>

<h2>Exemple concret</h2>
<p>Plusieurs clients signalent que les emails de notification arrivent dans leur dossier spam. Vous ouvrez la configuration email et constatez que l\'enregistrement DMARC affiche un badge rouge « absent ». Vous ajoutez l\'enregistrement DMARC recommandé dans la zone DNS du domaine. Après propagation (24-48h), le badge passe au vert. Vous vérifiez ensuite les blacklists : l\'IP n\'est pas listée. Le taux de livraison remonte de 82 % à 97 % sur la semaine suivante.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Utiliser un SMTP sans authentification TLS</strong> — Les emails envoyés sans TLS sont vulnérables à l\'interception et sont pénalisés par les filtres anti-spam des destinataires. Activez toujours le chiffrement TLS.</li>
<li><strong>Ne pas monitorer le taux de rebond</strong> — Un taux de rebond élevé dégrade la réputation de l\'IP d\'envoi. Nettoyez régulièrement les adresses invalides et vérifiez les adresses email à l\'inscription.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Les emails sont envoyés de manière fiable avec une authentification correcte, les templates sont professionnels et la délivrabilité est surveillée en continu.</p>',
                ],
                [
                    'title' => 'Paramètres de sécurité',
                    'slug' => 'parametres-securite',
                    'excerpt' => 'Configurez la durée des sessions, la politique de mots de passe, l\'authentification à deux facteurs et le journal d\'audit.',
                    'content' => '<h2>Contexte</h2>
<p>Les paramètres de sécurité protègent la plateforme et les données des clients contre les accès non autorisés. L\'onglet Sécurité des paramètres plateforme centralise la configuration de l\'authentification, des sessions et de l\'audit. Ces paramètres s\'appliquent à tous les utilisateurs de la plateforme.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Configurer la durée des sessions</strong> — Définissez la durée d\'inactivité avant déconnexion automatique. La valeur par défaut est 2 heures. Pour les environnements sensibles, réduisez à 30 minutes. La fonctionnalité « Se souvenir de moi » peut être activée ou désactivée globalement.</li>
<li><strong>Définir la politique de mots de passe</strong> — Configurez les exigences minimales : longueur (minimum 8 caractères recommandé), complexité (majuscules, chiffres, caractères spéciaux), historique (interdire les N derniers mots de passe) et expiration (forcer le changement tous les X jours, ou jamais).</li>
<li><strong>Paramétrer l\'authentification à deux facteurs (2FA)</strong> — Activez le 2FA pour les administrateurs plateforme (fortement recommandé) et/ou pour tous les utilisateurs. Les méthodes disponibles sont : application TOTP (Google Authenticator, Authy) et codes de secours.</li>
<li><strong>Consulter le journal d\'audit</strong> — Le journal d\'audit enregistre toutes les actions sensibles : connexions, modifications de permissions, changements de configuration, actions administratives. Filtrez par utilisateur, type d\'action ou date pour investiguer un incident de sécurité.</li>
<li><strong>Configurer les alertes de sécurité</strong> — Définissez les seuils d\'alerte : nombre de tentatives de connexion échouées avant blocage du compte (par défaut 5), notification en cas de connexion depuis une nouvelle IP, alerte en cas de modification des paramètres de sécurité.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous remarquez dans le journal d\'audit 15 tentatives de connexion échouées sur le compte d\'un administrateur en 5 minutes, provenant d\'une IP inconnue. Le compte a été automatiquement bloqué après la 5ème tentative (seuil par défaut). Vous vérifiez que l\'administrateur est bien l\'auteur des tentatives : il confirme avoir oublié son mot de passe. Vous débloquez le compte et lui envoyez un lien de réinitialisation. Vous décidez aussi d\'activer le 2FA obligatoire pour tous les administrateurs.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Désactiver le blocage de compte après échecs</strong> — Le blocage automatique est une protection essentielle contre les attaques par force brute. Ne le désactivez jamais, ajustez seulement le seuil si nécessaire.</li>
<li><strong>Ne pas consulter le journal d\'audit régulièrement</strong> — Les tentatives d\'intrusion et les actions suspectes ne sont détectées que si quelqu\'un consulte les logs. Planifiez une revue hebdomadaire du journal d\'audit.</li>
</ul>

<h2>Résultat attendu</h2>
<p>La plateforme est protégée par des paramètres de sécurité robustes, l\'authentification forte est en place et le journal d\'audit fournit une traçabilité complète des actions sensibles.</p>',
                ],
                [
                    'title' => 'Gestion des marchés',
                    'slug' => 'gestion-marches',
                    'excerpt' => 'Configurez les marchés (pays et devises), les paramètres de localisation et les traductions de l\'interface.',
                    'content' => '<h2>Contexte</h2>
<p>Leezr est conçu pour opérer sur plusieurs marchés géographiques. Chaque marché représente une combinaison pays/devise/langue avec ses spécificités réglementaires et fiscales. La gestion des marchés se fait depuis l\'onglet Marchés des paramètres plateforme et impacte la facturation, l\'interface et les documents.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Consulter les marchés existants</strong> — L\'onglet Marchés affiche la liste des marchés configurés avec leur pays, devise, langue et nombre d\'entreprises associées. Le marché par défaut (France, EUR, français) est marqué comme principal.</li>
<li><strong>Ajouter un nouveau marché</strong> — Cliquez sur « Nouveau marché ». Sélectionnez le pays, la devise (les taux de change sont mis à jour automatiquement quotidiennement) et la langue de l\'interface. Définissez les règles fiscales : taux de TVA, format des factures, mentions légales obligatoires.</li>
<li><strong>Configurer les traductions</strong> — Pour chaque langue activée, vérifiez que toutes les clés de traduction sont renseignées. L\'interface de traduction affiche les clés manquantes en rouge. Les traductions couvrent l\'interface utilisateur, les emails, les notifications et les documents générés.</li>
<li><strong>Gérer les taux de change</strong> — La section Devises affiche les taux de change actuels et l\'historique. Les taux sont mis à jour automatiquement mais peuvent être corrigés manuellement si nécessaire (par exemple pour figer un taux sur une période de facturation).</li>
<li><strong>Associer une entreprise à un marché</strong> — Quand une entreprise s\'inscrit, elle est automatiquement associée au marché correspondant à son pays. Vous pouvez modifier cette association manuellement depuis la fiche de l\'entreprise si nécessaire (cas d\'une filiale dans un pays différent).</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous souhaitez ouvrir Leezr au marché belge. Vous créez un nouveau marché « Belgique » avec la devise EUR (même devise que la France) et les deux langues : français et néerlandais. Vous configurez le taux de TVA belge (21 %) et le format de facturation conforme à la réglementation belge. Vous complétez les traductions néerlandaises manquantes. Les entreprises belges qui s\'inscrivent seront automatiquement associées à ce marché.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Ouvrir un marché sans compléter les traductions</strong> — Les clés de traduction manquantes affichent le code technique au lieu du texte. Vérifiez que 100 % des clés sont traduites avant de rendre un marché actif.</li>
<li><strong>Modifier un taux de TVA rétroactivement</strong> — Le changement de taux de TVA ne doit pas affecter les factures déjà émises. Le système applique le taux en vigueur à la date de facturation, pas le taux actuel.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le nouveau marché est configuré avec ses paramètres fiscaux, ses langues et ses traductions. Les entreprises du pays concerné bénéficient d\'une expérience localisée complète.</p>',
                ],
                [
                    'title' => 'Feature flags',
                    'slug' => 'feature-flags',
                    'excerpt' => 'Activez ou désactivez des fonctionnalités globalement ou par entreprise, et gérez les stratégies de déploiement progressif.',
                    'content' => '<h2>Contexte</h2>
<p>Les feature flags permettent de contrôler la disponibilité des fonctionnalités sans déploiement de code. C\'est un outil essentiel pour le déploiement progressif de nouvelles fonctionnalités, les tests A/B et la gestion des fonctionnalités expérimentales. La page Feature flags est accessible depuis le hub Paramètres.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Consulter les feature flags</strong> — La page Feature flags affiche la liste de tous les flags avec leur nom, description, état (activé/désactivé) et portée (global, par entreprise, pourcentage). Le badge indique le nombre d\'entreprises concernées par chaque flag.</li>
<li><strong>Créer un feature flag</strong> — Cliquez sur « Nouveau flag ». Donnez un nom technique (snake_case), une description lisible et sélectionnez la stratégie de déploiement : global (tout le monde), par entreprise (liste blanche) ou pourcentage (rollout progressif).</li>
<li><strong>Activer pour des entreprises spécifiques</strong> — En mode « par entreprise », ajoutez les entreprises qui doivent voir la fonctionnalité. C\'est idéal pour les bêta-testeurs ou les clients pilotes. Le flag peut être activé et désactivé instantanément sans impact sur les autres clients.</li>
<li><strong>Déployer progressivement</strong> — En mode « pourcentage », définissez le pourcentage d\'entreprises qui voient la fonctionnalité (10 %, 25 %, 50 %, 100 %). Augmentez progressivement en surveillant les métriques et les retours pour détecter les problèmes avant le déploiement complet.</li>
<li><strong>Archiver un flag</strong> — Quand une fonctionnalité est stable et activée pour tous, archivez le flag. L\'archivage documente que la fonctionnalité est devenue permanente. Nettoyez régulièrement les flags archivés pour garder la liste lisible.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous lancez une nouvelle fonctionnalité d\'export PDF avancé. Vous créez le flag « advanced_pdf_export » en mode « par entreprise » et l\'activez pour 3 entreprises pilotes. Pendant une semaine, vous surveillez les retours et les métriques d\'utilisation. Aucun problème n\'est signalé. Vous passez en mode « pourcentage » à 25 %, puis 50 %, puis 100 % sur les 3 semaines suivantes. La fonctionnalité est maintenant disponible pour tous et le flag peut être archivé.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Accumuler des flags sans les nettoyer</strong> — Chaque flag ajoute de la complexité au code et aux tests. Archivez et nettoyez les flags devenus permanents. Un inventaire trimestriel est recommandé.</li>
<li><strong>Déployer à 100 % sans étape intermédiaire</strong> — Le déploiement progressif existe pour détecter les problèmes tôt. Passer directement de 0 % à 100 % annule cet avantage et expose tous les clients à un éventuel bug.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Les feature flags sont gérés de manière structurée, le déploiement progressif réduit les risques et les flags obsolètes sont régulièrement nettoyés pour maintenir la clarté du système.</p>',
                ],
            ],
        ],
    ],
];
