# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-01-14

### Added
- Initial release
- CalDAV server integration (SOGo, Mailcow, Nextcloud compatible)
- Inverse availability system (only explicitly marked slots are bookable)
- Multiple meeting types with custom durations, colors, and descriptions
- Real-time availability checking
- Race condition protection with database-level locking
- Asynchronous booking processing for fast response times
- Customizable email templates with placeholders
- Rate limiting (5 bookings per IP per hour)
- Honeypot spam protection
- Responsive frontend design
- Built-in caching for CalDAV requests
- Admin settings page with connection testing
- Email debug logging
- Shortcode support with options

### Security
- Nonce verification on all AJAX endpoints
- Input sanitization for all user data
- SQL injection protection via prepared statements
- Date/time format validation
- Email validation (frontend + backend)
- Duration whitelist validation
- Past date rejection
- IP logging for bookings
- Cloudflare/proxy IP detection support

## [0.1.0] - 2026-01-14

### Added
- Initial development version
- Basic CalDAV integration
- Simple booking form
