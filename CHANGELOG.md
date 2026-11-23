# Changelog

## 2.7.2 — DDoS filtering profile API compatibility

- Updated client and admin DDoS profile management for CentralSpec's opaque profile and location identifiers, including safe URL path encoding.
- Refreshed profile and server IP data before assignment or removal; preserved assignment ID `0` and the provider's unknown `enabled` state.
- Moved admin DDoS assignment and removal to a protected POST route, with staff authorization, credential-row and server checks, and CSRF validation. Added explicit CSRF and IP/CIDR validation for client actions.
- Clarified empty-assignment and uncertain-operation messages so they do not imply a successful removal; added DDoS contract, transport, client, and admin regression tests.
