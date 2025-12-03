<img width="1024" height="617" alt="Namnlös design (6)" src="https://github.com/user-attachments/assets/0694611d-1db1-4a2f-8cac-83a020e7fcee" />

# HostUp Domain Reseller Module for WHMCS

This is an integration module that allows you to connect your WHMCS installation directly to HostUp to automate the sale and management of domain names.

### Installation
1.  Access your WHMCS installation via FTP or a file manager.
2.  Navigate to the directory: `/modules/registrars/`.
3.  Create a new folder named: `hostupreseller`.
4.  Upload the PHP file to that folder and name it `hostupreseller.php`.

### Configuration
1.  Log in to your **WHMCS Admin**.
2.  Go to **System Settings** → **Domain Registrars**.
3.  Locate "HostUp Domain Reseller" and click **Activate**.
4.  Fill in your details:
    * **API Base URL:** Leave the default value (`https://cloud.hostup.se`) unless instructed otherwise.
    * **API Key:** Paste your API key (Bearer token) generated in the HostUp panel.
    * **Enable Debug Logging:** Check this box if you encounter issues and need to see exact error messages in the WHMCS "Module Log".

### Features
* **Register domains** (including handling of extra fields like organization numbers/Tax IDs).
* **Transfer domains** (using EPP code).
* **Renew domains**.
* **Manage Nameservers (NS)**.
* **Update Contact Information** (Whois).
* **Get EPP Code** (Auth Code).
* **Domain Search** (Availability Check) directly within WHMCS.
* **Synchronization** of expiry dates and status automatically via WHMCS cron.

### Limitations (Good to Know)
The following features are not currently supported by the API. Attempting to use them in WHMCS will result in an error message:
* Registrar Lock (Lock/Unlock domain).
* DNS Management (Host Records).
* ID Protection (Whois Privacy) toggle.

### Tips
If you receive error messages during registration, please verify that you have set the correct **Organization Number/Tax ID** for the client in WHMCS, as this is often required for .se/.nu domains.
