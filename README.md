# ZenMove-CRM-Provisioning-Module-for-WHMCS
A WHMCS provisioning module that automatically creates and manages ZenMove CRM instances for your customers.

Designed for moving companies, lead marketplaces, and SaaS providers who want to offer a ready-to-use CRM platform at signup.

🚀 Features
🔄 Automatic CRM instance provisioning on order
👤 Creates customer accounts instantly
🔑 Sends login credentials to clients
🏢 Supports multi-tenant CRM deployments
⚙️ Easy API-based configuration
💼 Built for movers, agencies, and lead platforms
📦 Installation
Download or clone the repository:
git clone https://github.com/YOUR_USERNAME/zenmove-crm-whmcs-module.git
Upload to your WHMCS modules directory:
/modules/servers/
Log in to WHMCS Admin:
Setup → Products/Services → Servers
Add a new server using the ZenMove module
Assign the module to a product:
Setup → Products/Services → Products → Module Settings
⚙️ Configuration

Edit your configuration file:

config.php

Set the following values:

API Endpoint (ZenMove CRM API)
API Key
Default CRM settings (optional)
🔄 Provisioning Flow

When a customer orders a product:

WHMCS triggers the module
A new ZenMove CRM instance is created
User credentials are generated
Login details are emailed to the customer
🧪 Supported Actions
Create Account (Provision)
Suspend Account
Unsuspend Account
Terminate Account
💡 Use Cases
Moving companies offering CRM to franchisees
Lead generation platforms managing clients
Agencies reselling CRM access
SaaS-style CRM businesses
🛠 Requirements
WHMCS 8+
PHP 7.4+
Active ZenMove API access
🔐 Security
API-based authentication
No sensitive data stored locally
Designed for secure provisioning workflows
📄 License

MIT License

🌐 About ZenMove

ZenMove provides CRM and lead management tools built specifically for the moving industry.

https://zenmove.ca
