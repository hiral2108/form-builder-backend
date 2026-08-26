<?php

return [

    'site_name' => 'Form Builder',

    'short_site_name' => 'Form Builder',

    'site_icon_path' => 'assets/images/favicon.ico',

    'viewport_content' => 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0',

    'pagination_limit' => 10,

    'platforms' => [
        'shopify' => [
            'name'      => 'Shopify',
            'slug'      => 'shopify',
            'APIkey'    => env('SHOPIFY_APP_KEY'),
            'secretkey' => env('SHOPIFY_APP_SECRET_KEY'),
            'payment'   => 'shopify'
        ],
        'wix' => [
            'name'      => 'Wix',
            'slug'      => 'wix',
            'APIkey'    => '',
            'secretkey' => '',
            'payment'   => 'wix'
        ],
        'ecwid' => [
            'name'      => 'Ecwid',
            'slug'      => 'ecwid',
            'APIkey'    => '',
            'secretkey' => '',
            'payment'   => 'stripe'
        ]
    ]

];
