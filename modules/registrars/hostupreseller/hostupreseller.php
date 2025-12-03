<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Carbon;
use WHMCS\Domain\Registrar\Domain as RegistrarDomain;

const HOSTUP_DEFAULT_BASE = "https://cloud.hostup.se";

/**
 * Module metadata - Required by WHMCS to display module info and config
 */
function hostupreseller_MetaData()
{
    return array(
        'DisplayName' => 'HostUp Domain Reseller',
        'APIVersion' => '1.1',
    );
}

/**
 * Module configuration
 * Note: FriendlyName is handled by MetaData() - do NOT include it here
 */
function hostupreseller_getConfigArray()
{
    return array(
        "apiBase" => array(
            "FriendlyName" => "API Base URL",
            "Type" => "text",
            "Size" => "50",
            "Default" => HOSTUP_DEFAULT_BASE,
            "Description" => "Root of the HostUp order API",
        ),
        "apiKey" => array(
            "FriendlyName" => "API Key",
            "Type" => "password",
            "Size" => "60",
            "Description" => "Bearer token with read/write domain scopes",
        ),
        "timeout" => array(
            "FriendlyName" => "Timeout (seconds)",
            "Type" => "text",
            "Size" => "5",
            "Default" => "30",
        ),
        "debug" => array(
            "FriendlyName" => "Enable Debug Logging",
            "Type" => "yesno",
            "Description" => "Log API request/response bodies to activity log",
        ),
    );
}

/**
 * Validate configuration before saving
 * Called by WHMCS when admin saves module settings
 */
function hostupreseller_config_validate($params)
{
    // API Key is required
    if (empty($params["apiKey"])) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration("API Key is required");
    }

    // Test API connectivity
    $base = rtrim($params["apiBase"] ?: HOSTUP_DEFAULT_BASE, "/");
    $ch = curl_init($base . "/api/domain-products");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Bearer " . $params["apiKey"],
        "Content-Type: application/json",
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration("Connection failed: " . $error);
    }

    if ($httpCode === 401 || $httpCode === 403) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration("Invalid API Key - authentication failed");
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration("API error (HTTP {$httpCode})");
    }
}

/**
 * Basic HTTP helper for JSON APIs
 */
