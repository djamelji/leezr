<?php

// ADR-834 — messages serveur de l'assistant de création de société Platform.
return [
    'errors' => [
        'moduleRefused' => 'Le module :module n\'a pas pu être activé : :reason',
        'moduleIncompatible' => 'Le module :module n\'est pas disponible pour cette activité.',
        'modulePlanRequired' => 'Le module :module nécessite le plan :plan.',
        'moduleUnavailable' => 'Le module :module n\'est pas commercialisé.',
        'dependencyUnavailable' => 'Le module :module nécessite :dependency, qui n\'est pas disponible avec ce plan.',
        'paidOptionsNeedTrial' => 'Les options payantes ne peuvent être activées à la création que pendant un essai (elles sont facturées après l\'essai). Activez-les ensuite depuis la fiche société.',
        'overrideForbidden' => 'Vous n\'êtes pas autorisé à accorder des conditions commerciales différentes du plan.',
        'overrideFreePlan' => 'Le plan gratuit n\'a pas de conditions commerciales à ajuster.',
        'jobdomainInactive' => 'Cette activité n\'est pas disponible.',
        'legalStatusMarket' => 'Cette forme juridique n\'existe pas dans le pays choisi.',
        'unknownField' => 'Ce champ ne fait pas partie des informations société pour cette activité et ce pays.',
        'fieldRequired' => ':field est obligatoire pour ce pays.',
        'fieldTooLong' => ':field est trop long.',
        'fieldFormat' => ':field n\'a pas le format attendu.',
    ],
];
