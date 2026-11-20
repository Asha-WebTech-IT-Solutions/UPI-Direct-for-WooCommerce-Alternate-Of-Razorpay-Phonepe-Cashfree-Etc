# Security Policy

This plugin handles payment state for online stores, so we take reports seriously.

## Supported versions

Security fixes are released for the latest minor version.

## Reporting a vulnerability

**Please do not open a public issue.** Report privately via GitHub's
[“Report a vulnerability”](../../security/advisories/new) (Security → Advisories), or email the maintainers at the
address listed in the repository profile.

Include: affected version, steps to reproduce, impact, and a proof of concept if you have one.

You can expect an acknowledgement within **3 working days**, a triage result within **7 days**, and a coordinated
fix and disclosure. We credit reporters who want credit.

## In scope

- Any way to get an order marked paid **without** trusted verification (the most serious class)
- Authorisation bypass on REST or admin routes, order enumeration, CSRF, XSS, SQL injection
- Bypass of duplicate-reference or attempt-limit protections
- Webhook authentication flaws in the plugin's webhook dispatcher

## Out of scope

- Customers entering a UTR that is not theirs (mitigated by manual statement matching; documented)
- Issues requiring an already-compromised administrator account
- Third-party verifier add-ons (report to their authors)
- Findings from automated scanners without a demonstrated impact

## Design principles (what we defend)

1. The browser is untrusted. Only verifiers (a human with `manage_woocommerce`, or a provider) can complete an order.
2. Provider verdicts must match amount, currency and transaction reference.
3. State changes are atomic and idempotent.
4. Customer routes need the order key **and** a per-order nonce; admin routes need a capability.
