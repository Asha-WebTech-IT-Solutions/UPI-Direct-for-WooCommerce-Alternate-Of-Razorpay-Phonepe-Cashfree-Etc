# Changelog

All notable changes are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [1.0.0]

### Added
- WooCommerce payment gateway with merchant, checkout and verification settings.
- UPI Intent (`upi://pay`) and app-specific launch links; browser-side QR code.
- Mobile-first payment panel with reference (UTR) submission, live status polling and a no-JS fallback.
- Transaction table, event history, order metabox and **WooCommerce → UPI Transactions** screen.
- Verifier API (`UPIDW_Verifier_Interface`), registry, manual verifier, webhook route, Action Scheduler polling.
- REST endpoints for status, reference submission, admin verify/reject/list.
- HPOS and Cart & Checkout Blocks support.
- Duplicate-UTR protection, attempt limits, atomic state transitions.
- Unit and integration tests, GitHub Actions CI and release workflow.
