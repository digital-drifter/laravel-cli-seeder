<?php

namespace DigitalDrifter\LaravelCliSeeder\Commands;

use DigitalDrifter\LaravelCliSeeder\Models\Dummy;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Faker\Generator;
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
     * @var Collection
     */
    protected $columns;

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

        if ((static::model() !== Dummy::class) && !is_null(static::model())) {
            $this->parents = app(static::model())->all([static::primaryKey(), static::displayName()]);
            $this->tables  = collect(static::doctrine()->listTables())->map->getName();
            $this->faker   = app(Generator::class);

            foreach (static::providers() as $provider) {
                $this->faker->addProvider(app()->makeWith($provider, ['generator' => $this->faker]));
            }
        }
    }

    /**
     * @return array
     */
    public static function providers()
    {
        return config('cli-seeder.faker.providers', []);
    }

    /**
     * @return string|null
     */
    public static function model()
    {
        return config('cli-seeder.parent.model', null);
    }

    /**
     * @return string
     */
    public static function primaryKey()
    {
        return config('cli-seeder.parent.primary_key', 'id');
    }

    /**
     * @return string
     */
    public static function displayName()
    {
        return config('cli-seeder.parent.display_name', 'name');
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
        $this->parentSelectPrompt();
        $this->tableSelectPrompt();
        $this->mapTableColumns();
        $this->generate();
    }

    /**
     * @return void
     */
    private function parentSelectPrompt()
    {
        if (is_null($this->parents)) {
            $this->warn(sprintf('The parent model configuration option is invalid. Config value: %s.', static::model()));
            if (!$this->confirm('Continue')) {
                $this->comment('Set the parent model in config/cli-seeder.php.');
                exit();
            }
        } else {
            $this->comment('Begin typing for autocompletion. Use the up/down arrows to select previous or next entry.');
            $name = $this->anticipate(sprintf('Select %s', static::model()), $this->parents->pluck(static::displayName())->toArray());
            if (!$this->parents->contains(static::displayName(), $name)) {
                $this->error(sprintf('Parent model %s \'%s\' is invalid. Exiting.', static::displayName(), $name));
                exit();
            }
            $this->parent = $this->parents->firstWhere(static::displayName(), $name);
        }
    }

    /**
     * @return void
     */
    private function tableSelectPrompt()
    {
        $this->table = $this->anticipate('For which table?', $this->tables->toArray());

        if (!$this->tables->contains($this->table)) {
            $this->error(sprintf('Table name \'%s\' is invalid. Exiting.', $this->table));
            exit();
        }
    }

    /**
     * @return void
     */
    private function mapTableColumns()
    {
        $this->columns = collect(Schema::getColumnListing($this->table))->map(function (string $column) {
            return [
                'column' => $column,
                'type'   => Schema::getColumnType($this->table, $column)
            ];
        });
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

        $action = $this->choice('Data prepared. Would you like to:', ['Insert', 'Modify', 'Discard']);

        switch ($action) {
            case 'Insert':
                $this->insertData($count, $data);
                break;
            case 'Modify':
                $this->modifyData($data);
                break;
            case 'Discard':
                $this->info('Discarded all data. No records added. Exiting.');
                exit();
                break;
            default:
                $this->error('Invalid choice. Exiting.');
                exit();
        }
    }

    /**
     * @param array $data
     */
    private function modifyData(array $data)
    {
        $rows = array_map(function ($row) {
            return array_map(function ($column) {
                return is_string($column) && strlen($column) > 10 ? substr($column, 0, 10) : $column;
            }, $row);
        }, $data);

        $this->table($this->columns->pluck('name')->toArray(), $rows);
    }

    /**
     * @param int $count
     * @param array $data
     */
    private function insertData(int $count, array $data)
    {
        DB::table($this->table)->insert($data);

        $this->info(sprintf('Added %d rows to %s for parent model %s.', $count, $this->table, $this->getParentModelIdentifier()));
    }

    /**
     * @param int $count
     * @param array $data
     *
     * @return void
     */
    private function generateData(int $count, array &$data)
    {
        do {
            $data[] = $this->columns->reduce(function (array $attributes, array $item) {
                // handle the special cases for a column when setting the value, defaulting to Faker generated data based
                // on the column data type.
                switch (true) {
                    case $item['column'] === static::primaryKey():
                        return data_set($attributes, $item['column'], $this->getMaxId());
                        break;
                    case $item['column'] === $this->getParentForeignKeyName():
                        return data_set($attributes, $item['column'], $this->getParentPrimaryKey());
                        break;
                    case ends_with($item['column'], sprintf('_%s', static::primaryKey())):
                        // attempt to find a matching foreign key constraint based on the column name. If a match is found, then
                        // check if it has a column matching the events table foreign key so a related record can be used
                        // as the foreign relation for the current iteration data. Otherwise, set the column value based on the data type.
                        if (!is_null($foreign = $this->findMatchingForeignConstraint($item['column']))) {
                            if ($this->tableColumnMatchesParentForeignKeyName($foreign)) {
                                $record = DB::table($foreign->getForeignTableName())->where($this->getParentForeignKeyName(), $this->getParentPrimaryKey())->inRandomOrder()->first();
                            } else {
                                $record = DB::table($foreign->getForeignTableName())->whereNotNull($item['column'])->inRandomOrder()->first();
                            }

                            return data_set($attributes, $item['column'], $record->id);
                        };

                        return data_set($attributes, $item['column'], $this->getValueForDataType($item['type']));
                        break;
                    case str_contains($item['column'], 'facebook_id'):
                        return data_set($attributes, $item['column'], $this->faker->randomNumber(15));
                        break;
                    case str_contains($item['column'], ['guid', 'uuid']):
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
                    case str_contains($item['column'], ['zipcode', 'zip_code', 'postal']):
                        return data_set($attributes, $item['column'], $this->faker->postcode);
                        break;
                    case str_contains($item['column'], 'company'):
                        return data_set($attributes, $item['column'], $this->faker->company);
                        break;
                    case str_contains($item['column'], 'address'):
                        return data_set($attributes, $item['column'], $this->faker->streetAddress);
                        break;
                    case str_contains($item['column'], 'phone'):
                        return data_set($attributes, $item['column'], $this->faker->phoneNumber);
                        break;
                    case str_contains($item['column'], ['hyperlink', 'url']):
                        return data_set($attributes, $item['column'], $this->faker->url);
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
     * @return int
     */
    private function getMaxId()
    {
        static $maxId;

        if (!$maxId) {
            $maxId = DB::table($this->table)->max(static::primaryKey());
        }

        return ++$maxId;
    }

    /**
     * @return string|null
     */
    private function getParentModelIdentifier()
    {
        return !is_null($this->parent) ? $this->parent->getAttribute(static::displayName()) : null;
    }

    /**
     * @param ForeignKeyConstraint $foreign
     *
     * @return bool
     */
    private function tableColumnMatchesParentForeignKeyName(ForeignKeyConstraint $foreign)
    {
        return !is_null($this->parent) && in_array($this->getParentForeignKeyName(), Schema::getColumnListing($foreign->getForeignTableName()));
    }

    /**
     * @return string|null
     */
    private function getParentForeignKeyName()
    {
        return !is_null($this->parent) ? $this->parent->getForeignKey() : null;
    }

    /**
     * @return int|null
     */
    private function getParentPrimaryKey()
    {
        return !is_null($this->parent) ? $this->parent->getKey() : null;
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
