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
     * @var array
     */
    protected $data = [];

    /**
     * @var int
     */
    protected $count;

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
     * @param string $type
     *
     * @return array|null
     */
    public static function getOptionsForDataType(string $type)
    {
        return config(sprintf('cli-seeder.faker.data_types.%s', $type), null);
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
        $this->count = intval($this->ask('Number of rows to generate', 1));

        if (!is_int($this->count) || ($this->count <= 0)) {
            $this->error(sprintf('Invalid number of rows: %s. Must be an integer greater than zero. Exiting.', $this->count));
            exit();
        }

        $this->generateData();
        $this->postGenerate();
    }

    /**
     * @return void
     */
    private function postGenerate()
    {
        $action = $this->choice('Data prepared. Would you like to:', ['Insert Now', 'Review and Edit', 'Discard and Exit']);

        switch ($action) {
            case 'Insert Now':
                $this->insert();
                break;
            case 'Review and Edit':
                $this->review();
                break;
            case 'Discard and Exit':
                $this->info('Discarded all data. No records added. Exiting.');
                exit();
                break;
            default:
                $this->error('Invalid choice. Exiting.');
                exit();
        }
    }

    /**
     * @return void
     */
    private function review()
    {
        $rows = array_map(function ($row) {
            return array_map(function ($column) {
                return is_string($column) && strlen($column) > 10 ? substr($column, 0, 10) . '...' : $column;
            }, $row);
        }, $this->data);

        $this->table($this->columns->pluck('column')->toArray(), $rows);

        $id = $this->choice('Modify Row', array_map(function ($row) {
            return $row['id'];
        }, $rows));

        $row = array_first($this->data, function ($row) use ($id) {
            return $row['id'] === intval($id);
        });

        $this->rowDetails($row);
    }

    /**
     * @param array $row
     */
    private function rowDetails(array $row)
    {
        $rows = array_map(function ($key, $value) {
            return [$key, $value];
        }, array_keys($row), array_values($row));

        $this->table(['Column', 'Value'], $rows);

        $column = $this->anticipate('Edit Column. Type \'None\' to go back.', array_merge(['None'], $this->columns->pluck('column')->toArray()));

        if ($column !== 'None') {
            $value = $this->ask(sprintf('Enter value for %s', $column));

            $index = collect($this->data)->search(function (array $datum) use ($row) {
                return $datum['id'] === $row['id'];
            });

            $this->data[$index][$column] = $value;

            $this->rowDetails($this->data[$index]);
        } else {
            $this->postGenerate();
        }
    }

    /**
     * Perform the data insertion.
     *
     * @return void
     */
    private function insert()
    {
        DB::table($this->table)->insert($this->data);

        $this->info(sprintf('Added %d rows to %s for parent model %s.', $this->count, $this->table, $this->getParentModelIdentifier()));
    }

    /**
     * Generate the data to be inserted for the selected table.
     *
     * @return void
     */
    private function generateData()
    {
        $count = $this->count;

        do {
            $this->data[] = $this->columns->reduce(function (array $attributes, array $item) {
                // handle the special cases for a column when setting the value, defaulting to Faker generated data based
                // on the column data type.
                switch (true) {
                    case $item['column'] === static::primaryKey():
                        $value = $this->getMaxId();
                        break;
                    case $item['column'] === $this->getParentForeignKeyName():
                        $value = $this->getParentPrimaryKey();
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

                            $value = $record->id;
                        } else {
                            $value = $this->getValueForDataType($item['type']);
                        }
                        break;
                    case str_contains($item['column'], 'facebook_id'):
                        $value = $this->faker->randomNumber(15);
                        break;
                    case str_contains($item['column'], ['guid', 'uuid']):
                        $value = Str::uuid();
                        break;
                    case str_contains($item['column'], 'email'):
                        $value = $this->faker->safeEmail;
                        break;
                    case str_contains($item['column'], 'first_name'):
                        $value = $this->faker->firstName;
                        break;
                    case str_contains($item['column'], 'last_name'):
                        $value = $this->faker->lastName;
                        break;
                    case str_contains($item['column'], 'city'):
                        $value = $this->faker->city;
                        break;
                    case str_contains($item['column'], 'state'):
                        $value = $this->faker->state();
                        break;
                    case str_contains($item['column'], 'country'):
                        $value = $this->faker->country();
                        break;
                    case str_contains($item['column'], ['zipcode', 'zip_code', 'postal']):
                        $value = $this->faker->postcode;
                        break;
                    case str_contains($item['column'], 'company'):
                        $value = $this->faker->company;
                        break;
                    case str_contains($item['column'], 'address'):
                        $value = $this->faker->streetAddress;
                        break;
                    case str_contains($item['column'], 'phone'):
                        $value = $this->faker->phoneNumber;
                        break;
                    case str_contains($item['column'], ['hyperlink', 'url']):
                        $value = $this->faker->url;
                        break;
                    default:
                        $value = $this->getValueForDataType($item['type']);
                }

                return data_set($attributes, $item['column'], $value);
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
        $options = static::getOptionsForDataType($type);

        switch ($type) {
            case 'bigint':
                return random_int(data_get($options, 'min', PHP_INT_MIN), data_get($options, 'max', PHP_INT_MAX));
                break;
            case 'boolean':
                return $this->faker->boolean;
                break;
            case 'date':
            case 'datetime':
                return $this->faker->dateTimeBetween(data_get($options, 'range.from', '-6 months'), data_get($options, 'range.to', 'now'))
                                   ->format(data_get($options, 'format', $type === 'date' ? 'Y-m-d' : 'Y-m-d H:m:s'));
                break;
            case 'string':
            case 'text':
                $method = $type === 'string' ? 'words' : 'paragraphs';

                return $this->faker->$method(random_int(data_get($options, sprintf('%s.min', $method), 1), data_get($options, sprintf('%s.max', $method), 3)), true);
                break;
            default:
                $this->warn(sprintf('Unsupported data type: %s. Using value \'null\'.', $type));

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