function hostupreseller_http(array $params, $method, $path, $payload = null, array $query = array())
{
    $base = rtrim($params["apiBase"] ?: HOSTUP_DEFAULT_BASE, "/");
    $url = $base . $path;

    if (!empty($query)) {
        $url .= "?" . http_build_query($query);
    }

    $headers = array("Content-Type: application/json");
    if (!empty($params["apiKey"])) {
        $headers[] = "Authorization: Bearer " . $params["apiKey"];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $timeout = (int) ($params["timeout"] ?? 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout > 0 ? $timeout : 30);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $logPayload = array(
        "url" => $url,
        "method" => strtoupper($method),
        "query" => $query,
        "payload" => $payload,
    );
    $shouldLog = !empty($params["debug"]);

    if ($errno) {
        if ($shouldLog && function_exists("logModuleCall")) {
            logModuleCall(
                "hostupreseller",
                "{$method} {$path}",
                $logPayload,
                $raw,
                "HTTP request failed: " . $errno
            );
        }
        return array("success" => false, "error" => "HTTP request failed: " . $errno);
    }

    $data = json_decode($raw, true);
    if (!$data) {
        if ($shouldLog && function_exists("logModuleCall")) {
            logModuleCall(
                "hostupreseller",
                "{$method} {$path}",
                $logPayload,
                $raw,
                array(
                    "error" => "Unable to decode response",
                    "http_status" => $status,
                )
            );
        }
        return array(
            "success" => false,
            "error" => "Unable to decode response",
            "http_status" => $status,
            "raw" => $raw,
        );
    }

    $formatValidationErrors = function ($responseData) {
        if (empty($responseData["details"]) || !is_array($responseData["details"])) {
            return "";
        }

        $messages = array();
        foreach ($responseData["details"] as $detail) {
            $field = isset($detail["field"]) ? $detail["field"] : null;
            $message = isset($detail["message"]) ? $detail["message"] : null;

            if ($field && $message) {
                $messages[] = "{$field}: {$message}";
            } elseif ($message) {
                $messages[] = $message;
            }
        }

        return implode("; ", $messages);
    };

    $log = function ($label, $content) use ($params, $path, $method) {
        if (empty($params["debug"])) {
            return;
        }
        logActivity("[hostupreseller] {$method} {$path} {$label}: " . json_encode($content));
    };

    $log("request", $payload);
    $log("response", $data);

    if ($status >= 200 && $status < 300 && !empty($data["success"])) {
        if ($shouldLog && function_exists("logModuleCall")) {
            logModuleCall("hostupreseller", "{$method} {$path}", $logPayload, $data, "success");
        }
        return array("success" => true, "data" => ($data["data"] ?? $data));
    }

    $message = $data["message"] ?? $data["error"] ?? "Unknown error";
    $validationDetails = $formatValidationErrors($data);
    if (!empty($validationDetails)) {
        $message .= " (" . $validationDetails . ")";
    }

    if ($shouldLog && function_exists("logModuleCall")) {
        logModuleCall(
            "hostupreseller",
            "{$method} {$path}",
            $logPayload,
            $data,
            array(
                "http_status" => $status,
                "message" => $message,
            )
        );
    }

    return array(
        "success" => false,
        "error" => $message,
        "http_status" => $status,
        "body" => $data,
        "request_id" => $data["requestId"] ?? null,
    );
}

/**
 * Cache and resolve TLD → product id
 */
function hostupreseller_getProductId(array $params, $tld)
{
    static $cache = null;

    if ($cache === null) {
        $resp = hostupreseller_http($params, "GET", "/api/domain-products");
        if (!$resp["success"]) {
            return array(null, $resp["error"]);
        }

        $cache = array();
        $products = $resp["data"]["tlds"] ?? array();
        foreach ($products as $product) {
            if (!empty($product["tld"]) && !empty($product["productId"])) {
                $cache[strtolower($product["tld"])] = $product["productId"];
            }
        }
    }

    $key = "." . ltrim(strtolower($tld), ".");
    if (!isset($cache[$key])) {
        return array(null, "No product id found for TLD {$key}");
    }

    return array($cache[$key], null);
}

function hostupreseller_collectNameservers(array $params)
{
    $nameservers = array();
    for ($i = 1; $i <= 5; $i++) {
        $key = "ns{$i}";
        if (!empty($params[$key])) {
            $nameservers[] = trim($params[$key]);
        }
    }
    return $nameservers;
}

function hostupreseller_getIdentificationNumber(array $params)
{
    $additional = $params["additionalfields"] ?? array();
    $value = null;

    if (is_array($additional)) {
        foreach ($additional as $key => $candidate) {
            if (is_string($key) && stripos($key, "identification") !== false) {
                $trimmed = is_string($candidate) ? trim($candidate) : "";
                if ($trimmed !== "") {
                    $value = $trimmed;
                    break;
                }
            }
        }
    }

    if ($value) {
        return $value;
    }

    return null;
}

function hostupreseller_tldSupportsOrgno($tld)
{
    $normalized = ltrim(strtolower((string) $tld), ".");
    return in_array($normalized, array("se", "nu"), true);
}

function hostupreseller_formatOrgnoForDisplay($orgno)
{
    if (!is_string($orgno)) {
        return "";
    }

    $value = trim($orgno);
    $country = null;

    // Strip leading country tag like [SE]
    if (preg_match('/^\[([A-Za-z]{2,})\](.*)$/', $value, $matches)) {
        $country = strtoupper($matches[1]);
        $value = ltrim($matches[2]);
    }

    // Format Swedish numbers with dash (YYMMDD-XXXX or XXXXXX-XXXX)
    if ($country === "SE" || $country === null) {
        $digits = preg_replace('/[^0-9]/', '', $value);
        if (strlen($digits) === 12) {
            // YYYYMMDDXXXX → YYMMDD-XXXX
            return substr($digits, 2, 6) . "-" . substr($digits, 8);
        }
        if (strlen($digits) === 10) {
            // YYMMDDXXXX → YYMMDD-XXXX
            return substr($digits, 0, 6) . "-" . substr($digits, 6);
        }
    }

    return $value;
}

function hostupreseller_formatOrgnoForApi($orgno, $tld)
{
    $value = is_string($orgno) ? trim($orgno) : "";
    if ($value === "") {
        return "";
    }

    // If already tagged, send as-is
    if (preg_match('/^\[[A-Za-z]{2,}\]/', $value)) {
        return $value;
    }

    // For supported TLDs, tag with SE and keep human-friendly formatting
    if (hostupreseller_tldSupportsOrgno($tld)) {
        $formatted = hostupreseller_formatOrgnoForDisplay($value);
        return "[SE]" . $formatted;
    }

    return $value;
}

function hostupreseller_extractNameservers(array $details)
{
    // Prefer explicit nameservers array
    if (!empty($details["nameservers"]) && is_array($details["nameservers"])) {
        $clean = array();
        foreach ($details["nameservers"] as $ns) {
            if ($ns === null) {
                continue;
            }
            $ns = trim($ns);
            if ($ns === "") {
                continue;
            }
            $clean[] = $ns;
            if (count($clean) >= 5) {
                break;
            }
        }
        return $clean;
    }

    $nameservers = array();
    for ($i = 1; $i <= 5; $i++) {
        $key = "ns{$i}";
        if (!empty($details[$key])) {
            $nameservers[] = trim($details[$key]);
        }
    }
    return $nameservers;
}

function hostupreseller_buildContact(array $params)
{
    $isOrganisation = !empty($params["companyname"]);
    $identificationNumber = hostupreseller_getIdentificationNumber($params);

    return array(
        "type" => $isOrganisation ? "organisation" : "private",
        "firstname" => $params["firstname"] ?? "",
        "lastname" => $params["lastname"] ?? "",
        "companyname" => $params["companyname"] ?? "",
        "orgno" => $identificationNumber ?? ($params["companyid"] ?? ($params["tax_id"] ?? "")),
        "address1" => $params["address1"] ?? "",
        "address2" => $params["address2"] ?? "",
        "city" => $params["city"] ?? "",
        "state" => $params["state"] ?? "",
        "postcode" => $params["postcode"] ?? "",
        "country" => $params["countrycode"] ?? "",
        "email" => $params["email"] ?? "",
        "phonenumber" => $params["phonenumber"] ?? "",
    );
}

function hostupreseller_buildClientData(array $params)
{
    $contact = hostupreseller_buildContact($params);
    $password = bin2hex(random_bytes(8));

    return array(
        "firstname" => $contact["firstname"],
        "lastname" => $contact["lastname"],
        "email" => $contact["email"],
        "password" => $password,
        "passwordConfirm" => $password,
        "companyname" => $contact["companyname"],
        "address1" => $contact["address1"],
        "address2" => $contact["address2"],
        "city" => $contact["city"],
        "state" => $contact["state"],
        "postcode" => $contact["postcode"],
        "country" => $contact["country"],
        "phonenumber" => $contact["phonenumber"],
        "orgno" => $contact["orgno"],
        "accountType" => $contact["companyname"] ? "organisation" : "private",
        // Turnstile/email verification is enforced by the API; this payload assumes server-to-server allowance.
    );
}

function hostupreseller_domainString(array $params)
{
    $tld = ltrim($params["tld"], ".");
    return $params["sld"] . "." . $tld;
}

function hostupreseller_findDomainId(array $params, $fqdn)
{
    $resp = hostupreseller_http(
        $params,
        "GET",
        "/api/client-domains",
        null,
        array("page" => 0, "limit" => 1000)
    );

    if (!$resp["success"]) {
        return array(null, $resp["error"]);
    }

    $domains = $resp["data"]["domains"] ?? array();
    foreach ($domains as $domain) {
        if (isset($domain["name"]) && strcasecmp($domain["name"], $fqdn) === 0) {
            $id = $domain["id"] ?? $domain["domainid"] ?? null;
            if ($id !== null) {
                return array($id, null);
            }
        }
    }

    return array(null, "Domain {$fqdn} not found for this API key");
}

function hostupreseller_normalizeExpiry(array $details)
{
    $candidates = array(
        $details["expires"] ?? null,
        $details["expirydate"] ?? null,
        $details["next_due"] ?? null,
    );

    foreach ($candidates as $candidate) {
        if ($candidate === null) {
            continue;
        }
        if (is_string($candidate)) {
            $trimmed = trim($candidate);
            if (
                $trimmed === "" ||
                $trimmed === "0000-00-00" ||
                $trimmed === "0000-00-00 00:00:00"
            ) {
                continue;
            }

            $ts = @strtotime($trimmed);
            if ($ts !== false && $ts > 0) {
                return date("Y-m-d", $ts);
            }
        }
    }

    // Fallback to one year from today to avoid WHMCS "Invalid Response"
    return date("Y-m-d", strtotime("+1 year"));
}

function hostupreseller_stripOuterQuotes($value)
{
    if (!is_string($value)) {
        return $value;
    }

    $trimmed = trim($value);
    $first = substr($trimmed, 0, 1);
    $last = substr($trimmed, -1);

    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
        return substr($trimmed, 1, -1);
    }

    return $trimmed;
}

