<?php declare(strict_types=1);

return [
    'here-we-donate.com' => [
        'logLevel' => 'INFO',

        'webhookSecret' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', // random string 32 chars

        'adminEmail'      => 'admin@mail.com',
        'accountantEmail' => 'accountant@mail.com',

        'periodGroupId'             => 1000, // Mandator        /periodgroup/1000
        'donationAccountTemplateId' => 2010, // Donations       /accounttemplate/2010
        'debtorAccountTemplateId'   => 2020, // Debtors         /accounttemplate/2020
        'bankAccountTemplateId'     => 2030, // Bank            /accounttemplate/2030
        'debtorCategoryId'          => 3000, // Inbox RaiseNow  /debitorcategory/3000

        'groupIdForNewMembers' => 101,

        'weblingApiUrl' => 'https://gruenesandbox.webling.ch',
        'weblingApiKey' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',

        'weblingServiceApiUrl'       => 'https://weblingservice.gruene.ch',
        'weblingServiceClientId'     => '1',
        'weblingServiceClientSecret' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'tokenEncryptionKey'         => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', // random string 32 chars

        'donorField'       => 'donorCountry',
        'newsletterFieldD' => 'newsletterCountryD',
        'newsletterFieldF' => 'newsletterCountryF',
    ],
];