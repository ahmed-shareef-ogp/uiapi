<?php

namespace Ogp\UiApi\Models;

class Person extends BaseModel
{
    protected $table = 'people';

    protected function searchColumns(): array
    {
        return ['first_name', 'last_name', 'email'];
    }

    public static function apiSchema(): array
    {
        return [
            'columns' => [
                'id' => ['type' => 'integer', 'label' => ['en' => 'ID'], 'lang' => ['en']],
                'first_name' => ['type' => 'string', 'label' => ['en' => 'First Name'], 'lang' => ['en']],
                'last_name' => ['type' => 'string', 'label' => ['en' => 'Last Name'], 'lang' => ['en']],
                'email' => ['type' => 'string', 'label' => ['en' => 'Email'], 'lang' => ['en']],
                'gender' => ['type' => 'string', 'label' => ['en' => 'Gender'], 'lang' => ['en'], 'filterable' => true],
                'country_id' => ['type' => 'integer', 'label' => ['en' => 'Country'], 'lang' => ['en'], 'filterable' => true],
                'created_at' => ['type' => 'datetime', 'label' => ['en' => 'Created At'], 'lang' => ['en']],
            ],
        ];
    }
}
