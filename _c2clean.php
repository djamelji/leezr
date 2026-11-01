<?php
use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
Model::unguard();
$c = Company::where('slug', 'c2-recette')->first();
if ($c) { $id=$c->id; $c->forceDelete(); echo "deleted company {$id}\n"; }
else echo "no c2-recette company\n";
\App\Core\Models\User::whereIn('email', ['rh-nosalary@c2.test','rh-salary@c2.test'])->delete();
echo "users cleaned\n";