function hostupreseller_isSupportedDnsType($type)
{
    $typeUpper = strtoupper(trim((string) $type));

    return in_array($typeUpper, array("A", "AAAA", "MX", "CNAME", "TXT"), true);
}

function hostupreseller_hostnameFromRecordName($name, $zoneDomain)
{
    $cleanName = rtrim((string) $name, ".");
    $cleanZone = rtrim((string) $zoneDomain, ".");

    if ($cleanName === "" || strcasecmp($cleanName, $cleanZone) === 0) {
        return "";
    }

    $suffix = "." . $cleanZone;
    if ($cleanZone !== "" && strlen($cleanName) > strlen($suffix) && strcasecmp(substr($cleanName, -strlen($suffix)), $suffix) === 0) {
        return substr($cleanName, 0, -strlen($suffix));
    }

    return $cleanName;
}

function hostupreseller_dnsNameToRelative($hostname, $zoneDomain)
{
    $name = rtrim(trim((string) $hostname), ".");
    $zone = rtrim((string) $zoneDomain, ".");

    if ($name === "" || $name === "@") {
        return "";
    }

    if ($zone !== "") {
        if (strcasecmp($name, $zone) === 0) {
            return "";
        }

        $suffix = "." . $zone;
        if (strlen($name) > strlen($suffix) && strcasecmp(substr($name, -strlen($suffix)), $suffix) === 0) {
            return substr($name, 0, -strlen($suffix));
        }
    }

    return $name;
}

function hostupreseller_formatDnsRecordForWhmcs(array $record, $zoneDomain)
{
    $name = isset($record["name"]) ? $record["name"] : "";
    $hostname = hostupreseller_hostnameFromRecordName($name, $zoneDomain);

    $value = $record["value"] ?? ($record["content"] ?? "");
    $priority = $record["priority"] ?? ($record["prio"] ?? null);

    return array(
        "hostname" => $hostname,
        "type" => $record["type"] ?? "",
        "address" => hostupreseller_stripOuterQuotes($value),
        "priority" => $priority !== null ? (string) $priority : null,
        "recid" => isset($record["id"]) ? (string) $record["id"] : "",
    );
}

function hostupreseller_buildDnsPayloadFromWhmcs(array $record, $zoneDomain)
{
    $type = strtoupper(trim((string) ($record["type"] ?? "")));
    $address = isset($record["address"]) ? trim((string) $record["address"]) : "";

    if ($type === "" || $address === "") {
        return array("success" => false, "error" => "Type and address are required for DNS records");
    }

    $hostname = isset($record["hostname"]) ? $record["hostname"] : "";
    $name = hostupreseller_dnsNameToRelative($hostname, $zoneDomain);

    $priorityRaw = $record["priority"] ?? null;
    $priority = null;
    if ($priorityRaw !== null && $priorityRaw !== "" && strcasecmp((string) $priorityRaw, "N/A") !== 0) {
        if (is_numeric($priorityRaw)) {
            $priority = (int) $priorityRaw;
        }
    }

    $payload = array(
        "type" => $type,
        "value" => hostupreseller_stripOuterQuotes($address),
    );

    // Root records use empty string; API will normalize to FQDN
    if ($name !== "") {
        $payload["name"] = $name;
    } else {
        $payload["name"] = "";
    }

    if ($priority !== null) {
        $payload["priority"] = $priority;
    }

    if (isset($record["ttl"]) && is_numeric($record["ttl"])) {
        $payload["ttl"] = (int) $record["ttl"];
    }

    return array("success" => true, "payload" => $payload);
}

function hostupreseller_dnsNeedsUpdate(array $existing, array $desired, $zoneDomain)
{
    $existingName = hostupreseller_hostnameFromRecordName($existing["name"] ?? "", $zoneDomain);
    $desiredName = $desired["name"] ?? "";

    $existingValue = hostupreseller_stripOuterQuotes($existing["value"] ?? ($existing["content"] ?? ""));
    $desiredValue = hostupreseller_stripOuterQuotes($desired["value"] ?? "");

    $existingType = strtoupper(trim((string) ($existing["type"] ?? "")));
    $desiredType = strtoupper(trim((string) ($desired["type"] ?? "")));

    $existingPriority = $existing["priority"] ?? ($existing["prio"] ?? null);
    $desiredPriority = $desired["priority"] ?? null;

    if ($existingType !== $desiredType) {
        return true;
    }

    if ($existingName !== $desiredName) {
        return true;
    }

    if ($existingValue !== $desiredValue) {
        return true;
    }

    // Normalize priority comparison
    $existingPriorityStr = $existingPriority !== null ? (string) $existingPriority : "";
    $desiredPriorityStr = $desiredPriority !== null ? (string) $desiredPriority : "";

    return $existingPriorityStr !== $desiredPriorityStr;
}

/**
 * Register domain
 */
