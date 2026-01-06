<?php
namespace App\Models;

use App\Models\Person;
use App\Models\Concerns\ApiQueryable;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use ApiQueryable;

    public function apiSchema(): array
    {
        return [
            'columns'    => [
                'id'                  => [
                    'hidden'         => true,
                    'key'            => 'id',
                    'label'          => ["dv" => "އައިޑީ", "en" => "Id"],
                    'type'           => 'number',

                    'formField'      => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|integer|unque:entries,id',

                    'sortable'       => true,
                    'filterable'     => [
                        'type'  => 'search',
                        'label' => ["dv" => "އައިޑީ", "en" => "Id"],
                        'value' => 'id',
                    ],
                ],

                'nationality_eng'     => [
                    'hidden'     => false,
                    'label'      => ["dv" => "ޤައުމިއްޔަތު", "en" => "Nationality"],
                    'type'       => 'string',

                    'sortable'   => true,
                    'filterable' => [
                        'type'  => 'select',
                        'label' => ["dv" => "ޤައުމިއްޔަތު", "en" => "Nationality"],
                        'value' => 'id',
                    ],
                ],

                'nationality_div'     => [
                    'hidden'     => false,
                    'label'      => ["dv" => "ޤައުމިއްޔަތު", "en" => "Nationality"],
                    'type'       => 'string',

                    'sortable'   => true,
                    'filterable' => [
                        'type'  => 'select',
                        'label' => ["dv" => "ޤައުމިއްޔަތު", "en" => "Nationality"],
                        'value' => 'id',
                    ],
                ],

                'name_eng'            => [
                    'hidden' => false,
                    'label'      => ["dv" => "ނަން", "en" => "Name"],
                    'type'       => 'string',

                    'sortable'   => true,
                    'filterable' => [
                        'type'  => 'select',
                        'label' => ["dv" => "ނަން", "en" => "Name"],
                        'value' => 'id',
                    ],
                ],

                'name_div'            => [
                    'hidden' => false,
                    'label'      => ["dv" => "ނަން", "en" => "Name"],
                    'type'       => 'string',

                    'sortable'   => true,
                    'filterable' => [
                        'type'  => 'select',
                        'label' => ["dv" => "ނަން", "en" => "Name"],
                        'value' => 'id',
                    ],
                ],

                'country_code_alpha3' => [
                    'hidden' => false,
                    'label'      => ["dv" => "ކޯޑު", "en" => "Alpha-3 Code"],
                    'type'       => 'string',

                    'sortable'   => true,
                    'filterable' => [
                        'type'  => 'select',
                        'label' => ["dv" => "ކޯޑު", "en" => "Alpha-3 Code"],
                        'value' => 'id',
                    ],
                ],

                'created_at'          => [
                    'hidden' => true,
                    'type'   => 'datetime',
                ],
                'updated_at'          => [
                    'hidden' => true,
                    'type'   => 'datetime',
                ],
            ],
            'searchable' => [
                'nationality_eng',
                'nationality_div',
                'name_eng',
                'name_div',
                'country_code_alpha3',
            ],

        ];
    }

    public function people()
    {
        return $this->hasMany(Person::class, 'country_id', 'id');
    }
}
