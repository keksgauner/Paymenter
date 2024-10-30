<?php

namespace App\Console\Commands;

use App\Models\ConfigOption;
use App\Models\Currency;
use App\Models\CustomProperty;
use App\Models\Gateway;
use App\Models\Plan;
use App\Models\Price;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;

class UpgradeFromAlpha extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:old-data {host} {dbname} {username} {password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate data from old database structure to new structure';

    protected $pdo;

    protected $currency_code;

    public function handle()
    {
        $host = $this->argument('host');
        $dbname = $this->argument('dbname');
        $username = $this->argument('username');
        $password = $this->argument('password');

        try {
            $this->pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Get default currency
            $currency_settings = $this->pdo->query("SELECT * FROM `settings` WHERE `key` = 'currency' or `key` = 'currency_sign' or `key` = 'currency_position'")->fetchAll();
            $currency_settings = array_combine(array_column($currency_settings, 'key'), $currency_settings);
            $this->currency_code = $currency_settings['currency']['value'];

            $currency = Currency::where('code', $this->currency_code)->first();
            if (is_null($currency)) {
                Currency::create([
                    'code' => $this->currency_code,
                    'prefix' => $currency_settings['currency_position']['value'] === 'left' ? $currency_settings['currency_sign']['value'] : null,
                    'suffix' => $currency_settings['currency_position']['value'] === 'right' ? $currency_settings['currency_sign']['value'] : null,
                    'format' => '1,000.00',
                ]);
            }

            $this->info('Connected successfully');
            DB::statement('SET foreign_key_checks=0');

            $this->settings();
            $this->config_options();
            $this->migrateCoupons();
            $this->migrateCategories();
            $this->migrateUsers();
            $this->user_properties();
            $this->migrateTickets();
            $this->migrateTicketMessages();
            $this->migrateTaxRates();
            $this->extensions();
            $this->products();
            $this->config_option_products();
            $this->plans();
            $this->ordersAndServices();
            $this->service_configs();
            $this->invoices();

            DB::statement('SET foreign_key_checks=1');
        } catch (PDOException $e) {
            $this->error('Connection failed: ' . $e->getMessage());
        }
    }

