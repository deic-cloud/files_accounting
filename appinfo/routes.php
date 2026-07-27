<?php

declare(strict_types=1);

return [
	'ocs' => [
		// Institutional / admin API
		['name' => 'Api#getBills',       'url' => '/api/v1/bills',         'verb' => 'GET'],
		['name' => 'Api#getInvoice',     'url' => '/api/v1/invoice',       'verb' => 'GET'],
		['name' => 'Api#getUsage',       'url' => '/api/v1/usage',         'verb' => 'GET'],
		['name' => 'Api#getStatistics',  'url' => '/api/v1/statistics',    'verb' => 'GET'],
		['name' => 'Api#setFreeQuota',   'url' => '/api/v1/freequota',     'verb' => 'POST'],
		['name' => 'Api#getFreeQuota',   'url' => '/api/v1/freequota',     'verb' => 'GET'],
		['name' => 'Api#listGifts',      'url' => '/api/v1/gifts',         'verb' => 'GET'],
		['name' => 'Api#createGift',     'url' => '/api/v1/gifts',         'verb' => 'POST'],
		['name' => 'Api#deleteGift',     'url' => '/api/v1/gifts/{code}',  'verb' => 'DELETE'],
		['name' => 'Api#redeemGift',     'url' => '/api/v1/gifts/redeem',  'verb' => 'POST'],
		// Personal (logged-in user viewing own data)
		['name' => 'Api#myBills',        'url' => '/api/v1/my/bills',      'verb' => 'GET'],
		['name' => 'Api#myUsage',        'url' => '/api/v1/my/usage',      'verb' => 'GET'],
		['name' => 'Api#myInvoice',      'url' => '/api/v1/my/invoice',    'verb' => 'GET'],
		['name' => 'Api#myGifts',        'url' => '/api/v1/my/gifts',      'verb' => 'GET'],
		['name' => 'Api#myRedeemGift',   'url' => '/api/v1/my/gifts/redeem', 'verb' => 'POST'],
	],
	'routes' => [
		// Inter-silo endpoints secured with shared secret
		['name' => 'Internal#currentUsageAverage', 'url' => '/internal/currentusageaverage', 'verb' => 'POST'],
		['name' => 'Internal#personalStorage',     'url' => '/internal/personalstorage',     'verb' => 'POST'],
		['name' => 'Internal#setFreeQuota',        'url' => '/internal/setfreequota',        'verb' => 'POST'],
		['name' => 'Internal#getPrepaid',          'url' => '/internal/prepaid',             'verb' => 'GET'],
		['name' => 'Internal#setPrepaid',          'url' => '/internal/prepaid',             'verb' => 'POST'],
		['name' => 'Internal#expireGifts',         'url' => '/internal/expiregifts',         'verb' => 'POST'],
		['name' => 'Internal#redeemGift',          'url' => '/internal/redeemgift',          'verb' => 'POST'],
		['name' => 'Internal#createGift',          'url' => '/internal/creategift',          'verb' => 'POST'],
	],
];
