# Laravel CLI Seeder

A (semi) intelligent utility to generate test data quickly.

# About

The idea behind this package is generate test data based on a table that acts as the central reference point. Often times, applications
will have a table that effectively serves as the root node for a relationship graph. For example, a typical multi-tenancy application
will define a tenants, accounts, customers, etc. table which other tables define a foreign relationship with.

Using `tenants` as the example table, there could be `users`, `settings`, and `posts` tables which each have a `tenant_id`. By setting the `Tenant` model as the
parent model in the configuration, this package will query for all `tenants` and prompt to select one for the generation process. Anytime
a column name `tenant_id` is encountered, the selected `tenant`'s primary key will be used.

In addition, when other foreign keys are detected, it will attempt to only use those which are assigned to the `tenant`, as well.

# Installation

```bash
composer require digital-drifter/laravel-cli-seeder
```

# Usage

```bash
php artisan cli-seeder:generate
```

# Configuration

> config/cli-seeder.php

```php
<?php

use App\Models\Tenant;

return [
    /**
     * Decide on a parent model as explained above.
     * Set the primary key column name.
     * Set the column used for display purposes.
     */
    'parent' => [
        'model' => Tenant::class,
        'primary_key'  => 'id',
        'display_name' => 'name'
    ]
];
```

