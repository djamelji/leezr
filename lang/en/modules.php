<?php

return [
    // ADR-821 — module dependencies
    'dependency_not_covered' => 'Inconsistent offer: ":module" is offered in the :plan plan, but the module it depends on (":dependency") is not at the same level. Include the dependency first.',
    'dependency_inconsistent' => 'These modules have inconsistent dependencies (cycle or unknown module).',
    'compatibility_in_use' => '":module" is still active in companies of this business: it stays compatible.',
];
