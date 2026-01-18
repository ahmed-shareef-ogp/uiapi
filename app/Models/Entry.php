<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entry extends Model
{
    private $deletable = [
        'enabled' => true,
        'condition' => [
            [
                'field' => 'date_entry',
                'operator' => '<',
                'value' => 'now() - interval \'1 day\'',
            ],
        ],
    ];

    /**
     * Example API schema for Entry.
     *
     * Notes:
     * - Hidden columns remain in data; frontend decides visibility.
     * - All columns are filterable/sortable unless explicitly excluded (not used here).
     * - Global search applies to 'ref_num' and 'summary'.
     */
    public function apiSchema(): array
    {
        return [
            'columns' => [
                'id' => [
                    'hidden' => true,
                    'key' => 'id',
                    'label' => ['dv' => 'އައިޑީ', 'en' => 'Id'],
                    'type' => 'number',

                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|integer|unque:entries,id',

                    'sortable' => true,
                    'filterable' => [
                        'type' => 'search',
                        'label' => ['dv' => 'އައިޑީ', 'en' => 'Id'],
                        'value' => 'Id',
                    ],
                ],
                'entry_type_id' => [
                    'hidden' => false,
                    'label' => ['dv' => 'އެންޓްރީ ޓައިޕް އައިޑީ', 'en' => 'Entry Type Id'],
                    'type' => 'number',
                ],
                'ref_num' => [
                    'hidden' => false,
                    'label' => ['dv' => 'ރެފަރެންސް ނަމްބަރ', 'en' => 'Reference Number'],
                    'type' => 'string',
                ],
                'summary' => [
                    'hidden' => false,
                    'label' => ['dv' => 'ސަމަރީ', 'en' => 'Summary'],
                    'type' => 'string',
                ],
                'date_entry' => [
                    'hidden' => false,
                    'label' => ['dv' => 'އެންޓްރީކުރި ދުވަސް', 'en' => 'Date of Entry'],
                    'type' => 'date',
                ],
                'created_at' => [
                    'hidden' => true,
                    'type' => 'datetime',
                ],
                'updated_at' => [
                    'hidden' => true,
                    'type' => 'datetime',
                ],
            ],
            'searchable' => [
                'ref_num',
                'summary',
            ],

            'deletable' => [
                'condition' => "'date_entry' < now() - interval '1 day'",
            ],

        ];
    }

    /**
     * Relation to EntryType.
     */
    public function entryType(): BelongsTo
    {
        return $this->belongsTo(EntryType::class, 'entry_type_id');
    }
}
