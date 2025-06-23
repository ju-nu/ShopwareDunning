Junu Dunning System
A PHP 8.3+ project for automating dunning processes in Shopware 6.6.10.4, supporting multiple sales channels with independent API credentials, Brevo email settings, and HTML templates. Includes dry-run mode and Supervisor compatibility.
Features

Fetches Shopware orders in "reminded" transaction state via Admin API.
Processes each sales channel independently with unique configurations.
Implements three dunning stages: Zahlungserinnerung, Mahnung 1, Mahnung 2.
Sends emails via Brevo with invoice PDF attachments.
Supports dry-run mode for simulation without modifying Shopware or sending emails.
Logs actions to logs/dunning.log and saves dry-run invoices to logs/dry-run/{sales_channel_id}/.
Handles SIGTERM for graceful shutdown with Supervisor.

Requirements

PHP 8.3.21
Shopware 6.6.10.4
Brevo account with API key
Composer
Supervisor (for production)

Installation

Clone the repository:git clone <repository-url>
cd dunning-system


Install dependencies:composer install


Copy .env.example to .env:cp .env.example .env


Configure sales channels in config/shops.json. Example:[
    {
        "url": "https://shop.example.com",
        "api_key": "your-shopware-api-key",
        "api_secret": "your-shopware-api-secret",
        "sales_channel_id": "32-character-uuid",
        "sales_channel_domain": "channel1.example.com",
        "brevo_api_key": "your-brevo-api-key",
        "no_invoice_email": "auftrag@channel1.example.com",
        "ze_template": "ze.html",
        "mahnung1_template": "mahnung1.html",
        "mahnung2_template": "mahnung2.html",
        "due_days": 10
    }
]


Ensure write permissions for logs:chmod -R 0777 logs/



Usage

Normal Mode:php dunning.php


Dry-Run Mode:php dunning.php --dry-run

Simulates actions, logs to logs/dunning.log, and saves invoices to logs/dry-run/{sales_channel_id}/.

Supervisor Configuration
Create /etc/supervisor/conf.d/dunning.conf:
[program:dunning]
command=php /path/to/dunning.php
directory=/path/to/project
autostart=true
autorestart=true
stderr_logfile=/path/to/logs/dunning.err.log
stdout_logfile=/path/to/logs/dunning.out.log
user=www-data
stopsignal=TERM

For dry-run:
[program:dunning-dry-run]
command=php /path/to/dunning.php --dry-run
directory=/path/to/project
autostart=true
autorestart=true
stderr_logfile=/path/to/logs/dunning-dry-run.err.log
stdout_logfile=/path/to/logs/dunning-dry-run.out.log
user=www-data
stopsignal=TERM

Update and restart Supervisor:
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart all

Logs

Main Log: logs/dunning.log (DEBUG, INFO, ERROR levels).
Dry-Run Invoices: logs/dry-run/{sales_channel_id}/{order_number}_{document_id}.pdf.
Example log entry:[2025-06-23T12:17:00+02:00] dunning.INFO: [DRY-RUN] Simulated email sending {"to":"customer@example.com","subject":"Zahlungserinnerung für Bestellung 12345","content_length":1200,"attachment":"logs/dry-run/uuid1/12345_doc456.pdf","sales_channel_id":"uuid1","order_number":"12345"}



Notes

Ensure sales_channel_id is a 32-character UUID.
Templates are in templates/; customize as needed.
Rate limiting: 100ms between sales channels, 50ms between orders.
Requires write permissions for logs/ and logs/dry-run/.

License
MIT License. See LICENSE for details.