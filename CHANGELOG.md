# Changelog

## [[1.4.0]](https://github.com/Payfast/zencart-aggregation/releases/tag/v1.4.0)

### Added
- Updated branding to use the Payfast by Network logo.
- Revised configuration branding to Payfast Aggregation.

### Fixed
- Resolved a Payfast signature mismatch caused by HTML content being included in the `item_description` field by sanitising and normalising the value before signature generation.
- Prevented Payfast ITN failures by defensively defining missing Zen Cart category constants in the ITN context, eliminating fatal errors without requiring Zen Cart core modifications.

## [[1.3.0]](https://github.com/Payfast/zencart-aggregation/releases/tag/v1.3.0)

### Added
- Updated the Payfast common library to version 1.4.0.
- Code quality improvements.

## [[1.2.0]](https://github.com/Payfast/zencart-aggregation/releases/tag/v1.2.0)

### Added
- Branding update.
- Integration with the Payfast common library.
- Code quality improvements.

### Security
- General testing to ensure compatibility with latest Zencart version (2.0.1).

## [[1.1.4]](https://github.com/Payfast/zencart-aggregation/releases/tag/v1.1.4)

### Added
- Various ZenCart Notifiers for better compatibility.
- Code quality improvements.

### Fixed
- Update guest orders correctly with addresses.

### Security
- General testing to ensure compatibility with latest Zencart version (1.5.8).

## [[1.1.3]](https://github.com/Payfast/zencart-aggregation/releases/tag/v1.1.3)

### Added
- Update for PHP 8.0.

### Fixed
- General Fixes.

### Security
- General testing to ensure compatibility with latest Zencart version (1.5.8).
