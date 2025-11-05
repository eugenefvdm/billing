<?php

use App\Models\User;

return [
    'default_payment_methods' => ['card', 'eft'],

    'billables' => [
        'user' => [
            'model' => User::class,
            'trial_days' => 30,
            'default_interval' => 'monthly',
            'currency_prefix' => 'R ',
            'plans' => [
                [
                    'name' => 'Startup',
                    'short_description' => "",
                    'monthly' => [
                        'setup_amount' => 69000,
                        'recurring_amount' => 69000,
                    ],
                    'yearly' => [
                        'setup_amount' => 700000,
                        'recurring_amount' => 700000,
                    ],
                    'features' => [
                        'Feature 1',
                        'Feature 2',
                        'Feature 3',
                    ],
                    'archived' => false,
                    'cta' => '30 DAY FREE TRIAL',
                    'mostPopular' => false,
                ],
                [
                    'name' => 'Business',
                    'short_description' => "",
                    'monthly' => [
                        'setup_amount' => 199000,
                        'recurring_amount' => 199000,
                    ],
                    'yearly' => [
                        'setup_amount' => 2189000,
                        'recurring_amount' => 2189000,
                    ],
                    'features' => [
                        'Feature 1',
                        'Feature 2',
                        'Feature 3',
                    ],
                    'archived' => false,
                    'cta' => '30 DAY FREE TRIAL',
                    'mostPopular' => true,
                ],
            ],
        ],
    ],

    'payfast' => [
        'merchant_id' => env('PAYFAST_MERCHANT_ID'),
        'merchant_key' => env('PAYFAST_MERCHANT_KEY'),
        'passphrase' => env('PAYFAST_PASSPHRASE'),
        'test_mode' => env('PAYFAST_TEST_MODE'),
        'merchant_id_test' => env('PAYFAST_MERCHANT_ID_TEST'),
        'merchant_key_test' => env('PAYFAST_MERCHANT_KEY_TEST'),
        'passphrase_test' => env('PAYFAST_PASSPHRASE_TEST'),
        'test_mode_itn_url' => env('PAYFAST_TEST_MODE_ITN_URL'),
        'debug' => env('PAYFAST_DEBUG', false),
    ],

    'eft' => [
        'bank_name' => env('BANK_NAME'),
        'bank_account_number' => env('BANK_ACCOUNT_NUMBER'),
        'bank_account_type' => env('BANK_ACCOUNT_TYPE', 'Cheque'),
        'bank_branch_code' => env('BANK_BRANCH_CODE'),
    ],

    'invoice' => [
        'default_due_days' => env('INVOICE_DEFAULT_DUE_DAYS', 7),
        'pdf_path' => env('INVOICE_PDF_PATH', 'invoices'),
        'company_name' => env('INVOICE_COMPANY_NAME', config('app.name')),
        'company_address' => env('INVOICE_COMPANY_ADDRESS'),
        'company_city' => env('INVOICE_COMPANY_CITY'),
        'company_phone' => env('INVOICE_COMPANY_PHONE'),
        'company_email' => env('INVOICE_COMPANY_EMAIL', env('MAIL_FROM_ADDRESS')),
        'reminders' => [
            'first_overdue_notice' => env('INVOICE_FIRST_OVERDUE_NOTICE', 3),
            'second_overdue_notice' => env('INVOICE_SECOND_OVERDUE_NOTICE', 6),
            'third_overdue_notice' => env('INVOICE_THIRD_OVERDUE_NOTICE', 9),
        ],
    ],

    'debug' => env('BILLING_DEBUG', false),

];
