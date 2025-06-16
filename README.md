# Junu Dunning System for Shopware 6.6

A PHP 8.4 script to automate dunning for Shopware 6.6 orders in the "reminded" transaction state, integrated with Brevo for HTML email notifications.

## Prerequisites

- PHP 8.4+
- Composer
- Shopware 6.6.10.4 Admin API access (client ID and secret)
- Brevo account with API key
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

3. Copy the `.env.example` to `.env` and configure your Shopware systems:
   ```bash
   cp .env.example .env
   nano .env
   ```

4. Create the logs directory:
   ```bash
   mkdir logs
   chmod 777 logs
   ```

5. Place HTML email templates (`ze.html`, `mahnung1.html`, `mahnung2.html`) in the `templates/` directory.

## Configuration

Edit `.env` to include your Shopware systems and Brevo settings. Example:
```env
SHOPWARE_SYSTEMS=[
    {
        "url": "https://shop1.example.com",
        "api_key": "your_client_id",
        "api_secret": "your_client_secret",
        "domain": "shop1.example.com",
        "brevo_api_key": "your_brevo_api_key",
        "no_invoice_email": "auftrag@shop1.example.com",
        "ze_template": "ze.html",
        "mahnung1_template": "mahnung1.html",
        "mahnung2_template": "mahnung2.html",
        "due_days": 10
    }
]
```

### Email Templates

HTML templates must include the following placeholders:
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

## Running with Supervisor

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

## Logs

Logs are written to `logs/dunning.log` with detailed information on orders processed, emails sent, and errors.

## Development

- The code follows PSR-12 standards and uses PHP 8.4 features (strict typing, typed properties).
- Source files are in `src/` with a modular structure (`Config`, `Service`, `Exception`).
- Run `composer dump-autoload` after adding new classes.

## License

MIT