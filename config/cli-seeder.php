<?php

use DigitalDrifter\LaravelCliSeeder\Models\Dummy;
use Faker\Provider\en_US\Address;

return [
    /**
     * The parent model serves as the main point of relationship resolution. The generator will
     * select all instances of the model and prompt to select one to start. The foreign key name
     * will be searched for across table columns during the generation process, with the primary
     * key being used.
     * Replace this with the fully qualified class name of any model to use as a default. If left
     * empty or as is, random - likely unrelated - records will be used for setting foreign keys.
     * Set the display_name to the column that is to be used when displaying input prompts.
     */
    'parent'  => [
        'model'        => Dummy::class,
        'primary_key'  => 'id',
        'display_name' => 'name'
    ],

    /**
     * If using MariaDB, enum types must be mapped to strings. Set to false if using something other
     * and skip mapping.
     */
    'mariadb' => true,

    /**
     * Faker configuration options.
     * Set defaults for Faker when creating data.
     */
    'faker'   => [
        /**
         * Add Faker providers for extended or localized data.
         */
        'providers'  => [
            Address::class
        ],
        /**
         * Define default restrictions for each supported data type.
         */
        'data_types' => [
            /**
             * If column is unsigned, min will default to 1.
             */
            'bigint' => [
                'min' => PHP_INT_MIN,
                'max' => PHP_INT_MAX
            ],
            'date'     => [
                'format' => 'Y-m-d',
                'range'  => [
                    'from' => '-6 months',
                    'to'   => '+6 months'
                ]
            ],
            'datetime' => [
                'format' => 'Y-m-d H:m:s',
                'range'  => [
                    'from' => '-6 months',
                    'to'   => '+6 months'
                ]
            ],
            'string'   => [
                'words' => [
                    'min' => 1,
                    'max' => 3
                ]
            ],
            'text'     => [
                'paragraphs' => [
                    'min' => 1,
                    'max' => 3
                ]
            ]
        ]
    ],
    'tables' => [
        'hotels' => [
            'columns' => [
                'billing_default' => [
                    'use' => ['IPO', 'Room + tax to master']
                ]
            ]
        ]
    ]
];
