<?php

// ADR-787 — Help Center : espace salarié (audience company). Ajouté au groupe « Guide Entreprise ».
// Chaque article n'est proposé que si le module qu'il décrit est actif (HelpCenterAccess::DEFAULT_RULES).

return [
    'group_slug' => 'guide-entreprise',
    'topic' => [
        'title' => 'Mon espace salarié',
        'slug' => 'mon-espace-salarie',
        'description' => 'Votre compte, votre planning, vos heures, vos absences, vos documents et vos bulletins.',
        'icon' => 'tabler-user-circle',
        'articles' => [
            [
                'title' => 'Gérer mon compte et mes coordonnées',
                'slug' => 'mon-compte-et-mes-coordonnees',
                'excerpt' => 'Modifier votre mot de passe, votre photo et votre adresse personnelle.',
                'content' => '<p>Votre compte se gère depuis le menu de votre avatar, en haut à droite.</p>
<h2>Ce que vous pouvez modifier</h2>
<ul><li>Votre mot de passe et la sécurité de connexion (onglet Sécurité).</li><li>Votre photo.</li><li>Votre adresse personnelle, même après la signature de votre contrat.</li></ul>
<h2>Ce que vous ne pouvez pas modifier</h2>
<p>Une fois votre contrat établi, les informations qui figurent à votre dossier (nom, date de naissance, numéro de sécurité sociale, coordonnées bancaires…) sont verrouillées. Pour les corriger, adressez-vous à votre entreprise : le champ concerné l\'indique.</p>
<p>Le thème et la langue se changent directement depuis la barre du haut.</p>',
            ],
            [
                'title' => 'Consulter mon planning',
                'slug' => 'consulter-mon-planning',
                'excerpt' => 'Voir vos prochains créneaux publiés par votre entreprise.',
                'content' => '<p>Ouvrez <strong>Mon activité</strong>. La section planning liste vos prochains créneaux <strong>publiés</strong> : date, horaires, lieu et poste.</p>
<p>Un créneau en préparation n\'apparaît pas tant que votre responsable ne l\'a pas publié. Vous êtes prévenu lorsqu\'un créneau publié change.</p>',
            ],
            [
                'title' => 'Pointer et suivre mes heures',
                'slug' => 'pointer-mes-heures',
                'excerpt' => 'Pointer l\'arrivée, les pauses et le départ ; lire vos heures de la semaine et de la période.',
                'content' => '<p>Depuis l\'Accueil ou <strong>Mon activité</strong>, utilisez <strong>Pointer l\'arrivée</strong>, puis démarrez et terminez vos pauses, et pointez votre départ.</p>
<h2>Lire vos heures</h2>
<ul><li><strong>Cette semaine</strong> : heures pointées depuis le premier jour de la semaine de votre entreprise, pauses déduites.</li><li><strong>Période</strong> : heures de la période de paie en cours, ou du mois si votre entreprise n\'a pas de période définie.</li><li><strong>À valider</strong> / <strong>Validées</strong> : état de vos feuilles de temps.</li></ul>
<p>Les heures pointées ne sont pas des heures payées : votre entreprise les valide avant la paie.</p>',
            ],
            [
                'title' => 'Demander une absence',
                'slug' => 'demander-une-absence',
                'excerpt' => 'Poser un congé et suivre la réponse de votre responsable.',
                'content' => '<p>Dans <strong>Mon activité</strong>, choisissez <strong>Demander un congé</strong>, indiquez le type d\'absence et les dates, puis envoyez.</p>
<p>Votre demande reste « en attente » jusqu\'à la décision de votre responsable. Vos soldes sont affichés dans la même page.</p>',
            ],
            [
                'title' => 'Mes documents et mes bulletins',
                'slug' => 'mes-documents-et-bulletins',
                'excerpt' => 'Déposer les pièces demandées et télécharger vos bulletins de paie.',
                'content' => '<p>Ouvrez <strong>Mon espace</strong> :</p>
<ul><li><strong>Mes documents</strong> : les pièces que votre entreprise vous demande, à déposer ou à renouveler.</li><li><strong>Mes bulletins</strong> : vos bulletins publiés, à télécharger.</li><li><strong>Mes signatures</strong> : les documents à signer.</li></ul>
<p>Vous ne voyez que vos propres documents.</p>',
            ],
        ],
    ],
];