function hostupreseller_RegisterDomain($params)
{
    $shouldLog = !empty($params["debug"]) && function_exists("logModuleCall");
    $payload = null;

    try {
        $domain = hostupreseller_domainString($params);
        list($productId, $productErr) = hostupreseller_getProductId($params, $params["tld"]);
        if (!$productId) {
            return array("error" => $productErr);
        }

        $nameservers = hostupreseller_collectNameservers($params);
        $contact = hostupreseller_buildContact($params);
        $clientData = hostupreseller_buildClientData($params);

        $payload = array(
            "clientData" => $clientData,
            "cartItems" => array(
                array(
                    "type" => "register",
                    "domain" => $domain,
                    "productId" => (string) $productId,
                    "years" => (int) ($params["regperiod"] ?? 1),
                    "nameserverOption" => count($nameservers) > 0 ? "custom" : "default",
                    "nameservers" => $nameservers,
                    "registrantContact" => $contact,
                ),
            ),
            "attemptKey" => "whmcs-register-" . uniqid(),
        );

        $resp = hostupreseller_http($params, "POST", "/api/create-order", $payload);
        if (!$resp["success"]) {
            if ($shouldLog) {
                logModuleCall(
                    "hostupreseller",
                    "RegisterDomain",
                    $payload,
                    $resp,
                    $resp["error"]
                );
            }
            return array("error" => $resp["error"]);
        }

        // Verify the domain actually exists and is active
        list($domainId, $findErr) = hostupreseller_findDomainId($params, $domain);
        if (!$domainId) {
            $message = "Order created, but domain not found: " . $findErr;
            if ($shouldLog) {
                logModuleCall(
                    "hostupreseller",
                    "RegisterDomain",
                    $payload,
                    $resp,
                    $message
                );
            }
            return array("error" => $message);
        }

        $detailsResp = hostupreseller_http($params, "GET", "/api/domain-details/" . $domainId);
        if ($detailsResp["success"]) {
            $details = $detailsResp["data"]["details"] ?? array();
            $status = strtoupper($details["status"] ?? "");
            $orderId = $details["order_id"] ?? null;
            $isActive = in_array($status, array("ACTIVE", "OK"));

            if (!$isActive) {
                // Allow pending/in-progress orders to pass; WHMCS will sync later
                $pendingStatuses = array("PENDING", "IN_PROGRESS", "PROCESSING");
                if (in_array($status, $pendingStatuses)) {
                    $message = "Domain pending (status: {$status})";
                    if ($orderId) {
                        $message .= " - Hostup order {$orderId}";
                    }
                    if ($shouldLog) {
                        logModuleCall(
                            "hostupreseller",
                            "RegisterDomain",
                            $payload,
                            $detailsResp,
                            $message
                        );
                    }
                    return array(
                        "success" => true,
                        "pending" => true,
                        "rawdata" => $resp["data"],
                        "orderid" => $orderId,
                        "status" => $status,
                    );
                }

                $message = "Domain not active (status: {$status})";
                if ($orderId) {
                    $message .= " - Hostup order {$orderId}";
                }
                if ($shouldLog) {
                    logModuleCall(
                        "hostupreseller",
                        "RegisterDomain",
                        $payload,
                        $detailsResp,
                        $message
                    );
                }
                return array("error" => $message);
            }
        } else {
            if ($shouldLog) {
                logModuleCall(
                    "hostupreseller",
                    "RegisterDomain",
                    $payload,
                    $detailsResp,
                    $detailsResp["error"]
                );
            }
            return array("error" => $detailsResp["error"]);
        }

        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "RegisterDomain",
                $payload,
                $resp,
                "success"
            );
        }

        return array("success" => true, "rawdata" => $resp["data"]);
    } catch (\Throwable $e) {
        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "RegisterDomain",
                $payload ?: array(),
                null,
                "Exception: " . $e->getMessage()
            );
        }
        return array("error" => "Unexpected error: " . $e->getMessage());
    }
}

/**
 * Transfer domain
 */
function hostupreseller_TransferDomain($params)
{
    $shouldLog = !empty($params["debug"]) && function_exists("logModuleCall");
    $payload = null;

    $domain = hostupreseller_domainString($params);
    list($productId, $productErr) = hostupreseller_getProductId($params, $params["tld"]);
    if (!$productId) {
        return array("error" => $productErr);
    }

    $nameservers = hostupreseller_collectNameservers($params);
    $contact = hostupreseller_buildContact($params);
    $clientData = hostupreseller_buildClientData($params);
    $epp = $params["transfersecret"] ?? $params["eppcode"] ?? "";

    $payload = array(
        "clientData" => $clientData,
        "cartItems" => array(
            array(
                "type" => "transfer",
                "domain" => $domain,
                "productId" => (string) $productId,
                "years" => (int) ($params["regperiod"] ?? 1),
                "eppCode" => $epp,
                "nameserverOption" => count($nameservers) > 0 ? "custom" : "default",
                "nameservers" => $nameservers,
                "registrantContact" => $contact,
            ),
        ),
        "attemptKey" => "whmcs-transfer-" . uniqid(),
    );

    $resp = hostupreseller_http($params, "POST", "/api/create-order", $payload);
    if (!$resp["success"]) {
        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "TransferDomain",
                $payload,
                $resp,
                $resp["error"]
            );
        }
        return array("error" => $resp["error"]);
    }

    // Double-check domain status to avoid false positives when Hostup fails registration
    list($domainId, $findErr) = hostupreseller_findDomainId($params, $domain);
    if (!$domainId) {
        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "TransferDomain",
                $payload,
                $resp,
                "Domain not found after order creation: " . $findErr
            );
        }
        return array("error" => "Order created, but domain not found: " . $findErr);
    }

    $detailsResp = hostupreseller_http($params, "GET", "/api/domain-details/" . $domainId);
    if ($detailsResp["success"]) {
        $details = $detailsResp["data"]["details"] ?? array();
        $status = strtoupper($details["status"] ?? "");
        $orderId = $details["order_id"] ?? null;
        $isActive = in_array($status, array("ACTIVE", "OK"));

        if (!$isActive) {
            $message = "Domain not active (status: {$status})";
            if ($orderId) {
                $message .= " - Hostup order {$orderId}";
            }
            if ($shouldLog) {
                logModuleCall(
                    "hostupreseller",
                    "TransferDomain",
                    $payload,
                    $detailsResp,
                    $message
                );
            }
            return array("error" => $message);
        }
    } else {
        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "TransferDomain",
                $payload,
                $detailsResp,
                $detailsResp["error"]
            );
        }
        return array("error" => $detailsResp["error"]);
    }

    if ($shouldLog) {
        logModuleCall(
            "hostupreseller",
            "TransferDomain",
            $payload,
            $resp,
            "success"
        );
    }

    return array("success" => true, "rawdata" => $resp["data"]);
}

/**
 * Renew domain
 */
function hostupreseller_RenewDomain($params)
{
    $domain = hostupreseller_domainString($params);
    list($domainId, $err) = hostupreseller_findDomainId($params, $domain);
    if (!$domainId) {
        return array("error" => $err);
    }

    $resp = hostupreseller_http($params, "POST", "/api/domain-renew/" . $domainId);
    if (!$resp["success"]) {
        return array("error" => $resp["error"]);
    }

    return array("success" => true, "rawdata" => $resp["data"]);
}

/**
 * Sync and return domain information
 */
