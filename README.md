# Junu Dunning System for Shopware 6.6

A PHP 8.3 script to automate dunning for Shopware 6.6 orders in the "reminded" transaction state, integrated with Brevo for HTML email notifications. Supports multiple sales channels per Shopware instance, each treated as a separate project with distinct credentials and templates.

## Prerequisites

- PHP 8.3.21+
- Composer
- Shopware 6.6.10.4 Admin API access (client ID and secret per sales channel)
- Brevo account with API key per sales channel
- Supervisor for running the script

## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/your-repo/junu-dunning.git
   cd junu-dunning
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Copy the `.env.example` to `.env` and configure your sales channels:
   ```bash
   cp .env.example .env
   nano .env
   ```

4. Create the logs directory:
   ```bash
   mkdir logs
   chmod 777 logs
   ```

5. Create template directories for each sales channel and place HTML email templates:
   - For each `sales_channel_id`, create a folder under `templates/` (e.g., `templates/sales_channel_uuid_1/`).
   - Place `ze.html`, `mahnung1.html`, and `mahnung2.html` in each sales channel's folder.

## Configuration

Edit `.env` to include your Shopware sales channels and Brevo settings. Example:
```env
SHOPWARE_SYSTEMS=[
    {
        "url": "https://shop1.example.com",
        "api_key": "your_client_id_1",
        "api_secret": "your_client_secret_1",
        "sales_channel_id": "sales_channel_uuid_1",
        "sales_channel_domain": "channel1.example.com",
        "brevo_api_key": "your_brevo_api_key_1",
        "no_invoice_email": "auftrag@channel1.example.com",
        "ze_template": "sales_channel_uuid_1/ze.html",
        "mahnung1_template": "sales_channel_uuid_1/mahnung1.html",
        "mahnung2_template": "sales_channel_uuid_1/mahnung2.html",
        "due_days": 10
    },
    {
        "url": "https://shop1.example.com",
        "api_key": "your_client_id_2",
        "api_secret": "your_client_secret_2",
        "sales_channel_id": "sales_channel_uuid_2",
        "sales_channel_domain": "channel2.example.com",
        "brevo_api_key": "your_brevo_api_key_2",
        "no_invoice_email": "auftrag@channel2.example.com",
        "ze_template": "sales_channel_uuid_2/ze.html",
        "mahnung1_template": "sales_channel_uuid_2/mahnung1.html",
        "mahnung2_template": "sales_channel_uuid_2/mahnung2.html",
        "due_days": 10
    }
]
```

### Email Templates

For each sales channel, create a folder under `templates/` named after the `sales_channel_id` (e.g., `templates/sales_channel_uuid_1/`). Each folder must contain:
- `ze.html`
- `mahnung1.html`
- `mahnung2.html`

Templates must include the following placeholders:
- `##FIRSTNAME##`: Billing first name
- `##LASTNAME##`: Billing last name
- `##ORDERID##`: Order number
- `##ORDERDATE##`: Order date (e.g., 17. Juni 2025)
- `##ORDERAMOUNT##`: Order amount (e.g., 100,00 EUR)
- `##INVOICENUM##`: Invoice number
- `##DUEDATE##`: Due date (e.g., 27. Juni 2025)
- `##DUEDAYS##`: Number of days until due
- `##SALESCHANNEL##`: Sales channel name
- `##CUSTOMERCOMMENT##`: Customer comment or "No comment provided"

## Running the Script

### Normal Mode
Run the script with Supervisor for continuous operation:

1. Install Supervisor:
   ```bash
   sudo apt-get install supervisor
   ```

2. Create a Supervisor configuration file (e.g., `/etc/supervisor/conf.d/junu-dunning.conf`):
   ```ini
   [program:junu-dunning]
   command=php /path/to/junu-dunning/dunning.php
   directory=/path/to/junu-dunning
   autostart=true
   autorestart=true
   stderr_logfile=/path/to/junu-dunning/logs/supervisor_err.log
   stdout_logfile=/path/to/junu-dunning/logs/supervisor_out.log
   ```

3. Update and start Supervisor:
   ```bash
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo supervisorctl start junu-dunning
   ```

### Dry-Run Mode
To simulate the dunning process without sending emails or updating orders, use the `--dry-run` flag. Invoices will be downloaded to `dry-run/{sales_channel_id}/{order_number}_{document_id}.pdf`.

Run manually:
```bash
php dunning.php --dry-run
```

Or configure Supervisor for dry-run:
```ini
[program:junu-dunning-dry]
command=php /path/to/junu-dunning/dunning.php --dry-run
directory=/path/to/junu-dunning
autostart=true
autorestart=true
stderr_logfile=/path/to/junu-dunning/logs/supervisor_dry_err.log
stdout_logfile=/path/to/junu-dunning/logs/supervisor_dry_out.log
```

Check `logs/dunning.log` for `[DRY-RUN]` entries detailing simulated actions.

## Logs

Logs are written to `logs/dunning.log` with detailed information on orders processed, emails sent (or simulated), and errors. In dry-run mode, look for `[DRY-RUN]` entries.

## Development

- The code follows PSR-12 standards and uses PHP 8.3 features (strict typing, typed properties).
- Source files are in `src/` with a modular structure (`Config`, `Service`, `Exception`).
- Run `composer dump-autoload` after adding new classes.

## License

MIT