<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntryType extends Model
{
    /**
     * Explicit table name (default would match, added for clarity).
     */
    protected $table = 'entry_types';

    /**
     * Casts to ensure proper JSON types (especially BIT -> boolean).
     */
    protected $casts = [
        'id' => 'integer',
        'online_shared' => 'integer',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Schema defining exposed columns, metadata, and searchable fields.
     * Hidden means frontend should hide, not that data is removed.
     */
    public function apiSchema(): array
    {
        return [
            'columns' => [
                'id' => [
                    'hidden' => true,
                    'type' => 'number',
                ],
                'name' => [
                    'hidden' => false,
                    'type' => 'string',
                ],
                'group_label' => [
                    'hidden' => false,
                    'type' => 'string',
                ],
                'group' => [
                    'hidden' => false,
                    'type' => 'string',
                ],
                'online_shared' => [
                    'hidden' => false,
                    'type' => 'number',
                ],
                'reply_header_text' => [
                    'hidden' => false,
                    'type' => 'string',
                ],
                'category' => [
                    'hidden' => false,
                    'type' => 'string',
                ],
                'created_at' => [
                    'hidden' => true,
                    'type' => 'datetime',
                ],
                'updated_at' => [
                    'hidden' => true,
                    'type' => 'datetime',
                ],
                // 'active' => [
                //     'hidden' => false,
                //     'type' => 'boolean',
                // ],
            ],
            'searchable' => [
                'name',
                'group_label',
                'group',
                'reply_header_text',
                'category',
            ],
        ];
    }

    /**
     * Relation to entries of this type.
     */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class, 'entry_type_id');
    }
}
