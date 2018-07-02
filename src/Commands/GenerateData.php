<?php

namespace DigitalDrifter\LaravelCliSeeder\Commands;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Faker\Generator;
use Faker\Provider\en_US\Address;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GenerateData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cli-seeder:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate random database records.';

    /**
     * @var Model
     */
    protected $parent;

    /**
     * @var Collection
     */
    protected $parents;

    /**
     * @var string
     */
    protected $table;

    /**
     * @var Collection
     */
    protected $tables;

    /**
     * @var string[]
     */
    protected $columns;

    /**
     * @var Collection
     */
    protected $columnDefinitions;

    /**
     * @var Generator
     */
    protected $faker;

    /**
     * Create a new command instance.
     *
     * @throws DBALException
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        if (config('cli-seeder.mariadb')) {
            static::doctrine()->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');
        }

        $this->parents = app(config('cli-seeder.parent.model'))->all([config('cli-seeder.parent.primary_key'), config('cli-seeder.parent.display_name')]);
        $this->tables  = collect(static::doctrine()->listTables())->map->getName();
        $this->faker   = app(Generator::class);

        $this->faker->addProvider(new Address($this->faker));
    }

    /**
     * @return AbstractSchemaManager
     */
    public static function doctrine()
    {
        return DB::getDoctrineSchemaManager();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->comment('Begin typing for autocompletion. Use the up/down arrows to select previous or next entry.');

        $name = $this->anticipate('Parent Model', $this->parents->pluck(config('cli-seeder.model.display_name'))->toArray());

        if (!$this->parents->contains(config('cli-seeder.model.display_name'), $name)) {
            $this->error(sprintf('Parent model %s \'%s\' is invalid. Exiting.', config('cli-seeder.model.display_name'), $name));
            exit();
        }

        $this->table = $this->anticipate('For which table?', $this->tables->toArray());

        if (!$this->tables->contains($this->table)) {
            $this->error(sprintf('Table name \'%s\' is invalid. Exiting.', $this->table));
            exit();
        }

        $this->parent            = $this->parents->firstWhere(config('cli-seeder.model.display_name'), $name);
        $this->columns           = Schema::getColumnListing($this->table);
        $this->columnDefinitions = collect($this->columns)->map(function (string $column) {
            return [
                'column' => $column,
                'type'   => Schema::getColumnType($this->table, $column)
            ];
        });

        $this->generate();
    }

    /**
     * Create records for the selected table with randomly generated data.
     *
     * @return void
     */
    private function generate()
    {
        $data = [];

        $count = intval($this->ask('Number of rows to generate', 1));

        if (!is_int($count) || ($count <= 0)) {
            $this->error(sprintf('Invalid number of rows: %s. Must be an integer greater than zero. Exiting.', $count));
            exit();
        }

        $this->generateData($count, $data);

        DB::table($this->table)->insert($data);

        $this->info(sprintf('Added %d rows to %s for parent model %s.', $count, $this->table, $this->event->getAttribute('name')));
    }

    /**
     * @param int $count
     * @param array $data
     *
     * @return void
     */
    private function generateData(int $count, array &$data)
    {
        $maxId = DB::table($this->table)->max('id');

        do {
            $data[] = $this->columnDefinitions->reduce(function (array $attributes, array $item) use (&$maxId) {
                // handle the special cases for a column when setting the value, defaulting to Faker generated data based
                // on the column data type.
                switch (true) {
                    case $item['column'] === 'id':
                        return data_set($attributes, 'id', ++$maxId);
                        break;
                    case $item['column'] === $this->parent->getForeignKey():
                        return data_set($attributes, $this->parent->getForeignKey(), $this->parent->getKey());
                        break;
                    case ends_with($item['column'], '_id'):
                        // attempt to find a matching foreign key constraint based on the column name. If a match is found, then
                        // check if it has a column matching the events table foreign key so a related record can be used
                        // as the foreign relation for the current iteration data. Otherwise, set the column value based on the data type.
                        if (!is_null($foreign = $this->findMatchingForeignConstraint($item['column']))) {
                            if (in_array($this->parent->getForeignKey(), Schema::getColumnListing($foreign->getForeignTableName()))) {
                                $record = DB::table($foreign->getForeignTableName())->where($this->parent->getForeignKey(), $this->parent->getKey())->first();

                                return data_set($attributes, $item['column'], $record->id);
                            }
                        };

                        return data_set($attributes, $item['column'], $this->getValueForDataType($item['type']));
                        break;
                    case str_contains($item['column'], 'facebook_id'):
                        return data_set($attributes, $item['column'], $this->faker->randomNumber(15));
                        break;
                    case str_contains($item['column'], 'guid'):
                        return data_set($attributes, $item['column'], Str::uuid());
                        break;
                    case str_contains($item['column'], 'email'):
                        return data_set($attributes, $item['column'], $this->faker->safeEmail);
                        break;
                    case str_contains($item['column'], 'first_name'):
                        return data_set($attributes, $item['column'], $this->faker->firstName);
                        break;
                    case str_contains($item['column'], 'last_name'):
                        return data_set($attributes, $item['column'], $this->faker->lastName);
                        break;
                    case str_contains($item['column'], 'city'):
                        return data_set($attributes, $item['column'], $this->faker->city);
                        break;
                    case str_contains($item['column'], 'state'):
                        return data_set($attributes, $item['column'], $this->faker->state());
                        break;
                    case str_contains($item['column'], 'country'):
                        return data_set($attributes, $item['column'], $this->faker->country());
                        break;
                    case str_contains($item['column'], 'zipcode'):
                        return data_set($attributes, $item['column'], $this->faker->postcode);
                        break;
                    case str_contains($item['column'], 'company'):
                        return data_set($attributes, $item['column'], $this->faker->company);
                        break;
                    default:
                        return data_set($attributes, $item['column'], $this->getValueForDataType($item['type']));
                }
            }, []);
        } while (--$count > 0);
    }

    /**
     * @param string $type
     *
     * @return mixed
     * @throws \Exception
     */
    private function getValueForDataType(string $type)
    {
        switch ($type) {
            case 'bigint':
                return $this->faker->randomDigitNotNull;
                break;
            case 'boolean':
                return $this->faker->boolean;
                break;
            case 'date':
                return $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d');
                break;
            case 'datetime':
                return $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d H:m:s');
                break;
            case 'string':
                return $this->faker->words(random_int(1, 3), true);
                break;
            case 'text':
                return $this->faker->paragraphs(random_int(1, 3), true);
                break;
            default:
                return null;
        }
    }

    /**
     * @param string $column
     *
     * @return ForeignKeyConstraint|null
     */
    private function findMatchingForeignConstraint(string $column)
    {
        return collect(static::doctrine()->listTableForeignKeys($this->table))->first(function (ForeignKeyConstraint $constraint) use ($column) {
            return in_array($column, $constraint->getLocalColumns());
        });
    }
}
