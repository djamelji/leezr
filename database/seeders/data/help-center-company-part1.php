<?php

// Help Center — Company audience part 1 (topics 1-4)
// Content for company users: getting started, company management, members, documents

return [
    'group' => [
        'title' => 'Guide Entreprise',
        'slug' => 'guide-entreprise',
        'icon' => 'tabler-building',
    ],
    'topics' => [
        // ──────────────────────────────────────────────
        // Topic 1 : Démarrage
        // ──────────────────────────────────────────────
        [
            'title' => 'Démarrage',
            'slug' => 'demarrage',
            'description' => 'Configurez votre espace Leezr en 15 minutes : profil, membres, modules.',
            'icon' => 'tabler-player-play',
            'articles' => [
                [
                    'title' => 'Compléter les étapes d\'onboarding',
                    'slug' => 'premiers-pas-apres-inscription',
                    'excerpt' => 'Suivez les 5 étapes du widget d\'onboarding pour rendre votre espace Leezr opérationnel.',
                    'content' => '<p>Dans ce guide, vous allez compléter les étapes de configuration initiale affichées sur votre Tableau de bord.</p>
<h2>Situation</h2>
<p>Vous venez de créer votre compte Leezr. Le widget d\'onboarding en haut du Tableau de bord liste les actions requises pour démarrer. Tant que ces étapes ne sont pas terminées, votre espace n\'est pas pleinement fonctionnel.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Compléter le profil entreprise</strong> — Ouvrez <em>Profil</em> dans le menu latéral. Renseignez le nom, l\'adresse du siège, le SIRET et le numéro de TVA intracommunautaire.</li>
  <li><strong>Ajouter votre logo</strong> — Dans <em>Profil</em>, cliquez sur la zone d\'upload. Format PNG ou JPG, 200×200 px minimum.</li>
  <li><strong>Inviter vos membres</strong> — Ouvrez <em>Membres</em> → <em>Inviter un membre</em>. Saisissez l\'email et attribuez un rôle (Administrateur, Gestionnaire ou Membre).</li>
  <li><strong>Activer vos modules</strong> — Ouvrez <em>Modules</em>. Activez ceux dont vous avez besoin : Documents, Expéditions, Workflows.</li>
  <li><strong>Vérifier la progression</strong> — Revenez sur le <em>Tableau de bord</em>. Le widget d\'onboarding doit afficher 100 %.</li>
</ol>
<h2>Résultat</h2>
<p>Votre espace Leezr est configuré, vos collaborateurs ont reçu leurs invitations et vos modules sont actifs. Le widget d\'onboarding disparaît du Tableau de bord.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Le widget reste bloqué à 80 %</strong> — Vérifiez que le SIRET et le logo sont bien renseignés dans Profil.</li>
  <li><strong>Un membre ne reçoit pas l\'invitation</strong> — Demandez-lui de vérifier ses spams. Vous pouvez renvoyer l\'invitation depuis la page Membres.</li>
  <li><strong>Un module n\'apparaît pas dans le menu</strong> — Rechargez la page après l\'activation. Le menu se met à jour automatiquement.</li>
</ul>',
                ],
                [
                    'title' => 'Configurer le profil entreprise',
                    'slug' => 'configurer-profil-entreprise',
                    'excerpt' => 'Renseignez les informations légales et le logo de votre entreprise dans la page Profil.',
                    'content' => '<p>Dans ce guide, vous allez renseigner les informations légales de votre entreprise dans Leezr.</p>
<h2>Situation</h2>
<p>Votre profil entreprise alimente les factures, les documents générés et l\'identification auprès de vos partenaires. Un profil incomplet bloque certaines fonctionnalités (facturation, génération de documents).</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir la page Profil</strong> — Menu latéral → <em>Profil</em> → onglet <em>Vue d\'ensemble</em>.</li>
  <li><strong>Modifier les informations générales</strong> — Cliquez sur l\'icône crayon. Renseignez le nom commercial, la raison sociale et la forme juridique.</li>
  <li><strong>Saisir l\'adresse du siège</strong> — Numéro, rue, code postal, ville, pays. Cette adresse figurera sur vos documents officiels.</li>
  <li><strong>Ajouter les identifiants légaux</strong> — SIRET (14 chiffres) et TVA intracommunautaire (FR + 11 chiffres).</li>
  <li><strong>Télécharger le logo</strong> — Zone d\'upload : PNG ou JPG, 200×200 px minimum.</li>
</ol>
<h2>Résultat</h2>
<p>Votre profil est complet. Le logo s\'affiche dans la barre de navigation et les documents générés contiennent vos coordonnées et identifiants fiscaux.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>SIRET refusé</strong> — Il doit contenir exactement 14 chiffres. Vérifiez sur votre extrait Kbis.</li>
  <li><strong>TVA au mauvais format</strong> — Le format français est FR suivi de 11 chiffres. Ne confondez pas avec le SIREN (9 chiffres).</li>
  <li><strong>Logo flou à l\'affichage</strong> — Utilisez une image d\'au moins 400×400 px pour un rendu net sur tous les écrans.</li>
</ul>',
                ],
                [
                    'title' => 'Inviter vos premiers membres',
                    'slug' => 'inviter-premiers-membres',
                    'excerpt' => 'Envoyez des invitations par email à vos collaborateurs et attribuez-leur un rôle adapté.',
                    'content' => '<p>Dans ce guide, vous allez inviter vos collaborateurs à rejoindre votre espace Leezr.</p>
<h2>Situation</h2>
<p>Vous devez constituer votre équipe sur la plateforme : chauffeurs, gestionnaires de flotte, dispatcheurs. Chaque membre reçoit un lien sécurisé par email pour créer son accès.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir la page Membres</strong> — Menu latéral → <em>Membres</em>.</li>
  <li><strong>Cliquer sur Inviter un membre</strong> — Bouton en haut à droite. Un panneau latéral s\'ouvre.</li>
  <li><strong>Saisir l\'email</strong> — Entrez l\'adresse email professionnelle du collaborateur.</li>
  <li><strong>Choisir le rôle</strong> — <em>Administrateur</em> (accès complet), <em>Gestionnaire</em> (opérations quotidiennes) ou <em>Membre</em> (consultation et tâches limitées).</li>
  <li><strong>Envoyer l\'invitation</strong> — Cliquez sur <em>Envoyer</em>. Le lien est valable 7 jours. Le statut passe à <em>En attente</em>.</li>
</ol>
<h2>Résultat</h2>
<p>Le collaborateur reçoit un email, crée son compte en quelques clics et apparaît comme actif dans votre liste de membres avec le rôle attribué.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Invitation non reçue</strong> — Vérifier le dossier spams du destinataire. Renvoyer l\'invitation depuis la page Membres si besoin.</li>
  <li><strong>Lien expiré (après 7 jours)</strong> — Renvoyez une nouvelle invitation depuis la page Membres.</li>
  <li><strong>Mauvais rôle attribué</strong> — Modifiez le rôle directement depuis la fiche du membre, pas besoin de réinviter.</li>
</ul>',
                ],
                [
                    'title' => 'Activer vos premiers modules',
                    'slug' => 'activer-premiers-modules',
                    'excerpt' => 'Parcourez les modules disponibles et activez ceux qui correspondent à votre activité de transport.',
                    'content' => '<p>Dans ce guide, vous allez activer les modules Leezr adaptés à votre activité.</p>
<h2>Situation</h2>
<p>Leezr fonctionne par modules indépendants : Documents, Expéditions, Workflows, etc. Vous activez uniquement ce dont vous avez besoin. De nouveaux modules peuvent être ajoutés à tout moment.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir la page Modules</strong> — Menu latéral → <em>Modules</em>. Chaque module est affiché sous forme de carte avec sa description et son statut.</li>
  <li><strong>Choisir un module</strong> — Lisez la description pour identifier les modules utiles à votre activité (ex. : Documents pour le suivi réglementaire, Expéditions pour la gestion des tournées).</li>
  <li><strong>Activer le module</strong> — Cliquez sur <em>Activer</em>. Si le module impacte votre facturation, une confirmation est demandée.</li>
  <li><strong>Configurer si nécessaire</strong> — Certains modules demandent un paramétrage initial. Suivez les instructions affichées à l\'écran.</li>
  <li><strong>Vérifier dans le menu</strong> — Le module activé apparaît dans le menu latéral. Cliquez dessus pour accéder à ses fonctionnalités.</li>
</ol>
<h2>Résultat</h2>
<p>Les modules sélectionnés sont actifs et accessibles depuis le menu latéral. Votre équipe peut commencer à les utiliser immédiatement.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Module activé mais absent du menu</strong> — Rechargez la page. Le menu se met à jour après activation.</li>
  <li><strong>Hésitation sur les modules à activer</strong> — Commencez par Documents (suivi réglementaire). Ajoutez les autres progressivement.</li>
  <li><strong>Module activé par erreur</strong> — Vous pouvez le désactiver depuis la même page Modules.</li>
</ul>',
                ],
                [
                    'title' => 'Consulter le tableau de bord quotidien',
                    'slug' => 'comprendre-tableau-de-bord',
                    'excerpt' => 'Utilisez le Tableau de bord pour suivre vos alertes, vos KPI et lancer des actions rapides chaque matin.',
                    'content' => '<p>Dans ce guide, vous allez utiliser le Tableau de bord comme point de contrôle quotidien de votre activité.</p>
<h2>Situation</h2>
<p>Le Tableau de bord est la première page affichée à la connexion. Il centralise les alertes, les indicateurs clés et les raccourcis. Le consulter chaque matin vous permet d\'anticiper les urgences.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Vérifier les alertes documents</strong> — Le widget d\'alertes liste les documents qui expirent dans les 30 jours. Cliquez sur une alerte pour ouvrir le document concerné.</li>
  <li><strong>Lire les indicateurs clés (KPI)</strong> — Les cartes de statistiques affichent : membres actifs, documents valides, expéditions en cours, taux de conformité.</li>
  <li><strong>Utiliser les actions rapides</strong> — La barre d\'actions rapides permet de créer une expédition, inviter un membre ou ajouter un document en un clic.</li>
  <li><strong>Consulter le fil d\'activité</strong> — En bas de page, les dernières actions de votre équipe : documents ajoutés, membres invités, modules activés.</li>
</ol>
<h2>Résultat</h2>
<p>En 2 minutes, vous avez une vue complète de l\'état de votre entreprise : échéances à traiter, activité de l\'équipe et actions à lancer.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Widget d\'onboarding encore visible</strong> — Il disparaît une fois toutes les étapes de configuration terminées. Complétez les étapes restantes.</li>
  <li><strong>Aucune alerte affichée</strong> — Vérifiez que des documents avec date d\'expiration sont bien enregistrés dans le module Documents.</li>
  <li><strong>KPI à zéro</strong> — Les indicateurs se remplissent au fur et à mesure que vous utilisez la plateforme (membres, documents, expéditions).</li>
</ul>',
                ],
            ],
        ],

        // ──────────────────────────────────────────────
        // Topic 2 : Gestion entreprise
        // ──────────────────────────────────────────────
        [
            'title' => 'Gestion entreprise',
            'slug' => 'gestion-entreprise',
            'description' => 'Mettez à jour les informations, paramètres et organisation de votre entreprise.',
            'icon' => 'tabler-building',
            'articles' => [
                [
                    'title' => 'Modifier les informations de l\'entreprise',
                    'slug' => 'modifier-informations-entreprise',
                    'excerpt' => 'Mettez à jour la raison sociale, l\'adresse ou les identifiants légaux après un déménagement ou un changement de statut.',
                    'content' => '<p>Dans ce guide, vous allez mettre à jour les informations légales de votre entreprise sur Leezr.</p>
<h2>Situation</h2>
<p>Votre entreprise a déménagé, changé de raison sociale ou mis à jour son numéro de TVA. Ces informations doivent être corrigées car elles apparaissent sur vos factures et documents générés.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir le profil entreprise</strong> — Menu latéral → <em>Profil</em> → onglet <em>Vue d\'ensemble</em>.</li>
  <li><strong>Passer en mode édition</strong> — Cliquez sur l\'icône crayon de la section à modifier (informations générales, adresse ou identifiants légaux).</li>
  <li><strong>Corriger les champs</strong> — Modifiez les valeurs nécessaires. Les champs obligatoires sont marqués d\'un astérisque.</li>
  <li><strong>Enregistrer</strong> — Cliquez sur <em>Enregistrer</em>. Les modifications s\'appliquent immédiatement sur toute la plateforme.</li>
</ol>
<h2>Résultat</h2>
<p>Les informations mises à jour apparaissent sur toutes les factures et documents générés à partir de maintenant.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>SIRET rejeté après une fusion</strong> — Vérifiez le nouveau numéro sur votre extrait Kbis. Le SIRET change lors d\'une nouvelle immatriculation.</li>
  <li><strong>Ancienne adresse sur un document déjà généré</strong> — Les documents existants conservent l\'adresse au moment de leur création. Seuls les nouveaux documents utilisent les coordonnées mises à jour.</li>
  <li><strong>Bouton Enregistrer grisé</strong> — Au moins un champ obligatoire est vide ou au mauvais format. Vérifiez les champs marqués en rouge.</li>
</ul>',
                ],
                [
                    'title' => 'Configurer les paramètres de l\'entreprise',
                    'slug' => 'gerer-parametres-entreprise',
                    'excerpt' => 'Réglez le fuseau horaire, la langue par défaut et les préférences de notification pour toute votre équipe.',
                    'content' => '<p>Dans ce guide, vous allez configurer les paramètres globaux qui s\'appliquent à tous les membres de votre entreprise.</p>
<h2>Situation</h2>
<p>Vous êtes administrateur et vous devez définir le fuseau horaire, la langue d\'interface et les canaux de notification pour votre organisation. Ces réglages impactent tous les membres.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir les paramètres</strong> — Menu latéral → <em>Profil</em> → onglet <em>Paramètres</em>.</li>
  <li><strong>Définir le fuseau horaire</strong> — Sélectionnez le fuseau de votre siège (ex. : Europe/Paris). Toutes les dates et alertes s\'afficheront dans ce fuseau.</li>
  <li><strong>Choisir la langue par défaut</strong> — Français ou anglais. Les nouveaux membres hériteront de cette langue.</li>
  <li><strong>Configurer les notifications</strong> — Activez ou désactivez les canaux (email, plateforme) pour les événements critiques : expiration de documents, nouvelles invitations.</li>
  <li><strong>Enregistrer</strong> — Cliquez sur <em>Enregistrer</em>. Les modifications s\'appliquent immédiatement pour tous les membres.</li>
</ol>
<h2>Résultat</h2>
<p>Tous les membres voient les dates au bon fuseau horaire, reçoivent les notifications configurées et utilisent l\'interface dans la langue choisie.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Alertes décalées de 1 ou 2 heures</strong> — Vérifiez que le fuseau horaire correspond bien à votre zone (attention au passage heure d\'été/hiver).</li>
  <li><strong>Un membre ne reçoit plus de notifications</strong> — Vérifiez les paramètres entreprise et les préférences personnelles du membre (les deux doivent être activés).</li>
  <li><strong>Onglet Paramètres absent</strong> — Seuls les administrateurs y ont accès. Vérifiez votre rôle.</li>
</ul>',
                ],
                [
                    'title' => 'Consulter les rôles et permissions',
                    'slug' => 'comprendre-roles-permissions',
                    'excerpt' => 'Vérifiez les droits associés à chaque rôle pour attribuer le bon niveau d\'accès à vos collaborateurs.',
                    'content' => '<p>Dans ce guide, vous allez consulter la page Rôles pour comprendre les permissions de chaque niveau d\'accès.</p>
<h2>Situation</h2>
<p>Vous devez attribuer un rôle à un nouveau membre et vous voulez vérifier ce que chaque rôle permet de faire. Leezr propose trois rôles avec des niveaux d\'accès différents.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir la page Rôles</strong> — Menu latéral → <em>Rôles</em>. La liste affiche chaque rôle avec un résumé de ses permissions.</li>
  <li><strong>Consulter le rôle Administrateur</strong> — Accès complet : membres, paramètres, facturation, modules, tous les contenus. Réservé au dirigeant ou responsable IT.</li>
  <li><strong>Consulter le rôle Gestionnaire</strong> — Gestion opérationnelle : documents, expéditions, rapports. Pas d\'accès aux paramètres entreprise ni à la facturation.</li>
  <li><strong>Consulter le rôle Membre</strong> — Consultation : ses documents personnels, ses missions, son profil. Pas de modification des données des autres.</li>
  <li><strong>Voir le détail par module</strong> — Cliquez sur un rôle pour afficher le tableau des permissions : lecture, création, modification, suppression pour chaque module.</li>
</ol>
<h2>Résultat</h2>
<p>Vous savez quel rôle attribuer selon les responsabilités du collaborateur : Administrateur pour la direction, Gestionnaire pour les responsables d\'exploitation, Membre pour les chauffeurs.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Trop d\'administrateurs</strong> — Limitez ce rôle à 2-3 personnes. Un administrateur a accès à tout, y compris la facturation et la suppression de données.</li>
  <li><strong>Gestionnaire qui ne voit pas les expéditions</strong> — Vérifiez que le module Expéditions est activé dans la page Modules.</li>
  <li><strong>Membre qui a besoin de plus de droits</strong> — Passez-le en Gestionnaire ou ajustez les permissions du rôle dans la page Rôles.</li>
</ul>',
                ],
                [
                    'title' => 'Consulter l\'historique d\'activité',
                    'slug' => 'consulter-historique-activite',
                    'excerpt' => 'Retrouvez qui a fait quoi et quand dans le fil d\'activité du Tableau de bord.',
                    'content' => '<p>Dans ce guide, vous allez consulter le fil d\'activité pour tracer les actions effectuées par votre équipe.</p>
<h2>Situation</h2>
<p>Vous devez vérifier qui a modifié un document, quand un membre a été invité ou quelle action a été effectuée récemment. Le transport exige une traçabilité complète pour les audits et contrôles.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir le Tableau de bord</strong> — Le fil d\'activité récente est affiché en bas de page.</li>
  <li><strong>Lire les entrées</strong> — Chaque entrée indique : l\'auteur, le type d\'action (création, modification, suppression), l\'objet concerné et la date/heure.</li>
  <li><strong>Filtrer par type</strong> — Utilisez les filtres pour isoler les modifications de documents, changements de membres ou actions sur les expéditions.</li>
  <li><strong>Identifier l\'auteur</strong> — Cliquez sur le nom du membre pour voir l\'ensemble de ses actions récentes.</li>
</ol>
<h2>Résultat</h2>
<p>Vous pouvez retracer n\'importe quelle action, identifier son auteur et fournir un historique complet en cas d\'audit ou de contrôle routier.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Fil d\'activité vide</strong> — Il se remplit au fur et à mesure que les membres utilisent la plateforme. Il faut au moins une action (document ajouté, membre invité) pour qu\'une entrée apparaisse.</li>
  <li><strong>Action introuvable</strong> — Utilisez les filtres par type d\'action et par date pour affiner votre recherche.</li>
  <li><strong>Activité d\'un membre désactivé</strong> — L\'historique des actions est conservé même après désactivation du membre.</li>
</ul>',
                ],
                [
                    'title' => 'Personnaliser l\'apparence de l\'interface',
                    'slug' => 'personnaliser-espace-travail',
                    'excerpt' => 'Changez le thème (clair/sombre), la disposition du menu et la langue de votre interface personnelle.',
                    'content' => '<p>Dans ce guide, vous allez personnaliser l\'apparence de votre interface Leezr sans impacter les autres membres.</p>
<h2>Situation</h2>
<p>Vous souhaitez adapter l\'interface à vos conditions de travail : mode sombre pour le travail de nuit, menu réduit pour un écran étroit, ou changement de langue.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Changer le thème</strong> — Cliquez sur l\'icône de thème dans la barre de navigation supérieure pour basculer entre mode clair et mode sombre. Sauvegarde automatique.</li>
  <li><strong>Réduire le menu latéral</strong> — Cliquez sur le bouton de bascule en haut du menu pour passer en mode icônes uniquement. Plus d\'espace à l\'écran.</li>
  <li><strong>Modifier votre profil personnel</strong> — Cliquez sur votre avatar en haut à droite → <em>Mon profil</em>. Changez votre nom, photo et préférences de notification.</li>
  <li><strong>Changer la langue</strong> — Dans votre profil personnel, sélectionnez français ou anglais. Ce choix est individuel.</li>
</ol>
<h2>Résultat</h2>
<p>Votre interface est adaptée à vos préférences. Le thème, la disposition et la langue sont sauvegardés et persistent entre les sessions.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Le thème revient au mode clair</strong> — Vérifiez que les cookies ne sont pas bloqués par votre navigateur. Le thème est stocké en cookie.</li>
  <li><strong>Confusion entre paramètres personnels et entreprise</strong> — Le thème et la langue ici ne s\'appliquent qu\'à vous. Les paramètres entreprise (fuseau, langue par défaut) sont gérés par les administrateurs dans Profil → Paramètres.</li>
  <li><strong>Photo de profil non enregistrée</strong> — Cliquez sur <em>Enregistrer</em> après avoir sélectionné la photo. Le thème et le menu se sauvegardent automatiquement, mais le profil nécessite une validation manuelle.</li>
</ul>',
                ],
            ],
        ],

        // ──────────────────────────────────────────────
        // Topic 3 : Membres
        // ──────────────────────────────────────────────
        [
            'title' => 'Membres',
            'slug' => 'membres',
            'description' => 'Invitez, gérez et organisez les membres de votre équipe avec des rôles adaptés.',
            'icon' => 'tabler-users',
            'articles' => [
                [
                    'title' => 'Inviter un nouveau membre',
                    'slug' => 'inviter-nouveau-membre',
                    'excerpt' => 'Ajoutez un collaborateur à votre espace Leezr par invitation email avec un rôle attribué.',
                    'content' => '<p>Dans ce guide, vous allez ajouter un nouveau collaborateur à votre espace Leezr.</p>
<h2>Situation</h2>
<p>Vous recrutez un chauffeur, un gestionnaire de flotte ou un dispatcheur et vous devez lui donner accès à la plateforme avec les bons droits.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir la page Membres</strong> — Menu latéral → <em>Membres</em>.</li>
  <li><strong>Cliquer sur Inviter un membre</strong> — Bouton en haut à droite. Le panneau latéral d\'invitation s\'ouvre.</li>
  <li><strong>Saisir l\'email</strong> — Entrez l\'adresse email professionnelle du collaborateur.</li>
  <li><strong>Choisir le rôle</strong> — Administrateur, Gestionnaire ou Membre selon ses responsabilités.</li>
  <li><strong>Envoyer</strong> — Cliquez sur <em>Envoyer l\'invitation</em>. Un email avec un lien sécurisé est envoyé, valable 7 jours.</li>
</ol>
<h2>Résultat</h2>
<p>Le collaborateur apparaît dans la liste avec le statut <em>En attente</em>. Une fois l\'invitation acceptée, le statut passe à <em>Actif</em> et il accède aux fonctionnalités de son rôle.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Email déjà utilisé</strong> — Cette adresse est déjà associée à un compte Leezr. Vérifiez si le membre existe déjà dans votre liste.</li>
  <li><strong>Invitation dans les spams</strong> — Demandez au collaborateur de vérifier le dossier courrier indésirable. Renvoyez l\'invitation depuis la page Membres.</li>
  <li><strong>Lien expiré après 7 jours</strong> — Supprimez l\'invitation en attente et renvoyez-en une nouvelle.</li>
</ul>',
                ],
                [
                    'title' => 'Modifier le rôle d\'un membre',
                    'slug' => 'attribuer-role-membre',
                    'excerpt' => 'Changez le rôle d\'un collaborateur pour adapter ses accès à ses nouvelles responsabilités.',
                    'content' => '<p>Dans ce guide, vous allez modifier le rôle d\'un membre existant pour ajuster ses droits d\'accès.</p>
<h2>Situation</h2>
<p>Un collaborateur change de poste (chauffeur promu chef d\'équipe, par exemple) et ses accès doivent refléter ses nouvelles responsabilités.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir la fiche du membre</strong> — Page <em>Membres</em> → cliquez sur le nom du collaborateur.</li>
  <li><strong>Identifier le rôle actuel</strong> — Le rôle actuel est affiché sous le nom du membre.</li>
  <li><strong>Changer le rôle</strong> — Cliquez sur le sélecteur de rôle et choisissez le nouveau rôle. Un résumé des permissions s\'affiche.</li>
  <li><strong>Enregistrer</strong> — Cliquez sur <em>Enregistrer</em>. Le changement prend effet immédiatement.</li>
</ol>
<h2>Résultat</h2>
<p>Le membre voit immédiatement les menus et fonctionnalités correspondant à son nouveau rôle. Il reçoit une notification du changement.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Membre qui ne voit pas les nouvelles fonctionnalités</strong> — Demandez-lui de recharger la page ou de se reconnecter.</li>
  <li><strong>Rétrogradation non anticipée</strong> — Prévenez toujours le membre avant de réduire ses droits pour éviter qu\'il se retrouve bloqué en plein travail.</li>
  <li><strong>Hésitation entre Gestionnaire et Membre</strong> — Le Gestionnaire peut modifier les données de l\'entreprise (documents, expéditions). Le Membre ne peut consulter et modifier que ses propres données.</li>
</ul>',
                ],
                [
                    'title' => 'Ajuster les permissions d\'un rôle',
                    'slug' => 'modifier-permissions-membre',
                    'excerpt' => 'Personnalisez les permissions d\'un rôle module par module depuis la page Rôles.',
                    'content' => '<p>Dans ce guide, vous allez ajuster les permissions d\'un rôle pour contrôler finement les accès par module.</p>
<h2>Situation</h2>
<p>Les rôles par défaut ne correspondent pas exactement à vos besoins. Par exemple, un dispatcheur doit créer des expéditions mais pas modifier les documents véhicules.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir la page Rôles</strong> — Menu latéral → <em>Rôles</em>.</li>
  <li><strong>Sélectionner le rôle à modifier</strong> — Cliquez sur le rôle concerné. Le tableau des permissions s\'affiche : une ligne par module, une colonne par action (lecture, création, modification, suppression).</li>
  <li><strong>Cocher ou décocher les permissions</strong> — Ajustez les cases pour accorder ou retirer des droits spécifiques.</li>
  <li><strong>Enregistrer</strong> — Cliquez sur <em>Enregistrer</em>. Tous les membres ayant ce rôle voient leurs accès mis à jour immédiatement.</li>
  <li><strong>Vérifier avec un membre concerné</strong> — Demandez à un collaborateur de ce rôle de confirmer qu\'il accède aux bonnes fonctionnalités.</li>
</ol>
<h2>Résultat</h2>
<p>Les permissions du rôle sont ajustées. Chaque membre de ce rôle accède exactement aux modules et actions configurés.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Permission retirée par erreur</strong> — Revenez sur la page Rôles et recochez la case manquante. La modification est instantanée.</li>
  <li><strong>Membre bloqué après modification</strong> — Vérifiez que les permissions de lecture sont bien cochées pour les modules dont il a besoin. Sans lecture, le module n\'apparaît pas dans le menu.</li>
  <li><strong>Modification impactant plusieurs personnes</strong> — Les permissions s\'appliquent à tous les membres du rôle. Si un seul membre a besoin d\'un accès spécifique, créez un rôle dédié.</li>
</ul>',
                ],
                [
                    'title' => 'Désactiver un membre',
                    'slug' => 'desactiver-retirer-membre',
                    'excerpt' => 'Retirez l\'accès d\'un collaborateur qui quitte l\'entreprise tout en conservant ses données et son historique.',
                    'content' => '<p>Dans ce guide, vous allez désactiver le compte d\'un collaborateur qui quitte votre entreprise.</p>
<h2>Situation</h2>
<p>Un chauffeur termine son contrat, un employé démissionne ou un intérimaire finit sa mission. Vous devez couper son accès le jour du départ tout en conservant ses données pour la traçabilité.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir la fiche du membre</strong> — Page <em>Membres</em> → cliquez sur le nom du collaborateur.</li>
  <li><strong>Cliquer sur Désactiver</strong> — Le membre perd immédiatement l\'accès à la plateforme. Son statut passe à <em>Inactif</em>.</li>
  <li><strong>Confirmer l\'action</strong> — Une boîte de dialogue vous demande de valider. Vérifiez le nom avant de confirmer.</li>
  <li><strong>Vérifier les documents associés</strong> — Les documents personnels du membre (permis, certifications) restent accessibles dans le module Documents.</li>
</ol>
<h2>Résultat</h2>
<p>Le membre ne peut plus se connecter. Ses documents, actions et historique sont conservés. Vous pouvez le réactiver ultérieurement si le collaborateur revient.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Oubli de désactivation le jour du départ</strong> — Désactivez immédiatement. Un ancien collaborateur avec un accès actif est un risque de sécurité.</li>
  <li><strong>Confusion entre Désactiver et Supprimer</strong> — Privilégiez toujours Désactiver. La désactivation préserve les données et permet la réactivation. La suppression est irréversible.</li>
  <li><strong>Besoin de réactiver un ancien membre</strong> — Retrouvez-le dans la liste des membres en filtrant par statut <em>Inactif</em>, puis cliquez sur <em>Réactiver</em>.</li>
</ul>',
                ],
                [
                    'title' => 'Auditer les accès de votre équipe',
                    'slug' => 'bonnes-pratiques-gestion-equipe',
                    'excerpt' => 'Passez en revue les rôles et comptes actifs chaque trimestre pour maintenir la sécurité des accès.',
                    'content' => '<p>Dans ce guide, vous allez effectuer une revue trimestrielle des accès de votre équipe sur Leezr.</p>
<h2>Situation</h2>
<p>Les départs, arrivées et changements de poste s\'accumulent. Sans revue régulière, des comptes obsolètes restent actifs et des rôles ne correspondent plus aux responsabilités réelles.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir la page Membres</strong> — Parcourez la liste complète. Identifiez les membres avec le statut <em>En attente</em> depuis plus de 7 jours (invitations expirées) et les membres qui ne se sont pas connectés récemment.</li>
  <li><strong>Désactiver les comptes inactifs</strong> — Un membre non connecté depuis 90 jours et absent de l\'entreprise doit être désactivé.</li>
  <li><strong>Vérifier les rôles</strong> — Pour chaque membre actif, confirmez que le rôle correspond à son poste actuel. Un chauffeur promu chef d\'équipe doit passer en Gestionnaire.</li>
  <li><strong>Limiter les administrateurs</strong> — Vérifiez qu\'il n\'y a pas plus de 2-3 administrateurs. Rétrogradez les accès inutilement élevés.</li>
  <li><strong>Supprimer les invitations expirées</strong> — Nettoyez les invitations en attente qui n\'aboutiront pas.</li>
</ol>
<h2>Résultat</h2>
<p>Vos accès sont à jour et conformes. Chaque membre actif a le bon rôle et aucun compte obsolète ne reste ouvert. Vous pouvez démontrer une gestion rigoureuse en cas d\'audit.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Ancien intérimaire encore actif</strong> — Filtrez par date de dernière connexion pour repérer les comptes dormants.</li>
  <li><strong>Personne ne sait qui est administrateur</strong> — Filtrez par rôle sur la page Membres pour lister tous les administrateurs actuels.</li>
  <li><strong>Revue jamais effectuée</strong> — Planifiez un rappel trimestriel. 15 minutes suffisent pour passer en revue les accès d\'une équipe de 20 personnes.</li>
</ul>',
                ],
            ],
        ],

        // ──────────────────────────────────────────────
        // Topic 4 : Documents
        // ──────────────────────────────────────────────
        [
            'title' => 'Documents',
            'slug' => 'documents',
            'description' => 'Ajoutez, suivez et renouvelez vos documents réglementaires pour assurer la conformité.',
            'icon' => 'tabler-file-text',
            'articles' => [
                [
                    'title' => 'Naviguer dans le module Documents',
                    'slug' => 'comprendre-types-documents',
                    'excerpt' => 'Parcourez les catégories de documents (entreprise, véhicules, personnel) et identifiez les statuts d\'un coup d\'oeil.',
                    'content' => '<p>Dans ce guide, vous allez explorer le module Documents pour localiser et filtrer vos documents réglementaires.</p>
<h2>Situation</h2>
<p>Vous devez gérer des dizaines de documents : licences de transport, contrôles techniques, permis de conduire, cartes conducteur, certificats ADR. Le module Documents les organise par catégorie avec des indicateurs de statut visuels.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir le module Documents</strong> — Menu latéral → <em>Documents</em>. La vue d\'ensemble affiche un compteur par statut : valides, expirant bientôt, expirés, manquants.</li>
  <li><strong>Parcourir les catégories</strong> — <em>Entreprise</em> (licence de transport, Kbis, RC pro), <em>Véhicules</em> (carte grise, contrôle technique, assurance) et <em>Personnel</em> (permis, FIMO/FCO, carte conducteur, certificat ADR).</li>
  <li><strong>Lire les indicateurs de statut</strong> — Vert = valide, orange = expire sous 30 jours, rouge = expiré, gris = manquant.</li>
  <li><strong>Filtrer les documents</strong> — Utilisez les filtres par catégorie, statut ou membre pour retrouver un document spécifique.</li>
</ol>
<h2>Résultat</h2>
<p>Vous repérez instantanément les documents critiques (expirés ou manquants) et pouvez agir en priorité sur ceux qui mettent votre conformité en risque.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Document mal catégorisé</strong> — Un document dans la mauvaise catégorie ne déclenche pas les bonnes alertes. Vérifiez le type lors de l\'ajout.</li>
  <li><strong>Statut « Manquant » inattendu</strong> — Cela signifie qu\'un document requis n\'a pas été téléchargé. Ajoutez-le ou demandez-le au membre concerné.</li>
  <li><strong>Module Documents absent du menu</strong> — Il doit être activé depuis la page Modules.</li>
</ul>',
                ],
                [
                    'title' => 'Ajouter un document',
                    'slug' => 'telecharger-gerer-document',
                    'excerpt' => 'Téléchargez un document, renseignez ses métadonnées (type, expiration) et associez-le à un membre ou véhicule.',
                    'content' => '<p>Dans ce guide, vous allez ajouter un document dans Leezr avec toutes ses métadonnées pour activer le suivi automatique.</p>
<h2>Situation</h2>
<p>Vous recevez un nouveau contrôle technique, un permis de conduire renouvelé ou une attestation d\'assurance. Vous devez l\'enregistrer dans Leezr avec sa date d\'expiration pour que le système vous alerte avant l\'échéance.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir le module Documents</strong> — Menu latéral → <em>Documents</em> → bouton <em>Ajouter un document</em>.</li>
  <li><strong>Choisir le type</strong> — Sélectionnez dans la liste : permis de conduire, contrôle technique, attestation d\'assurance, licence de transport, FIMO/FCO, etc.</li>
  <li><strong>Renseigner les métadonnées</strong> — Numéro du document, date d\'émission, date d\'expiration. Associez le document à un membre (documents personnels) ou laissez-le au niveau entreprise.</li>
  <li><strong>Télécharger le fichier</strong> — Cliquez sur la zone d\'upload. Formats acceptés : PDF, JPG, PNG. Taille max : 10 Mo.</li>
  <li><strong>Enregistrer</strong> — Cliquez sur <em>Enregistrer</em>. Le document apparaît dans la liste avec son statut calculé automatiquement.</li>
</ol>
<h2>Résultat</h2>
<p>Le document est enregistré avec ses métadonnées. Leezr programmera automatiquement des alertes avant la date d\'expiration (30, 15 et 7 jours).</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Pas d\'alerte reçue</strong> — Vérifiez que la date d\'expiration est bien renseignée. Sans date, aucune alerte ne sera générée.</li>
  <li><strong>Fichier refusé</strong> — Vérifiez le format (PDF, JPG ou PNG) et la taille (max 10 Mo). Réduisez la résolution du scan si nécessaire.</li>
  <li><strong>Document non associé au bon membre</strong> — Modifiez le document après création pour corriger l\'association.</li>
</ul>',
                ],
                [
                    'title' => 'Suivre les dates d\'expiration',
                    'slug' => 'suivre-dates-expiration',
                    'excerpt' => 'Anticipez les renouvellements grâce aux alertes automatiques et au tableau de bord des expirations.',
                    'content' => '<p>Dans ce guide, vous allez configurer et utiliser le suivi des expirations pour ne jamais circuler avec un document périmé.</p>
<h2>Situation</h2>
<p>Dans le transport, un document expiré = véhicule immobilisé, chauffeur interdit de circuler, amende pour l\'entreprise. Le suivi automatique de Leezr vous alerte à l\'avance pour planifier les renouvellements.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Consulter le tableau des expirations</strong> — Page <em>Documents</em> : le compteur en haut affiche les documents valides, expirant bientôt et expirés.</li>
  <li><strong>Vérifier les alertes email</strong> — Leezr envoie des emails automatiques à 30, 15 et 7 jours avant l\'expiration.</li>
  <li><strong>Consulter le Tableau de bord</strong> — Le widget d\'alertes liste les prochaines échéances avec un lien direct vers chaque document.</li>
  <li><strong>Planifier le renouvellement</strong> — Prenez rendez-vous (contrôle technique, visite médicale) dès la première alerte à 30 jours.</li>
  <li><strong>Mettre à jour le document</strong> — Après renouvellement, ajoutez le nouveau document dans Leezr. L\'ancien est archivé automatiquement.</li>
</ol>
<h2>Résultat</h2>
<p>Chaque expiration est anticipée. Aucun véhicule ni chauffeur ne circule avec un document périmé. Votre taux de conformité reste à 100 %.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Alerte non reçue par email</strong> — Vérifiez que les notifications email sont activées dans les paramètres entreprise et que l\'adresse email est correcte.</li>
  <li><strong>Ancien document toujours affiché comme actif</strong> — Ajoutez le nouveau document avec le même type. L\'ancien passe automatiquement en archive.</li>
  <li><strong>Délai de renouvellement trop court</strong> — Si 30 jours ne suffisent pas (ex. : contrôle technique avec 6 semaines d\'attente), contactez votre administrateur pour ajuster les seuils d\'alerte.</li>
</ul>',
                ],
                [
                    'title' => 'Demander un document à un membre',
                    'slug' => 'gerer-demandes-documents',
                    'excerpt' => 'Envoyez une demande formelle à un collaborateur pour qu\'il fournisse un document manquant (permis, FIMO, carte conducteur).',
                    'content' => '<p>Dans ce guide, vous allez créer une demande de document pour qu\'un membre fournisse une pièce manquante.</p>
<h2>Situation</h2>
<p>Un chauffeur n\'a pas encore téléchargé son permis de conduire, sa carte conducteur ou son certificat FIMO dans Leezr. Vous devez collecter ces documents avant un audit ou un contrôle DREAL.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Identifier les documents manquants</strong> — Page <em>Documents</em> → filtrez par statut <em>Manquant</em>. La liste indique quel membre doit fournir quel document.</li>
  <li><strong>Créer la demande</strong> — Cliquez sur le bouton de demande à côté du document manquant. Précisez le type de document attendu, la date limite et un message au membre.</li>
  <li><strong>Le membre est notifié</strong> — Le collaborateur reçoit un email et une notification plateforme avec les instructions et la date limite.</li>
  <li><strong>Suivre l\'avancement</strong> — Le statut de la demande évolue : <em>En attente</em> → <em>Fourni</em> (en attente de validation) → <em>Validé</em>.</li>
  <li><strong>Valider le document reçu</strong> — Vérifiez la lisibilité et les dates, puis validez. Le statut passe de Manquant à Valide.</li>
</ol>
<h2>Résultat</h2>
<p>Le document manquant est collecté et validé dans les délais. Votre taux de conformité documentaire s\'améliore et vous êtes prêt pour l\'audit.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Membre qui ne répond pas</strong> — Renvoyez un rappel depuis la page Documents à mi-parcours du délai. Ne comptez pas uniquement sur la première notification.</li>
  <li><strong>Document fourni mais illisible</strong> — Rejetez le document avec un commentaire et demandez un nouveau scan de meilleure qualité.</li>
  <li><strong>Délai trop court</strong> — Laissez au moins 7 jours. Certains collaborateurs devront scanner un document qu\'ils n\'ont pas sous la main.</li>
</ul>',
                ],
                [
                    'title' => 'Configurer les alertes de documents',
                    'slug' => 'configurer-notifications-documents',
                    'excerpt' => 'Définissez les seuils d\'alerte (60, 30, 15 jours) et les destinataires pour chaque type de document.',
                    'content' => '<p>Dans ce guide, vous allez paramétrer les seuils et destinataires des alertes d\'expiration pour ne jamais manquer une échéance.</p>
<h2>Situation</h2>
<p>Les seuils d\'alerte par défaut (30, 15, 7 jours) ne conviennent pas à tous les types de documents. Un contrôle technique nécessite un rendez-vous 6 semaines à l\'avance, une assurance se renouvelle en quelques jours.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir les paramètres de notification</strong> — Page <em>Documents</em> → icône engrenage → section <em>Notifications</em>.</li>
  <li><strong>Définir les seuils par type</strong> — Configurez les paliers d\'alerte pour chaque catégorie : ex. 60 jours pour les contrôles techniques, 30 jours pour les assurances, 45 jours pour les permis.</li>
  <li><strong>Choisir les destinataires</strong> — Pour chaque catégorie : le membre concerné seul, le gestionnaire, l\'administrateur ou une combinaison.</li>
  <li><strong>Activer les notifications plateforme</strong> — En plus des emails, activez les alertes dans la cloche de notification. Elles restent visibles jusqu\'au traitement.</li>
</ol>
<h2>Résultat</h2>
<p>Chaque type de document a des seuils d\'alerte adaptés à son délai de renouvellement réel. Les bonnes personnes sont alertées au bon moment.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Seuil trop court pour un contrôle technique</strong> — Les centres de contrôle ont souvent 3-4 semaines de délai. Configurez l\'alerte à 60 jours minimum.</li>
  <li><strong>Trop de destinataires = personne ne réagit</strong> — Désignez un responsable clair pour chaque catégorie. Si tout le monde reçoit l\'alerte, personne ne se sent responsable.</li>
  <li><strong>Notifications désactivées par erreur</strong> — Vérifiez que les canaux email et plateforme sont bien cochés. Les deux doivent être actifs pour une couverture complète.</li>
</ul>',
                ],
            ],
        ],
    ],
];
