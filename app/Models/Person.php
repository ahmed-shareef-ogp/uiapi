<?php

namespace App\Models;

use App\Models\Concerns\ApiQueryable;
use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    use ApiQueryable;

    protected $table = 'people';

    public function apiSchema(): array
    {
        return [
            'columns' => [
                'id' => [
                    'hidden' => true,
                    'key' => 'id',
                    'label' => ['dv' => 'އައިޑީ', 'en' => 'Id'],
                    'lang' => ['en', 'dv'],
                    'type' => 'number',
                    'displayType' => 'text',

                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|integer|unique:entries,id',

                    'sortable' => true,

                ],

                'first_name_eng' => [
                    'hidden' => false,
                    'key' => 'first_name_eng',
                    'label' => ['dv' => 'ފުރަތަމަ ނަން', 'en' => 'First Name'],
                    'lang' => ['en'],
                    'type' => 'string',
                    'displayType' => 'englishText',

                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|string|max:255',

                    'sortable' => true,
                ],

                'middle_name_eng' => [
                    'hidden' => false,
                    'key' => 'middle_name_eng',
                    'label' => ['dv' => 'މެދު ނަން', 'en' => 'Middle Name'],
                    'lang' => ['en'],
                    'type' => 'string',
                    'displayType' => 'englishText',

                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|string|max:255',

                    'sortable' => true,
                ],

                'last_name_eng' => [
                    'hidden' => false,
                    'key' => 'last_name_eng',
                    'label' => ['dv' => 'ފަހު ނަން', 'en' => 'Last Name'],
                    'lang' => ['en'],
                    'type' => 'string',
                    'displayType' => 'englishText',

                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|string|max:255',

                    'sortable' => true,
                ],

                'first_name_div' => [
                    'hidden' => false,
                    'key' => 'first_name_div',
                    'label' => ['dv' => 'ފުރަތަމަ ނަން', 'en' => 'First Name'],
                    'lang' => ['dv'],
                    'type' => 'string',
                    'displayType' => 'text',

                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|string|max:255',

                    'sortable' => true,
                ],

                'middle_name_div' => [
                    'hidden' => false,
                    'key' => 'middle_name_div',
                    'label' => ['dv' => 'މެދު ނަން', 'en' => 'Middle Name'],
                    'type' => 'string',
                    'lang' => ['dv'],
                    'displayType' => 'text',

                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|string|max:255',

                    'sortable' => true,
                ],

                'last_name_div' => [
                    'hidden' => false,
                    'key' => 'last_name_div',
                    'label' => ['dv' => 'ފަހު ނަން', 'en' => 'Last Name'],
                    'type' => 'string',
                    'lang' => ['dv'],
                    'displayType' => 'text',

                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|string|max:255',

                    'sortable' => true,
                ],

                'date_of_birth' => [
                    'hidden' => false,
                    'key' => 'date_of_birth',
                    'label' => ['dv' => 'އުފަން ދުވަސް', 'en' => 'Date of Birth'],
                    'type' => 'date',
                    'displayType' => 'text',
                    'lang' => ['en', 'dv'],

                    'sortable' => true,
                ],

                'date_of_death' => [
                    'hidden' => false,
                    'key' => 'date_of_death',
                    'label' => ['dv' => 'ނިޔާވި ދުވަސް', 'en' => 'Date of Death'],
                    'type' => 'date',
                    'displayType' => 'text',
                    'lang' => ['en', 'dv'],

                    'sortable' => true,
                ],

                'gender' => [
                    'hidden' => false,
                    'key' => 'gender',
                    'label' => ['dv' => 'ޖިންސު', 'en' => 'Gender'],
                    'type' => 'string',
                    'displayType' => 'chip',
                    'lang' => ['en', 'dv'],

                    'filterable' => [
                        'type' => 'select',
                        'label' => ['dv' => 'ޖިންސު', 'en' => 'Gender'],
                        'mode' => 'self',
                        'items' => [
                            ['itemTitle' => '-empty-', 'itemValue' => ''],
                            ['itemTitle' => 'Male', 'itemValue' => 'male'],
                            ['itemTitle' => 'Female', 'itemValue' => 'female'],
                        ],
                        // 'value'     => 'Gender',
                        // 'itemTitle' => 'Gender',
                        // 'itemValue' => 'gender',
                    ],
                ],

                'contact' => [
                    'hidden' => false,
                    'key' => 'contact',
                    'label' => ['dv' => 'ގުޅޭނެ ނަންބަރު', 'en' => 'Contact'],
                    'type' => 'json',
                    'displayType' => 'text',
                    'lang' => ['en', 'dv'],
                ],

                'father_name' => [
                    'hidden' => false,
                    'key' => 'father_name',
                    'label' => ['dv' => 'ބައްޕަގެ ނަން', 'en' => 'Fathers Name'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'lang' => ['dv'],
                ],

                'country_id' => [
                    'hidden' => true,
                    'key' => 'country_id',
                    'label' => ['dv' => 'ޤައުމުގެ އައިޑީ', 'en' => 'Country ID'],
                    'type' => 'number',
                    'displayType' => 'text',
                    'lang' => ['en', 'dv'],

                    'filterable' => [
                        'type' => 'select',
                        'label' => ['dv' => 'ޤައުމު', 'en' => 'Country'],
                        'mode' => 'relation',
                        'relationship' => 'country',
                        'itemTitle' => 'name_eng',
                        'sourceModel' => 'Country', // not required now since mode is 'relation' and relationship is provided
                        'value' => 'Country', // not required now since mode is 'relation' and relationship is provided
                        'itemValue' => 'id',      // not required now since mode is 'relation' and relationship is provided
                    ],
                ],

                'mother_name' => [
                    'hidden' => false,
                    'key' => 'mother_name',
                    'label' => ['dv' => 'މަންމަގެ ނަން', 'en' => 'Mothers Name'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'lang' => ['dv'],
                ],

                'guardian' => [
                    'hidden' => false,
                    'key' => 'guardian',
                    'label' => ['dv' => 'ބެލެނިވެރިޔާ', 'en' => 'Guardian Name'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'lang' => ['dv'],
                ],

                'remarks' => [
                    'hidden' => false,
                    'key' => 'remarks',
                    'label' => ['dv' => 'ރިމާކްސް', 'en' => 'Remarks'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'lang' => ['dv'],
                ],

                'police_pid' => [
                    'hidden' => false,
                    'key' => 'police_pid',
                    'label' => ['dv' => 'ޕޮލިސް ޕީއައިޑީ', 'en' => 'Police PID'],
                    'type' => 'number',
                    'displayType' => 'text',
                    'lang' => ['en', 'dv'],
                ],

                'crpc_id' => [
                    'hidden' => false,
                    'key' => 'crpc_id',
                    'label' => ['dv' => 'ސީއާރްޕީސީ އައިޑީ', 'en' => 'CRPC ID'],
                    'type' => 'number',
                    'displayType' => 'text',
                    'lang' => ['en', 'dv'],
                ],

                'is_in_custody' => [
                    'hidden' => false,
                    'key' => 'is_in_custody',
                    'label' => ['dv' => 'ހުރީ ހައްޔަރުގަ', 'en' => 'Is in custody'],
                    'type' => 'boolean',
                    'displayType' => 'checkbox',
                    'lang' => ['en', 'dv'],
                    'sortable' => true,
                ],

                'created_at' => [
                    'hidden' => true,
                    'key' => 'created_at',
                    'label' => ['dv' => 'އުފެއްދި ތާރީޚް', 'en' => 'Created At'],
                    'type' => 'datetime',
                    'displayType' => 'text',
                    'lang' => ['en', 'dv'],
                ],

                'updated_at' => [
                    'hidden' => true,
                    'key' => 'updated_at',
                    'label' => ['dv' => 'އަޕްޑޭޓްކުރި ތާރީޚް', 'en' => 'Updated At'],
                    'type' => 'datetime',
                    'displayType' => 'text',
                    'lang' => ['en', 'dv'],
                ],

            ],
            'searchable' => [
                'id',
                'first_name_eng',
                'middle_name_eng',
                'last_name_eng',
                'first_name_div',
                'middle_name_div',
                'last_name_div',
                'date_of_birth',
                'date_of_death',
                'gender',
                'contact',
                'father_name',
                'country_id',
                'mother_name',
                'guardian',
                'remarks',
                'police_pid',
                'crpc_id',
                'is_in_custody',
                'created_at',
                'updated_at',
            ],

        ];
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }
}
