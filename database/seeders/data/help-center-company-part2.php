<?php

// Help Center — Company audience part 2 (topics 5-7)

return [
    // ─── Topic 5: Modules ───────────────────────────────────────────────
    [
        'title'       => 'Modules',
        'slug'        => 'modules',
        'description' => 'Activez et gérez les modules pour adapter Leezr à vos besoins transport et logistique.',
        'icon'        => 'tabler-puzzle',
        'articles'    => [
            [
                'title'   => 'Explorer le catalogue de modules',
                'slug'    => 'decouvrir-les-modules-disponibles',
                'excerpt' => 'Parcourez les modules disponibles et identifiez ceux qui correspondent à votre activité de transport.',
                'content' => '<p>Dans ce guide, vous allez explorer le catalogue de modules Leezr et identifier ceux qui répondent à vos besoins opérationnels.</p>
<h2>Situation</h2>
<p>Vous cherchez à étendre les fonctionnalités de votre espace entreprise — gestion documentaire, suivi d\'expéditions ou automatisation de workflows — et vous voulez savoir ce qui est disponible avant d\'activer quoi que ce soit.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir la page Modules</strong> — cliquez sur <strong>Modules</strong> dans le menu latéral.</li>
  <li><strong>Parcourir les modules</strong> — chaque carte affiche le nom, la description et le badge d\'état (<em>Disponible</em>, <em>Actif</em> ou <em>Inclus</em>).</li>
  <li><strong>Consulter une fiche</strong> — cliquez sur un module pour voir ses fonctionnalités et son coût mensuel.</li>
  <li><strong>Vérifier le badge d\'état</strong> — distinguez les modules déjà inclus dans votre plan des modules payants supplémentaires.</li>
</ol>
<h2>Résultat</h2>
<p>Vous savez exactement quels modules sont disponibles, leur coût et leur valeur ajoutée pour votre activité de transport.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Confusion inclus/payant</strong> — vérifiez le badge d\'état sur la carte du module avant toute activation.</li>
  <li><strong>Module introuvable</strong> — utilisez les filtres par catégorie pour affiner votre recherche.</li>
</ul>',
            ],
            [
                'title'   => 'Activer un module',
                'slug'    => 'activer-un-module',
                'excerpt' => 'Activez un module en quelques clics pour accéder immédiatement à ses fonctionnalités.',
                'content' => '<p>Dans ce guide, vous allez activer un module depuis la page Modules pour débloquer de nouvelles fonctionnalités.</p>
<h2>Situation</h2>
<p>Vous avez identifié un module utile à votre activité (ex. : Workflows pour automatiser l\'assignation des expéditions) et vous souhaitez l\'activer immédiatement.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Accéder aux Modules</strong> — menu latéral → <strong>Modules</strong>.</li>
  <li><strong>Cliquer sur Activer</strong> — bouton présent sur la carte du module souhaité.</li>
  <li><strong>Vérifier le récapitulatif</strong> — la boîte de dialogue affiche l\'impact sur votre facture (montant proratisé).</li>
  <li><strong>Confirmer l\'activation</strong> — le module passe en état <em>Actif</em> et les nouvelles entrées de menu apparaissent.</li>
</ol>
<h2>Résultat</h2>
<p>Le module est actif, ses fonctionnalités sont accessibles immédiatement et le montant proratisé apparaît sur votre prochaine facture.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Bouton Activer grisé</strong> — seuls les administrateurs peuvent activer un module. Vérifiez votre rôle.</li>
  <li><strong>Surprise sur la facture</strong> — consultez toujours le récapitulatif de coût dans la boîte de confirmation avant de valider.</li>
</ul>',
            ],
            [
                'title'   => 'Désactiver un module',
                'slug'    => 'desactiver-un-module',
                'excerpt' => 'Désactivez un module inutilisé pour réduire vos coûts. Vos données sont conservées.',
                'content' => '<p>Dans ce guide, vous allez désactiver un module que vous n\'utilisez plus tout en conservant vos données.</p>
<h2>Situation</h2>
<p>Un module ne correspond plus à vos besoins ou vous souhaitez optimiser vos coûts d\'abonnement. Vous voulez retirer l\'accès aux fonctionnalités sans perdre l\'historique associé.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Accéder aux Modules</strong> — menu latéral → <strong>Modules</strong>.</li>
  <li><strong>Repérer le module actif</strong> — identifiez-le grâce au badge <em>Actif</em>.</li>
  <li><strong>Cliquer sur Désactiver</strong> — lisez l\'avertissement qui précise les fonctionnalités retirées.</li>
  <li><strong>Confirmer</strong> — le module repasse en état <em>Disponible</em>.</li>
</ol>
<h2>Résultat</h2>
<p>Le module est désactivé, vos coûts sont réduits dès la prochaine facture et vos données restent intactes pour une réactivation future.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Peur de perdre des données</strong> — la désactivation retire l\'accès mais ne supprime aucune donnée.</li>
  <li><strong>Workflows cassés</strong> — vérifiez qu\'aucun workflow actif ne dépend du module avant de le désactiver.</li>
  <li><strong>Crédit non visible</strong> — le crédit proratisé est appliqué sur la facture suivante, pas en temps réel.</li>
</ul>',
            ],
            [
                'title'   => 'Comprendre l\'impact des modules sur la facturation',
                'slug'    => 'impact-des-modules-sur-la-facturation',
                'excerpt' => 'Vérifiez le coût de chaque module et anticipez le montant de votre prochaine facture.',
                'content' => '<p>Dans ce guide, vous allez vérifier l\'impact financier de vos modules actifs et anticiper votre prochaine facture.</p>
<h2>Situation</h2>
<p>Vous activez ou désactivez des modules et vous voulez comprendre comment cela affecte votre abonnement. La facturation Leezr est proratisée : vous payez uniquement la durée réelle d\'utilisation.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Consulter le coût avant activation</strong> — la fiche du module affiche le montant mensuel additionnel.</li>
  <li><strong>Lire le récapitulatif d\'activation</strong> — la boîte de confirmation indique le prorata pour le cycle en cours.</li>
  <li><strong>Vérifier l\'aperçu de facture</strong> — allez dans <strong>Facturation</strong> → onglet <strong>Plan</strong> pour voir le total estimé.</li>
  <li><strong>Identifier les lignes détaillées</strong> — chaque module actif a sa propre ligne avec la période proratisée.</li>
</ol>
<h2>Résultat</h2>
<p>Vous maîtrisez le coût exact de chaque module et pouvez anticiper le montant de votre prochaine facture sans surprise.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Montant inattendu après activation</strong> — c\'est le prorata pour les jours restants du cycle, pas le mois complet.</li>
  <li><strong>Crédit après désactivation invisible</strong> — le crédit apparaît sur la facture suivante sous forme de ligne d\'ajustement.</li>
</ul>',
            ],
            [
                'title'   => 'Choisir les modules adaptés au transport',
                'slug'    => 'modules-recommandes-pour-le-transport',
                'excerpt' => 'Identifiez les modules essentiels pour une entreprise de transport et activez-les dans le bon ordre.',
                'content' => '<p>Dans ce guide, vous allez sélectionner et activer les modules les plus pertinents pour votre activité de transport et logistique.</p>
<h2>Situation</h2>
<p>Vous démarrez sur Leezr ou vous souhaitez optimiser votre utilisation. Vous ne savez pas quels modules activer en priorité parmi le catalogue.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Commencer par les Documents</strong> — activez le module Documents pour gérer vos certificats ATP, licences de transport et autorisations ADR.</li>
  <li><strong>Ajouter les Workflows</strong> — automatisez les alertes d\'expiration de documents et l\'assignation des expéditions.</li>
  <li><strong>Activer les Expéditions</strong> — centralisez le suivi de vos livraisons et lettres de voiture.</li>
  <li><strong>Évaluer un module à la fois</strong> — mesurez l\'impact de chaque module sur vos opérations avant d\'en activer un autre.</li>
</ol>
<h2>Résultat</h2>
<p>Vous disposez d\'une sélection de modules adaptée à votre activité, activés dans un ordre logique qui maximise la conformité réglementaire puis l\'efficacité opérationnelle.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Tout activer d\'un coup</strong> — procédez par étapes pour maîtriser chaque module avant de passer au suivant.</li>
  <li><strong>Négliger les Documents</strong> — la conformité réglementaire est critique dans le transport ; c\'est le premier module à activer.</li>
  <li><strong>Ignorer les Workflows</strong> — sans automatisation, les alertes d\'expiration de documents risquent d\'être oubliées.</li>
</ul>',
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
                'title'   => 'Lire et vérifier votre facture',
                'slug'    => 'comprendre-votre-facture',
                'excerpt' => 'Identifiez chaque ligne de votre facture Leezr : plan, modules, prorata et taxes.',
                'content' => '<p>Dans ce guide, vous allez lire votre facture Leezr et vérifier chaque ligne de montant.</p>
<h2>Situation</h2>
<p>Vous recevez votre facture mensuelle et vous voulez comprendre le détail des montants facturés : abonnement de base, modules additionnels, ajustements proratisés et taxes.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir la facture</strong> — menu latéral → <strong>Facturation</strong> → onglet <strong>Factures</strong> → cliquez sur la facture concernée.</li>
  <li><strong>Identifier les sections</strong> — <em>Abonnement</em> (plan de base), <em>Modules</em> (coûts additionnels), <em>Prorata</em> (ajustements mi-cycle), <em>Taxes</em> (TVA).</li>
  <li><strong>Vérifier le statut</strong> — en haut de la facture : <em>Payée</em>, <em>En attente</em> ou <em>Échouée</em>.</li>
  <li><strong>Contrôler les lignes de prorata</strong> — elles correspondent aux modules activés ou désactivés en cours de cycle.</li>
</ol>
<h2>Résultat</h2>
<p>Vous comprenez chaque ligne de votre facture et pouvez repérer immédiatement tout ajustement lié à un changement de plan ou de modules.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Confusion HT/TTC</strong> — les taxes sont toujours affichées séparément en bas de facture.</li>
  <li><strong>Ligne de prorata incomprise</strong> — elle correspond à un module activé ou désactivé en milieu de cycle de facturation.</li>
</ul>',
            ],
            [
                'title'   => 'Mettre à jour vos informations de paiement',
                'slug'    => 'mettre-a-jour-informations-de-paiement',
                'excerpt' => 'Modifiez votre carte bancaire ou votre adresse de facturation pour éviter les échecs de prélèvement.',
                'content' => '<p>Dans ce guide, vous allez mettre à jour votre moyen de paiement et vos coordonnées de facturation.</p>
<h2>Situation</h2>
<p>Votre carte bancaire expire bientôt, vous changez de banque ou votre adresse de facturation a changé. Vous devez mettre à jour ces informations pour éviter un échec de prélèvement.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Accéder à la facturation</strong> — menu latéral → <strong>Facturation</strong> → onglet <strong>Plan</strong>.</li>
  <li><strong>Modifier la carte</strong> — section <em>Moyen de paiement</em> → cliquez sur <strong>Modifier</strong>.</li>
  <li><strong>Saisir les nouvelles informations</strong> — numéro de carte, date d\'expiration et CVC dans le formulaire sécurisé.</li>
  <li><strong>Modifier l\'adresse</strong> — cliquez sur <strong>Modifier l\'adresse</strong> et renseignez les nouvelles coordonnées.</li>
  <li><strong>Valider</strong> — la nouvelle carte sera utilisée pour les prochains prélèvements.</li>
</ol>
<h2>Résultat</h2>
<p>Vos informations de paiement sont à jour et les prochains prélèvements s\'effectueront sans interruption de service.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Carte expirée non remplacée</strong> — cela entraîne un échec de prélèvement et une relance automatique.</li>
  <li><strong>Adresse incohérente</strong> — vérifiez que l\'adresse de facturation correspond aux informations fiscales de votre entreprise.</li>
</ul>',
            ],
            [
                'title'   => 'Changer de plan d\'abonnement',
                'slug'    => 'changer-de-plan',
                'excerpt' => 'Passez à un plan supérieur ou inférieur selon l\'évolution de votre entreprise.',
                'content' => '<p>Dans ce guide, vous allez changer de plan d\'abonnement pour l\'adapter à la taille de votre entreprise de transport.</p>
<h2>Situation</h2>
<p>Votre effectif augmente et le plan actuel ne suffit plus, ou vous souhaitez réduire vos coûts en passant à un plan inférieur. Le changement est proratisé et prend effet immédiatement.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Consulter les plans</strong> — menu latéral → <strong>Facturation</strong> → onglet <strong>Plan</strong>.</li>
  <li><strong>Comparer les plans</strong> — vérifiez les limites de chaque plan (nombre de membres, modules inclus).</li>
  <li><strong>Cliquer sur Changer de plan</strong> — un récapitulatif affiche le nouveau montant et le prorata.</li>
  <li><strong>Confirmer</strong> — les fonctionnalités du nouveau plan sont accessibles immédiatement.</li>
</ol>
<h2>Résultat</h2>
<p>Votre plan est modifié, le prorata est calculé automatiquement et les fonctionnalités sont disponibles sans délai.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Downgrade impossible</strong> — vérifiez que votre nombre de membres et modules actifs respectent les limites du plan inférieur.</li>
  <li><strong>Crédit non immédiat</strong> — lors d\'un downgrade, le crédit est appliqué sur la facture suivante.</li>
  <li><strong>Modules désactivés automatiquement</strong> — un downgrade peut retirer des modules non inclus dans le nouveau plan.</li>
</ul>',
            ],
            [
                'title'   => 'Télécharger vos factures en PDF',
                'slug'    => 'consulter-historique-de-facturation',
                'excerpt' => 'Retrouvez et téléchargez vos factures passées pour votre comptabilité.',
                'content' => '<p>Dans ce guide, vous allez retrouver vos factures passées et les télécharger au format PDF pour vos besoins comptables.</p>
<h2>Situation</h2>
<p>Votre comptable vous demande les justificatifs de vos dépenses Leezr pour la déclaration de TVA ou le bilan annuel. Vous devez retrouver et exporter les factures concernées.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Accéder aux factures</strong> — menu latéral → <strong>Facturation</strong> → onglet <strong>Factures</strong>.</li>
  <li><strong>Filtrer par période</strong> — utilisez les filtres de date (mois, trimestre, année).</li>
  <li><strong>Ouvrir une facture</strong> — cliquez dessus pour voir le détail complet.</li>
  <li><strong>Télécharger le PDF</strong> — cliquez sur l\'icône <strong>PDF</strong> pour exporter la facture.</li>
</ol>
<h2>Résultat</h2>
<p>Vous disposez de tous les justificatifs PDF nécessaires, avec numéro de TVA, montants HT/TTC et détail des lignes.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Facture introuvable</strong> — utilisez le filtre chronologique plutôt que de chercher par montant.</li>
  <li><strong>PDF incomplet</strong> — vérifiez que les informations fiscales de votre entreprise sont à jour dans votre profil.</li>
</ul>',
            ],
            [
                'title'   => 'Appliquer un coupon de réduction',
                'slug'    => 'gerer-les-coupons-et-reductions',
                'excerpt' => 'Saisissez un code promo pour réduire le montant de votre abonnement.',
                'content' => '<p>Dans ce guide, vous allez appliquer un coupon de réduction sur votre abonnement Leezr.</p>
<h2>Situation</h2>
<p>Vous avez reçu un code promo (salon professionnel, programme partenaire, offre commerciale) et vous souhaitez l\'appliquer pour bénéficier d\'une réduction sur vos prochaines factures.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Accéder à la facturation</strong> — menu latéral → <strong>Facturation</strong> → onglet <strong>Plan</strong>.</li>
  <li><strong>Ouvrir la section coupon</strong> — repérez la section <em>Coupon / Réduction</em> et cliquez sur <strong>Appliquer un coupon</strong>.</li>
  <li><strong>Saisir le code</strong> — entrez le code promo dans le champ prévu.</li>
  <li><strong>Vérifier les conditions</strong> — le système affiche le type de réduction, la durée et la date d\'expiration.</li>
  <li><strong>Confirmer</strong> — la réduction apparaît sur l\'aperçu de votre prochaine facture.</li>
</ol>
<h2>Résultat</h2>
<p>Le coupon est appliqué et la réduction est visible sur vos prochaines factures pour la durée indiquée.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Code invalide</strong> — vérifiez la date de validité et l\'orthographe exacte du code.</li>
  <li><strong>Deux coupons en même temps</strong> — un seul coupon actif est autorisé par abonnement.</li>
  <li><strong>Coupon vs crédit</strong> — le coupon réduit les futures factures ; un crédit est un solde déjà acquis.</li>
</ul>',
            ],
        ],
    ],

    // ─── Topic 7: Support ───────────────────────────────────────────────
    [
        'title'       => 'Support',
        'slug'        => 'support',
        'description' => 'Créez et suivez vos tickets pour obtenir de l\'aide rapidement.',
        'icon'        => 'tabler-headset',
        'articles'    => [
            [
                'title'   => 'Contacter le support Leezr',
                'slug'    => 'comment-contacter-le-support',
                'excerpt' => 'Choisissez le bon canal de support selon l\'urgence de votre demande.',
                'content' => '<p>Dans ce guide, vous allez identifier le canal de support adapté à votre situation et contacter l\'équipe Leezr.</p>
<h2>Situation</h2>
<p>Vous avez un problème ou une question et vous ne savez pas si vous devez consulter le Help Center, créer un ticket ou envoyer un email.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Consulter le Help Center d\'abord</strong> — menu latéral → <strong>Help Center</strong>. Cherchez votre question par mot-clé.</li>
  <li><strong>Créer un ticket si nécessaire</strong> — menu latéral → <strong>Support</strong> → <strong>Nouveau ticket</strong>.</li>
  <li><strong>Choisir la priorité</strong> — Basse (question générale), Moyenne (fonctionnalité dégradée), Haute (impact opérationnel), Critique (service inaccessible).</li>
  <li><strong>Décrire précisément le problème</strong> — étapes pour reproduire, message d\'erreur et impact sur votre activité.</li>
</ol>
<h2>Résultat</h2>
<p>Votre demande est prise en charge par le canal le plus adapté, avec un délai de réponse garanti selon la priorité choisie.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Email pour une urgence</strong> — créez un ticket avec priorité Haute ou Critique pour un suivi structuré et des délais garantis.</li>
  <li><strong>Ticket pour une question courante</strong> — consultez le Help Center d\'abord, la réponse y est souvent immédiate.</li>
</ul>',
            ],
            [
                'title'   => 'Créer un ticket de support efficace',
                'slug'    => 'creer-un-ticket-de-support',
                'excerpt' => 'Rédigez un ticket clair avec la bonne priorité pour accélérer le traitement de votre demande.',
                'content' => '<p>Dans ce guide, vous allez créer un ticket de support avec toutes les informations nécessaires pour un traitement rapide.</p>
<h2>Situation</h2>
<p>Vous avez un problème que le Help Center ne résout pas : un bug, un document au statut incorrect après renouvellement ou une fonctionnalité qui ne répond plus.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir le formulaire</strong> — menu latéral → <strong>Support</strong> → <strong>Nouveau ticket</strong>.</li>
  <li><strong>Rédiger un sujet précis</strong> — ex. : « Statut document incorrect après renouvellement certificat ATP ».</li>
  <li><strong>Choisir la priorité</strong> — évaluez l\'impact réel sur votre activité (voir l\'article sur les niveaux de priorité).</li>
  <li><strong>Décrire le problème</strong> — indiquez les étapes pour reproduire, le message d\'erreur et le résultat attendu vs obtenu.</li>
  <li><strong>Envoyer</strong> — vous recevez un numéro de ticket et une confirmation.</li>
</ol>
<h2>Résultat</h2>
<p>Votre ticket est créé avec les informations complètes, l\'équipe support est notifiée et vous pouvez suivre l\'avancement depuis votre espace Support.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Sujet vague (« ça ne marche pas »)</strong> — un sujet précis accélère le traitement de plusieurs heures.</li>
  <li><strong>Priorité Critique systématique</strong> — réservez-la aux situations qui bloquent votre activité ; l\'abus dilue l\'urgence.</li>
</ul>',
            ],
            [
                'title'   => 'Suivre vos tickets en cours',
                'slug'    => 'suivre-vos-tickets-en-cours',
                'excerpt' => 'Consultez l\'état de vos tickets et échangez avec le support jusqu\'à résolution.',
                'content' => '<p>Dans ce guide, vous allez suivre l\'avancement de vos tickets et échanger avec l\'équipe support.</p>
<h2>Situation</h2>
<p>Vous avez créé un ou plusieurs tickets et vous voulez connaître leur avancement, répondre aux questions du support ou vérifier qu\'un problème est bien résolu.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Voir tous vos tickets</strong> — menu latéral → <strong>Support</strong>. La liste affiche le statut de chaque ticket.</li>
  <li><strong>Comprendre les statuts</strong> — <em>Ouvert</em> (en attente de prise en charge), <em>En cours</em> (agent assigné), <em>En attente de réponse</em> (le support attend votre retour), <em>Résolu</em>.</li>
  <li><strong>Répondre au support</strong> — cliquez sur le ticket et utilisez le fil de discussion pour fournir les informations demandées.</li>
  <li><strong>Vérifier la résolution</strong> — quand le ticket passe en <em>Résolu</em>, testez que le problème est corrigé. Réouvrez-le si nécessaire.</li>
</ol>
<h2>Résultat</h2>
<p>Vous suivez chaque ticket de sa création à sa résolution et conservez un historique complet de tous vos échanges.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Ticket bloqué en « En attente »</strong> — le support attend votre réponse. Vérifiez le fil de discussion.</li>
  <li><strong>Nouveau ticket pour relancer</strong> — utilisez le fil de discussion du ticket existant au lieu d\'en créer un nouveau.</li>
</ul>',
            ],
            [
                'title'   => 'Choisir le bon niveau de priorité',
                'slug'    => 'niveaux-de-priorite-et-delais',
                'excerpt' => 'Sélectionnez la bonne priorité pour garantir un délai de réponse adapté à l\'urgence.',
                'content' => '<p>Dans ce guide, vous allez choisir le bon niveau de priorité pour vos tickets afin d\'obtenir une réponse dans les délais adaptés.</p>
<h2>Situation</h2>
<p>Vous créez un ticket et vous hésitez entre les niveaux de priorité. Le mauvais choix peut retarder le traitement d\'une urgence ou surcharger l\'équipe avec de fausses alertes critiques.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Basse</strong> — question générale, suggestion d\'amélioration → réponse sous 48h ouvrées.</li>
  <li><strong>Moyenne</strong> — fonctionnalité dégradée sans blocage d\'activité → réponse sous 24h ouvrées.</li>
  <li><strong>Haute</strong> — fonctionnalité importante indisponible, impact sur les opérations → réponse sous 8h ouvrées.</li>
  <li><strong>Critique</strong> — service inaccessible ou perte de données → réponse sous 2h ouvrées.</li>
</ol>
<h2>Résultat</h2>
<p>Votre ticket est traité dans le délai garanti correspondant à son niveau d\'urgence réel.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Tout en Critique</strong> — cela dilue l\'urgence et peut ralentir le traitement des vraies urgences pour tous les clients.</li>
  <li><strong>Conformité sous-estimée</strong> — un chauffeur bloqué sans lettre de voiture avant un départ est une priorité Haute, pas Moyenne.</li>
  <li><strong>Question en Haute</strong> — une demande d\'information sans impact opérationnel relève de la priorité Basse.</li>
</ul>',
            ],
            [
                'title'   => 'Trouver une réponse dans le Help Center',
                'slug'    => 'consulter-le-help-center',
                'excerpt' => 'Cherchez une réponse par mot-clé ou par catégorie avant de créer un ticket.',
                'content' => '<p>Dans ce guide, vous allez utiliser le Help Center pour trouver rapidement une réponse sans créer de ticket.</p>
<h2>Situation</h2>
<p>Vous avez une question sur l\'utilisation de Leezr — gestion de membres, modules, facturation — et vous voulez une réponse immédiate sans attendre le support.</p>
<h2>Étapes</h2>
<ol>
  <li><strong>Ouvrir le Help Center</strong> — menu latéral → <strong>Help Center</strong>.</li>
  <li><strong>Chercher par mot-clé</strong> — tapez un terme simple dans la barre de recherche (ex. : « ajouter chauffeur », « facture », « module »).</li>
  <li><strong>Parcourir les catégories</strong> — explorez par thème : Profil, Membres, Documents, Modules, Facturation, Support.</li>
  <li><strong>Suivre les étapes de l\'article</strong> — chaque article contient des instructions pas à pas et des solutions aux problèmes fréquents.</li>
  <li><strong>Créer un ticket si besoin</strong> — en bas de chaque article, un lien permet de créer un ticket pré-rempli si l\'article ne résout pas votre problème.</li>
</ol>
<h2>Résultat</h2>
<p>Vous trouvez une réponse immédiate à votre question et gagnez en autonomie sur l\'utilisation quotidienne de Leezr.</p>
<h2>Problèmes fréquents</h2>
<ul>
  <li><strong>Termes trop techniques</strong> — utilisez des mots simples (« ajouter chauffeur » plutôt que « provisioning utilisateur »).</li>
  <li><strong>Articles connexes ignorés</strong> — consultez les suggestions en bas de page, elles contiennent souvent des compléments utiles.</li>
</ul>',
            ],
        ],
    ],
];
