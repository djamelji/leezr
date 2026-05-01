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
            'description' => 'Vos premiers pas sur Leezr : inscription, configuration et prise en main rapide de la plateforme.',
            'icon' => 'tabler-player-play',
            'articles' => [
                [
                    'title' => 'Premiers pas après l\'inscription',
                    'slug' => 'premiers-pas-apres-inscription',
                    'excerpt' => 'Après avoir créé votre compte Leezr, quelques étapes essentielles vous permettront de profiter pleinement de la plateforme. Découvrez comment compléter votre profil, inviter vos collaborateurs et activer vos premiers modules.',
                    'content' => '<h2>Contexte</h2>
<p>Vous venez de créer votre compte Leezr et vous arrivez sur votre tableau de bord pour la première fois. La plateforme vous guide à travers les étapes de configuration initiale grâce au widget d\'onboarding affiché en haut du tableau de bord. Ces étapes garantissent que votre espace de travail est opérationnel et prêt à accueillir votre équipe.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Complétez votre profil entreprise</strong> — Rendez-vous dans la section <em>Profil</em> depuis le menu latéral. Renseignez le nom de votre entreprise, l\'adresse du siège, le numéro SIRET et le numéro de TVA intracommunautaire. Ces informations apparaîtront sur vos documents et factures.</li>
<li><strong>Ajoutez votre logo</strong> — Toujours dans <em>Profil</em>, cliquez sur la zone d\'upload pour télécharger le logo de votre entreprise. Ce logo sera utilisé dans l\'en-tête de votre espace et sur les documents générés.</li>
<li><strong>Invitez vos premiers membres</strong> — Accédez à la page <em>Membres</em> et cliquez sur le bouton <em>Inviter un membre</em>. Saisissez l\'adresse email de vos collaborateurs et attribuez-leur un rôle adapté (administrateur, gestionnaire ou membre).</li>
<li><strong>Activez vos modules</strong> — Rendez-vous sur la page <em>Modules</em> pour parcourir les fonctionnalités disponibles. Activez ceux dont vous avez besoin : Documents, Expéditions, Workflows, etc.</li>
<li><strong>Explorez le tableau de bord</strong> — Revenez sur votre <em>Tableau de bord</em> pour vérifier votre progression d\'onboarding et découvrir les widgets de suivi d\'activité.</li>
</ol>

<h2>Exemple concret</h2>
<p>La société TransRoute Express vient de s\'inscrire. Le gérant complète le profil avec le SIRET et l\'adresse du dépôt de Toulouse, invite son responsable d\'exploitation et ses deux dispatcheurs, puis active les modules Documents et Expéditions. En 10 minutes, l\'équipe reçoit ses invitations par email et peut commencer à travailler.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Oublier de compléter le SIRET</strong> — Ce champ est nécessaire pour la facturation et les documents réglementaires. Pensez à le renseigner dès la configuration initiale.</li>
<li><strong>Inviter des membres sans leur attribuer de rôle</strong> — Chaque membre doit avoir un rôle défini pour accéder aux fonctionnalités appropriées.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Votre espace Leezr est configuré avec les informations de votre entreprise, vos collaborateurs ont reçu leurs invitations et vos modules sont activés. Le widget d\'onboarding du tableau de bord affiche 100 % de progression.</p>',
                ],
                [
                    'title' => 'Configurer votre profil entreprise',
                    'slug' => 'configurer-profil-entreprise',
                    'excerpt' => 'Le profil entreprise centralise vos informations légales et de contact. Apprenez à renseigner correctement le nom, l\'adresse, le SIRET, la TVA et le logo de votre société.',
                    'content' => '<h2>Contexte</h2>
<p>Les informations de votre profil entreprise servent de base à toute la plateforme : elles apparaissent sur les factures, les documents générés et permettent d\'identifier votre société auprès de vos partenaires. Un profil complet et à jour est indispensable pour une utilisation professionnelle de Leezr.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accédez au profil</strong> — Dans le menu latéral, cliquez sur <em>Profil</em>. L\'onglet <em>Vue d\'ensemble</em> affiche les informations actuelles de votre entreprise.</li>
<li><strong>Modifiez les informations générales</strong> — Cliquez sur le bouton de modification pour éditer le nom commercial, la raison sociale et la forme juridique de votre entreprise.</li>
<li><strong>Renseignez l\'adresse</strong> — Saisissez l\'adresse complète du siège social : numéro, rue, code postal, ville et pays. Cette adresse figurera sur vos documents officiels.</li>
<li><strong>Ajoutez les identifiants légaux</strong> — Renseignez le numéro SIRET (14 chiffres) et le numéro de TVA intracommunautaire (format FR + 11 chiffres). Ces informations sont obligatoires pour la facturation.</li>
<li><strong>Téléchargez votre logo</strong> — Cliquez sur la zone de téléchargement pour ajouter le logo de votre entreprise. Les formats acceptés sont PNG et JPG, avec une taille recommandée de 200x200 pixels minimum.</li>
</ol>

<h2>Exemple concret</h2>
<p>LogiNord SARL met à jour son profil après un déménagement de siège. Le gestionnaire accède à la page Profil, modifie l\'adresse postale de Lille vers le nouveau dépôt de Roubaix, vérifie que le SIRET est toujours correct et met à jour le logo avec la nouvelle charte graphique. Les factures suivantes afficheront automatiquement la nouvelle adresse.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Numéro SIRET incomplet</strong> — Le SIRET doit contenir exactement 14 chiffres. Vérifiez sur votre extrait Kbis si vous avez un doute.</li>
<li><strong>TVA au mauvais format</strong> — Le numéro de TVA intracommunautaire français commence par FR suivi de 11 chiffres. Ne confondez pas avec le numéro SIREN.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Votre profil entreprise est complet avec toutes les informations légales à jour. Votre logo s\'affiche dans la barre de navigation et les documents générés contiennent les bonnes coordonnées et identifiants fiscaux.</p>',
                ],
                [
                    'title' => 'Inviter vos premiers membres',
                    'slug' => 'inviter-premiers-membres',
                    'excerpt' => 'Constituez votre équipe sur Leezr en envoyant des invitations par email. Attribuez un rôle à chaque membre dès l\'invitation pour structurer les accès.',
                    'content' => '<h2>Contexte</h2>
<p>Leezr est un outil collaboratif conçu pour les équipes. Inviter vos collaborateurs leur permet d\'accéder à la plateforme avec des droits adaptés à leur fonction. L\'invitation se fait par email : chaque personne reçoit un lien sécurisé pour créer son accès et rejoindre votre espace entreprise.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Ouvrez la page Membres</strong> — Dans le menu latéral, cliquez sur <em>Membres</em>. La liste de tous les membres actuels s\'affiche avec leur rôle et statut.</li>
<li><strong>Cliquez sur Inviter un membre</strong> — Le bouton en haut à droite ouvre le formulaire d\'invitation dans un panneau latéral.</li>
<li><strong>Saisissez l\'adresse email</strong> — Entrez l\'adresse email professionnelle de la personne à inviter. Leezr vérifie que cette adresse n\'est pas déjà associée à un compte existant.</li>
<li><strong>Sélectionnez un rôle</strong> — Choisissez le rôle approprié : <em>Administrateur</em> pour un accès complet, <em>Gestionnaire</em> pour la gestion opérationnelle ou <em>Membre</em> pour un accès limité aux tâches quotidiennes.</li>
<li><strong>Envoyez l\'invitation</strong> — Cliquez sur <em>Envoyer</em>. Le membre reçoit un email avec un lien d\'inscription valable 7 jours. Son statut apparaît comme <em>En attente</em> dans la liste des membres.</li>
</ol>

<h2>Exemple concret</h2>
<p>Le directeur de FlotteExpress invite son chef de parc avec le rôle Gestionnaire et ses cinq chauffeurs avec le rôle Membre. Les chauffeurs pourront consulter leurs documents personnels (permis, FIMO) et les missions assignées, tandis que le chef de parc pourra gérer l\'ensemble de la flotte et des documents véhicules.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Invitation expirée</strong> — Si un membre ne clique pas sur le lien dans les 7 jours, vous devrez renvoyer l\'invitation depuis la page Membres.</li>
<li><strong>Mauvais rôle attribué</strong> — Vous pouvez modifier le rôle d\'un membre à tout moment depuis sa fiche. Pas besoin de le réinviter.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vos collaborateurs reçoivent un email d\'invitation, créent leur compte en quelques clics et apparaissent dans votre liste de membres avec le rôle que vous leur avez attribué. Ils peuvent immédiatement commencer à utiliser la plateforme.</p>',
                ],
                [
                    'title' => 'Activer vos premiers modules',
                    'slug' => 'activer-premiers-modules',
                    'excerpt' => 'Leezr fonctionne par modules activables à la demande. Découvrez comment parcourir les modules disponibles et activer ceux qui correspondent à votre activité.',
                    'content' => '<h2>Contexte</h2>
<p>Leezr adopte une architecture modulaire : chaque fonctionnalité majeure (Documents, Expéditions, Workflows, etc.) est un module indépendant que vous activez selon vos besoins. Cette approche vous permet de ne payer et d\'utiliser que ce qui est utile à votre activité, et d\'ajouter progressivement de nouvelles fonctionnalités au fil de votre croissance.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accédez à la page Modules</strong> — Dans le menu latéral, cliquez sur <em>Modules</em>. La page affiche tous les modules disponibles sous forme de cartes avec leur description et leur statut (actif ou inactif).</li>
<li><strong>Parcourez les modules disponibles</strong> — Chaque carte de module décrit sa fonctionnalité, les prérequis éventuels et l\'impact sur votre abonnement. Prenez le temps de lire les descriptions pour identifier ceux qui vous sont utiles.</li>
<li><strong>Activez un module</strong> — Cliquez sur le bouton <em>Activer</em> de la carte du module souhaité. Une confirmation vous est demandée si le module a un impact sur votre facturation.</li>
<li><strong>Configurez le module</strong> — Certains modules nécessitent une configuration initiale après activation. Suivez les instructions affichées à l\'écran pour paramétrer le module selon vos besoins.</li>
<li><strong>Vérifiez l\'activation</strong> — Le module activé apparaît désormais dans votre menu latéral. Cliquez dessus pour accéder à ses fonctionnalités.</li>
</ol>

<h2>Exemple concret</h2>
<p>Une entreprise de transport frigorifique active le module Documents pour suivre les certificats ATP (Accord relatif au Transport international de denrées Périssables) de ses véhicules, puis le module Expéditions pour gérer ses tournées de livraison. Les deux modules apparaissent dans le menu et sont immédiatement opérationnels.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Activer trop de modules d\'un coup</strong> — Commencez par les modules essentiels à votre activité quotidienne et ajoutez-en au fur et à mesure. Cela facilite la prise en main par votre équipe.</li>
<li><strong>Ne pas configurer un module après activation</strong> — Un module activé mais non configuré n\'apportera pas de valeur. Prenez quelques minutes pour le paramétrer.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Les modules sélectionnés sont actifs et accessibles depuis le menu latéral. Votre équipe peut commencer à utiliser les nouvelles fonctionnalités et votre facturation reflète les modules activés.</p>',
                ],
                [
                    'title' => 'Comprendre le tableau de bord',
                    'slug' => 'comprendre-tableau-de-bord',
                    'excerpt' => 'Le tableau de bord est votre point d\'entrée quotidien sur Leezr. Découvrez les widgets, indicateurs clés et actions rapides qui vous donnent une vue d\'ensemble de votre activité.',
                    'content' => '<h2>Contexte</h2>
<p>Le tableau de bord est la première page que vous voyez en vous connectant à Leezr. Il centralise les informations essentielles de votre activité sous forme de widgets : progression d\'onboarding, alertes de documents expirants, statistiques d\'expéditions et actions rapides. C\'est votre cockpit quotidien pour piloter votre entreprise.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Consultez le widget d\'onboarding</strong> — En haut du tableau de bord, la barre de progression vous indique les étapes de configuration restantes. Cliquez sur chaque étape pour la compléter directement.</li>
<li><strong>Surveillez les alertes documents</strong> — Le widget d\'alertes affiche les documents qui arrivent à expiration dans les 30 prochains jours. Cliquez sur une alerte pour accéder directement au document concerné.</li>
<li><strong>Consultez les indicateurs clés (KPI)</strong> — Les cartes de statistiques affichent les métriques importantes : nombre de membres actifs, documents en cours de validité, expéditions en cours et taux de conformité documentaire.</li>
<li><strong>Utilisez les actions rapides</strong> — La barre d\'actions rapides vous permet de créer une expédition, d\'inviter un membre ou de télécharger un document en un seul clic, sans naviguer dans les menus.</li>
<li><strong>Parcourez le fil d\'activité</strong> — En bas du tableau de bord, le fil d\'activité récente liste les dernières actions effectuées par les membres de votre équipe : documents ajoutés, membres invités, modules activés.</li>
</ol>

<h2>Exemple concret</h2>
<p>Chaque matin, le responsable d\'exploitation de TransAlpes ouvre son tableau de bord. Il voit immédiatement que deux contrôles techniques expirent cette semaine, que trois expéditions sont en cours de livraison et qu\'un nouveau membre a accepté son invitation hier. Il clique sur l\'alerte du contrôle technique pour planifier le rendez-vous au centre de contrôle.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Ignorer les alertes du tableau de bord</strong> — Les alertes de documents expirants sont critiques dans le transport. Un permis ou un contrôle technique expiré peut immobiliser un véhicule ou un chauffeur.</li>
<li><strong>Ne pas revenir régulièrement au tableau de bord</strong> — Prenez l\'habitude de consulter le tableau de bord en début de journée pour avoir une vue d\'ensemble actualisée.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous maîtrisez la lecture du tableau de bord et savez interpréter chaque widget. Vous utilisez les alertes pour anticiper les échéances critiques et les actions rapides pour gagner du temps au quotidien.</p>',
                ],
            ],
        ],

        // ──────────────────────────────────────────────
        // Topic 2 : Gestion entreprise
        // ──────────────────────────────────────────────
        [
            'title' => 'Gestion entreprise',
            'slug' => 'gestion-entreprise',
            'description' => 'Gérez les informations, paramètres et organisation de votre entreprise sur Leezr.',
            'icon' => 'tabler-building',
            'articles' => [
                [
                    'title' => 'Modifier les informations de l\'entreprise',
                    'slug' => 'modifier-informations-entreprise',
                    'excerpt' => 'Vos coordonnées ont changé ou vous devez corriger une information légale ? Apprenez à mettre à jour le profil de votre entreprise en quelques clics.',
                    'content' => '<h2>Contexte</h2>
<p>Les informations de votre entreprise peuvent évoluer : déménagement de siège, changement de raison sociale, mise à jour du numéro de TVA après une restructuration. Il est essentiel de maintenir ces données à jour car elles sont utilisées pour la facturation, la conformité documentaire et l\'identification de votre société auprès de vos partenaires sur la plateforme.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accédez au profil entreprise</strong> — Dans le menu latéral, cliquez sur <em>Profil</em>. L\'onglet <em>Vue d\'ensemble</em> présente un résumé de toutes les informations enregistrées.</li>
<li><strong>Passez en mode édition</strong> — Cliquez sur le bouton de modification (icône crayon) dans la section que vous souhaitez mettre à jour : informations générales, adresse ou identifiants légaux.</li>
<li><strong>Modifiez les champs nécessaires</strong> — Corrigez ou complétez les informations. Les champs obligatoires sont marqués d\'un astérisque. La validation s\'effectue en temps réel pour éviter les erreurs de format.</li>
<li><strong>Enregistrez les modifications</strong> — Cliquez sur <em>Enregistrer</em>. Un message de confirmation s\'affiche. Les modifications sont immédiatement prises en compte sur l\'ensemble de la plateforme.</li>
<li><strong>Vérifiez la mise à jour</strong> — Rechargez la page Profil pour confirmer que les nouvelles informations sont bien affichées. Vérifiez notamment que le SIRET et la TVA sont au bon format.</li>
</ol>

<h2>Exemple concret</h2>
<p>La société MeriFret vient de fusionner avec un autre transporteur. L\'administrateur met à jour la raison sociale de « MeriFret SARL » à « MeriFret Logistics SAS », modifie le numéro SIRET suite à la nouvelle immatriculation et ajoute la nouvelle adresse du siège à Marseille. Toutes les factures suivantes reflèteront ces changements.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Modifier le SIRET sans vérifier</strong> — Un SIRET erroné peut entraîner des problèmes de facturation. Vérifiez toujours sur votre extrait Kbis ou sur societe.com.</li>
<li><strong>Oublier de mettre à jour l\'adresse sur les documents</strong> — Après un déménagement, vérifiez que vos documents générés affichent la nouvelle adresse.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Les informations de votre entreprise sont à jour sur toute la plateforme. Les factures, documents et communications utilisent les coordonnées et identifiants corrects.</p>',
                ],
                [
                    'title' => 'Gérer les paramètres de l\'entreprise',
                    'slug' => 'gerer-parametres-entreprise',
                    'excerpt' => 'Configurez les préférences de votre espace entreprise : fuseau horaire, langue, format de date et autres paramètres qui impactent l\'ensemble de votre équipe.',
                    'content' => '<h2>Contexte</h2>
<p>Les paramètres de votre entreprise définissent le comportement global de la plateforme pour tous les membres de votre équipe. Le fuseau horaire affecte l\'affichage des dates et les notifications, la langue détermine l\'interface par défaut et les préférences de notification impactent toute l\'organisation. Ces réglages sont accessibles uniquement aux administrateurs.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accédez aux paramètres</strong> — Dans le menu latéral, cliquez sur <em>Profil</em> puis sélectionnez l\'onglet <em>Paramètres</em>. Cette section regroupe toutes les préférences de votre espace entreprise.</li>
<li><strong>Configurez le fuseau horaire</strong> — Sélectionnez le fuseau horaire correspondant au siège de votre entreprise. Ce paramètre affecte l\'affichage des heures et les déclenchements de notifications pour tous les membres.</li>
<li><strong>Choisissez la langue par défaut</strong> — Sélectionnez la langue principale de votre organisation (français ou anglais). Les nouveaux membres hériteront de ce paramètre par défaut.</li>
<li><strong>Définissez les préférences de notification</strong> — Configurez les canaux de notification (email, plateforme) et leur fréquence pour les événements critiques : expiration de documents, nouvelles expéditions, invitations.</li>
<li><strong>Enregistrez les paramètres</strong> — Cliquez sur <em>Enregistrer</em>. Les modifications s\'appliquent immédiatement à tous les membres de votre entreprise.</li>
</ol>

<h2>Exemple concret</h2>
<p>EuroTrans opère depuis la France mais gère des chauffeurs en Roumanie et en Espagne. L\'administrateur configure le fuseau horaire sur Europe/Paris pour le siège, active les notifications email pour les expirations de documents (permis de conduire, cartes de conducteur) et définit le français comme langue par défaut de l\'interface.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Mauvais fuseau horaire</strong> — Un fuseau horaire incorrect décale toutes les alertes d\'expiration. Vérifiez que le fuseau correspond bien à votre zone d\'exploitation principale.</li>
<li><strong>Désactiver les notifications critiques</strong> — Ne désactivez jamais les notifications d\'expiration de documents dans le transport : un oubli peut entraîner une immobilisation de véhicule.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vos paramètres d\'entreprise sont configurés correctement. Tous les membres voient les dates au bon fuseau horaire, reçoivent les notifications pertinentes et utilisent l\'interface dans la langue définie.</p>',
                ],
                [
                    'title' => 'Comprendre les rôles et permissions',
                    'slug' => 'comprendre-roles-permissions',
                    'excerpt' => 'Leezr propose un système de rôles pour contrôler l\'accès aux fonctionnalités. Découvrez les différents rôles disponibles et ce que chacun peut faire sur la plateforme.',
                    'content' => '<h2>Contexte</h2>
<p>Dans une entreprise de transport, tous les collaborateurs n\'ont pas besoin d\'accéder aux mêmes informations. Un chauffeur consulte ses documents personnels et ses missions, un chef de parc gère la flotte complète, et le dirigeant supervise l\'ensemble avec accès à la facturation. Le système de rôles de Leezr permet de structurer ces niveaux d\'accès de manière simple et sécurisée.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Consultez les rôles disponibles</strong> — Accédez à la page <em>Rôles</em> depuis le menu latéral. Cette page liste tous les rôles définis pour votre entreprise avec un résumé de leurs permissions.</li>
<li><strong>Comprenez le rôle Administrateur</strong> — L\'administrateur a un accès complet à toutes les fonctionnalités : gestion des membres, paramètres d\'entreprise, facturation, modules et tous les contenus. C\'est le rôle du dirigeant ou du responsable IT.</li>
<li><strong>Comprenez le rôle Gestionnaire</strong> — Le gestionnaire peut gérer les opérations quotidiennes : créer et modifier des documents, gérer les expéditions, consulter les rapports. Il n\'a pas accès aux paramètres d\'entreprise ni à la facturation.</li>
<li><strong>Comprenez le rôle Membre</strong> — Le membre a un accès en lecture et des droits limités : il consulte ses documents personnels, ses missions et peut mettre à jour son profil. Il ne peut pas modifier les données des autres membres.</li>
<li><strong>Vérifiez les permissions de chaque rôle</strong> — Cliquez sur un rôle pour voir le détail de ses permissions module par module. Un tableau récapitule les droits de lecture, création, modification et suppression pour chaque fonctionnalité.</li>
</ol>

<h2>Exemple concret</h2>
<p>Chez RapidFret, le gérant est Administrateur, les deux responsables d\'exploitation sont Gestionnaires et les 15 chauffeurs sont Membres. Les gestionnaires peuvent ajouter des documents de véhicules et planifier les expéditions, mais seul l\'administrateur peut inviter de nouveaux membres ou modifier les paramètres de facturation.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Donner le rôle Administrateur à tous</strong> — Réservez ce rôle aux personnes qui doivent réellement gérer l\'entreprise sur la plateforme. Trop d\'administrateurs augmente les risques d\'erreurs de configuration.</li>
<li><strong>Confondre Gestionnaire et Membre</strong> — Le Gestionnaire peut modifier les données de l\'entreprise (documents, expéditions), tandis que le Membre ne peut consulter et modifier que ses propres données.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous comprenez le système de rôles de Leezr et pouvez attribuer le rôle approprié à chaque collaborateur en fonction de ses responsabilités. Votre organisation est structurée et chaque membre accède uniquement aux fonctionnalités dont il a besoin.</p>',
                ],
                [
                    'title' => 'Consulter l\'historique d\'activité',
                    'slug' => 'consulter-historique-activite',
                    'excerpt' => 'L\'historique d\'activité vous permet de savoir qui a fait quoi et quand. Suivez les actions de vos membres pour garantir la traçabilité et la sécurité.',
                    'content' => '<h2>Contexte</h2>
<p>Dans le secteur du transport, la traçabilité est une exigence réglementaire et opérationnelle. Leezr enregistre automatiquement les actions significatives effectuées par les membres de votre équipe : création ou modification de documents, changements de paramètres, invitations de membres, activations de modules. Cet historique est précieux pour les audits, la résolution de problèmes et le suivi de l\'activité de votre équipe.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accédez au fil d\'activité</strong> — Le fil d\'activité récente est visible sur votre <em>Tableau de bord</em>. Il affiche les dernières actions effectuées par l\'ensemble des membres de votre entreprise.</li>
<li><strong>Lisez les entrées du fil</strong> — Chaque entrée indique l\'auteur de l\'action, le type d\'action (création, modification, suppression), l\'objet concerné et la date/heure précise.</li>
<li><strong>Filtrez par type d\'action</strong> — Utilisez les filtres disponibles pour isoler un type d\'action spécifique : modifications de documents, changements de membres, actions sur les expéditions.</li>
<li><strong>Identifiez l\'auteur</strong> — Chaque action est associée au membre qui l\'a effectuée. Cliquez sur le nom du membre pour accéder à son profil et voir l\'ensemble de ses actions récentes.</li>
<li><strong>Exportez si nécessaire</strong> — Pour les besoins d\'audit ou de conformité, vous pouvez consulter l\'historique sur une période donnée et identifier précisément la chronologie des événements.</li>
</ol>

<h2>Exemple concret</h2>
<p>Lors d\'un contrôle routier, les autorités constatent qu\'un document de véhicule a été modifié récemment. Le responsable d\'exploitation consulte l\'historique d\'activité et peut prouver que la modification a été faite par le gestionnaire de flotte il y a trois jours, suite au renouvellement du contrôle technique. La traçabilité complète rassure les contrôleurs.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Ne pas consulter régulièrement l\'historique</strong> — L\'historique d\'activité est un outil de supervision. Consultez-le régulièrement pour détecter d\'éventuelles anomalies ou actions non autorisées.</li>
<li><strong>Confondre activité et notification</strong> — L\'historique enregistre toutes les actions, tandis que les notifications ne signalent que les événements configurés comme importants.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous savez consulter et interpréter l\'historique d\'activité de votre entreprise. Vous pouvez retracer n\'importe quelle action, identifier son auteur et utiliser ces informations pour les audits ou la résolution de problèmes.</p>',
                ],
                [
                    'title' => 'Personnaliser votre espace de travail',
                    'slug' => 'personnaliser-espace-travail',
                    'excerpt' => 'Adaptez l\'apparence de Leezr à vos préférences : thème clair ou sombre, disposition de la navigation et personnalisation de votre profil utilisateur.',
                    'content' => '<h2>Contexte</h2>
<p>Chaque utilisateur peut personnaliser l\'apparence de son interface Leezr selon ses préférences, sans impacter les autres membres de l\'équipe. Le choix du thème (clair ou sombre), la disposition du menu de navigation et les informations de votre profil personnel sont des réglages individuels qui rendent votre utilisation quotidienne plus confortable.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Changez le thème</strong> — Cliquez sur l\'icône de thème dans la barre de navigation supérieure pour basculer entre le mode clair et le mode sombre. Votre préférence est sauvegardée automatiquement et persiste entre les sessions.</li>
<li><strong>Configurez la navigation</strong> — Le menu latéral peut être réduit (icônes uniquement) ou étendu (icônes + libellés) en cliquant sur le bouton de bascule en haut du menu. Choisissez le mode qui convient le mieux à la taille de votre écran.</li>
<li><strong>Mettez à jour votre profil personnel</strong> — Cliquez sur votre avatar en haut à droite, puis sur <em>Mon profil</em>. Vous pouvez modifier votre nom, votre photo de profil et vos préférences de notification personnelles.</li>
<li><strong>Choisissez votre langue</strong> — Dans les préférences de votre profil, sélectionnez la langue d\'interface (français ou anglais). Ce paramètre est personnel et n\'affecte pas les autres membres.</li>
<li><strong>Vérifiez le rendu</strong> — Naviguez dans quelques pages pour vérifier que le thème et la disposition vous conviennent. Ajustez si nécessaire.</li>
</ol>

<h2>Exemple concret</h2>
<p>Un dispatcheur de nuit chez NoctaFret active le mode sombre pour réduire la fatigue oculaire pendant ses longues sessions devant l\'écran. Il réduit le menu latéral pour gagner de l\'espace à l\'écran sur son poste de travail à écran étroit et configure ses notifications pour ne recevoir que les alertes urgentes pendant ses heures de service.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Confondre paramètres personnels et paramètres entreprise</strong> — Le thème et la langue que vous choisissez ici ne s\'appliquent qu\'à votre propre compte. Les paramètres d\'entreprise (fuseau horaire, langue par défaut) sont gérés par les administrateurs.</li>
<li><strong>Oublier de sauvegarder</strong> — Le thème et le menu se sauvegardent automatiquement, mais les modifications de profil nécessitent de cliquer sur <em>Enregistrer</em>.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Votre interface Leezr est personnalisée selon vos préférences visuelles et ergonomiques. Le thème, la navigation et votre profil sont configurés pour un confort optimal au quotidien.</p>',
                ],
            ],
        ],

        // ──────────────────────────────────────────────
        // Topic 3 : Membres
        // ──────────────────────────────────────────────
        [
            'title' => 'Membres',
            'slug' => 'membres',
            'description' => 'Invitez, gérez et organisez les membres de votre équipe avec des rôles et permissions adaptés.',
            'icon' => 'tabler-users',
            'articles' => [
                [
                    'title' => 'Inviter un nouveau membre',
                    'slug' => 'inviter-nouveau-membre',
                    'excerpt' => 'Ajoutez un collaborateur à votre espace Leezr en quelques clics. L\'invitation par email permet au nouveau membre de créer son accès et de rejoindre immédiatement votre équipe.',
                    'content' => '<h2>Contexte</h2>
<p>L\'ajout de membres est une opération courante dans la vie d\'une entreprise de transport : embauche d\'un nouveau chauffeur, arrivée d\'un gestionnaire de flotte, recrutement d\'un dispatcheur. Leezr simplifie cette procédure grâce à un système d\'invitation par email qui guide le nouveau membre dans la création de son compte et la prise en main de la plateforme.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Rendez-vous sur la page Membres</strong> — Dans le menu latéral, cliquez sur <em>Membres</em>. La liste affiche tous les membres actuels avec leur rôle, statut et date d\'ajout.</li>
<li><strong>Ouvrez le formulaire d\'invitation</strong> — Cliquez sur le bouton <em>Inviter un membre</em> en haut à droite. Un panneau latéral s\'ouvre avec le formulaire d\'invitation.</li>
<li><strong>Renseignez les informations</strong> — Saisissez l\'adresse email du collaborateur. Sélectionnez le rôle à attribuer dans la liste déroulante : Administrateur, Gestionnaire ou Membre.</li>
<li><strong>Validez l\'envoi</strong> — Cliquez sur <em>Envoyer l\'invitation</em>. Un email contenant un lien d\'inscription sécurisé est envoyé au collaborateur. Le lien est valable 7 jours.</li>
<li><strong>Suivez le statut</strong> — Dans la liste des membres, le nouveau collaborateur apparaît avec le statut <em>En attente</em>. Le statut passera à <em>Actif</em> une fois qu\'il aura accepté l\'invitation et créé son compte.</li>
</ol>

<h2>Exemple concret</h2>
<p>TransAlpes recrute un nouveau chauffeur poids lourd. Le responsable d\'exploitation ouvre la page Membres, clique sur Inviter un membre, saisit l\'adresse email du chauffeur et lui attribue le rôle Membre. Le chauffeur reçoit un email, clique sur le lien, crée son mot de passe et accède immédiatement à son espace personnel où il peut consulter ses documents (permis C, FIMO, carte conducteur).</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Adresse email erronée</strong> — Vérifiez soigneusement l\'adresse email avant d\'envoyer. Une faute de frappe empêchera le collaborateur de recevoir l\'invitation.</li>
<li><strong>Invitation dans les spams</strong> — Si le collaborateur ne reçoit pas l\'email, demandez-lui de vérifier son dossier de courrier indésirable. Vous pouvez renvoyer l\'invitation depuis la page Membres.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le collaborateur reçoit un email d\'invitation, crée son compte en quelques minutes et apparaît comme membre actif dans votre liste. Il peut immédiatement accéder aux fonctionnalités correspondant à son rôle.</p>',
                ],
                [
                    'title' => 'Attribuer un rôle à un membre',
                    'slug' => 'attribuer-role-membre',
                    'excerpt' => 'Le rôle détermine ce qu\'un membre peut faire sur la plateforme. Apprenez à choisir et modifier le rôle d\'un collaborateur pour adapter ses accès à ses responsabilités.',
                    'content' => '<h2>Contexte</h2>
<p>Le rôle attribué à un membre conditionne l\'ensemble de ses accès sur Leezr. Un rôle trop restrictif empêchera le collaborateur de travailler efficacement, tandis qu\'un rôle trop permissif expose l\'entreprise à des risques de modification accidentelle. Il est donc essentiel de choisir le bon rôle dès l\'invitation et de le faire évoluer si les responsabilités du membre changent.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accédez à la fiche du membre</strong> — Sur la page <em>Membres</em>, cliquez sur le nom du collaborateur dont vous souhaitez modifier le rôle. Sa fiche détaillée s\'affiche.</li>
<li><strong>Identifiez le rôle actuel</strong> — Le rôle actuel est affiché sous le nom du membre. Les trois rôles disponibles sont : Administrateur, Gestionnaire et Membre.</li>
<li><strong>Modifiez le rôle</strong> — Cliquez sur le sélecteur de rôle et choisissez le nouveau rôle. Un résumé des permissions associées s\'affiche pour vous aider dans votre choix.</li>
<li><strong>Confirmez la modification</strong> — Cliquez sur <em>Enregistrer</em>. Le changement de rôle prend effet immédiatement : les menus et fonctionnalités accessibles au membre sont mis à jour.</li>
<li><strong>Informez le collaborateur</strong> — Le membre reçoit une notification l\'informant du changement de rôle. Il est recommandé de le prévenir en amont pour éviter toute confusion.</li>
</ol>

<h2>Exemple concret</h2>
<p>Chez LogiSud, un chauffeur expérimenté est promu chef d\'équipe. L\'administrateur ouvre sa fiche membre, change son rôle de Membre à Gestionnaire. Le nouveau chef d\'équipe peut désormais gérer les documents de ses chauffeurs, planifier les tournées et consulter les rapports d\'activité de son équipe, sans avoir accès à la facturation ni aux paramètres d\'entreprise.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Promouvoir sans former</strong> — Lorsque vous donnez un rôle avec plus de permissions, assurez-vous que le membre comprend ses nouvelles responsabilités sur la plateforme.</li>
<li><strong>Rétrograder sans prévenir</strong> — Un changement de rôle vers un niveau inférieur peut surprendre le collaborateur qui perd l\'accès à certaines fonctionnalités. Prévenez-le toujours en amont.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le rôle du membre est mis à jour et ses accès reflètent ses nouvelles responsabilités. Il voit les menus et fonctionnalités correspondant à son rôle dès sa prochaine connexion.</p>',
                ],
                [
                    'title' => 'Modifier les permissions d\'un membre',
                    'slug' => 'modifier-permissions-membre',
                    'excerpt' => 'Au-delà des rôles, Leezr permet d\'ajuster finement les permissions d\'un membre module par module. Personnalisez les accès selon les besoins exacts de chaque collaborateur.',
                    'content' => '<h2>Contexte</h2>
<p>Les rôles prédéfinis couvrent la majorité des cas d\'usage, mais certaines situations nécessitent un ajustement plus fin. Par exemple, un gestionnaire peut avoir besoin d\'accéder au module Documents mais pas au module Expéditions, ou un membre peut nécessiter un droit de création sur un module spécifique. Les permissions par module permettent cette granularité sans créer de nouveaux rôles.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accédez à la page Rôles</strong> — Dans le menu latéral, cliquez sur <em>Rôles</em>. Cette page affiche la liste des rôles avec leurs permissions détaillées.</li>
<li><strong>Sélectionnez le rôle à personnaliser</strong> — Cliquez sur le rôle que vous souhaitez ajuster. Le détail des permissions s\'affiche sous forme de tableau : chaque ligne représente un module et chaque colonne un type d\'accès (lecture, création, modification, suppression).</li>
<li><strong>Ajustez les permissions</strong> — Cochez ou décochez les cases pour accorder ou retirer des permissions spécifiques. Les modifications sont prévisualisées en temps réel.</li>
<li><strong>Enregistrez les changements</strong> — Cliquez sur <em>Enregistrer</em>. Toutes les personnes ayant ce rôle verront leurs accès mis à jour immédiatement.</li>
<li><strong>Testez les accès</strong> — Demandez au collaborateur concerné de vérifier qu\'il accède bien aux fonctionnalités attendues et qu\'il ne voit pas celles qui lui ont été retirées.</li>
</ol>

<h2>Exemple concret</h2>
<p>Chez NordTrans, le dispatcheur doit pouvoir créer des expéditions mais ne doit pas modifier les documents réglementaires des véhicules. L\'administrateur ajuste les permissions du rôle Gestionnaire opérationnel : accès complet au module Expéditions, lecture seule sur le module Documents. Le dispatcheur peut planifier les tournées sans risquer de modifier accidentellement un contrôle technique.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Retirer trop de permissions</strong> — Un membre qui ne peut rien faire demandera constamment de l\'aide. Assurez-vous que les permissions accordées permettent une autonomie suffisante.</li>
<li><strong>Oublier de tester</strong> — Après chaque modification de permissions, vérifiez avec le collaborateur que ses accès sont corrects. Une permission manquante peut bloquer son travail.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Les permissions sont ajustées avec précision pour chaque rôle. Chaque collaborateur accède exactement aux modules et actions dont il a besoin, ni plus, ni moins, garantissant à la fois productivité et sécurité.</p>',
                ],
                [
                    'title' => 'Désactiver ou retirer un membre',
                    'slug' => 'desactiver-retirer-membre',
                    'excerpt' => 'Lorsqu\'un collaborateur quitte l\'entreprise ou change de poste, apprenez à désactiver son accès tout en préservant l\'historique de ses actions et ses données.',
                    'content' => '<h2>Contexte</h2>
<p>Le départ d\'un collaborateur (fin de contrat, mutation, démission) nécessite de retirer rapidement son accès à la plateforme pour des raisons de sécurité. Leezr permet de désactiver un membre sans supprimer ses données : les documents qu\'il a téléchargés, les actions qu\'il a effectuées et son historique restent disponibles pour la traçabilité et la conformité réglementaire.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accédez à la fiche du membre</strong> — Sur la page <em>Membres</em>, trouvez le collaborateur concerné et cliquez sur son nom pour ouvrir sa fiche détaillée.</li>
<li><strong>Choisissez l\'action appropriée</strong> — Deux options s\'offrent à vous : <em>Désactiver</em> (le membre ne peut plus se connecter mais ses données sont conservées) ou <em>Retirer</em> (le membre est dissocié de votre entreprise).</li>
<li><strong>Désactivez le membre</strong> — Cliquez sur <em>Désactiver</em>. Le membre perd immédiatement l\'accès à la plateforme. Son statut passe à <em>Inactif</em> dans la liste. Vous pouvez le réactiver ultérieurement si nécessaire.</li>
<li><strong>Confirmez la désactivation</strong> — Une boîte de dialogue vous demande de confirmer l\'action. Vérifiez le nom du membre avant de valider.</li>
<li><strong>Vérifiez les documents associés</strong> — Après la désactivation, vérifiez que les documents personnels du membre (permis, certifications) sont bien transférés ou archivés selon vos procédures internes.</li>
</ol>

<h2>Exemple concret</h2>
<p>Un chauffeur de RapidFret quitte l\'entreprise en fin de CDD. L\'administrateur ouvre sa fiche, clique sur Désactiver. Le chauffeur ne peut plus se connecter mais ses documents (permis C, FIMO, carte conducteur) restent accessibles dans le module Documents. L\'historique de ses expéditions est conservé pour les éventuels audits ou litiges commerciaux.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Ne pas désactiver immédiatement</strong> — Le jour du départ, désactivez le membre sans attendre. Un ancien collaborateur qui conserve ses accès représente un risque de sécurité.</li>
<li><strong>Supprimer au lieu de désactiver</strong> — La désactivation préserve les données et l\'historique. Privilégiez toujours cette option pour maintenir la traçabilité réglementaire.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le membre est désactivé et ne peut plus accéder à la plateforme. Ses données, documents et historique d\'actions sont préservés. Vous pouvez le réactiver ultérieurement si le collaborateur revient dans l\'entreprise.</p>',
                ],
                [
                    'title' => 'Bonnes pratiques de gestion d\'équipe',
                    'slug' => 'bonnes-pratiques-gestion-equipe',
                    'excerpt' => 'Sécurisez et optimisez la gestion de vos membres avec les bonnes pratiques : principe du moindre privilège, revues périodiques des accès et organisation des rôles.',
                    'content' => '<h2>Contexte</h2>
<p>La gestion des accès est un enjeu de sécurité et de conformité majeur pour les entreprises de transport. Les réglementations imposent une traçabilité stricte des actions et un contrôle des accès aux données sensibles (données personnelles des chauffeurs, documents réglementaires, informations financières). Adopter les bonnes pratiques dès le départ vous évitera des problèmes lors d\'audits ou de contrôles.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Appliquez le principe du moindre privilège</strong> — Attribuez à chaque membre uniquement les permissions dont il a besoin pour son travail quotidien. Un chauffeur n\'a pas besoin d\'accéder à la facturation, un comptable n\'a pas besoin de gérer les expéditions.</li>
<li><strong>Révisez les accès régulièrement</strong> — Chaque trimestre, parcourez la liste des membres et vérifiez que les rôles et permissions sont toujours adaptés. Les changements de poste, les départs et les arrivées doivent être reflétés immédiatement sur la plateforme.</li>
<li><strong>Limitez le nombre d\'administrateurs</strong> — Idéalement, ne conservez que deux ou trois administrateurs : le dirigeant et un ou deux responsables de confiance. Un administrateur a accès à tout, y compris la facturation et la suppression de données.</li>
<li><strong>Désactivez les comptes inactifs</strong> — Un membre qui ne s\'est pas connecté depuis 90 jours doit être vérifié. S\'il a quitté l\'entreprise, désactivez son compte immédiatement.</li>
<li><strong>Documentez vos choix de rôles</strong> — Maintenez un document interne listant qui a quel rôle et pourquoi. En cas d\'audit ou de litige, cette documentation prouve que les accès sont gérés de manière réfléchie.</li>
</ol>

<h2>Exemple concret</h2>
<p>Le responsable qualité de LogiPro effectue sa revue trimestrielle des accès. Il identifie qu\'un ancien intérimaire a toujours un compte actif (il le désactive), qu\'un gestionnaire promu directeur d\'agence a besoin du rôle Administrateur (il le met à jour) et que deux chauffeurs partis en retraite n\'ont pas été désactivés (il corrige). En 15 minutes, les accès sont à jour et conformes.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Négliger les revues d\'accès</strong> — Les accès obsolètes s\'accumulent silencieusement. Sans revue régulière, vous risquez de maintenir des accès non autorisés pendant des mois.</li>
<li><strong>Partager des identifiants</strong> — Chaque membre doit avoir son propre compte. Le partage d\'identifiants empêche toute traçabilité et viole les principes de sécurité.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Votre gestion des membres est rigoureuse et sécurisée. Les accès sont adaptés aux responsabilités de chacun, les comptes inactifs sont désactivés et vous pouvez démontrer une gestion conforme des accès lors d\'un audit.</p>',
                ],
            ],
        ],

        // ──────────────────────────────────────────────
        // Topic 4 : Documents
        // ──────────────────────────────────────────────
        [
            'title' => 'Documents',
            'slug' => 'documents',
            'description' => 'Gérez vos documents réglementaires, suivez les expirations et assurez la conformité de votre entreprise.',
            'icon' => 'tabler-file-text',
            'articles' => [
                [
                    'title' => 'Comprendre les types de documents',
                    'slug' => 'comprendre-types-documents',
                    'excerpt' => 'Leezr distingue plusieurs types de documents réglementaires : certifications, licences, assurances et attestations. Chaque type a ses propres règles de suivi et d\'expiration.',
                    'content' => '<h2>Contexte</h2>
<p>Les entreprises de transport doivent gérer un grand nombre de documents réglementaires : licences de transport communautaire, attestations de capacité, permis de conduire des chauffeurs, cartes de conducteur, contrôles techniques des véhicules, assurances, certificats ADR pour les matières dangereuses, etc. Leezr organise ces documents par types pour faciliter leur gestion et leur suivi.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accédez au module Documents</strong> — Dans le menu latéral, cliquez sur <em>Documents</em>. La page d\'accueil du module affiche une vue d\'ensemble de tous vos documents organisés par catégorie.</li>
<li><strong>Découvrez les catégories</strong> — Les documents sont classés en catégories : <em>Entreprise</em> (licence de transport, Kbis, attestation d\'assurance RC), <em>Véhicules</em> (carte grise, contrôle technique, assurance véhicule) et <em>Personnel</em> (permis de conduire, FIMO/FCO, carte conducteur, certificat ADR).</li>
<li><strong>Comprenez les statuts</strong> — Chaque document a un statut : <em>Valide</em> (en cours de validité), <em>Expire bientôt</em> (dans les 30 jours), <em>Expiré</em> (date dépassée) ou <em>Manquant</em> (requis mais non téléchargé).</li>
<li><strong>Consultez les alertes</strong> — Les documents expirant bientôt ou expirés sont mis en évidence par des indicateurs colorés : vert (valide), orange (expire bientôt), rouge (expiré).</li>
<li><strong>Utilisez les filtres</strong> — Filtrez les documents par catégorie, par statut ou par membre pour retrouver rapidement un document spécifique.</li>
</ol>

<h2>Exemple concret</h2>
<p>TransHazard, spécialisé dans le transport de matières dangereuses, utilise le module Documents pour suivre les certificats ADR de ses 20 chauffeurs, les contrôles techniques semestriels de ses citernes et l\'attestation de conseiller à la sécurité. Chaque type de document a sa propre fréquence de renouvellement : le certificat ADR tous les 5 ans, le contrôle technique tous les 6 mois et l\'attestation de conseiller chaque année.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Ne pas catégoriser correctement</strong> — Un document mal catégorisé ne déclenchera pas les bonnes alertes. Assurez-vous de sélectionner le type exact lors du téléchargement.</li>
<li><strong>Ignorer les documents manquants</strong> — Le statut <em>Manquant</em> indique un document requis mais absent. En transport, un document manquant lors d\'un contrôle peut entraîner une immobilisation.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous comprenez l\'organisation des documents dans Leezr et pouvez identifier rapidement le statut de chaque document. Les catégories et statuts vous permettent de maintenir une conformité documentaire rigoureuse.</p>',
                ],
                [
                    'title' => 'Télécharger et gérer un document',
                    'slug' => 'telecharger-gerer-document',
                    'excerpt' => 'Ajoutez un document à votre espace Leezr, renseignez ses métadonnées (type, date d\'expiration) et associez-le à un membre ou un véhicule pour un suivi complet.',
                    'content' => '<h2>Contexte</h2>
<p>L\'ajout d\'un document dans Leezr va au-delà du simple stockage de fichier. Chaque document est enrichi de métadonnées essentielles : type de document, date d\'émission, date d\'expiration, membre ou véhicule associé. Ces informations alimentent le système d\'alertes automatiques et le suivi de conformité. Un document bien renseigné est un document bien suivi.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accédez au module Documents</strong> — Dans le menu latéral, cliquez sur <em>Documents</em>. La page affiche la liste de tous les documents existants avec leurs statuts.</li>
<li><strong>Créez un nouveau document</strong> — Cliquez sur le bouton <em>Ajouter un document</em>. Le formulaire de création s\'ouvre dans un panneau latéral.</li>
<li><strong>Sélectionnez le type de document</strong> — Choisissez le type dans la liste : permis de conduire, contrôle technique, attestation d\'assurance, licence de transport, certificat FIMO/FCO, etc. Le type détermine les champs de métadonnées affichés.</li>
<li><strong>Renseignez les métadonnées</strong> — Saisissez le numéro du document, la date d\'émission et la date d\'expiration. Associez le document à un membre (pour les documents personnels) ou laissez-le au niveau entreprise (pour les documents généraux).</li>
<li><strong>Téléchargez le fichier</strong> — Cliquez sur la zone d\'upload pour sélectionner le fichier numérisé (PDF, JPG ou PNG). La taille maximale est de 10 Mo par fichier. Validez avec <em>Enregistrer</em>.</li>
</ol>

<h2>Exemple concret</h2>
<p>Le gestionnaire de flotte de SudTrans vient de recevoir le nouveau contrôle technique d\'un semi-remorque. Il ouvre le module Documents, clique sur Ajouter, sélectionne le type « Contrôle technique », saisit la date du contrôle et la date de prochaine visite (6 mois plus tard), télécharge le scan PDF du rapport et associe le document au véhicule concerné. Leezr programmera automatiquement une alerte 30 jours avant la prochaine visite.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Oublier la date d\'expiration</strong> — Sans date d\'expiration, Leezr ne peut pas générer d\'alerte de renouvellement. Ce champ est essentiel pour les documents réglementaires.</li>
<li><strong>Fichier illisible</strong> — Assurez-vous que le scan est lisible et complet. Un document flou ou tronqué ne sera pas exploitable en cas de contrôle.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le document est enregistré avec toutes ses métadonnées, le fichier est stocké de manière sécurisée et le système d\'alertes est programmé pour vous notifier avant l\'expiration. Le document est accessible à tout moment depuis la liste des documents.</p>',
                ],
                [
                    'title' => 'Suivre les dates d\'expiration',
                    'slug' => 'suivre-dates-expiration',
                    'excerpt' => 'Le suivi des expirations est critique dans le transport. Leezr vous alerte automatiquement avant l\'échéance de vos documents pour anticiper les renouvellements.',
                    'content' => '<h2>Contexte</h2>
<p>Dans le transport routier, un document expiré peut avoir des conséquences immédiates et graves : immobilisation du véhicule, interdiction de circuler pour le chauffeur, amende pour l\'entreprise, voire suspension de la licence de transport. Le suivi des dates d\'expiration est donc une priorité absolue. Leezr automatise ce suivi pour vous garantir de ne jamais être pris au dépourvu.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Consultez le tableau de bord des expirations</strong> — Sur la page <em>Documents</em>, la vue d\'ensemble affiche un compteur des documents par statut : valides, expirant bientôt (sous 30 jours) et expirés. Les documents critiques sont mis en évidence.</li>
<li><strong>Recevez les alertes par email</strong> — Leezr envoie automatiquement des notifications par email lorsqu\'un document arrive à 30 jours de son expiration, puis à 15 jours et à 7 jours. Ces seuils peuvent être configurés par l\'administrateur.</li>
<li><strong>Consultez les alertes sur le tableau de bord</strong> — Le widget d\'alertes du <em>Tableau de bord</em> liste les documents expirant prochainement avec un lien direct vers chaque document concerné.</li>
<li><strong>Planifiez le renouvellement</strong> — Lorsqu\'une alerte apparaît, organisez le renouvellement : prise de rendez-vous pour un contrôle technique, demande de prolongation d\'assurance, passage à la visite médicale pour un chauffeur.</li>
<li><strong>Mettez à jour le document</strong> — Après le renouvellement, ajoutez le nouveau document dans Leezr avec la nouvelle date d\'expiration. L\'ancien document est automatiquement archivé pour la traçabilité.</li>
</ol>

<h2>Exemple concret</h2>
<p>Le responsable de parc de FretNational reçoit un email l\'informant que les contrôles techniques de trois véhicules expirent dans 30 jours. Il planifie immédiatement les rendez-vous au centre de contrôle agréé, en échelonnant les passages sur deux semaines pour ne pas immobiliser trop de véhicules simultanément. Après chaque visite, il télécharge le nouveau rapport dans Leezr.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Ignorer les alertes email</strong> — Les alertes sont conçues pour anticiper. Les ignorer revient à risquer une exploitation avec un document expiré, ce qui est sanctionné par la réglementation.</li>
<li><strong>Ne pas archiver l\'ancien document</strong> — Leezr archive automatiquement l\'ancienne version. N\'essayez pas de supprimer l\'ancien document : l\'historique est précieux pour les audits.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous êtes alerté systématiquement avant chaque expiration de document. Les renouvellements sont anticipés et aucun véhicule ni chauffeur ne circule avec un document périmé. Votre taux de conformité documentaire reste à 100 %.</p>',
                ],
                [
                    'title' => 'Gérer les demandes de documents',
                    'slug' => 'gerer-demandes-documents',
                    'excerpt' => 'Sollicitez vos membres pour qu\'ils fournissent les documents requis. Leezr vous permet de suivre l\'avancement des demandes et d\'assurer la conformité de votre équipe.',
                    'content' => '<h2>Contexte</h2>
<p>Dans une entreprise de transport, certains documents sont sous la responsabilité des membres eux-mêmes : permis de conduire, carte de conducteur, certificat médical, attestation FIMO/FCO. L\'entreprise a l\'obligation légale de vérifier la validité de ces documents mais dépend de ses collaborateurs pour les obtenir. Les demandes de documents dans Leezr permettent de formaliser et de suivre ces requêtes.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Identifiez les documents manquants</strong> — Sur la page <em>Documents</em>, filtrez par statut <em>Manquant</em> pour voir les documents requis mais non encore fournis par les membres. La liste indique quel membre doit fournir quel document.</li>
<li><strong>Créez une demande</strong> — Cliquez sur le bouton de demande à côté du document manquant. Un formulaire vous permet de spécifier le type de document attendu, la date limite de fourniture et un message personnalisé pour le membre.</li>
<li><strong>Le membre est notifié</strong> — Le collaborateur reçoit une notification (email et plateforme) lui indiquant le document à fournir et la date limite. Il peut télécharger le document directement depuis la notification.</li>
<li><strong>Suivez l\'avancement</strong> — La page Documents affiche le statut de chaque demande : <em>En attente</em>, <em>Fourni</em> (en attente de validation) ou <em>Validé</em>. Vous pouvez relancer un membre si la date limite approche.</li>
<li><strong>Validez le document reçu</strong> — Lorsque le membre télécharge son document, vérifiez sa conformité (lisibilité, dates, cohérence) et validez-le. Le statut passe de Manquant à Valide.</li>
</ol>

<h2>Exemple concret</h2>
<p>TransSecur doit vérifier les permis de conduire de ses 30 chauffeurs avant l\'audit annuel de la DREAL. L\'administrateur identifie 8 permis non encore enregistrés dans Leezr, crée une demande pour chacun avec une date limite à J-15 avant l\'audit. Les chauffeurs reçoivent un email et téléchargent la photo de leur permis depuis leur smartphone. Le responsable valide chaque document reçu.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Fixer des délais irréalistes</strong> — Laissez au moins 7 jours aux membres pour fournir un document. Certains devront scanner ou photographier un document qu\'ils n\'ont pas sous la main.</li>
<li><strong>Ne pas relancer</strong> — Si un membre n\'a pas répondu à mi-parcours du délai, envoyez un rappel depuis la plateforme. Ne comptez pas uniquement sur la première notification.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Tous les documents requis sont demandés, suivis et collectés dans les délais. Votre taux de conformité documentaire s\'améliore et vous pouvez démontrer lors d\'un audit que les procédures de collecte sont en place.</p>',
                ],
                [
                    'title' => 'Configurer les notifications de documents',
                    'slug' => 'configurer-notifications-documents',
                    'excerpt' => 'Paramétrez les alertes et rappels pour ne jamais manquer une échéance. Définissez les seuils de notification, les destinataires et les canaux d\'alerte.',
                    'content' => '<h2>Contexte</h2>
<p>Les notifications de documents sont votre filet de sécurité contre les oublis d\'expiration. Leezr permet de configurer précisément quand et comment vous êtes alerté : nombre de jours avant l\'expiration, fréquence des rappels et destinataires des alertes. Une bonne configuration garantit que les bonnes personnes sont informées au bon moment pour agir avant qu\'il ne soit trop tard.</p>

<h2>Étapes</h2>
<ol>
<li><strong>Accédez aux paramètres de notification</strong> — Depuis la page <em>Documents</em>, cliquez sur l\'icône de configuration (engrenage). La section <em>Notifications</em> affiche les réglages actuels pour les alertes d\'expiration.</li>
<li><strong>Définissez les seuils d\'alerte</strong> — Configurez les paliers de notification : par exemple, 60 jours, 30 jours, 15 jours et 7 jours avant l\'expiration. Chaque palier déclenche un email de rappel aux destinataires configurés.</li>
<li><strong>Choisissez les destinataires</strong> — Pour chaque catégorie de document, définissez qui reçoit les alertes : le membre concerné uniquement, le gestionnaire, l\'administrateur ou une combinaison. Les documents critiques doivent alerter plusieurs personnes.</li>
<li><strong>Activez les notifications plateforme</strong> — En plus des emails, activez les notifications dans la plateforme. Elles apparaissent dans la cloche de notification en haut de l\'écran et restent visibles jusqu\'à ce qu\'elles soient traitées.</li>
<li><strong>Testez la configuration</strong> — Vérifiez qu\'un document expirant prochainement déclenche bien les notifications attendues. Ajustez les seuils si nécessaire en fonction de vos délais de renouvellement habituels.</li>
</ol>

<h2>Exemple concret</h2>
<p>AutoRoute Transport configure ses notifications avec une approche progressive : alerte à 60 jours pour les contrôles techniques (il faut prendre rendez-vous tôt pour ne pas immobiliser le véhicule), 30 jours pour les assurances (le courtier a besoin de temps) et 45 jours pour les permis de conduire (la visite médicale préalable prend du temps). Le responsable de parc et le dirigeant reçoivent toutes les alertes, les chauffeurs reçoivent uniquement celles concernant leurs documents personnels.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li><strong>Seuils trop courts</strong> — Alerter 7 jours avant l\'expiration d\'un contrôle technique ne laisse pas le temps de prendre rendez-vous. Adaptez les seuils au délai réel de renouvellement de chaque type de document.</li>
<li><strong>Trop de destinataires</strong> — Si tout le monde reçoit toutes les alertes, personne ne se sent responsable. Ciblez les destinataires pour que chaque alerte ait un responsable clairement identifié.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vos notifications de documents sont configurées avec des seuils adaptés à chaque type de document. Les bonnes personnes sont alertées au bon moment et les renouvellements sont systématiquement anticipés avec un délai suffisant pour agir.</p>',
                ],
            ],
        ],
    ],
];