function hostupreseller_GetDomainInformation($params)
{
    $shouldLog = !empty($params["debug"]) && function_exists("logModuleCall");
    $domain = hostupreseller_domainString($params);
    $logCtx = array("domain" => $domain);

    try {
        if ($shouldLog) {
            logModuleCall("hostupreseller", "GetDomainInformation:start", $logCtx, null, null);
        }

        list($domainId, $err) = hostupreseller_findDomainId($params, $domain);
        if (!$domainId) {
            throw new \RuntimeException($err ?: "Domain {$domain} not found for this API key");
        }

        $resp = hostupreseller_http($params, "GET", "/api/domain-details/" . $domainId);
        if (!$resp["success"]) {
            throw new \RuntimeException($resp["error"] ?? "Unable to fetch domain details");
        }

        $details = $resp["data"]["details"] ?? array();
        $status = strtoupper($details["status"] ?? "ACTIVE");
        $expiry = hostupreseller_normalizeExpiry($details);
        $nameservers = hostupreseller_extractNameservers($details);
        $nameserversAssoc = array(
            "ns1" => $nameservers[0] ?? "",
            "ns2" => $nameservers[1] ?? "",
            "ns3" => $nameservers[2] ?? "",
            "ns4" => $nameservers[3] ?? "",
            "ns5" => $nameservers[4] ?? "",
        );

        $transferLock = ($details["reglock"] ?? $details["registry_autorenew"] ?? "0") == "1";
        $idProtection = ($details["idprotection"] ?? "0") == "1";

        $domainObj = new RegistrarDomain();
        $domainObj
            ->setDomain($domain)
            ->setNameservers($nameserversAssoc)
            ->setRegistrationStatus($status)
            ->setTransferLock($transferLock)
            ->setExpiryDate(Carbon::createFromFormat("Y-m-d", $expiry))
            ->setIdProtectionStatus($idProtection);

        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "GetDomainInformation:success",
                $logCtx,
                $resp,
                array(
                    "status" => $status,
                    "expirydate" => $expiry,
                    "nameservers" => $nameservers,
                    "transferlock" => $transferLock,
                    "idprotection" => $idProtection,
                )
            );
        }

        return $domainObj;
    } catch (\Throwable $e) {
        if ($shouldLog) {
            logModuleCall("hostupreseller", "GetDomainInformation:error", $logCtx, null, $e->getMessage());
        }
        throw $e;
    }
}

/**
 * Get nameservers
 */
function hostupreseller_GetNameservers($params)
{
    $shouldLog = !empty($params["debug"]) && function_exists("logModuleCall");
    $domain = hostupreseller_domainString($params);
    $logCtx = array("domain" => $domain);

    if ($shouldLog) {
        logModuleCall("hostupreseller", "GetNameservers:start", $logCtx, null, null);
    }

    list($domainId, $err) = hostupreseller_findDomainId($params, $domain);
    if (!$domainId) {
        if ($shouldLog) {
            logModuleCall("hostupreseller", "GetNameservers:error", $logCtx, null, $err);
        }
        return array("error" => $err);
    }

    $resp = hostupreseller_http($params, "GET", "/api/domain-details/" . $domainId);
    if (!$resp["success"]) {
        if ($shouldLog) {
            logModuleCall("hostupreseller", "GetNameservers:error", $logCtx, $resp, $resp["error"]);
        }
        return array("error" => $resp["error"]);
    }

    $details = $resp["data"]["details"] ?? array();
    $nameservers = array();

    if (!empty($details["nameservers"]) && is_array($details["nameservers"])) {
        $nameservers = $details["nameservers"];
    } else {
        for ($i = 1; $i <= 5; $i++) {
            $key = "ns{$i}";
            if (!empty($details[$key])) {
                $nameservers[] = $details[$key];
            }
        }
    }

    $result = array(
        "ns1" => $nameservers[0] ?? "",
        "ns2" => $nameservers[1] ?? "",
        "ns3" => $nameservers[2] ?? "",
        "ns4" => $nameservers[3] ?? "",
        "ns5" => $nameservers[4] ?? "",
    );

    if ($shouldLog) {
        logModuleCall("hostupreseller", "GetNameservers:success", $logCtx, $resp, $result);
    }

    return $result;
}

/**
 * Save nameservers
 */
function hostupreseller_SaveNameservers($params)
{
    $domain = hostupreseller_domainString($params);
    list($domainId, $err) = hostupreseller_findDomainId($params, $domain);
    if (!$domainId) {
        return array("error" => $err);
    }

    $nameservers = hostupreseller_collectNameservers($params);
    if (count($nameservers) < 2) {
        return array("error" => "At least two nameservers are required");
    }

    $payload = array(
        "nameservers" => $nameservers,
    );

    $resp = hostupreseller_http($params, "POST", "/api/domains/" . $domainId . "/nameservers", $payload);
    if (!$resp["success"]) {
        return array("error" => $resp["error"]);
    }

    return array("success" => true);
}

/**
 * Get contact details
 */
function hostupreseller_GetContactDetails($params)
{
    $domain = hostupreseller_domainString($params);
    list($domainId, $err) = hostupreseller_findDomainId($params, $domain);
    if (!$domainId) {
        return array("error" => $err);
    }

    $resp = hostupreseller_http($params, "GET", "/api/domain-contacts/" . $domainId);
    if (!$resp["success"]) {
        return array("error" => $resp["error"]);
    }

    $contact = $resp["data"]["contacts"]["registrant"] ?? $resp["data"]["contacts"] ?? array();

    $fields = array(
        "First Name" => $contact["firstname"] ?? "",
        "Last Name" => $contact["lastname"] ?? "",
        "Company Name" => $contact["companyname"] ?? "",
        "Email Address" => $contact["email"] ?? "",
        "Address 1" => $contact["address1"] ?? "",
        "City" => $contact["city"] ?? "",
        "State" => $contact["state"] ?? "",
        "Postcode" => $contact["postcode"] ?? "",
        "Country" => $contact["country"] ?? "",
        "Phone Number" => $contact["phonenumber"] ?? "",
    );

    // Expose organisation/person number on supported TLDs so it can be edited in WHMCS
    if (hostupreseller_tldSupportsOrgno($params["tld"] ?? "")) {
        $label = !empty($contact["companyname"]) ? "Organisation Number" : "Personnummer";
        $fields[$label] = hostupreseller_formatOrgnoForDisplay($contact["orgno"] ?? "");
    }

    return array(
        "Registrant" => $fields,
    );
}

/**
 * Save contact details
 */
