<?php

namespace App\Admin\Repositories;

use App\Models\Goods as Model;
use Dcat\Admin\Form;
use Dcat\Admin\Repositories\EloquentRepository;

class Goods extends EloquentRepository
{
    /**
     * Model.
     *
     * @var string
     */
    protected $eloquentClass = Model::class;

    public function edit(Form $form)
    {
        // Supplier settings are hidden in public model output, but editable in the admin form.
        return parent::edit($form)->makeVisible('procurementSettings');
    }
}
