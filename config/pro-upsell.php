<?php
/**
 * PRO upsell content, generated from the plogins.com registry by
 * scripts/gen-pro-upsell.mjs. The admin upsell renders this; curate the
 * feature list to fit this plugin's settings screen (do not invent features).
 *
 * @package redono-pro
 */

defined('ABSPATH') || exit;

return [
    'name'       => 'Redono Pro',
    'url'        => 'https://plogins.com/redono-pro/pricing/',
    'campaign'   => 'redono',
    'locales'    => ['pl', 'de', 'es'],
    'sellable'   => true,
    'price_from' => 29,
    'currency'   => 'EUR',
    'lead'       => [
        'en' => 'Status emails, return shipping labels, automatic store credit, return-reason analytics and refund actions ship in version 0.6.0. Feature-complete PRO.',
        'pl' => 'Wydanie 0.6.0 dostarcza e-maile statusu, etykiety zwrotne, automatyczny kredyt sklepowy, analitykę powodów i akcje zwrotu środków. Feature-complete PRO.',
    ],
    'features'   => [
        [
            'en' => ['title' => 'Status-change emails', 'desc' => 'Notify the customer when a return is approved, rejected or completed (shipped).'],
            'pl' => ['title' => 'E-maile o statusie', 'desc' => 'Powiadom klienta, gdy zgłoszenie zostanie zatwierdzone, odrzucone lub zakończone (wdrożone).'],
        ],
        [
            'en' => ['title' => 'Refund amount line', 'desc' => 'Include the refund amount in the completed-status email (shipped).'],
            'pl' => ['title' => 'Kwota zwrotu w e-mailu', 'desc' => 'Dołącz kwotę zwrotu w wiadomości o statusie Completed (wdrożone).'],
        ],
        [
            'en' => ['title' => 'Store credit line', 'desc' => 'Include store-credit code and amount in the completed email (shipped).'],
            'pl' => ['title' => 'Kredyt sklepowy w e-mailu', 'desc' => 'Dołącz kod i kwotę kredytu sklepowego w wiadomości o zakończeniu (wdrożone).'],
        ],
        [
            'en' => ['title' => 'Return shipping labels', 'desc' => 'Enter the label URL, carrier and tracking number on the request; the status email passes them to the customer. The plugin does not create labels with a carrier.'],
            'pl' => ['title' => 'Etykiety zwrotnej wysyłki', 'desc' => 'W zgłoszeniu wpisujesz link do etykiety, przewoźnika i numer przesyłki, a e-mail o statusie przekazuje je klientowi. Wtyczka nie tworzy etykiet u przewoźnika.'],
        ],
        [
            'en' => ['title' => 'Automatic store credit', 'desc' => 'Issue store credit automatically when a return is completed (shipped).'],
            'pl' => ['title' => 'Automatyczny kredyt sklepowy', 'desc' => 'Wystaw kredyt sklepowy automatycznie po zakończeniu zwrotu (wdrożone).'],
        ],
        [
            'en' => ['title' => 'Return-reason analytics', 'desc' => 'A dashboard of return reasons, rates and trends to spot the products and causes driving returns (shipped).'],
            'pl' => ['title' => 'Analityka powodów', 'desc' => 'Pulpit powodów, trendów i linii produktowych napędzających zwroty (wdrożone).'],
        ],
        [
            'en' => ['title' => 'Refund actions', 'desc' => 'Process WooCommerce refunds from the return request screen (RefundActions, shipped in 0.6.0). Since 1.1.0 a request opened from a Withdraw declaration shows its refund deadline, and the shop gets one email reminder before it. For cash-on-delivery and bank-transfer orders the customer can enter a refund bank account (IBAN) on the form, stored encrypted and masked in admin.'],
            'pl' => ['title' => 'Akcje zwrotu środków', 'desc' => 'Przetwarzaj zwroty WooCommerce z ekranu zgłoszenia zwrotu (RefundActions, wdrożone w 0.6.0). Od 1.1.0 zgłoszenie otwarte z odstąpienia w Withdraw pokazuje termin zwrotu pieniędzy, a sklep dostaje jedno przypomnienie e-mailem przed jego upływem. Przy płatności za pobraniem albo przelewem klient może podać w formularzu numer rachunku (IBAN), zapisywany w postaci zaszyfrowanej i maskowany w panelu.'],
        ],
    ],
];