function hostupreseller_SaveContactDetails($params)
{
    $domain = hostupreseller_domainString($params);
    list($domainId, $err) = hostupreseller_findDomainId($params, $domain);
    if (!$domainId) {
        return array("error" => $err);
    }

    $details = $params["contactdetails"]["Registrant"] ?? array();

    $update = array(
        "firstname" => $details["First Name"] ?? "",
        "lastname" => $details["Last Name"] ?? "",
        "companyname" => $details["Company Name"] ?? "",
        "email" => $details["Email Address"] ?? "",
        "address1" => $details["Address 1"] ?? "",
        "city" => $details["City"] ?? "",
        "state" => $details["State"] ?? "",
        "postcode" => $details["Postcode"] ?? "",
        "country" => $details["Country"] ?? "",
        "phonenumber" => $details["Phone Number"] ?? "",
    );

    // Map organisation/person number back to API for supported TLDs
    if (hostupreseller_tldSupportsOrgno($params["tld"] ?? "")) {
        $orgnoInput = null;
        if (isset($details["Organisation Number"])) {
            $orgnoInput = $details["Organisation Number"];
        } elseif (isset($details["Personnummer"])) {
            $orgnoInput = $details["Personnummer"];
        }
        if ($orgnoInput !== null) {
            $update["orgno"] = hostupreseller_formatOrgnoForApi($orgnoInput, $params["tld"] ?? "");
        }
    }

    $payload = array("updateContactInfo" => $update);

    $resp = hostupreseller_http($params, "POST", "/api/domain-contacts/" . $domainId, $payload);
    if (!$resp["success"]) {
        return array("error" => $resp["error"]);
    }

    return array("success" => true);
}

/**
 * Get EPP/auth code
 */
function hostupreseller_GetEPPCode($params)
{
    $domain = hostupreseller_domainString($params);
    list($domainId, $err) = hostupreseller_findDomainId($params, $domain);
    if (!$domainId) {
        return array("error" => $err);
    }

    $resp = hostupreseller_http($params, "POST", "/api/domain-epp/" . $domainId);
    if (!$resp["success"]) {
        return array("error" => $resp["error"]);
    }

    $code = $resp["data"]["epp_code"] ?? ($resp["data"]["message"] ?? "");
    return array("eppcode" => $code);
}

/**
 * Availability check using queued domain-check endpoint
 * Polls until job completes or timeout (max 15 seconds)
 * Returns WHMCS ResultsList with SearchResult objects
 */
function hostupreseller_CheckAvailability($params)
{
    // Get search term and TLDs from WHMCS params
    $searchTerm = $params["searchTerm"] ?? ($params["sld"] ?? "");
    $tldsToInclude = $params["tldsToInclude"] ?? array();
    $premiumEnabled = (bool) ($params["premiumEnabled"] ?? false);

    // Fallback for older WHMCS or direct calls
    if (empty($tldsToInclude)) {
        $tldsToInclude = isset($params["tlds"]) && is_array($params["tlds"])
            ? $params["tlds"]
            : array("." . ltrim($params["tld"] ?? "", "."));
    }

    // Normalize TLDs to have leading dot
    $tlds = array();
    foreach ($tldsToInclude as $tld) {
        $tlds[] = "." . ltrim($tld, ".");
    }

    $payload = array("sld" => $searchTerm, "tlds" => $tlds);
    $resp = hostupreseller_http($params, "POST", "/api/domain-check", $payload);
    if (!$resp["success"]) {
        throw new \RuntimeException("Domain check failed: " . ($resp["error"] ?? "unknown error"));
    }

    $jobId = $resp["data"]["jobId"] ?? null;
    if (!$jobId) {
        throw new \RuntimeException("Domain check job not created");
    }

    // Poll until completed or timeout (max 15 seconds, poll every 500ms)
    $maxAttempts = 30;
    $apiResults = array();
    $jobStatus = "pending";

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        usleep(500000); // 500ms between polls

        $statusResp = hostupreseller_http($params, "GET", "/api/domain-check/" . $jobId);
        if (!$statusResp["success"]) {
            continue;
        }

        $jobStatus = $statusResp["data"]["status"] ?? "pending";
        $apiResults = $statusResp["data"]["results"] ?? array();

        if ($jobStatus === "completed" || !empty($apiResults)) {
            break;
        }
    }

    if (empty($apiResults)) {
        throw new \RuntimeException("Domain availability check timed out - please try again");
    }

    // Build WHMCS ResultsList with SearchResult objects
    $results = new ResultsList();

    foreach ($apiResults as $item) {
        $domainName = $item["domain"] ?? "";
        if (empty($domainName)) {
            continue;
        }

        // Split domain into SLD and TLD
        $dotPos = strpos($domainName, ".");
        if ($dotPos === false) {
            continue;
        }
        $sld = substr($domainName, 0, $dotPos);
        $tld = substr($domainName, $dotPos + 1);

        $searchResult = new SearchResult($sld, $tld);

        // Map status
        $itemStatus = strtolower($item["status"] ?? "");
        if ($itemStatus === "available") {
            $searchResult->setStatus(SearchResult::STATUS_NOT_REGISTERED);
        } elseif ($itemStatus === "registered" || $itemStatus === "unavailable") {
            $searchResult->setStatus(SearchResult::STATUS_REGISTERED);
        } elseif ($itemStatus === "reserved") {
            $searchResult->setStatus(SearchResult::STATUS_RESERVED);
        } else {
            $searchResult->setStatus(SearchResult::STATUS_TLD_NOT_SUPPORTED);
        }

        // Handle premium domains if enabled
        if ($premiumEnabled && !empty($item["premium"])) {
            $searchResult->setPremiumDomain(true);
            $registerPrice = $item["price"] ?? ($item["periods"]["1"]["register"] ?? 0);
            $renewPrice = $item["renewalPrice"] ?? ($item["periods"]["1"]["renew"] ?? 0);
            $searchResult->setPremiumCostPricing(array(
                "register" => $registerPrice,
                "renew" => $renewPrice,
                "CurrencyCode" => "SEK",
            ));
        }

        $results->append($searchResult);
    }

    return $results;
}

/**
 * TLD pricing not exposed via API; return empty to let WHMCS keep manual pricing
 */
function hostupreseller_GetTldPricing($params)
{
    return array("error" => "TLD pricing is managed in HostUp; configure pricing in WHMCS");
}

// Optional functions not supported by HostUp API today
function hostupreseller_GetRegistrarLock($params)
{
    return array("error" => "Registrar lock management is not supported via API");
}

function hostupreseller_SaveRegistrarLock($params)
{
    return array("error" => "Registrar lock management is not supported via API");
}

