<img width="1024" height="617" alt="HostUp WHMCS domain reseller module" src="https://github.com/user-attachments/assets/0694611d-1db1-4a2f-8cac-83a020e7fcee" />

# HostUp Domain Reseller Module for WHMCS

The HostUp Domain Reseller module connects WHMCS to HostUp so you can sell and manage domains directly from WHMCS. It uses the HostUp v2 API for domain registration, transfer, renewal, nameserver updates, contact updates, EPP code requests, availability checks, and WHMCS domain synchronization.

## Requirements

- WHMCS 9.0 or later is recommended.
- A HostUp account with reseller/API access.
- A HostUp API key with the required scopes listed below.
- PHP cURL enabled on the WHMCS server.

## Installation

1. Access your WHMCS installation by FTP, SSH, or your hosting file manager.
2. Open the WHMCS registrar modules directory:
   `/modules/registrars/`
3. Create this folder:
   `/modules/registrars/hostupreseller/`
4. Upload the module file as:
   `/modules/registrars/hostupreseller/hostupreseller.php`

## API Key

Create an API key in the HostUp panel and add it to the module configuration in WHMCS. Keep the key private; it should only be stored inside WHMCS.

Recommended scopes for full module functionality:

- `read:domains` - read existing domains and sync domain status.
- `write:domains` - renew domains and update nameservers/contact details.
- `write:orders` - place new registration and transfer-in orders.
- `write:billing` - allow order and billing flows required by checkout.
- `transfer:domains` - request outgoing EPP/auth codes.

If you only enable part of the module, you can use fewer scopes. For example, renewals and nameserver/contact updates need `read:domains` and `write:domains`, while new registrations and transfer-in orders also need `write:orders`.

## Configuration

1. Log in to WHMCS Admin.
2. Go to **System Settings** > **Domain Registrars**.
3. Find **HostUp Domain Reseller** and click **Activate**.
4. Configure the module:
   - **API Base URL:** use `https://cloud.hostup.se` unless HostUp has given you another URL.
   - **API Key:** paste your HostUp API key.
   - **Timeout:** leave the default unless HostUp support asks you to change it.
   - **Enable Debug Logging:** enable only while troubleshooting. It writes module request/response details to the WHMCS module log.

## Supported Features

- Domain registration.
- Domain transfer-in with EPP/auth code.
- Domain renewal from WHMCS.
- Domain availability search in WHMCS.
- Nameserver read and update.
- Registrant contact/WHOIS read and update.
- EPP/auth-code request for transfer-out.
- Expiry date and status synchronization through the WHMCS cron.
- Transfer status synchronization through the WHMCS cron.
- TLD pricing sync for supported TLDs.

## DNS Management

When using HostUp whitelabel DNS, use these nameservers:

- `ns1.wdns.se`
- `ns2.wdns.se`

## Known Limitations

- Registrar lock management is not currently supported through this WHMCS module.
- Child/private nameserver registration, modification, and deletion are not currently supported through this WHMCS module.

## Notes for .se and .nu Domains

Some TLDs require extra registrant information. For `.se` and `.nu`, make sure the customer profile or WHMCS domain additional fields include the correct Swedish personnummer or organisation number where required. Missing or incorrectly formatted details can cause registration or transfer validation errors.

## Troubleshooting

If an action fails:

1. Confirm the API Base URL is `https://cloud.hostup.se`.
2. Confirm the API key is active and has the required scopes.
3. Check that the WHMCS domain contact details are complete.
4. Enable **Debug Logging** temporarily and retry the action.
5. Review **Utilities** > **Logs** > **Module Log** in WHMCS.

Disable debug logging after troubleshooting because module logs can contain operational details.
