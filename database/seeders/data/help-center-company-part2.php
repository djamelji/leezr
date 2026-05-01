<?php

// Help Center — Company audience part 2 (topics 5-7)

return [
    // ─── Topic 5: Modules ───────────────────────────────────────────────
    [
        'title'       => 'Modules',
        'slug'        => 'modules',
        'description' => 'Découvrez, activez et gérez les modules disponibles pour enrichir les fonctionnalités de votre espace.',
        'icon'        => 'tabler-puzzle',
        'articles'    => [
            [
                'title'   => 'Découvrir les modules disponibles',
                'slug'    => 'decouvrir-les-modules-disponibles',
                'excerpt' => 'Explorez le catalogue de modules Leezr pour identifier les fonctionnalités qui correspondent à vos besoins logistiques. Chaque module affiche une description détaillée et un aperçu de la valeur ajoutée pour votre activité.',
                'content' => '<h2>Contexte</h2>
<p>Leezr propose un catalogue de modules complémentaires qui étendent les capacités de votre espace entreprise. Chaque module correspond à un domaine fonctionnel précis — gestion documentaire avancée, suivi d\'expéditions, automatisation de workflows — et peut être activé indépendamment selon vos besoins opérationnels.</p>

<h2>Étapes</h2>
<ol>
<li>Rendez-vous dans le menu latéral et cliquez sur <strong>Modules</strong>.</li>
<li>Parcourez la liste des modules disponibles, classés par catégorie.</li>
<li>Cliquez sur la carte d\'un module pour afficher sa fiche détaillée : description, fonctionnalités incluses et impact sur votre abonnement.</li>
<li>Consultez le badge d\'état qui indique si le module est <em>Disponible</em>, <em>Actif</em> ou <em>Inclus dans votre plan</em>.</li>
<li>Utilisez les filtres par catégorie pour affiner votre recherche si le catalogue est étendu.</li>
</ol>

<h2>Exemple concret</h2>
<p>Votre société de transport routier souhaite automatiser le renouvellement des certifications ADR de ses chauffeurs. En parcourant le catalogue, vous repérez le module <strong>Documents avancés</strong> qui inclut les alertes d\'expiration et le suivi de conformité. La fiche vous indique le coût mensuel additionnel et les fonctionnalités précises.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Confondre un module déjà inclus dans votre plan avec un module payant supplémentaire — vérifiez le badge d\'état.</li>
<li>Ignorer la description détaillée et activer un module sans vérifier qu\'il correspond réellement à votre besoin.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous avez une vision claire de l\'ensemble des modules disponibles, de leur utilité pour votre activité de transport et de leur impact financier, ce qui vous permet de prendre une décision éclairée avant toute activation.</p>',
            ],
            [
                'title'   => 'Activer un module',
                'slug'    => 'activer-un-module',
                'excerpt' => 'Activez un module en quelques clics depuis la page Modules. L\'accès aux nouvelles fonctionnalités est immédiat et votre facturation est ajustée automatiquement.',
                'content' => '<h2>Contexte</h2>
<p>Lorsque vous identifiez un module utile à votre activité, son activation est instantanée. Dès l\'activation, les nouvelles fonctionnalités apparaissent dans votre menu et sont accessibles à tous les membres de votre entreprise disposant des permissions appropriées.</p>

<h2>Étapes</h2>
<ol>
<li>Accédez à la page <strong>Modules</strong> depuis le menu latéral.</li>
<li>Repérez le module que vous souhaitez activer.</li>
<li>Cliquez sur le bouton <strong>Activer</strong> présent sur la carte du module.</li>
<li>Confirmez l\'activation dans la boîte de dialogue qui s\'affiche — elle récapitule l\'impact sur votre facture.</li>
<li>Le module passe en état <em>Actif</em> et les nouvelles entrées de menu apparaissent immédiatement.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous gérez une flotte de 30 véhicules et souhaitez activer le module <strong>Workflows</strong> pour automatiser l\'assignation des expéditions. Après avoir cliqué sur Activer, la section Workflows apparaît dans votre menu. Vous pouvez immédiatement créer votre premier workflow d\'assignation automatique.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Ne pas vérifier que vous disposez des droits administrateur — seuls les administrateurs peuvent activer un module.</li>
<li>Oublier de consulter l\'impact sur la facturation avant de confirmer l\'activation.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le module est actif, ses fonctionnalités sont accessibles immédiatement dans votre espace et le montant proratisé est reflété sur votre prochaine facture.</p>',
            ],
            [
                'title'   => 'Désactiver un module',
                'slug'    => 'desactiver-un-module',
                'excerpt' => 'Désactivez un module que vous n\'utilisez plus. Vos données sont conservées et l\'accès aux fonctionnalités est retiré jusqu\'à une éventuelle réactivation.',
                'content' => '<h2>Contexte</h2>
<p>Si un module ne correspond plus à vos besoins ou si vous souhaitez optimiser vos coûts, vous pouvez le désactiver à tout moment. Les données associées au module sont préservées : en cas de réactivation ultérieure, vous retrouverez votre historique intact.</p>

<h2>Étapes</h2>
<ol>
<li>Rendez-vous sur la page <strong>Modules</strong> depuis le menu latéral.</li>
<li>Identifiez le module actif que vous souhaitez désactiver (badge <em>Actif</em>).</li>
<li>Cliquez sur le bouton <strong>Désactiver</strong> sur la carte du module.</li>
<li>Lisez attentivement l\'avertissement dans la boîte de dialogue : il précise quelles fonctionnalités seront retirées.</li>
<li>Confirmez la désactivation. Le module repasse en état <em>Disponible</em>.</li>
</ol>

<h2>Exemple concret</h2>
<p>Votre entreprise a utilisé le module de suivi GPS pendant une période d\'essai mais décide de ne pas le conserver. En le désactivant, les données de traçabilité collectées restent en base. Si vous réactivez le module trois mois plus tard, tout l\'historique de géolocalisation est toujours disponible.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Craindre une perte de données — la désactivation retire l\'accès mais ne supprime aucune donnée.</li>
<li>Désactiver un module utilisé par des workflows actifs : pensez à vérifier les dépendances avant la désactivation.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le module est désactivé, les fonctionnalités associées disparaissent du menu et votre prochaine facture reflète la réduction de coût. Vos données restent intactes pour une réactivation future.</p>',
            ],
            [
                'title'   => 'Impact des modules sur la facturation',
                'slug'    => 'impact-des-modules-sur-la-facturation',
                'excerpt' => 'Comprenez comment l\'activation ou la désactivation d\'un module affecte votre abonnement. La facturation est proratisée et un aperçu de la prochaine facture est disponible.',
                'content' => '<h2>Contexte</h2>
<p>Chaque module payant a un impact direct sur le montant de votre abonnement mensuel. Leezr utilise un système de facturation proratisée : vous ne payez que pour la durée réelle d\'utilisation d\'un module au cours du cycle de facturation en cours.</p>

<h2>Étapes</h2>
<ol>
<li>Avant d\'activer un module, consultez le montant additionnel affiché sur sa fiche dans la page <strong>Modules</strong>.</li>
<li>Lors de l\'activation, la boîte de confirmation affiche le montant proratisé pour le cycle en cours.</li>
<li>Pour vérifier l\'impact global, rendez-vous dans <strong>Facturation</strong> et consultez l\'aperçu de la prochaine facture.</li>
<li>Les lignes détaillées indiquent le coût de chaque module actif et la période proratisée le cas échéant.</li>
<li>En cas de désactivation en cours de cycle, un crédit proratisé apparaît sur la facture suivante.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous activez le module <strong>Documents avancés</strong> à 29 € / mois le 15 du mois. Votre prochaine facture inclura un montant proratisé de ~14,50 € pour les 15 jours restants. Le mois suivant, le montant complet de 29 € sera facturé.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>S\'attendre à un remboursement immédiat lors d\'une désactivation — le crédit est appliqué sur la facture suivante, pas en temps réel.</li>
<li>Ignorer l\'aperçu de facturation et être surpris par le montant de la prochaine facture.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous maîtrisez l\'impact financier de chaque module et pouvez anticiper précisément le montant de votre prochaine facture grâce à l\'aperçu disponible dans la section Facturation.</p>',
            ],
            [
                'title'   => 'Modules recommandés pour le transport',
                'slug'    => 'modules-recommandes-pour-le-transport',
                'excerpt' => 'Identifiez les modules les plus pertinents pour une entreprise de transport et logistique. Découvrez lesquels apportent le plus de valeur selon votre profil d\'activité.',
                'content' => '<h2>Contexte</h2>
<p>Le catalogue Leezr propose des modules conçus spécifiquement pour répondre aux enjeux du secteur transport et logistique : conformité réglementaire, traçabilité des expéditions, gestion de flotte et optimisation des processus métier.</p>

<h2>Étapes</h2>
<ol>
<li>Rendez-vous sur la page <strong>Modules</strong> et consultez les modules marqués comme <em>Recommandé</em> pour le transport.</li>
<li>Évaluez chaque module selon vos priorités : conformité, productivité ou visibilité opérationnelle.</li>
<li>Commencez par les modules essentiels à la conformité (documents, certifications) avant les modules d\'optimisation.</li>
<li>Consultez les retours d\'utilisation et les indicateurs de valeur ajoutée affichés sur chaque fiche.</li>
<li>Activez les modules un par un pour mesurer l\'impact de chacun sur vos opérations.</li>
</ol>

<h2>Exemple concret</h2>
<p>Une société de transport frigorifique active en priorité le module <strong>Documents</strong> pour gérer les certificats ATP de ses véhicules, puis le module <strong>Workflows</strong> pour automatiser l\'alerte 60 jours avant expiration. Enfin, elle active le module <strong>Expéditions</strong> pour centraliser le suivi de ses livraisons sensibles.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Activer tous les modules en même temps sans prendre le temps de maîtriser chacun — procédez par étapes.</li>
<li>Négliger le module Documents alors que la conformité réglementaire est un enjeu critique dans le transport.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous disposez d\'une sélection de modules adaptée à votre activité de transport, activés dans un ordre logique qui maximise la valeur ajoutée et la maîtrise de chaque fonctionnalité.</p>',
            ],
        ],
    ],

    // ─── Topic 6: Facturation ───────────────────────────────────────────
    [
        'title'       => 'Facturation',
        'slug'        => 'facturation',
        'description' => 'Consultez vos factures, gérez vos moyens de paiement et suivez votre abonnement Leezr.',
        'icon'        => 'tabler-credit-card',
        'articles'    => [
            [
                'title'   => 'Comprendre votre facture',
                'slug'    => 'comprendre-votre-facture',
                'excerpt' => 'Apprenez à lire et interpréter chaque ligne de votre facture Leezr : abonnement de base, modules, taxes et prorata. Identifiez rapidement le détail de chaque montant.',
                'content' => '<h2>Contexte</h2>
<p>Chaque mois, Leezr génère une facture détaillée qui récapitule l\'ensemble des services facturés : plan d\'abonnement, modules actifs, ajustements proratisés et taxes applicables. Comprendre cette facture vous permet de contrôler vos dépenses et de détecter toute anomalie.</p>

<h2>Étapes</h2>
<ol>
<li>Accédez à la section <strong>Facturation</strong> depuis le menu latéral.</li>
<li>Cliquez sur l\'onglet <strong>Factures</strong> pour voir la liste de vos factures.</li>
<li>Ouvrez la facture souhaitée pour afficher le détail des lignes.</li>
<li>Identifiez les sections : <em>Abonnement</em> (plan de base), <em>Modules</em> (coûts additionnels), <em>Prorata</em> (ajustements) et <em>Taxes</em>.</li>
<li>Vérifiez le statut de paiement en haut de la facture : <em>Payée</em>, <em>En attente</em> ou <em>Échouée</em>.</li>
</ol>

<h2>Exemple concret</h2>
<p>Votre facture de mars affiche : Plan Pro à 99 €, module Documents à 29 €, prorata module Workflows activé le 20 mars à 9,67 €, TVA 20 % à 27,53 €, total 165,20 €. Chaque ligne correspond à un service clairement identifié.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Confondre le montant HT et TTC — les taxes sont toujours affichées séparément en bas de facture.</li>
<li>Ne pas comprendre une ligne de prorata — elle correspond à l\'activation ou la désactivation d\'un module en cours de cycle.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous comprenez chaque ligne de votre facture, vous pouvez vérifier les montants et identifier rapidement tout ajustement lié à un changement de plan ou de modules.</p>',
            ],
            [
                'title'   => 'Mettre à jour vos informations de paiement',
                'slug'    => 'mettre-a-jour-informations-de-paiement',
                'excerpt' => 'Modifiez votre carte bancaire ou vos coordonnées de facturation en toute sécurité. Les paiements sont traités via Stripe pour garantir la sécurité de vos données.',
                'content' => '<h2>Contexte</h2>
<p>Leezr utilise Stripe comme passerelle de paiement sécurisée. Vos informations de carte bancaire ne sont jamais stockées sur nos serveurs. Vous pouvez mettre à jour votre moyen de paiement ou votre adresse de facturation à tout moment sans interrompre votre service.</p>

<h2>Étapes</h2>
<ol>
<li>Rendez-vous dans <strong>Facturation</strong> depuis le menu latéral.</li>
<li>Dans l\'onglet <strong>Plan</strong>, repérez la section <em>Moyen de paiement</em>.</li>
<li>Cliquez sur <strong>Modifier</strong> pour ouvrir le formulaire sécurisé de mise à jour.</li>
<li>Saisissez les nouvelles informations de carte bancaire (numéro, date d\'expiration, CVC).</li>
<li>Pour l\'adresse de facturation, cliquez sur <strong>Modifier l\'adresse</strong> et renseignez les nouvelles coordonnées.</li>
<li>Validez — une confirmation s\'affiche et la nouvelle carte sera utilisée pour les prochains prélèvements.</li>
</ol>

<h2>Exemple concret</h2>
<p>La carte bancaire associée à votre compte expire fin mars. Vous accédez à la section Facturation, cliquez sur Modifier, saisissez les données de votre nouvelle carte Visa Pro et validez. Le prochain prélèvement sera effectué sur cette nouvelle carte sans interruption de service.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Laisser une carte expirée en moyen de paiement principal — cela entraîne un échec de prélèvement et une relance automatique.</li>
<li>Modifier l\'adresse de facturation sans vérifier la cohérence avec les informations fiscales de votre entreprise.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vos informations de paiement sont à jour, les prochains prélèvements s\'effectueront sans incident et votre adresse de facturation correspond à vos coordonnées officielles.</p>',
            ],
            [
                'title'   => 'Changer de plan',
                'slug'    => 'changer-de-plan',
                'excerpt' => 'Passez à un plan supérieur pour débloquer plus de fonctionnalités ou réduisez votre abonnement. Le changement est proratisé et prend effet immédiatement.',
                'content' => '<h2>Contexte</h2>
<p>Leezr propose plusieurs plans d\'abonnement adaptés à la taille et aux besoins de votre entreprise de transport. Vous pouvez passer à un plan supérieur (upgrade) ou inférieur (downgrade) à tout moment. Le système calcule automatiquement le prorata pour que vous ne payiez que ce que vous utilisez.</p>

<h2>Étapes</h2>
<ol>
<li>Accédez à <strong>Facturation</strong> dans le menu latéral.</li>
<li>Dans l\'onglet <strong>Plan</strong>, consultez votre plan actuel et les plans disponibles.</li>
<li>Cliquez sur <strong>Changer de plan</strong> à côté du plan souhaité.</li>
<li>Un récapitulatif s\'affiche avec : le nouveau montant mensuel, le crédit pour la période restante du plan actuel et le montant proratisé dû immédiatement.</li>
<li>Confirmez le changement. Les nouvelles fonctionnalités (en cas d\'upgrade) sont accessibles immédiatement.</li>
</ol>

<h2>Exemple concret</h2>
<p>Votre entreprise passe de 10 à 25 chauffeurs et le plan Starter ne suffit plus. Vous choisissez le plan Pro le 15 du mois. Le système crédite les 15 jours restants du plan Starter et facture le prorata du plan Pro. Vos nouveaux membres peuvent être ajoutés immédiatement.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>En cas de downgrade, ne pas vérifier que vous restez dans les limites du nouveau plan (nombre de membres, modules inclus).</li>
<li>S\'attendre à un remboursement immédiat lors d\'un downgrade — le crédit est appliqué sur la facture suivante.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Votre plan est modifié, le prorata est calculé automatiquement et les fonctionnalités du nouveau plan sont disponibles immédiatement dans votre espace.</p>',
            ],
            [
                'title'   => 'Consulter l\'historique de facturation',
                'slug'    => 'consulter-historique-de-facturation',
                'excerpt' => 'Retrouvez l\'ensemble de vos factures passées, téléchargez-les au format PDF et gardez une trace comptable complète de votre abonnement Leezr.',
                'content' => '<h2>Contexte</h2>
<p>Leezr conserve l\'intégralité de votre historique de facturation. Chaque facture est disponible en téléchargement PDF pour vos besoins comptables et fiscaux. Vous pouvez filtrer et rechercher vos factures par période ou par statut.</p>

<h2>Étapes</h2>
<ol>
<li>Accédez à <strong>Facturation</strong> depuis le menu latéral.</li>
<li>Cliquez sur l\'onglet <strong>Factures</strong> pour afficher la liste chronologique.</li>
<li>Utilisez les filtres de date pour restreindre la période affichée (mois, trimestre, année).</li>
<li>Cliquez sur une facture pour en voir le détail complet.</li>
<li>Cliquez sur l\'icône <strong>PDF</strong> pour télécharger la facture au format PDF.</li>
</ol>

<h2>Exemple concret</h2>
<p>Votre comptable vous demande toutes les factures du premier trimestre pour la déclaration de TVA. Vous filtrez les factures de janvier à mars, puis téléchargez les trois PDF en quelques clics. Chaque facture contient le numéro de TVA, les montants HT et TTC et le détail des lignes.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Chercher une facture par son montant au lieu de sa date — utilisez le filtre chronologique pour une recherche plus efficace.</li>
<li>Oublier de télécharger les factures avant un changement d\'exercice comptable — elles restent disponibles en ligne mais le téléchargement anticipé est recommandé.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous accédez à l\'ensemble de votre historique de facturation, pouvez télécharger chaque facture en PDF et disposez de tous les justificatifs nécessaires pour votre comptabilité.</p>',
            ],
            [
                'title'   => 'Gérer les coupons et réductions',
                'slug'    => 'gerer-les-coupons-et-reductions',
                'excerpt' => 'Appliquez un code promo ou un coupon de réduction sur votre abonnement. Vérifiez sa validité, sa durée et l\'impact sur vos prochaines factures.',
                'content' => '<h2>Contexte</h2>
<p>Leezr propose ponctuellement des coupons de réduction pour les nouveaux clients, les programmes partenaires ou les offres promotionnelles. Un coupon peut offrir un pourcentage de remise ou un montant fixe, applicable une seule fois ou sur plusieurs cycles de facturation.</p>

<h2>Étapes</h2>
<ol>
<li>Accédez à <strong>Facturation</strong> depuis le menu latéral.</li>
<li>Dans l\'onglet <strong>Plan</strong>, repérez la section <em>Coupon / Réduction</em>.</li>
<li>Cliquez sur <strong>Appliquer un coupon</strong> et saisissez le code dans le champ prévu.</li>
<li>Le système valide le code et affiche le détail : type de réduction (pourcentage ou montant fixe), durée d\'application et date d\'expiration.</li>
<li>Confirmez l\'application. La réduction apparaît sur l\'aperçu de votre prochaine facture.</li>
</ol>

<h2>Exemple concret</h2>
<p>Lors d\'un salon professionnel du transport, vous recevez le code <strong>TRANSPORT2024</strong> offrant 20 % de réduction pendant 3 mois. Vous l\'appliquez dans la section Facturation. Vos trois prochaines factures affichent une ligne de remise de 20 % sur le montant de votre plan.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Saisir un code expiré — vérifiez la date de validité communiquée avec le coupon.</li>
<li>Tenter d\'appliquer deux coupons simultanément — un seul coupon actif est autorisé par abonnement.</li>
<li>Confondre un coupon de réduction avec un crédit de compte — le coupon s\'applique sur les futures factures, le crédit est un solde déjà acquis.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Le coupon est appliqué à votre abonnement, la réduction apparaît clairement sur vos prochaines factures et vous pouvez vérifier la durée restante à tout moment dans la section Facturation.</p>',
            ],
        ],
    ],

    // ─── Topic 7: Support ───────────────────────────────────────────────
    [
        'title'       => 'Support',
        'slug'        => 'support',
        'description' => 'Contactez le support, créez et suivez vos tickets pour obtenir de l\'aide rapidement.',
        'icon'        => 'tabler-headset',
        'articles'    => [
            [
                'title'   => 'Comment contacter le support',
                'slug'    => 'comment-contacter-le-support',
                'excerpt' => 'Découvrez les différents canaux pour joindre l\'équipe support Leezr : Help Center, tickets et email. Choisissez le canal le plus adapté à votre besoin.',
                'content' => '<h2>Contexte</h2>
<p>Leezr met à votre disposition plusieurs canaux de support pour répondre à vos questions et résoudre vos problèmes. Selon la nature et l\'urgence de votre demande, vous pouvez consulter le Help Center en libre-service, créer un ticket de support ou contacter l\'équipe par email.</p>

<h2>Étapes</h2>
<ol>
<li>Pour une question courante, consultez d\'abord le <strong>Help Center</strong> accessible depuis le menu latéral — il contient des articles détaillés classés par thématique.</li>
<li>Si vous ne trouvez pas de réponse, cliquez sur <strong>Support</strong> dans le menu latéral pour accéder à l\'espace tickets.</li>
<li>Vous pouvez également envoyer un email à l\'adresse support indiquée dans la page Support — un ticket sera automatiquement créé.</li>
<li>Pour les urgences critiques (service inaccessible, perte de données), utilisez le formulaire de ticket avec la priorité <em>Critique</em>.</li>
</ol>

<h2>Exemple concret</h2>
<p>Un de vos chauffeurs ne parvient pas à accéder à ses documents de conformité avant un contrôle routier. Vous vérifiez d\'abord le Help Center pour un guide de dépannage rapide. Ne trouvant pas de solution, vous créez un ticket avec la priorité Haute pour obtenir une réponse dans les meilleurs délais.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Envoyer un email pour un problème urgent au lieu de créer un ticket avec priorité élevée — le ticket garantit un suivi structuré et des délais respectés.</li>
<li>Ne pas consulter le Help Center avant de contacter le support — de nombreuses questions y trouvent une réponse immédiate.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous connaissez les canaux de support disponibles et savez choisir le plus adapté à chaque situation, ce qui garantit une résolution rapide de vos demandes.</p>',
            ],
            [
                'title'   => 'Créer un ticket de support',
                'slug'    => 'creer-un-ticket-de-support',
                'excerpt' => 'Créez un ticket de support en quelques étapes pour signaler un problème ou poser une question. Définissez la priorité et fournissez les détails nécessaires à un traitement rapide.',
                'content' => '<h2>Contexte</h2>
<p>Le système de tickets est le canal principal pour les demandes qui nécessitent une intervention de l\'équipe support. Un ticket bien rédigé, avec les informations pertinentes et la bonne priorité, permet un traitement beaucoup plus rapide.</p>

<h2>Étapes</h2>
<ol>
<li>Cliquez sur <strong>Support</strong> dans le menu latéral pour accéder à la liste de vos tickets.</li>
<li>Cliquez sur le bouton <strong>Nouveau ticket</strong> en haut de la page.</li>
<li>Renseignez le <strong>sujet</strong> du ticket avec une description concise du problème.</li>
<li>Sélectionnez la <strong>priorité</strong> appropriée : Basse, Moyenne, Haute ou Critique.</li>
<li>Dans le champ <strong>description</strong>, décrivez le problème en détail : étapes pour le reproduire, message d\'erreur éventuel, impact sur votre activité.</li>
<li>Cliquez sur <strong>Envoyer</strong>. Vous recevez une confirmation et un numéro de ticket.</li>
</ol>

<h2>Exemple concret</h2>
<p>Un de vos documents de certification affiche un statut incorrect après un renouvellement. Vous créez un ticket avec le sujet « Statut document incorrect après renouvellement », priorité Moyenne, et décrivez dans la description : le nom du document, la date de renouvellement et le statut affiché versus le statut attendu.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Rédiger un sujet trop vague comme « Ça ne marche pas » — soyez précis pour accélérer le traitement.</li>
<li>Mettre systématiquement la priorité Critique — réservez-la aux situations qui bloquent votre activité.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Votre ticket est créé avec toutes les informations nécessaires, l\'équipe support est notifiée et vous pouvez suivre l\'avancement depuis votre espace Support.</p>',
            ],
            [
                'title'   => 'Suivre vos tickets en cours',
                'slug'    => 'suivre-vos-tickets-en-cours',
                'excerpt' => 'Consultez l\'état de vos tickets, échangez avec le support et suivez la progression de chaque demande jusqu\'à sa résolution complète.',
                'content' => '<h2>Contexte</h2>
<p>Une fois un ticket créé, vous pouvez suivre son avancement en temps réel depuis votre espace Support. Chaque ticket passe par plusieurs statuts et vous pouvez échanger des messages avec l\'équipe support directement dans le fil de discussion du ticket.</p>

<h2>Étapes</h2>
<ol>
<li>Accédez à <strong>Support</strong> depuis le menu latéral pour voir la liste de tous vos tickets.</li>
<li>Les tickets sont affichés avec leur statut actuel : <em>Ouvert</em>, <em>En cours</em>, <em>En attente de réponse</em> ou <em>Résolu</em>.</li>
<li>Cliquez sur un ticket pour voir le fil de discussion complet et les détails.</li>
<li>Si le support vous a posé une question (statut <em>En attente de réponse</em>), répondez directement dans le fil de discussion.</li>
<li>Lorsque le problème est résolu, le ticket passe en statut <em>Résolu</em>. Vous pouvez le rouvrir si nécessaire.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous avez signalé un problème d\'import de documents pour votre flotte. Le ticket passe en statut En cours lorsqu\'un agent le prend en charge. L\'agent vous demande un fichier d\'exemple — vous le fournissez via le fil de discussion. Après correction, le ticket passe en Résolu et vous vérifiez que l\'import fonctionne.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Ne pas répondre à une demande d\'information du support — le ticket reste bloqué en attente et le délai de résolution s\'allonge.</li>
<li>Créer un nouveau ticket pour relancer une demande existante — utilisez plutôt le fil de discussion du ticket d\'origine.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous suivez chaque ticket de sa création à sa résolution, échangez efficacement avec le support et conservez un historique complet de toutes vos demandes.</p>',
            ],
            [
                'title'   => 'Niveaux de priorité et délais',
                'slug'    => 'niveaux-de-priorite-et-delais',
                'excerpt' => 'Comprenez les quatre niveaux de priorité des tickets et les délais de réponse associés. Choisissez le bon niveau pour garantir un traitement adapté à l\'urgence.',
                'content' => '<h2>Contexte</h2>
<p>Leezr définit quatre niveaux de priorité pour les tickets de support, chacun associé à un délai de première réponse garanti. Choisir le bon niveau de priorité permet à l\'équipe support de traiter les demandes dans l\'ordre d\'urgence et de respecter les engagements de service.</p>

<h2>Étapes</h2>
<ol>
<li>Lors de la création d\'un ticket, évaluez l\'impact du problème sur votre activité.</li>
<li><strong>Basse</strong> : question générale, suggestion d\'amélioration — délai de réponse sous 48h ouvrées.</li>
<li><strong>Moyenne</strong> : fonctionnalité dégradée sans blocage d\'activité — délai de réponse sous 24h ouvrées.</li>
<li><strong>Haute</strong> : fonctionnalité importante indisponible, impact opérationnel — délai de réponse sous 8h ouvrées.</li>
<li><strong>Critique</strong> : service totalement inaccessible ou perte de données — délai de réponse sous 2h ouvrées.</li>
</ol>

<h2>Exemple concret</h2>
<p>Un de vos chauffeurs ne peut pas télécharger sa lettre de voiture avant un départ imminent : c\'est une priorité Haute car l\'impact opérationnel est direct. En revanche, une question sur la personnalisation d\'un rapport mensuel est une priorité Basse car elle n\'affecte pas les opérations quotidiennes.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Utiliser la priorité Critique pour des problèmes non bloquants — cela dilue l\'urgence et peut ralentir le traitement des vraies urgences.</li>
<li>Sous-estimer la priorité d\'un problème de conformité documentaire — si un contrôle est imminent, la priorité doit être Haute.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous choisissez la priorité adaptée à chaque situation, l\'équipe support traite votre demande dans les délais garantis et vos problèmes critiques sont pris en charge en priorité absolue.</p>',
            ],
            [
                'title'   => 'Consulter le Help Center',
                'slug'    => 'consulter-le-help-center',
                'excerpt' => 'Naviguez dans le Help Center pour trouver des réponses à vos questions. Recherchez par mot-clé, parcourez les catégories et consultez les articles les plus populaires.',
                'content' => '<h2>Contexte</h2>
<p>Le Help Center Leezr est une base de connaissances en libre-service qui regroupe des articles détaillés sur l\'ensemble des fonctionnalités de la plateforme. Il est conçu pour vous permettre de trouver rapidement des réponses sans avoir à créer un ticket de support.</p>

<h2>Étapes</h2>
<ol>
<li>Cliquez sur <strong>Help Center</strong> dans le menu latéral ou depuis le lien dans la page Support.</li>
<li>Utilisez la <strong>barre de recherche</strong> en haut de la page pour trouver un article par mot-clé (ex. : « facture », « document », « module »).</li>
<li>Parcourez les <strong>catégories thématiques</strong> pour explorer les articles par domaine : Profil, Membres, Documents, Modules, Facturation, Support.</li>
<li>Ouvrez un article pour consulter les instructions détaillées, exemples concrets et solutions aux erreurs fréquentes.</li>
<li>Si l\'article ne répond pas complètement à votre question, cliquez sur le lien <strong>Contacter le support</strong> en bas de l\'article pour créer un ticket pré-rempli.</li>
</ol>

<h2>Exemple concret</h2>
<p>Vous cherchez comment ajouter un nouveau chauffeur à votre entreprise. Dans la barre de recherche, vous tapez « ajouter membre ». Le Help Center affiche l\'article correspondant avec les étapes détaillées, les rôles disponibles et les permissions associées.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Utiliser des termes trop techniques dans la recherche — préférez des termes simples et fonctionnels (« ajouter chauffeur » plutôt que « provisioning utilisateur »).</li>
<li>Ignorer les articles connexes suggérés en bas de page — ils contiennent souvent des compléments d\'information utiles.</li>
</ul>

<h2>Résultat attendu</h2>
<p>Vous trouvez rapidement des réponses à vos questions grâce au Help Center, réduisez le nombre de tickets et gagnez en autonomie dans l\'utilisation quotidienne de Leezr.</p>',
            ],
        ],
    ],
];
