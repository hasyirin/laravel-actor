<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Relations\MorphMany;

class ExampleFilteredResource extends ExampleResource
{
    protected $table = 'example_resources';

    public function actions(): MorphMany
    {
        return parent::actions()->where('name', '!=', 'hidden');
    }
}