function hostupreseller_GetDNS($params)
{
    $shouldLog = !empty($params["debug"]) && function_exists("logModuleCall");
    $domain = hostupreseller_domainString($params);
    $logCtx = array("domain" => $domain);

    // 1) Resolve DNS zone for the domain
    $zoneResp = hostupreseller_http(
        $params,
        "GET",
        "/api/dns/domain/" . rawurlencode($domain)
    );

    if (!$zoneResp["success"]) {
        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "GetDNS:zone_lookup",
                $logCtx,
                $zoneResp,
                $zoneResp["error"] ?? "Zone lookup failed"
            );
        }
        return array("error" => $zoneResp["error"] ?? "Unable to fetch DNS zone");
    }

    $zone = $zoneResp["data"]["zone"] ?? $zoneResp["data"] ?? array();
    $zoneId = $zone["id"] ?? $zone["domain_id"] ?? $zone["zoneId"] ?? null;
    $zoneDomain = $zone["domain"] ?? $domain;

    if (!$zoneId) {
        return array("error" => "DNS zone not found for domain");
    }

    // 2) Fetch records for the zone
    $recordsResp = hostupreseller_http(
        $params,
        "GET",
        "/api/dns/zones/" . rawurlencode($zoneId) . "/records"
    );

    if (!$recordsResp["success"]) {
        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "GetDNS:records",
                $logCtx,
                $recordsResp,
                $recordsResp["error"] ?? "Failed to fetch DNS records"
            );
        }
        return array("error" => $recordsResp["error"] ?? "Failed to fetch DNS records");
    }

    $zoneData = $recordsResp["data"]["zone"] ?? $recordsResp["data"] ?? array();
    $records = $zoneData["records"] ?? ($recordsResp["data"]["records"] ?? array());

    $result = array();
    foreach ($records as $record) {
        $type = strtoupper((string) ($record["type"] ?? ""));
        // Only expose record types that WHMCS DNS UI can manage
        if (!hostupreseller_isSupportedDnsType($type)) {
            continue;
        }
        $result[] = hostupreseller_formatDnsRecordForWhmcs($record, $zoneDomain);
    }

    if ($shouldLog) {
        logModuleCall(
            "hostupreseller",
            "GetDNS:success",
            $logCtx,
            null,
            array("count" => count($result))
        );
    }

    return $result;
}

function hostupreseller_SaveDNS($params)
{
    $shouldLog = !empty($params["debug"]) && function_exists("logModuleCall");
    $domain = hostupreseller_domainString($params);
    $logCtx = array("domain" => $domain);

    $desiredRecords = $params["dnsrecords"] ?? array();
    if (!is_array($desiredRecords)) {
        return array("error" => "DNS records payload is invalid");
    }

    // 1) Resolve zone
    $zoneResp = hostupreseller_http(
        $params,
        "GET",
        "/api/dns/domain/" . rawurlencode($domain)
    );

    if (!$zoneResp["success"]) {
        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "SaveDNS:zone_lookup",
                $logCtx,
                $zoneResp,
                $zoneResp["error"] ?? "Zone lookup failed"
            );
        }
        return array("error" => $zoneResp["error"] ?? "Unable to fetch DNS zone");
    }

    $zone = $zoneResp["data"]["zone"] ?? $zoneResp["data"] ?? array();
    $zoneId = $zone["id"] ?? $zone["domain_id"] ?? $zone["zoneId"] ?? null;
    $zoneDomain = $zone["domain"] ?? $domain;

    if (!$zoneId) {
        return array("error" => "DNS zone not found for domain");
    }

    // 2) Load current records to calculate diff
    $currentResp = hostupreseller_http(
        $params,
        "GET",
        "/api/dns/zones/" . rawurlencode($zoneId) . "/records"
    );

    if (!$currentResp["success"]) {
        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "SaveDNS:current_records",
                $logCtx,
                $currentResp,
                $currentResp["error"] ?? "Failed to fetch current DNS records"
            );
        }
        return array("error" => $currentResp["error"] ?? "Failed to fetch current DNS records");
    }

    $currentZone = $currentResp["data"]["zone"] ?? $currentResp["data"] ?? array();
    $currentRecords = $currentZone["records"] ?? ($currentResp["data"]["records"] ?? array());

    $existingById = array();
    foreach ($currentRecords as $rec) {
        if (isset($rec["id"])) {
            $existingById[(string) $rec["id"]] = $rec;
        }
    }

    $seenIds = array();

    // 3) Create or update
    foreach ($desiredRecords as $record) {
        $recid = isset($record["recid"]) ? trim((string) $record["recid"]) : "";
        $typeUpper = strtoupper(trim((string) ($record["type"] ?? "")));

        // Ignore SOA/NS coming from WHMCS to avoid accidental edits/removals
        if ($typeUpper === "SOA" || $typeUpper === "NS") {
            if ($recid !== "") {
                $seenIds[$recid] = true;
            }
            continue;
        }

        // Skip unsupported/system records (e.g., SRV) so WHMCS cannot mangle them
        if (!hostupreseller_isSupportedDnsType($typeUpper)) {
            if ($recid !== "") {
                $seenIds[$recid] = true;
            }
            if ($shouldLog) {
                logModuleCall(
                    "hostupreseller",
                    "SaveDNS:skip_unsupported",
                    $record,
                    null,
                    "Unsupported DNS type; leaving record untouched"
                );
            }
            continue;
        }

        $build = hostupreseller_buildDnsPayloadFromWhmcs($record, $zoneDomain);
        if (!$build["success"]) {
            return array("error" => $build["error"]);
        }

        $payload = $build["payload"];

        if ($recid !== "" && isset($existingById[$recid])) {
            $seenIds[$recid] = true;

            if (hostupreseller_dnsNeedsUpdate($existingById[$recid], $payload, $zoneDomain)) {
                $updateResp = hostupreseller_http(
                    $params,
                    "PUT",
                    "/api/dns/zones/" . rawurlencode($zoneId) . "/records/" . rawurlencode($recid),
                    $payload
                );

                if (!$updateResp["success"]) {
                    if ($shouldLog) {
                        logModuleCall(
                            "hostupreseller",
                            "SaveDNS:update",
                            $payload,
                            $updateResp,
                            $updateResp["error"] ?? "Failed to update DNS record"
                        );
                    }
                    return array("error" => $updateResp["error"] ?? "Failed to update DNS record");
                }
            }
        } else {
            $createResp = hostupreseller_http(
                $params,
                "POST",
                "/api/dns/zones/" . rawurlencode($zoneId) . "/records",
                $payload
            );

            if (!$createResp["success"]) {
                if ($shouldLog) {
                    logModuleCall(
                        "hostupreseller",
                        "SaveDNS:create",
                        $payload,
                        $createResp,
                        $createResp["error"] ?? "Failed to create DNS record"
                    );
                }
                return array("error" => $createResp["error"] ?? "Failed to create DNS record");
            }
        }
    }

    // 4) Delete records that were removed (skip SOA/NS safety)
    foreach ($existingById as $id => $rec) {
        if (isset($seenIds[$id])) {
            continue;
        }

        $type = strtoupper((string) ($rec["type"] ?? ""));
        if ($type === "SOA" || $type === "NS") {
            continue;
        }
        if (!hostupreseller_isSupportedDnsType($type)) {
            continue;
        }

        $deleteResp = hostupreseller_http(
            $params,
            "DELETE",
            "/api/dns/zones/" . rawurlencode($zoneId) . "/records/" . rawurlencode($id)
        );

        if (!$deleteResp["success"]) {
            if ($shouldLog) {
                logModuleCall(
                    "hostupreseller",
                    "SaveDNS:delete",
                    $logCtx + array("record_id" => $id),
                    $deleteResp,
                    $deleteResp["error"] ?? "Failed to delete DNS record"
                );
            }
            return array("error" => $deleteResp["error"] ?? "Failed to delete DNS record");
        }
    }

    if ($shouldLog) {
        logModuleCall(
            "hostupreseller",
            "SaveDNS:success",
            $logCtx,
            null,
            array(
                "submitted" => count($desiredRecords),
                "existing" => count($existingById),
            )
        );
    }

    return array(); // success
}

