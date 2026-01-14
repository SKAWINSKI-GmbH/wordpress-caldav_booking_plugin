# Contributing to CalDAV Booking Plugin

First off, thank you for considering contributing to CalDAV Booking Plugin! It's people like you that make this plugin better for everyone.

## Code of Conduct

By participating in this project, you are expected to uphold our Code of Conduct: be respectful, inclusive, and constructive.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates.

When creating a bug report, include:

- **Clear title** describing the issue
- **Steps to reproduce** the behavior
- **Expected behavior** vs actual behavior
- **Screenshots** if applicable
- **Environment info**:
  - WordPress version
  - PHP version
  - CalDAV server type (SOGo, Mailcow, Nextcloud, etc.)
  - Browser (for frontend issues)

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, include:

- **Clear title** describing the suggestion
- **Use case** - why would this be useful?
- **Proposed solution** if you have one

### Pull Requests

1. Fork the repo and create your branch from `main`
2. If you've added code that should be tested, add tests
3. Ensure the test suite passes
4. Make sure your code follows the WordPress coding standards
5. Issue that pull request!

## Development Setup

### Prerequisites

- PHP 7.4 or higher
- WordPress development environment
- CalDAV server for testing (or mock)

### Local Development

```bash
# Clone your fork
git clone https://github.com/YOUR_USERNAME/caldav-booking-plugin.git
cd caldav-booking-plugin

# Create a feature branch
git checkout -b feature/your-feature-name

# Make your changes...

# Test PHP syntax
find . -name "*.php" -exec php -l {} \;

# Commit your changes
git commit -m "Add: your feature description"

# Push to your fork
git push origin feature/your-feature-name
```

### Coding Standards

This project follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).

Key points:
- Use tabs for indentation
- Use single quotes for strings (unless you need variable interpolation)
- Add proper PHPDoc comments for functions
- Sanitize all user inputs
- Escape all outputs
- Use prepared statements for database queries

### Commit Messages

Use clear, descriptive commit messages:

- `Add: new feature description`
- `Fix: bug description`
- `Update: what was updated`
- `Remove: what was removed`
- `Docs: documentation changes`

## Testing

Before submitting a PR, ensure:

1. PHP syntax check passes: `find . -name "*.php" -exec php -l {} \;`
2. Plugin activates without errors
3. Basic functionality works:
   - Settings save correctly
   - CalDAV connection test works
   - Booking form displays
   - Bookings are created

## Questions?

Feel free to open an issue with your question or reach out to the maintainers.

Thank you for contributing! 🎉
