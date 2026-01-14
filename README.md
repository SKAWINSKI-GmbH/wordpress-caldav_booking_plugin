# CalDAV Booking Plugin for WordPress

[![CI/CD](https://github.com/YOUR_USERNAME/caldav-booking-plugin/actions/workflows/ci.yml/badge.svg)](https://github.com/YOUR_USERNAME/caldav-booking-plugin/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)

A WordPress plugin for appointment booking with CalDAV synchronization. Compatible with SOGo, Mailcow, Nextcloud, and other CalDAV servers.

## ✨ Features

- **Inverse Availability System** - Only explicitly marked calendar events (e.g., "VERFÜGBAR") create bookable time windows
- **Multiple Meeting Types** - Configure different appointment types with custom durations, colors, and descriptions
- **Real-time Availability** - Checks CalDAV server for current availability
- **Race Condition Protection** - Database-level locking prevents double bookings
- **Async Processing** - Fast booking response with background CalDAV synchronization
- **Email Notifications** - Customizable templates for customer and admin emails
- **Spam Protection** - Rate limiting and honeypot fields
- **Responsive Design** - Works on all devices
- **Caching** - Built-in caching for improved performance

## 📋 Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- CalDAV server (SOGo, Mailcow, Nextcloud, etc.)
- SSL certificate (recommended)

## 🚀 Installation

### From GitHub Release

1. Download the latest release ZIP from [Releases](https://github.com/YOUR_USERNAME/caldav-booking-plugin/releases)
2. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**
3. Select the downloaded ZIP file and click **Install Now**
4. Activate the plugin

### Manual Installation

1. Clone this repository or download the source code
2. Copy the `caldav-booking-plugin` folder to `/wp-content/plugins/`
3. Activate the plugin in WordPress Admin

## ⚙️ Configuration

### 1. CalDAV Server Settings

Go to **Settings → CalDAV Booking** and configure:

| Setting | Description | Example |
|---------|-------------|---------|
| CalDAV URL | Base URL of your CalDAV server | `https://mail.example.com/SOGo/dav` |
| Username | CalDAV username | `user@example.com` |
| Password | CalDAV password | `********` |
| Calendar Path | Path to the calendar | `Calendar/personal` |

### 2. Availability Keywords

The plugin uses an **inverse availability system**. Create events in your calendar with specific keywords to mark available time slots:

- Default keyword: `VERFÜGBAR`
- Additional keywords can be configured in settings

**Example:** Create a recurring event "VERFÜGBAR" from 9:00-17:00 on weekdays.

### 3. Meeting Types

Configure different appointment types:

- **Name**: Display name (e.g., "Quick Call")
- **Duration**: Length in minutes (15, 30, 45, 60, 90, 120)
- **Description**: Optional description shown to customers
- **Color**: Visual indicator

### 4. Email Templates

Customize email notifications with placeholders:

| Placeholder | Description |
|-------------|-------------|
| `{name}` | Customer name |
| `{email}` | Customer email |
| `{phone}` | Customer phone |
| `{date}` | Appointment date |
| `{time}` | Start time |
| `{end_time}` | End time |
| `{type}` | Meeting type |
| `{message}` | Customer message |
| `{site_name}` | Website name |

## 📖 Usage

### Shortcode

Add the booking form to any page or post:

```
[caldav_booking]
```

### Shortcode Options

```
[caldav_booking title="Book an Appointment" days="14" type="Consultation"]
```

| Option | Description | Default |
|--------|-------------|---------|
| `title` | Form heading | "Termin buchen" |
| `days` | Days to show ahead | 14 |
| `type` | Filter to specific meeting type | (all types) |

## 🔒 Security Features

- **Nonce Verification** - All AJAX requests are verified
- **Input Sanitization** - All user inputs are sanitized
- **SQL Injection Protection** - Prepared statements for all queries
- **Rate Limiting** - Max 5 bookings per IP per hour
- **Honeypot Field** - Hidden field to catch bots
- **CSRF Protection** - WordPress nonce system

## 🛠️ Development

### Prerequisites

- PHP 7.4+
- Composer (optional, for development tools)
- Node.js (optional, for JS linting)

### Local Development

```bash
# Clone the repository
git clone https://github.com/YOUR_USERNAME/caldav-booking-plugin.git

# Install development dependencies (optional)
composer install

# Run PHP linting
find . -name "*.php" -exec php -l {} \;

# Run PHPCS
phpcs --standard=WordPress caldav-booking.php
```

### Creating a Release

1. Update version number in `caldav-booking.php`
2. Commit changes
3. Create and push a tag:

```bash
git tag v1.0.0
git push origin v1.0.0
```

The GitHub Actions workflow will automatically:
- Run all tests
- Build the release ZIP
- Create a GitHub Release with the ZIP attached

## 📁 File Structure

```
caldav-booking-plugin/
├── .github/
│   └── workflows/
│       └── ci.yml          # GitHub Actions CI/CD
├── assets/
│   ├── admin.css           # Admin styles
│   ├── booking.css         # Frontend styles
│   └── booking.js          # Frontend JavaScript
├── caldav-booking.php      # Main plugin file
├── LICENSE                 # MIT License
├── README.md               # This file
└── .gitignore              # Git ignore rules
```

## 🔧 Hooks & Filters

### Actions

```php
// After a booking is created
do_action('caldav_booking_created', $booking_id, $booking_data);

// After CalDAV sync completes
do_action('caldav_booking_synced', $booking_id, $result);
```

### Filters

```php
// Modify available slots
add_filter('caldav_available_slots', function($slots, $date, $duration) {
    return $slots;
}, 10, 3);

// Modify email content
add_filter('caldav_email_content', function($content, $booking) {
    return $content;
}, 10, 2);
```

## 🐛 Troubleshooting

### Bookings not appearing in calendar

1. Check CalDAV credentials in settings
2. Use "Test Connection" button
3. Ensure WP-Cron is running (or set up a real cron job)

### Slow performance

1. Enable caching in settings
2. Consider using a real cron job instead of WP-Cron:
   ```
   */5 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron
   ```

### Emails not sending

1. Enable email debug mode in settings
2. Check `wp-content/caldav-email-debug.log`
3. Consider using an SMTP plugin

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📞 Support

- **Issues**: [GitHub Issues](https://github.com/YOUR_USERNAME/caldav-booking-plugin/issues)
- **Documentation**: [Wiki](https://github.com/YOUR_USERNAME/caldav-booking-plugin/wiki)

## 🙏 Credits

Developed by [SKAWINSKI GmbH](https://skawinski.at)

---

**Note:** Replace `YOUR_USERNAME` with your actual GitHub username in the badge URLs and links.
