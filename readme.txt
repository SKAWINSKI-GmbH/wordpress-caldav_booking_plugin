=== CalDAV Booking ===
Contributors: skawinski
Tags: booking, appointment, caldav, calendar, sogo, mailcow, nextcloud
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.2.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Appointment booking with CalDAV synchronization. Compatible with SOGo, Mailcow, Nextcloud, and other CalDAV servers.

== Description ==

A WordPress plugin for appointment booking with CalDAV synchronization. Only explicitly marked calendar events create bookable time windows.

**Features:**

* Inverse Availability System - Only explicitly marked calendar events (e.g., "VERFÜGBAR") create bookable time windows
* Multiple Meeting Types - Configure different appointment types with custom durations, colors, and descriptions
* Real-time Availability - Checks CalDAV server for current availability
* Race Condition Protection - Database-level locking prevents double bookings
* Async Processing - Fast booking response with background CalDAV synchronization
* Email Notifications - Customizable templates for customer and admin emails
* Spam Protection - Rate limiting and honeypot fields
* Responsive Design - Works on all devices
* Caching - Built-in caching for improved performance

**Compatible CalDAV Servers:**

* SOGo (Mailcow)
* Nextcloud
* Radicale
* Any CalDAV-compliant server

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/caldav-booking-plugin/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings → CalDAV Booking to configure

== Configuration ==

1. Enter your CalDAV server URL (e.g., `https://mail.example.com/SOGo/dav/`)
2. Enter your CalDAV username and password
3. Select or enter the calendar path
4. Configure meeting types and durations
5. Set up email templates (optional)

== Usage ==

Add the booking form to any page or post using the shortcode:

`[caldav_booking]`

With options:

`[caldav_booking title="Book an Appointment" days="14" type="Consultation"]`

== Frequently Asked Questions ==

= How does the availability system work? =

Create events in your CalDAV calendar with the keyword "VERFÜGBAR" (or your configured keyword) to mark available time slots. Only these marked slots will be shown as bookable.

= Why are bookings not appearing in my calendar? =

Check your CalDAV credentials using the "Test Connection" button in settings. Ensure the calendar path is correct.

= How can I debug email issues? =

Enable email debug mode in settings and check the log file at `wp-content/caldav-email-debug.log`.

== Screenshots ==

1. Booking form frontend
2. Admin settings page
3. Meeting type configuration

== Changelog ==

= 0.2 =
* Added console debug mode with [CalDAV] prefixed browser logging
* Added calendar timezone setting (independent from WordPress timezone)
* Added required/optional field selector for email, phone, message fields
* Added async/sync processing toggle for CalDAV and email operations
* Added file-based debug logging
* Added debug log auto-cleanup when debug mode is disabled
* Fixed timezone handling for UTC/offset formats
* Fixed ATTENDEE CN quoting for names with special characters
* Fixed confirmation message when email is disabled

= 0.1 =
* Initial release
* CalDAV server integration
* Inverse availability system
* Multiple meeting types
* Email notifications
* Rate limiting and spam protection

== Upgrade Notice ==

= 0.2 =
Adds debug mode, timezone settings, and form field configuration options.

== Credits ==

Developed by [SKAWINSKI GmbH](https://skawinski.at)

== License ==

This plugin is licensed under the MIT License.

Copyright (c) 2025 SKAWINSKI GmbH (https://skawinski.at)

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
