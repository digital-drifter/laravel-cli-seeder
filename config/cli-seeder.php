<?php

use DigitalDrifter\LaravelCliSeeder\Models\Dummy;
use Faker\Provider\en_US\Address;

return [
    /**
     * The parent model serves as the main point of relationship resolution. The generator will
     * select all instances of the model and prompt to select one to start. The foreign key name
     * will be searched for across table columns during the generation process, with the primary
     * key being used.
     *
     * Replace this with the fully qualified class name of any model to use as a default. If left
     * empty or as is, random - likely unrelated - records will be used for setting foreign keys.
     *
     * Set the display_name to the column that is to be used when displaying input prompts.
     */
    'parent' => [
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
     *
     * Set defaults for Faker when creating data.
     */
    'faker'  => [
        'providers' => [
            Address::class
        ]
    ]
];
