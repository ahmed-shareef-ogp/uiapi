<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\ApiQueryable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entry extends Model
{
	use ApiQueryable;

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
                    'label' => ["dv" => "އައިޑީ", "en" => "Id"],
					'type' => 'number',

                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|integer|unque:entries,id',

                    'sortable' => true,
                    'filterable' => [
                        'type' => 'search',
                        'label' => ["dv" => "އައިޑީ", "en" => "Id"],
                        'value' => 'Id'
                    ]
				],
				'entry_type_id' => [
					'hidden' => false,
					'type' => 'number',
				],
				'ref_num' => [
					'hidden' => false,
					'type' => 'string',
				],
				'summary' => [
					'hidden' => false,
					'type' => 'string',
				],
				'date_entry' => [
					'hidden' => false,
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
