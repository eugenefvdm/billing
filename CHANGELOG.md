# Changelog

All notable changes to `billing` will be documented in this file.

## [v0.5] 2025-11-05

- Subscriptions paid via EFT will now only be activated after payment is received
- The default methods in `billing.php` are now both `card` and `eft`.
- Fixed date alignment issue when first paying by EFT and then swapping over to card
- Added messaging that can be dismissed about outstanding payment, payment cancelled, and paying outstanding amounts first before (re)subscribring
- Simplified the code by removing return URLs and update `.env.example`

## [v0.4] 2025-11-04

- Deprecate `name` and make sure `type` is consistent. Updated tests to remove `name`
- Remove `start_date` completely and now solely rely on `ends_at` even for EFTs

## [v0.3] 2025-11-04

- Removed constrain on invoices linked to subscriptions and indexes

## [v0.2] 2025-11-03

- Fix bug with plan relationship loading, implement planConfig

## [v0.1] 2025-10-31

- Removed PHP CS Fixer
- Renamed config('payfast') to config('billing')
- Added payfast to billing.payfast so layer the Payfast variables lower down
- Fixed "Undefined array key" error in subscriptions view by using `type` field instead of `plan`
- Removed inline color styles from subscription buttons for neutral/grey appearance
- Fixed view namespace in Livewire components to use `payfast::` instead of `vendor.payfast.` (views no longer need to be published)

## [v0.0] 2025-10-30

- Complete rewrite
- Package renamed to eugenefvdm/billing
- Added EFT and invoicing logic, including invoice reminders, and PDF invoices
- Removed All Nova stuff