    protected function settings()
    {
        $stmt = $this->pdo->query('SELECT * FROM settings');
        $records = $stmt->fetchAll();

        // Map of settings which are just renamed
        $old_to_new_map = [
            // General
            'timezone' => 'timezone',
            'language' => 'app_language',
            'app_logo' => 'logo',

            // Security
            'recaptcha_site_key' => 'captcha_site_key',
            'recaptcha_secret_key' => 'captcha_secret',

            // Social Login
            'google_enabled' => 'oauth_google',
            'google_client_id' => 'oauth_google_client_id',
            'google_client_secret' => 'oauth_google_client_secret',
            'github_enabled' => 'oauth_github',
            'github_client_id' => 'oauth_github_client_id',
            'github_client_secret' => 'oauth_github_client_secret',
            'discord_enabled' => 'oauth_discord',
            'discord_client_id' => 'oauth_discord_client_id',
            'discord_client_secret' => 'oauth_discord_client_secret',

            // Company Details
            'company_name' => 'company_name',
            'company_email' => 'company_email',
            'company_phone' => 'company_phone',
            'company_address' => 'company_address',
            'company_city' => 'company_city',
            'company_zip' => 'company_zip',

            // Tax
            'tax_enabled' => 'tax_enabled',
            'tax_type' => 'tax_type',

            // Mail
            'mail_disabled' => 'mail_disable',
            'must_verify_email' => 'mail_must_verify',
            'mail_host' => 'mail_host',
            'mail_port' => 'mail_port',
            'mail_username' => 'mail_username',
            'mail_password' => 'mail_password',
            'mail_encryption' => 'mail_encryption',
            'mail_from_address' => 'mail_from_address',
            'mail_from_name' => 'mail_from_name',

            // Other
            'currency' => 'default_currency',
        ];

        $settings = [];
        foreach ($records as $old_setting) {
            $key = $old_to_new_map[$old_setting['key']] ?? $old_setting['key'];
            $value = $old_setting['value'];

            // Migrate old settings directly if it is only renamed
            if (array_key_exists($old_setting['key'], $old_to_new_map)) {
                $avSetting = \App\Classes\Settings::getSetting($key);

                $settings[] = [
                    'key' => $key,
                    'value' => $value,
                    'type' => $avSetting->database_type ?? 'string',
                    'settingable_type' => null,
                    'settingable_id' => null,
                    'encrypted' => $avSetting->encrypted ?? false,
                    'created_at' => $old_setting['created_at'],
                    'updated_at' => $old_setting['updated_at'],
                ];
            } else {
                // Manually migrate completely or partially changed settings
                if ($key === 'recaptcha_type') {
                    $setting_id = array_search('recaptcha', array_column($records, 'key'));
                    $captcha_disabled = $records[$setting_id]['value'] === '0';

                    $settings[] = [
                        'key' => 'captcha',
                        'value' => $captcha_disabled ? 'disabled' : match ($value) {
                            'v2' => 'recaptcha-v2',
                            'v3' => 'recaptcha-v3',
                            default => $value
                        },
                        'type' => 'string',
                        'settingable_type' => null,
                        'settingable_id' => null,
                        'encrypted' => false,
                        'created_at' => $old_setting['created_at'],
                        'updated_at' => $old_setting['updated_at'],
                    ];
                } elseif ($key === 'company_country') {
                    $settings[] = [
                        'key' => $key,
                        'value' => array_flip((array) config('app.countries'))[$value],
                        'type' => 'string',
                        'settingable_type' => null,
                        'settingable_id' => null,
                        'encrypted' => false,
                        'created_at' => $old_setting['created_at'],
                        'updated_at' => $old_setting['updated_at'],
                    ];
                } elseif (in_array($key, [
                    'requiredClientDetails_address',
                    'requiredClientDetails_city',
                    'requiredClientDetails_zip',
                    'requiredClientDetails_country',
                    'requiredClientDetails_phone',
                ])) {
                    $key = str_replace('requiredClientDetails_', '', $key);
                    $property = CustomProperty::where('name', $key)->first();
                    if ($property) {
                        $property->update(['required' => $value === '1']);
                    }
                }
            }
        }

        foreach ($settings as $value) {
            DB::table('settings')->updateOrInsert(['key' => $value['key']], $value);
        }
        $this->info('Migrated settings!');
    }

