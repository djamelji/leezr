<?php
echo 'c2-recette exists: '.(\App\Core\Models\Company::where('slug','c2-recette')->exists() ? 'yes' : 'no')."\n";
echo 'run 12 exists: '.(\App\Core\Workforce\PayrollRun::withoutGlobalScopes()->where('id',12)->exists() ? 'yes' : 'no')."\n";
