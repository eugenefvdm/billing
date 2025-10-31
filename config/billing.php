<?php

use App\Models\User;

return [
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

    'invoice' => [
        'default_due_days' => env('INVOICE_DEFAULT_DUE_DAYS', 7),
        'pdf_storage_path' => env('INVOICE_PDF_PATH', 'invoices'),
        'reminders' => [
            'first_overdue_notice' => env('INVOICE_FIRST_OVERDUE_NOTICE', 3),
            'second_overdue_notice' => env('INVOICE_SECOND_OVERDUE_NOTICE', 6),
            'third_overdue_notice' => env('INVOICE_THIRD_OVERDUE_NOTICE', 9),
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
        'test_mode_callback_url' => env('PAYFAST_TEST_MODE_CALLBACK_URL', config('app.url')),        
        'debug' => env('PAYFAST_DEBUG', false),
        'return_url' => env('PAYFAST_RETURN_URL', '/payfast/return'),
        'cancel_url' => env('PAYFAST_CANCEL_URL', '/payfast/cancel'),
        'notify_url' => env('PAYFAST_NOTIFY_URL', '/payfast/notify'),
        // TODO Deprecate these two lines after first searching if they are in use
        // 'callback_url' => env('PAYFAST_CALLBACK_URL', config('app.url')),
        // 'callback_url_test' => env('PAYFAST_CALLBACK_URL_TEST', ''),
    ]

];