    protected function config_options()
    {
        $stmt = $this->pdo->query('SELECT c.id, c.name, c.type, c.order, c.hidden, c.group_id, g.name AS group_name, g.description AS group_description, g.products, c.created_at, c.updated_at FROM configurable_options as c JOIN configurable_option_groups as g ON c.group_id = g.id');
        $records = $stmt->fetchAll();

        $inputs_stmt = $this->pdo->query('SELECT * FROM configurable_option_inputs');
        $option_inputs = $inputs_stmt->fetchAll();

        $records = array_map(function ($record) {
            $option = explode('|', $record['name'], 2);
            $env_variable = $option[0];
            $name = $option[1] ?? $env_variable;

            return [
                'id' => $record['id'],
                'name' => trim($name),
                'env_variable' => $env_variable ? trim($env_variable) : trim($name),
                'type' => match ($record['type']) {
                    'quantity' => 'number',
                    'slider' => 'select',
                    default => $record['type']
                },
                // TODO: migrate sort, or not
                'sort' => null,
                'hidden' => $record['hidden'],
                'parent_id' => null,

                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        $option_inputs = array_map(function ($record) {
            $option = explode('|', $record['name'], 2);
            $env_variable = $option[0];
            $name = $option[1] ?? $env_variable;

            return [
                'id' => $record['id'],
                'name' => trim($name),
                'env_variable' => $env_variable ? trim($env_variable) : trim($name),
                'type' => null,
                // TODO: migrate sort, or not
                'sort' => null,
                'hidden' => $record['hidden'],

                'parent_id' => $record['option_id'],
                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $option_inputs);

        DB::table('config_options')->insert($records);

        foreach ($option_inputs as $input) {

            $input_id = $input['id'];
            $inputs_stmt = $this->pdo->query("SELECT * FROM configurable_option_input_pricing WHERE `input_id` = $input_id");
            $record = $inputs_stmt->fetchAll()[0];

            if (
                is_null($record['monthly']) &&
                is_null($record['quarterly']) &&
                is_null($record['semi_annually']) &&
                is_null($record['annually']) &&
                is_null($record['biennially']) &&
                is_null($record['triennially'])
            ) {
                unset($input['id']);
                $new_id = DB::table('config_options')->insertGetId($input);

                // Option is free
                $input_plan = [
                    'name' => 'Free',
                    'type' => 'free',
                    'priceable_id' => $new_id,
                    'priceable_type' => 'App\Models\ConfigOption',
                ];

                $plan_id = DB::table('plans')->insertGetId($input_plan);

                continue;
            }

            if (
                $record['monthly'] &&
                is_null($record['quarterly']) &&
                is_null($record['semi_annually']) &&
                is_null($record['annually']) &&
                is_null($record['biennially']) &&
                is_null($record['triennially'])
            ) {
                unset($input['id']);
                $new_id = DB::table('config_options')->insertGetId($input);

                // Option is one-time
                $input_plan = [
                    'name' => 'One Time',
                    'type' => 'one-time',
                    'priceable_id' => $new_id,
                    'priceable_type' => 'App\Models\ConfigOption',
                ];

                $plan_id = DB::table('plans')->insertGetId($input_plan);

                DB::table('prices')->insert([
                    'plan_id' => $plan_id,
                    'price' => $record['monthly'],
                    'setup_fee' => $record['monthly_setup'],
                    'currency_code' => $this->currency_code,
                ]);

                continue;
            }

            if (
                $record['monthly'] &&
                is_null($record['quarterly']) &&
                is_null($record['semi_annually']) &&
                is_null($record['annually']) &&
                is_null($record['biennially']) &&
                is_null($record['triennially'])
            ) {
                unset($input['id']);
                $new_id = DB::table('config_options')->insertGetId($input);

                // Option is one-time
                $input_plan = [
                    'name' => 'One Time',
                    'type' => 'one-time',
                    'priceable_id' => $new_id,
                    'priceable_type' => 'App\Models\ConfigOption',
                ];

                $plan_id = DB::table('plans')->insertGetId($input_plan);

                DB::table('prices')->insert([
                    'plan_id' => $plan_id,
                    'price' => $record['monthly'],
                    'setup_fee' => $record['monthly_setup'],
                    'currency_code' => $this->currency_code,
                ]);

                continue;
            }

            if (
                $record['monthly'] &&
                ($record['quarterly'] ||
                    $record['semi_annually'] ||
                    $record['annually'] ||
                    $record['biennially'] ||
                    $record['triennially']
                )
            ) {
                unset($input['id']);
                $new_id = DB::table('config_options')->insertGetId($input);

                $common_fields = [
                    'type' => 'recurring',
                    'priceable_id' => $new_id,
                    'priceable_type' => 'App\Models\ConfigOption',
                ];

                $plans = [];

                if ($record['monthly']) {
                    array_push($plans, array_merge([
                        'name' => 'Monthly',
                        'billing_period' => 1,
                        'billing_unit' => 'month',
                        'price' => [
                            'price' => $record['monthly'],
                            'setup_fee' => $record['monthly_setup'],
                            'currency_code' => $this->currency_code,
                        ],
                    ], $common_fields));
                }

                if ($record['quarterly']) {
                    array_push($plans, array_merge([
                        'name' => 'Quarterly',
                        'billing_period' => 3,
                        'billing_unit' => 'month',
                        'price' => [
                            'price' => $record['quarterly'],
                            'setup_fee' => $record['quarterly_setup'],
                            'currency_code' => $this->currency_code,
                        ],
                    ], $common_fields));
                }

                if ($record['semi_annually']) {
                    array_push($plans, array_merge([
                        'name' => 'Semi-Annually',
                        'billing_period' => 6,
                        'billing_unit' => 'month',
                        'price' => [
                            'price' => $record['semi_annually'],
                            'setup_fee' => $record['semi_annually_setup'],
                            'currency_code' => $this->currency_code,
                        ],
                    ], $common_fields));
                }

                if ($record['annually']) {
                    array_push($plans, array_merge([
                        'name' => 'Annually',
                        'billing_period' => 1,
                        'billing_unit' => 'year',
                        'price' => [
                            'price' => $record['annually'],
                            'setup_fee' => $record['annually_setup'],
                            'currency_code' => $this->currency_code,
                        ],
                    ], $common_fields));
                }

                if ($record['biennially']) {
                    array_push($plans, array_merge([
                        'name' => 'Biennially',
                        'billing_period' => 2,
                        'billing_unit' => 'year',
                        'price' => [
                            'price' => $record['biennially'],
                            'setup_fee' => $record['biennially_setup'],
                            'currency_code' => $this->currency_code,
                        ],
                    ], $common_fields));
                }

                if ($record['triennially']) {
                    array_push($plans, array_merge([
                        'name' => 'Triennially',
                        'billing_period' => 3,
                        'billing_unit' => 'year',
                        'price' => [
                            'price' => $record['triennially'],
                            'setup_fee' => $record['triennially_setup'],
                            'currency_code' => $this->currency_code,
                        ],
                    ], $common_fields));
                }

                $all_prices = [];
                foreach ($plans as $plan) {
                    $price = $plan['price'];
                    // Unset the price from the plan array, so it can be inserted without errors
                    unset($plan['price']);
                    $plan_id = DB::table('plans')->insertGetId($plan);
                    $all_prices[] = array_merge([
                        'plan_id' => $plan_id,
                    ], $price);
                }
                DB::table('prices')->insert($all_prices);

                continue;
            }
        }

        $this->info('Migrated config_options!');
    }

    protected function config_option_products()
    {
        $stmt = $this->pdo->query('SELECT c.id, c.name, c.type, c.order, c.hidden, c.group_id, g.name AS group_name, g.description AS group_description, g.products, c.created_at, c.updated_at FROM configurable_options as c JOIN configurable_option_groups as g ON c.group_id = g.id');
        $records = $stmt->fetchAll();

        $config_option_products = [];
        foreach ($records as $record) {

            $products = json_decode($record['products']);

            foreach ($products as $product_id) {
                $config_option_products[] = [
                    'config_option_id' => $record['id'],
                    'product_id' => (int) $product_id,
                ];
            }
        }

        DB::table('config_option_products')->insert($config_option_products);
        $this->info('Migrated config_option_products!');
    }

    protected function extensions()
    {
        $stmt = $this->pdo->query('SELECT * FROM extensions');
        $records = $stmt->fetchAll();

        $records = array_map(function ($record) {
            return [
                'id' => $record['id'],
                'name' => $record['display_name'] ?? $record['name'],
                'extension' => $record['name'],
                'type' => $record['type'],
                'enabled' => $record['enabled'],
            ];
        }, $records);

        DB::table('extensions')->insert($records);
        $this->info('Migrated extensions!');
    }

    protected function products()
    {
        $stmt = $this->pdo->query('SELECT * FROM products');
        $records = $stmt->fetchAll();

        $records = array_map(function ($record) {
            return [
                'id' => $record['id'],
                'name' => $record['name'],
                'slug' => Str::slug($record['name']),
                'description' => $record['description'],

                'category_id' => $record['category_id'],
                'image' => $record['image'],
                'stock' => $record['stock_enabled'] ? $record['stock'] : null,
                'per_user_limit' => $record['limit'],
                'allow_quantity' => match ($record['allow_quantity']) {
                    0 => 'disabled',
                    1 => 'separated',
                    2 => 'combined',
                    default => 'disabled'
                },
                'server_id' => $record['extension_id'],

                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        DB::table('products')->insert($records);
        $this->info('Migrated products!');
    }

    protected function product_upgrades()
    {
        $stmt = $this->pdo->query('SELECT * FROM product_upgrades');
        $records = $stmt->fetchAll();

        $records = array_map(function ($record) {
            return [
                'id' => $record['id'],
                'product_id' => $record['product_id'],
                'upgrade_id' => $record['upgrade_product_id'],

                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        DB::table('product_upgrades')->insert($records);
        $this->info('Migrated product_upgrades!');
    }

    // TODO: this is pending, fix this shit
    protected function service_cancellations()
    {
        $stmt = $this->pdo->query('SELECT * FROM cancellations');
        $records = $stmt->fetchAll();

        $records = array_map(function ($record) {
            return [
                'id' => $record['id'],
                'product_id' => $record['product_id'],
                'upgrade_id' => $record['upgrade_product_id'],

                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        DB::table('service_cancellations')->insert($records);
        $this->info('Migrated service_cancellations!');
    }

    protected function invoices()
    {
        $stmt = $this->pdo->query('SELECT * FROM invoices');
        $records = $stmt->fetchAll();
        $items_stmt = $this->pdo->query('
        SELECT
            invoice_items.*,
            order_products.id as service_id,
            order_products.quantity as service_quantity
        FROM
            invoice_items
        LEFT JOIN
            order_products ON invoice_items.product_id = order_products.id
        ');
        $v0_invoice_items = $items_stmt->fetchAll();

        $invoice_transactions = [];
        $invoice_items = [];

        $invoices = array_map(function ($record) use ($v0_invoice_items, &$invoice_items, &$invoice_transactions) {
            $transaction_amount = 0;

            $items = array_map(function ($item) use (&$transaction_amount) {

                $price = number_format((float) $item['total'], 2, '.', '');
                $transaction_amount += (float) $price;

                return [
                    'id' => $item['id'],
                    'invoice_id' => $item['invoice_id'],
                    'description' => $item['description'],
                    'price' => number_format((float) $item['total'], 2, '.', ''),
                    'quantity' => $item['service_quantity'] ?? 1,

                    'reference_type' => 'App\Models\Service',
                    'reference_id' => $item['service_id'],

                    'created_at' => $item['created_at'],
                    'updated_at' => $item['updated_at'],
                ];
            }, array_filter($v0_invoice_items, function ($item) use ($record) {
                return $item['invoice_id'] === $record['id'];
            }));

            $gateway = Gateway::where('name', $record['paid_with'])->get()->first();

            // Add the transaction details to invoice_transactions
            $invoice_transactions[] = [
                'invoice_id' => $record['id'],
                'transaction_id' => $record['paid_reference'],
                'gateway_id' => $gateway ? $gateway->id : null,
                'amount' => $transaction_amount,
                'fee' => null,

                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];

            // Add the invoice items to invoice_items
            $invoice_items = array_merge($invoice_items, $items);

            return [
                'id' => $record['id'],
                'status' => $record['status'],
                'due_at' => $record['due_date'],
                'currency_code' => $this->currency_code,
                'user_id' => $record['user_id'],

                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        DB::table('invoices')->insert($invoices);
        DB::table('invoice_items')->insert($invoice_items);
        DB::table('invoice_transactions')->insert($invoice_transactions);
        $this->info('Migrated invoices, invoice_items and invoice_transactions!');
    }

    protected function plans()
    {
        $stmt = $this->pdo->query('SELECT * FROM product_price');
        $records = $stmt->fetchAll();

        $plans = [];

        foreach ($records as $record) {

            $common_fields = [
                'type' => $record['type'],
                'priceable_id' => $record['product_id'],
                'priceable_type' => 'App\Models\Product',
            ];

            if ($record['monthly']) {
                array_push($plans, array_merge([
                    'name' => 'Monthly',
                    'billing_period' => 1,
                    'billing_unit' => 'month',
                    'price' => [
                        'price' => $record['monthly'],
                        'setup_fee' => $record['monthly_setup'],
                        'currency_code' => $this->currency_code,
                    ],
                ], $common_fields));
            }

            if ($record['quarterly']) {
                array_push($plans, array_merge([
                    'name' => 'Quarterly',
                    'billing_period' => 3,
                    'billing_unit' => 'month',
                    'price' => [
                        'price' => $record['quarterly'],
                        'setup_fee' => $record['quarterly_setup'],
                        'currency_code' => $this->currency_code,
                    ],
                ], $common_fields));
            }

            if ($record['semi_annually']) {
                array_push($plans, array_merge([
                    'name' => 'Semi-Annually',
                    'billing_period' => 6,
                    'billing_unit' => 'month',
                    'price' => [
                        'price' => $record['semi_annually'],
                        'setup_fee' => $record['semi_annually_setup'],
                        'currency_code' => $this->currency_code,
                    ],
                ], $common_fields));
            }

            if ($record['annually']) {
                array_push($plans, array_merge([
                    'name' => 'Annually',
                    'billing_period' => 1,
                    'billing_unit' => 'year',
                    'price' => [
                        'price' => $record['annually'],
                        'setup_fee' => $record['annually_setup'],
                        'currency_code' => $this->currency_code,
                    ],
                ], $common_fields));
            }

            if ($record['biennially']) {
                array_push($plans, array_merge([
                    'name' => 'Biennially',
                    'billing_period' => 2,
                    'billing_unit' => 'year',
                    'price' => [
                        'price' => $record['biennially'],
                        'setup_fee' => $record['biennially_setup'],
                        'currency_code' => $this->currency_code,
                    ],
                ], $common_fields));
            }

            if ($record['triennially']) {
                array_push($plans, array_merge([
                    'name' => 'Triennially',
                    'billing_period' => 3,
                    'billing_unit' => 'year',
                    'price' => [
                        'price' => $record['triennially'],
                        'setup_fee' => $record['triennially_setup'],
                        'currency_code' => $this->currency_code,
                    ],
                ], $common_fields));
            }
        }

        $all_prices = [];
        foreach ($plans as $plan) {
            $price = $plan['price'];
            // Unset the price from the plan array, so it can be inserted without errors
            unset($plan['price']);
            $plan_id = DB::table('plans')->insertGetId($plan);
            $all_prices[] = array_merge([
                'plan_id' => $plan_id,
            ], $price);
        }
        DB::table('prices')->insert($all_prices);

        $this->info('Migrated plans & prices!');
    }

    protected function ordersAndServices()
    {
        $stmt = $this->pdo->query('SELECT * FROM orders');
        $records = $stmt->fetchAll();

        $order_product_details = [];

        $records = array_map(function ($record) use (&$order_product_details) {
            $order_product_details[$record['id']] = [
                'coupon_id' => $record['coupon_id'],
                'user_id' => $record['user_id'],
            ];

            return [
                'id' => $record['id'],
                'user_id' => $record['user_id'],
                'currency_code' => $this->currency_code,

                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        DB::table('orders')->insert($records);
        $this->info('Migrated orders!');

        $stmt = $this->pdo->query('
        SELECT
            op.*,
            opc.value as stripe_subscription_id
        FROM
            order_products op
        LEFT JOIN
            order_products_config opc
            ON op.id = opc.order_product_id
            AND opc.key = \'stripe_subscription_id\'
        ');
        $records = $stmt->fetchAll();

        $records = array_map(function ($record) use ($order_product_details) {
            $order = $order_product_details[$record['order_id']];

            $billing = match ($record['billing_cycle']) {
                'monthly' => [
                    'type' => 'recurring',
                    'unit' => 'month',
                    'period' => 1,
                ],
                'quarterly' => [
                    'type' => 'recurring',
                    'unit' => 'month',
                    'period' => 3,
                ],
                'semi_annually' => [
                    'type' => 'recurring',
                    'unit' => 'month',
                    'period' => 6,
                ],
                'annually' => [
                    'type' => 'recurring',
                    'unit' => 'year',
                    'period' => 1,
                ],
                'biennially' => [
                    'type' => 'recurring',
                    'unit' => 'year',
                    'period' => 2,
                ],
                'triennially' => [
                    'type' => 'recurring',
                    'unit' => 'year',
                    'period' => 3,
                ],
                null => $record['price'] === 0 ? [
                    'type' => 'free',
                    'unit' => null,
                    'period' => null,
                ] : [
                    'type' => 'one-time',
                    'unit' => null,
                    'period' => null,
                ]
            };

            $price = Price::where('price', $record['price'])
                ->whereHas('plan', function ($query) use ($billing) {
                    $query->where('priceable_type', 'App\Models\Product')
                        ->where('type', $billing['type'])
                        ->where('billing_period', $billing['period'])
                        ->where('billing_unit', $billing['unit']);
                })->first();

            return [
                'id' => $record['id'],
                // Active instead of Paid status, leave rest unchanged
                'status' => match ($record['status']) {
                    'paid' => 'active',
                    default => $record['status']
                },
                'order_id' => $record['order_id'],
                'product_id' => $record['product_id'],
                'user_id' => $order['user_id'],
                'currency_code' => $this->currency_code,

                'quantity' => $record['quantity'],
                'price' => $record['price'],

                'plan_id' => $price ? $price->plan_id : null,
                'coupon_id' => $order['coupon_id'],
                'expires_at' => $record['expiry_date'],
                'subscription_id' => $record['stripe_subscription_id'],
                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        DB::table('services')->insert($records);
        $this->info('Migrated services!');
    }

    protected function service_configs()
    {
        $stmt = $this->pdo->query('SELECT * FROM order_products_config');
        $records = $stmt->fetchAll();

        $service_properties = [];
        $service_configs = [];

        foreach ($records as $record) {
            if ($record['key'] === 'stripe_subscription_id') {
                continue;
            }
            if ($record['is_configurable_option'] === 1) {
                $configOption = ConfigOption::whereId($record['key'])->first();
                if (in_array($configOption->type, ['text', 'number'])) {
                    $service_properties[] = [
                        'name' => $record['key'],
                        'key' => $record['key'],
                        'custom_property_id' => null,
                        'model_id' => $record['order_product_id'],
                        'model_type' => 'App\Models\Service',
                        'value' => $record['value'],
                    ];

                    continue;
                }
                $service_configs[] = [
                    'service_id' => $record['order_product_id'],
                    'config_option_id' => $configOption->id,
                    'config_value_id' => $record['value'],
                ];
            } else {
                $service_properties[] = [
                    'name' => $record['key'],
                    'key' => $record['key'],
                    'custom_property_id' => null,
                    'model_id' => $record['order_product_id'],
                    'model_type' => 'App\Models\Service',
                    'value' => $record['value'],
                ];
            }
        }

        DB::table('service_configs')->insert($service_configs);
        DB::table('properties')->insert($service_properties);
        $this->info('Migrated service_configs!');
    }

    protected function migrateUsers()
    {
        $stmt = $this->pdo->query('SELECT * FROM users');
        $records = $stmt->fetchAll();

        $records = array_map(function ($record) {
            return [
                'id' => $record['id'],
                'first_name' => $record['first_name'],
                'last_name' => $record['last_name'],
                'email' => $record['email'],
                // If the user had admin role, then give him admin, otherwise give no role
                'role_id' => $record['role_id'] === 1 ? 1 : null,
                'email_verified_at' => $record['email_verified_at'],
                'password' => $record['password'],
                'tfa_secret' => $record['tfa_secret'],
                'credits' => $record['credits'],
                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
                'remember_token' => $record['remember_token'],
            ];
        }, $records);

        DB::table('users')->insert($records);
        $this->info('Migrated Users!');
    }

    protected function user_properties()
    {
        $stmt = $this->pdo->query('SELECT id, address, city, state, zip, country, phone, companyname FROM users');
        $records = $stmt->fetchAll();

        $address = CustomProperty::where('model', 'App\Models\User')->where('key', 'address')->first();
        $city = CustomProperty::where('model', 'App\Models\User')->where('key', 'city')->first();
        $state = CustomProperty::where('model', 'App\Models\User')->where('key', 'state')->first();
        $zip = CustomProperty::where('model', 'App\Models\User')->where('key', 'zip')->first();
        $country = CustomProperty::where('model', 'App\Models\User')->where('key', 'country')->first();
        $phone = CustomProperty::where('model', 'App\Models\User')->where('key', 'phone')->first();
        $companyname = CustomProperty::where('model', 'App\Models\User')->where('key', 'company_name')->first();

        $properties = [];
        foreach ($records as $record) {
            if ($record['address']) {
                array_push($properties, [
                    'name' => $address->name,
                    'key' => $address->key,
                    'custom_property_id' => $address->id,
                    'model_id' => $record['id'],
                    'model_type' => 'App\Models\User',
                    'value' => $record['address'],
                ]);
            }
            if ($record['city']) {
                array_push($properties, [
                    'name' => $city->name,
                    'key' => $city->key,
                    'custom_property_id' => $city->id,
                    'model_id' => $record['id'],
                    'model_type' => 'App\Models\User',
                    'value' => $record['city'],
                ]);
            }
            if ($record['state']) {
                array_push($properties, [
                    'name' => $state->name,
                    'key' => $state->key,
                    'custom_property_id' => $state->id,
                    'model_id' => $record['id'],
                    'model_type' => 'App\Models\User',
                    'value' => $record['state'],
                ]);
            }
            if ($record['zip']) {
                array_push($properties, [
                    'name' => $zip->name,
                    'key' => $zip->key,
                    'custom_property_id' => $zip->id,
                    'model_id' => $record['id'],
                    'model_type' => 'App\Models\User',
                    'value' => $record['zip'],
                ]);
            }
            if ($record['country']) {
                array_push($properties, [
                    'name' => $country->name,
                    'key' => $country->key,
                    'custom_property_id' => $country->id,
                    'model_id' => $record['id'],
                    'model_type' => 'App\Models\User',
                    'value' => $record['country'],
                ]);
            }
            if ($record['phone']) {
                array_push($properties, [
                    'name' => $phone->name,
                    'key' => $phone->key,
                    'custom_property_id' => $phone->id,
                    'model_id' => $record['id'],
                    'model_type' => 'App\Models\User',
                    'value' => $record['phone'],
                ]);
            }
            if ($record['companyname']) {
                array_push($properties, [
                    'name' => $companyname->name,
                    'key' => $companyname->key,
                    'custom_property_id' => $companyname->id,
                    'model_id' => $record['id'],
                    'model_type' => 'App\Models\User',
                    'value' => $record['companyname'],
                ]);
            }
        }

        DB::table('properties')->insert($properties);
        $this->info('Migrated all user\'s properties!');
    }

    protected function migrateTickets()
    {
        $stmt = $this->pdo->query('SELECT * FROM tickets');
        $records = $stmt->fetchAll();

        $records = array_map(function ($record) {
            return [
                'id' => $record['id'],
                'subject' => $record['title'],
                'status' => $record['status'],
                'priority' => $record['priority'],
                'department' => null,

                'assigned_to' => $record['assigned_to'],
                'user_id' => $record['user_id'],
                'service_id' => $record['order_id'],

                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        DB::table('tickets')->insert($records);
        $this->info('Migrated Tickets!');
    }

    protected function migrateTicketMessages()
    {
        $stmt = $this->pdo->query('SELECT * FROM ticket_messages');
        $records = $stmt->fetchAll();

        $records = array_filter($records, fn ($record) => !is_null($record['message']) && $record['message'] !== '');

        $records = array_map(function ($record) {
            return [
                'id' => $record['id'],
                'ticket_id' => $record['ticket_id'],
                'user_id' => $record['user_id'],
                'message' => $record['message'],

                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        DB::table('ticket_messages')->insert($records);
        $this->info('Migrated Ticket Messages!');
    }

    protected function migrateTaxRates()
    {
        $stmt = $this->pdo->query('SELECT * FROM tax_rates');
        $records = $stmt->fetchAll();

        $records = array_map(function ($record) {
            return [
                'id' => $record['id'],
                'name' => $record['name'],
                'rate' => $record['rate'],
                'country' => $record['country'],

                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        DB::table('tax_rates')->insert($records);
        $this->info('Migrated Tax Rates!');
    }

    protected function migrateCategories()
    {
        $stmt = $this->pdo->query('SELECT * FROM categories');
        $records = $stmt->fetchAll();

        $records = array_map(function ($record) {
            return [

                'id' => $record['id'],
                'slug' => $record['slug'],
                'name' => $record['name'],
                'description' => $record['description'],
                'image_url' => $record['image'],
                'parent_id' => $record['category_id'],
                'full_slug' => $record['slug'],

                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        DB::table('categories')->insert($records);
        $this->info('Migrated Product Categories!');
    }

    protected function migrateCoupons()
    {
        $stmt = $this->pdo->query('SELECT * FROM coupons');
        $records = $stmt->fetchAll();

        $coupon_products = [];

        $records = array_map(function ($record) {
            if ($record['products']) {
                foreach (json_decode($record['products']) as $product_id) {
                    $coupon_products[] = [
                        'coupon_id' => (int) $record['id'],
                        'product_id' => (int) $product_id,
                    ];
                }
            }

            return [
                'id' => $record['id'],

                'type' => $record['type'],
                'recurring' => null,
                'code' => $record['code'],
                'value' => number_format((float) $record['value'], 2, '.', ''),
                'max_uses' => (int) $record['max_uses'],
                'starts_at' => $record['start_date'],
                'expires_at' => $record['end_date'],

                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        DB::table('coupons')->insert($records);
        DB::table('coupon_products')->insert($coupon_products);
        $this->info('Migrated Product Coupons!');
    }
}