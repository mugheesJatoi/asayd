<?php
try {
    $logBase = __DIR__;
    $timestamp = date('Y-m-d_H-i-s');

    $apiSecret = 'f922975118a8dc6e38a3beba047a2893271531ee1f66fe519a0e638c4fcf4aed';
    $shopifyToken = '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';
    $shopDomain = 'aziz-honey.myshopify.com';

    $rawPostData = file_get_contents('php://input');
    $hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';

    $calculatedHmac = base64_encode(hash_hmac('sha256', $rawPostData, $apiSecret, true));
    if ($hmacHeader !== $calculatedHmac) {
        file_put_contents("$logBase/fatal-error.txt", "Invalid HMAC\n", FILE_APPEND);
        http_response_code(401);
        exit("Unauthorized - Invalid HMAC.");
    }

    $order = json_decode($rawPostData, true);
    if (!$order) {
        file_put_contents("$logBase/fatal-error.txt", "JSON decode failed\n", FILE_APPEND);
        exit;
    }

    $orderId = $order['id'];
    $clientOrderRef = "F" . $orderId;

    $shipping = $order['shipping_address'] ?? $order['billing_address'] ?? [];
    $mobile = $shipping['phone'] ?? $order['phone'] ?? '+96891119925';
    if (!preg_match('/^(?:\+968|00968)\d{8}$/', $mobile)) {
        $mobile = '+96891119925';
    }

    // Login to Asyad
    $loginPayload = ["username" => "sandbox", "password" => "hfLX8!@ErPI1m3qQ"];
    $loginCurl = curl_init("https://apix.stag.asyadexpress.com/login");
    curl_setopt_array($loginCurl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($loginPayload)
    ]);
    $loginResponse = curl_exec($loginCurl);
    curl_close($loginCurl);
    $loginData = json_decode($loginResponse, true);
    $token = $loginData['data']['token'] ?? null;
    if (!$token) exit;

    // Prepare Payload
    $lineItem = $order['line_items'][0] ?? [];
    $itemPrice = floatval($lineItem['price'] ?? $order['total_price']);
    $itemQty = intval($lineItem['quantity'] ?? 1);
    $payload = [
        "ClientOrderRef" => $clientOrderRef,
        "Description" => "Order #" . $orderId,
        "PaymentType" => "PREPAID",
        "CODAmount" => 0,
        "ShippingCost" => 0,
        "TotalAmount" => 0,
        "ShipmentProduct" => "EXPRESS",
        "ShipmentService" => "ALL_DAY",
        "OrderType" => "DROPOFF",
        "PickupType" => "NEXTDAY",
        "PickupDate" => date('Y-m-d', strtotime('+1 day')),
        "TotalShipmentValue" => $itemPrice * $itemQty,
        "JourneyOptions" => [],
        "items" => [[
            "description" => $lineItem['title'] ?? 'Test Product',
            "sku" => "test020202022",
            "quantity" => $itemQty,
            "price" => $itemPrice,
            "hs_code" => "2106",
            "country_of_origin" => "OM"
        ]],
        "Consignee" => [
            "Name" => $shipping['name'] ?? 'Unknown',
            "MobileNo" => $mobile,
            "Email" => $order['email'] ?? 'test@example.com',
            "AddressLine1" => substr($shipping['address1'] ?? 'Unknown Address', 0, 35),
            "City" => $shipping['city'] ?? 'Muscat',
            "Country" => $shipping['country'] ?? 'Oman',
            "ZipCode" => $shipping['zip'] ?? '111',
            "Latitude" => $shipping['latitude'] ?? '23.5880',
            "Longitude" => $shipping['longitude'] ?? '58.3829'
        ],
        "Shipper" => [
            "ReturnAsSame" => true,
            "ContactName" => "Aziz Honey",
            "AddressLine1" => "PO Box 869, Muscat",
            "City" => "Muscat",
            "Country" => "Oman",
            "MobileNo" => "+96891119925",
            "Email" => "admin@azizhoney.com",
            "ZipCode" => "111",
            "Area" => "Muscat",
            "Region" => "Muscat",
            "Latitude" => "23.5880",
            "Longitude" => "58.3829"
        ],
        "PackageDetails" => [["Weight" => 1, "Width" => 10, "Length" => 10, "Height" => 5]]
    ];

    file_put_contents("$logBase/order-create.payload.json", json_encode($payload, JSON_PRETTY_PRINT));

    // Send Order to Asyad
    $orderCreate = curl_init("https://apix.stag.asyadexpress.com/v2/orders/fulfillment");
    curl_setopt_array($orderCreate, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    $orderResponse = curl_exec($orderCreate);
    curl_close($orderCreate);
    file_put_contents("$logBase/order-response.json", $orderResponse);

    $orderData = json_decode($orderResponse, true);
    $awbTracking = $orderData['data']['order_awb_number'] ?? null;

    if (!$awbTracking && str_contains($orderData['message'] ?? '', 'Order exists in system')) {
        $awbTracking = $orderData['data']['consigment_number'] ?? $clientOrderRef;
    }

    // Retry for AWB Label
    $labelUrl = null;
    if ($awbTracking) {
        for ($i = 0; $i < 8; $i++) {
            sleep(5);
            $awbUrl = "https://apix.stag.asyadexpress.com/v2/orders/$awbTracking/awb";
            $awbCurl = curl_init($awbUrl);
            curl_setopt_array($awbCurl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json'
                ]
            ]);
            $awbResponse = curl_exec($awbCurl);
            curl_close($awbCurl);
            file_put_contents("$logBase/awb-retry-{$awbTracking}-$i.json", $awbResponse);
            $awbData = json_decode($awbResponse, true);
            $labelUrl = $awbData['data']['awb']['label4x4'] ?? $awbData['data']['awb']['a5'] ?? null;
            if ($labelUrl) break;
        }
    }

    // Update Shopify Metafield
    if ($labelUrl) {
        $metaPayload = [
            'metafield' => [
                'namespace' => 'custom',
                'key' => 'label_url',
                'value' => $labelUrl,
                'type' => 'url'
            ]
        ];
        $metaUrl = "https://$shopDomain/admin/api/2023-10/orders/$orderId/metafields.json";
        $metaCurl = curl_init($metaUrl);
        curl_setopt_array($metaCurl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "X-Shopify-Access-Token: $shopifyToken",
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($metaPayload)
        ]);
        $metaResponse = curl_exec($metaCurl);
        curl_close($metaCurl);
        file_put_contents("$logBase/shopify-metafield-response.json", $metaResponse);

        // Email Notification
        mail(
            'mugheesjatoi@gmail.com',
            'New Asyad Label Generated',
            "Label PDF available for Order #$orderId:\n$labelUrl",
            "From: noreply@azizhoney.com"
        );
    } else {
        file_put_contents("$logBase/label-missing.txt", "AWB: $awbTracking\nLabel not found.\n");
    }

} catch (Throwable $e) {
    file_put_contents(__DIR__ . '/fatal-error.txt', $e->getMessage() . "\n" . $e->getTraceAsString(), FILE_APPEND);
    http_response_code(500);
}
