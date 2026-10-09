<?php

/*
|--------------------------------------------------------------------------
| Public landing page and self-service sign-up
|--------------------------------------------------------------------------
|
| Everything the guest-facing `/` page shows that is not read from the database: the sign-up switch, the product name and the
| contact details of the provider. Nothing here is a secret; leave a contact value empty and it is simply not shown.
*/

return [

    // false: the Register button becomes "Contact us" and /register answers 403.
    'registration_enabled' => (bool) env('PUBLIC_REGISTRATION_ENABLED', true),

    'brand' => env('LANDING_BRAND', 'ACCUNIVO'),

    // A plan has a price but no currency of its own; this label is printed next to it (for example "PKR"). Empty = number only.
    'currency_label' => env('LANDING_CURRENCY_LABEL', ''),

    'contact' => [
        'email' => env('LANDING_CONTACT_EMAIL', ''),
        'phone' => env('LANDING_CONTACT_PHONE', ''),
        'address' => env('LANDING_CONTACT_ADDRESS', ''),
    ],

];
