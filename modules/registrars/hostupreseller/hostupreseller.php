<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Carbon;
use WHMCS\Domain\Registrar\Domain as RegistrarDomain;
use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Results\ResultsList as TldResultsList;

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

    // Test API connectivity against an authenticated v2 endpoint.
    $base = rtrim($params["apiBase"] ?: HOSTUP_DEFAULT_BASE, "/");
    $ch = curl_init($base . "/api/v2/domains?limit=1");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Bearer " . $params["apiKey"],
        "Accept: application/json",
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

    $headers = array("Accept: application/json", "Content-Type: application/json");
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

    if ($raw === false) {
        $raw = "";
    }

    if ($raw === "" || $status === 204) {
        if ($status >= 200 && $status < 300) {
            if ($shouldLog && function_exists("logModuleCall")) {
                logModuleCall("hostupreseller", "{$method} {$path}", $logPayload, $raw, "success");
            }
            return array("success" => true, "data" => array(), "http_status" => $status);
        }

        if ($shouldLog && function_exists("logModuleCall")) {
            logModuleCall(
                "hostupreseller",
                "{$method} {$path}",
                $logPayload,
                $raw,
                array(
                    "error" => "Empty response from API",
                    "http_status" => $status,
                )
            );
        }

        return array(
            "success" => false,
            "error" => "Empty response from API",
            "http_status" => $status,
            "raw" => $raw,
        );
    }

    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        if ($shouldLog && function_exists("logModuleCall")) {
            logModuleCall(
                "hostupreseller",
                "{$method} {$path}",
                $logPayload,
                $raw,
                array(
                    "error" => "Unable to decode response",
                    "json_error" => json_last_error_msg(),
                    "http_status" => $status,
                )
            );
        }
        return array(
            "success" => false,
            "error" => "Unable to decode response: " . json_last_error_msg(),
            "http_status" => $status,
            "raw" => $raw,
        );
    }

    $formatValidationErrors = function ($responseData) {
        $items = array();
        if (!empty($responseData["details"]) && is_array($responseData["details"])) {
            $items = $responseData["details"];
        } elseif (!empty($responseData["errors"]) && is_array($responseData["errors"])) {
            $items = $responseData["errors"];
        }

        if (empty($items)) {
            return "";
        }

        $messages = array();
        foreach ($items as $detail) {
            $field = isset($detail["field"]) ? $detail["field"] : ($detail["pointer"] ?? null);
            $message = isset($detail["message"]) ? $detail["message"] : ($detail["detail"] ?? null);

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

    if ($status >= 200 && $status < 300) {
        if (isset($data["success"]) && !$data["success"]) {
            $message = $data["message"] ?? $data["error"] ?? "API request failed";
            return array(
                "success" => false,
                "error" => $message,
                "http_status" => $status,
                "body" => $data,
                "request_id" => $data["requestId"] ?? null,
            );
        }

        if ($shouldLog && function_exists("logModuleCall")) {
            logModuleCall("hostupreseller", "{$method} {$path}", $logPayload, $data, "success");
        }

        $responseData = (isset($data["success"]) && array_key_exists("data", $data))
            ? $data["data"]
            : $data;

        return array(
            "success" => true,
            "data" => $responseData,
            "http_status" => $status,
            "body" => $data,
            "request_id" => $data["requestId"] ?? null,
        );
    }

    $message = $data["detail"] ?? ($data["message"] ?? ($data["error"] ?? ($data["title"] ?? "HTTP {$status}")));
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
    return array(null, "Product IDs are internal in v2; create domain orders with /api/v2/orders.");
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

function hostupreseller_cleanString($value)
{
    if ($value === null) {
        return null;
    }
    if (is_string($value) || is_numeric($value)) {
        $trimmed = trim((string) $value);
        return $trimmed === "" ? null : $trimmed;
    }
    return null;
}

function hostupreseller_setIfNotEmpty(array &$target, $key, $value)
{
    $clean = hostupreseller_cleanString($value);
    if ($clean !== null) {
        $target[$key] = $clean;
    }
}

function hostupreseller_registrationIdentifierForV2($orgno, $tld)
{
    $formatted = hostupreseller_formatOrgnoForApi($orgno, $tld);
    $formatted = hostupreseller_cleanString($formatted);
    if ($formatted === null) {
        return null;
    }

    if (preg_match('/^\[([A-Za-z]{2})\](.*)$/', $formatted, $matches)) {
        $value = trim($matches[2]);
        if ($value === "") {
            return null;
        }
        return array(
            "value" => $value,
            "countryCode" => strtoupper($matches[1]),
        );
    }

    return array(
        "value" => $formatted,
        "countryCode" => hostupreseller_tldSupportsOrgno($tld) ? "SE" : null,
    );
}

function hostupreseller_buildV2RegistrantContact(array $params)
{
    $contact = hostupreseller_buildContact($params);
    $result = array(
        "type" => $contact["type"],
    );

    hostupreseller_setIfNotEmpty($result, "firstName", $contact["firstname"]);
    hostupreseller_setIfNotEmpty($result, "lastName", $contact["lastname"]);
    hostupreseller_setIfNotEmpty($result, "companyName", $contact["companyname"]);
    hostupreseller_setIfNotEmpty($result, "email", $contact["email"]);
    hostupreseller_setIfNotEmpty($result, "phoneNumber", $contact["phonenumber"]);
    hostupreseller_setIfNotEmpty($result, "street", $contact["address1"]);
    hostupreseller_setIfNotEmpty($result, "address2", $contact["address2"]);
    hostupreseller_setIfNotEmpty($result, "city", $contact["city"]);
    hostupreseller_setIfNotEmpty($result, "state", $contact["state"]);
    hostupreseller_setIfNotEmpty($result, "postalCode", $contact["postcode"]);
    hostupreseller_setIfNotEmpty($result, "countryCode", $contact["country"]);

    $identifier = hostupreseller_registrationIdentifierForV2($contact["orgno"], $params["tld"] ?? "");
    if ($identifier !== null) {
        $result["registrationIdentifier"] = $identifier;
    }

    return $result;
}

function hostupreseller_buildClientData(array $params)
{
    $contact = hostupreseller_buildContact($params);
    $clientData = array(
        "accountType" => $contact["companyname"] ? "organisation" : "private",
    );

    hostupreseller_setIfNotEmpty($clientData, "firstName", $contact["firstname"]);
    hostupreseller_setIfNotEmpty($clientData, "lastName", $contact["lastname"]);
    hostupreseller_setIfNotEmpty($clientData, "email", $contact["email"]);
    hostupreseller_setIfNotEmpty($clientData, "companyName", $contact["companyname"]);
    hostupreseller_setIfNotEmpty($clientData, "countryCode", $contact["country"]);
    hostupreseller_setIfNotEmpty($clientData, "phoneNumber", $contact["phonenumber"]);

    $address = array();
    hostupreseller_setIfNotEmpty($address, "street", $contact["address1"]);
    hostupreseller_setIfNotEmpty($address, "address2", $contact["address2"]);
    hostupreseller_setIfNotEmpty($address, "city", $contact["city"]);
    hostupreseller_setIfNotEmpty($address, "state", $contact["state"]);
    hostupreseller_setIfNotEmpty($address, "postalCode", $contact["postcode"]);
    if (!empty($address)) {
        $clientData["address"] = $address;
    }

    $identifier = hostupreseller_registrationIdentifierForV2($contact["orgno"], $params["tld"] ?? "");
    if ($identifier !== null) {
        $clientData["registrationIdentifier"] = $identifier;
    }

    return $clientData;
}

function hostupreseller_domainString(array $params)
{
    $tld = ltrim($params["tld"], ".");
    return $params["sld"] . "." . $tld;
}

function hostupreseller_findDomainId(array $params, $fqdn)
{
    list($domain, $err) = hostupreseller_findDomain($params, $fqdn);
    if (!$domain) {
        return array(null, $err);
    }

    return array($domain["id"] ?? null, null);
}

function hostupreseller_findDomain(array $params, $fqdn)
{
    $resp = hostupreseller_http(
        $params,
        "GET",
        "/api/v2/domains",
        null,
        array("name" => $fqdn, "limit" => 50)
    );

    if (!$resp["success"]) {
        return array(null, $resp["error"]);
    }

    $domains = $resp["data"]["data"] ?? ($resp["data"]["domains"] ?? array());
    foreach ($domains as $domain) {
        if (isset($domain["name"]) && strcasecmp($domain["name"], $fqdn) === 0) {
            if (!empty($domain["id"])) {
                return array($domain, null);
            }
        }
    }

    return array(null, "Domain {$fqdn} not found for this API key");
}

function hostupreseller_getDomainDetails(array $params, $domainId)
{
    return hostupreseller_http(
        $params,
        "GET",
        "/api/v2/domains/" . rawurlencode($domainId)
    );
}

function hostupreseller_normalizeExpiry(array $details)
{
    $candidates = array(
        $details["expiresAt"] ?? null,
        $details["nextDueAt"] ?? null,
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

function hostupreseller_domainStatus(array $details)
{
    $status = hostupreseller_cleanString($details["serviceStatus"] ?? ($details["status"] ?? ""));
    return strtolower($status ?? "");
}

function hostupreseller_domainLifecycleType(array $details)
{
    if (!empty($details["lifecycle"]) && is_array($details["lifecycle"])) {
        return strtolower((string) ($details["lifecycle"]["type"] ?? ""));
    }

    return strtolower((string) ($details["type"] ?? ""));
}

function hostupreseller_isTransferInProgress(array $details)
{
    if (!empty($details["lifecycle"]) && is_array($details["lifecycle"])) {
        if (!empty($details["lifecycle"]["transferInProgress"])) {
            return true;
        }
        return strtolower((string) ($details["lifecycle"]["type"] ?? "")) === "transfer"
            && hostupreseller_domainStatus($details) === "pending";
    }

    $status = strtoupper((string) ($details["status"] ?? ""));
    $type = strtoupper((string) ($details["type"] ?? ""));
    return strpos($status, "TRANSFER") !== false || strpos($type, "TRANSFER") !== false;
}

function hostupreseller_requiredAcceptedTerms(array $params, $tld, $action)
{
    $resp = hostupreseller_http(
        $params,
        "GET",
        "/api/v2/products/domains/" . rawurlencode(ltrim((string) $tld, "."))
    );

    if (!$resp["success"]) {
        return array();
    }

    $requirements = $resp["data"]["registryRequirements"][$action] ?? array();
    $terms = array();
    foreach ($requirements as $requirement) {
        if (!empty($requirement["acceptedTermsKey"])) {
            $terms[] = $requirement["acceptedTermsKey"];
        }
    }

    return array_values(array_unique($terms));
}

function hostupreseller_amountValue($value)
{
    if (is_array($value)) {
        return isset($value["amount"]) ? (float) $value["amount"] : null;
    }
    if (is_numeric($value)) {
        return (float) $value;
    }
    return null;
}

function hostupreseller_tldRequiresEpp(array $tldDetails)
{
    $requirements = $tldDetails["registryRequirements"]["transfer"] ?? array();
    foreach ($requirements as $requirement) {
        if (($requirement["key"] ?? null) === "eppCode" && !empty($requirement["required"])) {
            return true;
        }
    }
    return false;
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
        $nameservers = hostupreseller_collectNameservers($params);
        $contact = hostupreseller_buildV2RegistrantContact($params);
        $clientData = hostupreseller_buildClientData($params);
        $domainItem = array(
            "type" => "domain",
            "action" => "register",
            "domainName" => $domain,
            "years" => (int) ($params["regperiod"] ?? 1),
            "registrantContact" => $contact,
        );

        if (count($nameservers) > 0) {
            $domainItem["nameservers"] = $nameservers;
        }

        $acceptedTerms = hostupreseller_requiredAcceptedTerms($params, $params["tld"] ?? "", "registration");
        if (!empty($acceptedTerms)) {
            $domainItem["acceptedTerms"] = $acceptedTerms;
        }

        $payload = array(
            "paymentMethod" => "invoice",
            "clientData" => $clientData,
            "items" => array(
                $domainItem,
            ),
            "attemptKey" => "whmcs-register-" . ($params["domainid"] ?? md5($domain)) . "-" . date("YmdH"),
        );

        $resp = hostupreseller_http($params, "POST", "/api/v2/orders", $payload);
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
    $nameservers = hostupreseller_collectNameservers($params);
    $contact = hostupreseller_buildV2RegistrantContact($params);
    $clientData = hostupreseller_buildClientData($params);
    $epp = $params["transfersecret"] ?? $params["eppcode"] ?? "";
    $domainItem = array(
        "type" => "domain",
        "action" => "transfer",
        "domainName" => $domain,
        "years" => (int) ($params["regperiod"] ?? 1),
        "eppCode" => $epp,
        "registrantContact" => $contact,
    );

    if (count($nameservers) > 0) {
        $domainItem["nameservers"] = $nameservers;
    }

    $acceptedTerms = hostupreseller_requiredAcceptedTerms($params, $params["tld"] ?? "", "transfer");
    if (!empty($acceptedTerms)) {
        $domainItem["acceptedTerms"] = $acceptedTerms;
    }

    $payload = array(
        "paymentMethod" => "invoice",
        "clientData" => $clientData,
        "items" => array(
            $domainItem,
        ),
        "attemptKey" => "whmcs-transfer-" . ($params["domainid"] ?? md5($domain)) . "-" . date("YmdH"),
    );

    $resp = hostupreseller_http($params, "POST", "/api/v2/orders", $payload);
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

    $resp = hostupreseller_http(
        $params,
        "POST",
        "/api/v2/domains/" . rawurlencode($domainId) . "/actions/renew",
        array()
    );
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

        $resp = hostupreseller_getDomainDetails($params, $domainId);
        if (!$resp["success"]) {
            throw new \RuntimeException($resp["error"] ?? "Unable to fetch domain details");
        }

        $details = $resp["data"] ?? array();
        $status = hostupreseller_domainStatus($details);
        $expiry = hostupreseller_normalizeExpiry($details);
        $nameservers = hostupreseller_extractNameservers($details);
        $nameserversAssoc = array(
            "ns1" => $nameservers[0] ?? "",
            "ns2" => $nameservers[1] ?? "",
            "ns3" => $nameservers[2] ?? "",
            "ns4" => $nameservers[3] ?? "",
            "ns5" => $nameservers[4] ?? "",
        );

        $transferLock = !empty($details["lifecycle"]["registrarLockEnabled"])
            || !empty($details["registryLock"]["enabled"]);
        $idProtection = !empty($details["whoisPrivacy"]["enabled"]);

        $domainObj = new RegistrarDomain();
        $domainObj
            ->setDomain($domain)
            ->setNameservers($nameserversAssoc)
            ->setRegistrationStatus($status ?: "unknown")
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

    $resp = hostupreseller_http(
        $params,
        "GET",
        "/api/v2/domains/" . rawurlencode($domainId) . "/nameservers"
    );
    if (!$resp["success"]) {
        if ($shouldLog) {
            logModuleCall("hostupreseller", "GetNameservers:error", $logCtx, $resp, $resp["error"]);
        }
        return array("error" => $resp["error"]);
    }

    $nameservers = $resp["data"]["nameservers"] ?? array();

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

    $resp = hostupreseller_http(
        $params,
        "POST",
        "/api/v2/domains/" . rawurlencode($domainId) . "/nameservers",
        $payload
    );
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

    $resp = hostupreseller_http(
        $params,
        "GET",
        "/api/v2/domains/" . rawurlencode($domainId) . "/contacts"
    );
    if (!$resp["success"]) {
        return array("error" => $resp["error"]);
    }

    $contact = $resp["data"]["registrant"] ?? array();

    $fields = array(
        "First Name" => $contact["firstName"] ?? "",
        "Last Name" => $contact["lastName"] ?? "",
        "Company Name" => $contact["companyName"] ?? "",
        "Email Address" => $contact["email"] ?? "",
        "Address 1" => $contact["street"] ?? "",
        "Address 2" => $contact["address2"] ?? "",
        "City" => $contact["city"] ?? "",
        "State" => $contact["state"] ?? "",
        "Postcode" => $contact["postalCode"] ?? "",
        "Country" => $contact["countryCode"] ?? "",
        "Phone Number" => $contact["phoneNumber"] ?? "",
    );

    // Expose organisation/person number on supported TLDs so it can be edited in WHMCS
    if (hostupreseller_tldSupportsOrgno($params["tld"] ?? "")) {
        $label = !empty($contact["companyName"]) ? "Organisation Number" : "Personnummer";
        $identifier = $contact["registrationIdentifier"]["value"] ?? "";
        $fields[$label] = hostupreseller_formatOrgnoForDisplay($identifier);
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

    $update = array();
    hostupreseller_setIfNotEmpty($update, "firstName", $details["First Name"] ?? "");
    hostupreseller_setIfNotEmpty($update, "lastName", $details["Last Name"] ?? "");
    hostupreseller_setIfNotEmpty($update, "companyName", $details["Company Name"] ?? "");
    hostupreseller_setIfNotEmpty($update, "email", $details["Email Address"] ?? "");
    hostupreseller_setIfNotEmpty($update, "street", $details["Address 1"] ?? "");
    hostupreseller_setIfNotEmpty($update, "address2", $details["Address 2"] ?? "");
    hostupreseller_setIfNotEmpty($update, "city", $details["City"] ?? "");
    hostupreseller_setIfNotEmpty($update, "state", $details["State"] ?? "");
    hostupreseller_setIfNotEmpty($update, "postalCode", $details["Postcode"] ?? "");
    hostupreseller_setIfNotEmpty($update, "countryCode", $details["Country"] ?? "");
    hostupreseller_setIfNotEmpty($update, "phoneNumber", $details["Phone Number"] ?? "");

    // Map organisation/person number back to API for supported TLDs
    if (hostupreseller_tldSupportsOrgno($params["tld"] ?? "")) {
        $orgnoInput = null;
        if (isset($details["Organisation Number"])) {
            $orgnoInput = $details["Organisation Number"];
        } elseif (isset($details["Personnummer"])) {
            $orgnoInput = $details["Personnummer"];
        }
        if ($orgnoInput !== null) {
            $identifier = hostupreseller_registrationIdentifierForV2($orgnoInput, $params["tld"] ?? "");
            if ($identifier !== null) {
                $update["registrationIdentifier"] = $identifier;
            }
        }
    }

    $payload = array("registrant" => $update);

    $resp = hostupreseller_http(
        $params,
        "POST",
        "/api/v2/domains/" . rawurlencode($domainId) . "/contacts",
        $payload
    );
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

    $resp = hostupreseller_http(
        $params,
        "POST",
        "/api/v2/domains/" . rawurlencode($domainId) . "/actions/request-epp",
        array()
    );
    if (!$resp["success"]) {
        return array("error" => $resp["error"]);
    }

    $code = $resp["data"]["eppCode"] ?? null;
    if ($code) {
        return array("eppcode" => $code);
    }

    return array("success" => "success");
}

/**
 * Availability check using v2 queued availability endpoint
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

    // Normalize TLDs and submit full domain names to v2 availability.
    $names = array();
    foreach ($tldsToInclude as $tld) {
        $names[] = $searchTerm . "." . ltrim($tld, ".");
    }

    $payload = array("names" => $names);
    $resp = hostupreseller_http($params, "POST", "/api/v2/domains/availability", $payload);
    if (!$resp["success"]) {
        throw new \RuntimeException("Domain check failed: " . ($resp["error"] ?? "unknown error"));
    }

    $apiResults = $resp["data"]["data"] ?? array();
    $pollUrl = $resp["data"]["operation"]["pollUrl"] ?? null;

    if (empty($apiResults) && $pollUrl) {
        // Poll until completed or timeout (max 15 seconds, poll every 500ms)
        $maxAttempts = 30;
        $jobStatus = "processing";

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            usleep(500000); // 500ms between polls

            $statusResp = hostupreseller_http($params, "GET", $pollUrl);
            if (!$statusResp["success"]) {
                continue;
            }

            $jobStatus = $statusResp["data"]["status"] ?? "processing";
            $apiResults = $statusResp["data"]["data"] ?? array();

            if ($jobStatus === "completed" || !empty($apiResults)) {
                break;
            }

            if ($jobStatus === "failed") {
                throw new \RuntimeException($statusResp["data"]["reason"] ?? "Domain availability check failed");
            }
        }
    }

    if (empty($apiResults)) {
        throw new \RuntimeException("Domain availability check timed out - please try again");
    }

    // Build WHMCS ResultsList with SearchResult objects
    $results = new ResultsList();

    foreach ($apiResults as $item) {
        $domainName = $item["name"] ?? ($item["domain"] ?? "");
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
        if (!empty($item["available"]) || $itemStatus === "available") {
            $searchResult->setStatus(SearchResult::STATUS_NOT_REGISTERED);
        } elseif ($itemStatus === "registered" || $itemStatus === "unavailable" || $itemStatus === "") {
            $searchResult->setStatus(SearchResult::STATUS_REGISTERED);
        } elseif ($itemStatus === "reserved") {
            $searchResult->setStatus(SearchResult::STATUS_RESERVED);
        } else {
            $searchResult->setStatus(SearchResult::STATUS_TLD_NOT_SUPPORTED);
        }

        // Handle premium domains if enabled
        if ($premiumEnabled && !empty($item["premium"])) {
            $searchResult->setPremiumDomain(true);
            $registerPrice = hostupreseller_amountValue($item["billing"] ?? null) ?? 0;
            $renewPrice = $item["renewalAmount"] ?? 0;
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
 * Sync TLD pricing for .se and .nu from HostUp API
 * Used by WHMCS Utilities > Registrar TLD Sync
 * @see https://developers.whmcs.com/domain-registrars/tld-pricing-sync/
 */
function hostupreseller_GetTldPricing($params)
{
    $shouldLog = !empty($params["debug"]) && function_exists("logModuleCall");

    // Only sync .se and .nu
    $supportedTlds = array(".se", ".nu");

    // Fetch available TLDs from the v2 public catalog.
    $resp = hostupreseller_http($params, "GET", "/api/v2/products/domains");
    if (!$resp["success"]) {
        $error = $resp["error"] ?? "Failed to fetch TLD list";
        if ($shouldLog) {
            logModuleCall("hostupreseller", "GetTldPricing", array(), $resp, $error);
        }
        return array("error" => $error);
    }

    $products = $resp["data"]["tlds"] ?? array();
    $results = new TldResultsList();

    foreach ($products as $product) {
        $tld = strtolower($product["tld"] ?? "");

        // Only process .se and .nu
        if (!in_array($tld, $supportedTlds, true)) {
            continue;
        }

        // Fetch detailed pricing for this TLD
        $detailsResp = hostupreseller_http(
            $params,
            "GET",
            "/api/v2/products/domains/" . rawurlencode(ltrim($tld, "."))
        );

        if (!$detailsResp["success"]) {
            if ($shouldLog) {
                logModuleCall(
                    "hostupreseller",
                    "GetTldPricing:details",
                    array("tld" => $tld),
                    $detailsResp,
                    $detailsResp["error"] ?? "Failed to fetch TLD details"
                );
            }
            continue;
        }

        $data = $detailsResp["data"] ?? array();
        $domainPricing = $data["domainPricing"] ?? array();
        $oneYear = null;
        $years = array();
        foreach ($domainPricing as $row) {
            if (!empty($row["years"])) {
                $years[] = (int) $row["years"];
            }
            if ((int) ($row["years"] ?? 0) === 1) {
                $oneYear = $row;
            }
        }

        // Extract prices from the canonical v2 money objects.
        $registerPrice = hostupreseller_amountValue($oneYear["register"] ?? ($data["register"] ?? null)) ?? 0;
        $renewPrice = hostupreseller_amountValue($oneYear["renew"] ?? ($data["renew"] ?? null)) ?? 0;
        $transferPrice = hostupreseller_amountValue($oneYear["transfer"] ?? ($data["transfer"] ?? null));
        $currency = $data["billing"]["currencyCode"]
            ?? ($data["register"]["currencyCode"] ?? ($data["renew"]["currencyCode"] ?? "SEK"));

        // Skip if no valid pricing found
        if ($registerPrice <= 0 && $renewPrice <= 0) {
            continue;
        }

        // Build ImportItem with pricing
        sort($years);
        $item = (new ImportItem())
            ->setExtension($tld)
            ->setMinYears(!empty($years) ? min($years) : 1)
            ->setMaxYears(!empty($years) ? max($years) : 1)
            ->setRegisterPrice($registerPrice)
            ->setRenewPrice($renewPrice)
            ->setTransferPrice($transferPrice)
            ->setCurrency($currency)
            ->setEppRequired(hostupreseller_tldRequiresEpp($data)); // Derived from v2 registry requirements

        if (!empty($years) && method_exists($item, "setYears")) {
            $item->setYears($years);
        }

        $results[] = $item;

        if ($shouldLog) {
            logModuleCall(
                "hostupreseller",
                "GetTldPricing:item",
                array("tld" => $tld),
                array(
                    "register" => $registerPrice,
                    "renew" => $renewPrice,
                    "transfer" => $transferPrice,
                ),
                "success"
            );
        }
    }

    if ($shouldLog) {
        logModuleCall(
            "hostupreseller",
            "GetTldPricing:complete",
            array(),
            array("count" => count($results)),
            "success"
        );
    }

    return $results;
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

        $syncResp = hostupreseller_http(
            $params,
            "POST",
            "/api/v2/domains/" . rawurlencode($domainId) . "/actions/status-sync",
            array()
        );
        if (!$syncResp["success"] && $shouldLog) {
            logModuleCall(
                "hostupreseller",
                "Sync:status-sync",
                $logCtx,
                $syncResp,
                $syncResp["error"] ?? "Status sync failed"
            );
        }

        $resp = hostupreseller_getDomainDetails($params, $domainId);
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

        $details = $resp["data"] ?? array();
        $status = hostupreseller_domainStatus($details);
        $expiry = hostupreseller_normalizeExpiry($details);

        $result = array(
            "expirydate" => $expiry,
            "nextduedate" => $expiry,
            "rawdata" => $details,
        );

        // Map status flags expected by WHMCS
        if ($status === "active") {
            $result["active"] = true;
        } elseif ($status === "expired") {
            $result["expired"] = true;
        } elseif ($status === "cancelled" || $status === "terminated") {
            $result["cancelled"] = true;
        } elseif ($status === "transferred_away" || $status === "transferredaway") {
            $result["transferredAway"] = true;
        } elseif ($status === "suspended") {
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

        $syncResp = hostupreseller_http(
            $params,
            "POST",
            "/api/v2/domains/" . rawurlencode($domainId) . "/actions/status-sync",
            array()
        );
        if (!$syncResp["success"] && $shouldLog) {
            logModuleCall(
                "hostupreseller",
                "TransferSync:status-sync",
                $logCtx,
                $syncResp,
                $syncResp["error"] ?? "Status sync failed"
            );
        }

        $resp = hostupreseller_getDomainDetails($params, $domainId);
        if (!$resp["success"]) {
            throw new \RuntimeException($resp["error"] ?? "Unable to fetch domain details");
        }

        $details = $resp["data"] ?? array();
        $status = hostupreseller_domainStatus($details);
        $expiry = hostupreseller_normalizeExpiry($details);

        if ($status === "active") {
            $result = array(
                "completed" => true,
                "expirydate" => $expiry,
            );
            if ($shouldLog) {
                logModuleCall("hostupreseller", "TransferSync:completed", $logCtx, $resp, $result);
            }
            return $result;
        }

        if ($status === "pending" || hostupreseller_isTransferInProgress($details)) {
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

/**
 * Client area custom buttons
 * Adds buttons to the domain management page in the client area
 */
function hostupreseller_ClientAreaCustomButtonArray()
{
    return array(
        "Get EPP Code" => "getEppCodeClient",
    );
}

/**
 * Client area allowed functions
 * Defines which custom functions can be invoked from the client area
 */
function hostupreseller_ClientAreaAllowedFunctions()
{
    return array(
        "getEppCodeClient",
    );
}

/**
 * Get EPP code from client area
 * Called when customer clicks "Get EPP Code" button
 */
function hostupreseller_getEppCodeClient($params)
{
    $result = hostupreseller_GetEPPCode($params);

    if (isset($result["error"])) {
        return array(
            "success" => false,
            "errorMessage" => $result["error"],
        );
    }

    $eppCode = $result["eppcode"] ?? "";

    return array(
        "success" => true,
        "eppcode" => $eppCode,
    );
}

/**
 * Client area output
 * Renders custom HTML/template in the domain details client area page
 */
function hostupreseller_ClientArea($params)
{
    $domain = hostupreseller_domainString($params);
    $tld = "." . ltrim($params["tld"] ?? "", ".");

    // Build informational output for .se/.nu domains
    $output = "";

    if (hostupreseller_tldSupportsOrgno($tld)) {
        $output .= '<div class="alert alert-info">';
        $output .= '<strong>Svensk domän</strong><br>';
        $output .= 'För att flytta denna domän till en annan registrar, ';
        if ($tld === ".nu") {
            $output .= 'behöver du en EPP-kod (authcode). Klicka på "Get EPP Code" ovan.';
        } else {
            $output .= 'kontakta vår support. .se-domäner kräver ingen EPP-kod.';
        }
        $output .= '</div>';
    }

    return $output;
}

/**
 * Additional domain fields for .se and .nu TLDs
 * Displays identification number field during domain registration/transfer
 * @see https://developers.whmcs.com/domain-registrars/domain-information/
 */
function hostupreseller_AdditionalDomainFields()
{
    $identificationField = array(
        "Name" => "Identification Number",
        "LangVar" => "identificationnumber",
        "DisplayName" => "Personnummer / Organisationsnummer",
        "Type" => "text",
        "Size" => 20,
        "Required" => true,
        "Description" => "Ange personnummer (ÅÅMMDD-XXXX) eller organisationsnummer (XXXXXX-XXXX)",
        "Ispapi-Name" => "X-SE-REGISTRANT-IDNUMBER",
    );

    return array(
        ".se" => array($identificationField),
        ".nu" => array($identificationField),
    );
}
