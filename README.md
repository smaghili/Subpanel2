# SubPanel2 - Subscription Management Panel

SubPanel2 is a web-based panel for managing V2Ray/XRay subscriptions with features like config testing, auto-backup, and more.

## Features

- 🔐 Secure login system
- 📊 Subscription management
- ✅ Config testing capability
- 🔄 Auto-backup system
- 📱 QR code generation
- 📋 Easy config copying
- 🕒 On-hold subscription support
- 🔍 Config validity checking

## Prerequisites

Before installation, make sure you have:
- A server running Ubuntu/Debian
- A domain pointed to your server
- Root access to your server

## Installation

### Quick Installation
Run this command to start the installation:

```bash
curl -o install.sh https://raw.githubusercontent.com/smaghili/Subpanel2/main/installsub.sh && chmod +x install.sh && sudo ./install.sh
```

During installation, you'll be prompted to:
1. Enter your domain name
2. Wait for automatic SSL certificate generation
3. Complete the setup process

### Default Login Credentials
- Username: `admin`
- Password: `admin123`

⚠️ **Important**: Change your password after first login!

## System Requirements

- PHP 7.4 or higher
- Python 3.x
- SQLite3
- Nginx
- XRay
- Python packages:
  - aiohttp

All requirements will be automatically installed during setup.

## Directory Structure

```
/var/www/
├── html/           # Web files
├── db/             # Database files
├── config/         # Configuration files
└── scripts/        # System scripts
```

## Security Features

- SSL/TLS encryption
- Session management
- SQL injection protection
- XSS protection
- Secure file permissions

## Support

For issues and feature requests, please open an issue on GitHub.

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Acknowledgments

Thanks to all contributors who have helped make this project better!