function hostupreseller_IDProtectToggle($params)
{
    return array("error" => "ID protection toggle is not supported by HostUp API");
}

function hostupreseller_RequestDelete($params)
{
    return array("error" => "Domain deletion is not exposed via API");
}

function hostupreseller_RegisterNameserver($params)
{
    return array("error" => "Child nameserver registration is not supported via API");
}

function hostupreseller_ModifyNameserver($params)
{
    return array("error" => "Child nameserver modification is not supported via API");
}

function hostupreseller_DeleteNameserver($params)
{
    return array("error" => "Child nameserver deletion is not supported via API");
}

function hostupreseller_Sync($params)
{
    $shouldLog = !empty($params["debug"]) && function_exists("logModuleCall");
    $domain = hostupreseller_domainString($params);
    $logCtx = array("domain" => $domain);

    try {
        list($domainId, $err) = hostupreseller_findDomainId($params, $domain);
        if (!$domainId) {
            if ($shouldLog) {
                logModuleCall(
                    "hostupreseller",
                    "Sync",
                    $logCtx,
                    null,
                    $err
                );
            }
            return array("error" => $err);
        }

        $resp = hostupreseller_http($params, "GET", "/api/domain-details/" . $domainId);
        if (!$resp["success"]) {
            if ($shouldLog) {
                logModuleCall(
                    "hostupreseller",
                    "Sync",
                    $logCtx,
                    $resp,
                    $resp["error"]
                );
            }
            return array("error" => $resp["error"]);
        }

        $details = $resp["data"]["details"] ?? array();
        $status = strtoupper($details["status"] ?? "");
        $expiry = hostupreseller_normalizeExpiry($details);

        $result = array(
            "expirydate" => $expiry,
            "nextduedate" => $expiry,
            "rawdata" => $details,
        );

        // Map status flags expected by WHMCS
        if (in_array($status, array("ACTIVE", "OK", "OK (AUTORENEW)"))) {
            $result["active"] = true;
        } elseif ($status === "EXPIRED") {
            $result["expired"] = true;
        } elseif ($status === "CANCELLED" || $status === "CANCELED") {
            $result["cancelled"] = true;
        } elseif (in_array($status, array("TRANSFERRED", "TRANSFERRED AWAY"))) {
            $result["transferredAway"] = true;
        } elseif ($status === "SUSPENDED") {
            $result["suspended"] = true;
        }

        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "Sync",
                $logCtx,
                $resp,
                $result
            );
        }

        return $result;
    } catch (\Throwable $e) {
        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "Sync",
                $logCtx,
                null,
                "Exception: " . $e->getMessage()
            );
        }
        return array("error" => "Sync failed: " . $e->getMessage());
    }
}

function hostupreseller_TransferSync($params)
{
    $shouldLog = !empty($params["debug"]) && function_exists("logModuleCall");
    $domain = hostupreseller_domainString($params);
    $logCtx = array("domain" => $domain);

    try {
        if ($shouldLog) {
            logModuleCall("hostupreseller", "TransferSync:start", $logCtx, null, null);
        }

        list($domainId, $err) = hostupreseller_findDomainId($params, $domain);
        if (!$domainId) {
            throw new \RuntimeException($err ?: "Domain {$domain} not found for this API key");
        }

        $resp = hostupreseller_http($params, "GET", "/api/domain-details/" . $domainId);
        if (!$resp["success"]) {
            throw new \RuntimeException($resp["error"] ?? "Unable to fetch domain details");
        }

        $details = $resp["data"]["details"] ?? array();
        $status = strtoupper($details["status"] ?? "");
        $expiry = hostupreseller_normalizeExpiry($details);

        $completedStatuses = array("ACTIVE", "OK", "REGISTERED");
        $pendingStatuses = array("PENDING", "PENDING TRANSFER", "TRANSFER", "PROCESSING", "IN_PROGRESS");

        if (in_array($status, $completedStatuses)) {
            $result = array(
                "completed" => true,
                "expirydate" => $expiry,
            );
            if ($shouldLog) {
                logModuleCall("hostupreseller", "TransferSync:completed", $logCtx, $resp, $result);
            }
            return $result;
        }

        if (in_array($status, $pendingStatuses)) {
            if ($shouldLog) {
                logModuleCall("hostupreseller", "TransferSync:pending", $logCtx, $resp, $status);
            }
            return array(); // No status change
        }

        $reason = "Status: {$status}";
        if ($shouldLog) {
            logModuleCall("hostupreseller", "TransferSync:failed", $logCtx, $resp, $reason);
        }
        return array("failed" => true, "reason" => $reason);
    } catch (\Throwable $e) {
        if ($shouldLog) {
            logModuleCall("hostupreseller", "TransferSync:error", $logCtx, null, $e->getMessage());
        }
        return array("error" => $e->getMessage());
    }
}

function hostupreseller_AutoRenewSync($params)
{
    return hostupreseller_Sync($params);
}

function hostupreseller_GetDomainSuggestions($params)
{
    // Return empty ResultsList to satisfy WHMCS type expectations
    return new ResultsList();
}

function hostupreseller_DomainSuggestionOptions($params)
{
    return array();
}

function hostupreseller_ResendIRTPVerificationEmail($params)
{
    return array("error" => "IRTP verification not applicable");
}

function hostupreseller_AdminCustomButtonArray()
{
    return array();
}
