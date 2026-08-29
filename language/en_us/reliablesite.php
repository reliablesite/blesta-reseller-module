<?php
/**
 * en_us language strings for the ReliableSite module (v2.0).
 */

// Basics
$lang['Reliablesite.name'] = 'ReliableSite';
$lang['Reliablesite.description'] = 'Provision and manage dedicated servers - customer creation, pending-order assignment, catalog sync, and client self-service (power, KVM, OS reinstall, reverse DNS, MAC, backups, bandwidth and DDoS protection).';
$lang['Reliablesite.module_row'] = 'ReliableSite Account';
$lang['Reliablesite.module_row_plural'] = 'Accounts';

// Cron
$lang['Reliablesite.cron.catalog_sync_name'] = 'ReliableSite Catalog Sync';
$lang['Reliablesite.cron.catalog_sync_desc'] = 'Synchronizes stock (and prices for non-frozen products) for imported ReliableSite products.';

// Errors
$lang['Reliablesite.!error.account_name.valid'] = 'Please enter an account name.';
$lang['Reliablesite.!error.api_key.valid'] = 'Please enter your ReliableSite API key.';
$lang['Reliablesite.!error.api_key.connection'] = 'The API key could not be verified against the ReliableSite API.';
$lang['Reliablesite.!error.api_key.missing'] = 'No ReliableSite API key is configured.';
$lang['Reliablesite.!error.client.missing'] = 'The client could not be found for provisioning.';
$lang['Reliablesite.!error.invalid_action'] = 'Invalid action - something went wrong.';

// Manage landing buttons
$lang['Reliablesite.manage.btn_add_credential'] = 'Add API Credentials';
$lang['Reliablesite.manage.btn_settings'] = 'Settings';
$lang['Reliablesite.manage.btn_pending'] = 'Pending Orders';
$lang['Reliablesite.manage.btn_servers'] = 'Servers';
$lang['Reliablesite.manage.btn_catalog'] = 'Catalog';
$lang['Reliablesite.manage.btn_customers'] = 'Customers';
$lang['Reliablesite.manage.btn_ddos_profiles'] = 'DDoS Profiles';
$lang['Reliablesite.manage.btn_ddos_history'] = 'DDoS History';
$lang['Reliablesite.manage.btn_null_routes'] = 'Null Routes';
$lang['Reliablesite.manage.btn_sync_log'] = 'Sync Log';
$lang['Reliablesite.manage.btn_askbrian'] = 'Ask Brian';

// Ask Brian (AI assistant)
$lang['Reliablesite.askbrian.box_title'] = 'Ask Brian';

// Settings / row meta
$lang['Reliablesite.settings.box_title'] = 'ReliableSite Settings';
$lang['Reliablesite.settings.select_group'] = '-- Select a package group --';
$lang['Reliablesite.row_meta.account_name'] = 'Account Name';
$lang['Reliablesite.row_meta.api_key'] = 'API Key';
$lang['Reliablesite.row_meta.api_token'] = 'API Token (auto)';
$lang['Reliablesite.row_meta.api_token_validity'] = 'API Token Expiry (auto)';
$lang['Reliablesite.row_meta.admin_notify_enabled'] = 'Email admins on new pending orders';
$lang['Reliablesite.row_meta.admin_notify_emails'] = 'Notification recipients (comma-separated)';
$lang['Reliablesite.row_meta.catalog_sync_enabled'] = 'Enable automatic catalog sync';
$lang['Reliablesite.row_meta.options_sync_enabled'] = 'Sync configurable options';
$lang['Reliablesite.row_meta.options_sync_enabled.note'] = 'Imports ReliableSite option groups (OS, upgrades, etc.) as Blesta configurable options and attaches them to the matching packages, so customer selections are sent with the order.';
$lang['Reliablesite.row_meta.order_default_payment_method'] = 'Default order payment method';
$lang['Reliablesite.row_meta.order_default_payment_method.note'] = 'The ReliableSite payment gateway module used when placing an order (e.g. paypal). Prefills the Create Order form and can be overridden per order.';
$lang['Reliablesite.row_meta.sync_report_enabled'] = 'Email a sync report after each run';
$lang['Reliablesite.row_meta.sync_frequency'] = 'Sync frequency (minutes)';
$lang['Reliablesite.row_meta.import_package_group'] = 'Import into package group';
$lang['Reliablesite.row_meta.import_currency_code'] = 'Import currency';
$lang['Reliablesite.row_meta.markup_type'] = 'Markup type';
$lang['Reliablesite.row_meta.markup_value'] = 'Markup value';
$lang['Reliablesite.row_meta.cycle_mode'] = 'Billing cycles to import';
$lang['Reliablesite.settings.markup_percent'] = 'Percentage of base price (%)';
$lang['Reliablesite.settings.markup_flat'] = 'Flat fee per month';
$lang['Reliablesite.settings.cycle_monthly'] = 'Monthly only';
$lang['Reliablesite.settings.cycle_all'] = 'All cycles offered by the inventory item';
$lang['Reliablesite.settings.payment_method_none'] = '-- None (require selection per order) --';
$lang['Reliablesite.settings.currency_default'] = '-- Your default currency --';

// Customer-facing white-label assistant (api.php?action=chat)
$lang['Reliablesite.row_meta.brian_chat_enabled'] = 'Enable the sales assistant endpoint';
$lang['Reliablesite.row_meta.brian_chat_enabled.note'] = 'Publishes a public chat endpoint your website can post visitor messages to. Off by default; nothing is exposed until you enable it and list your websites.';
$lang['Reliablesite.row_meta.brian_agent_name'] = 'Assistant name';
$lang['Reliablesite.row_meta.brian_company_name'] = 'Company name';
$lang['Reliablesite.row_meta.brian_currency'] = 'Quote prices in';
$lang['Reliablesite.row_meta.brian_allowed_origins'] = 'Allowed websites';
$lang['Reliablesite.row_meta.brian_allowed_origins.note'] = 'One site per line, as https://www.example.com or *.example.com for all subdomains. Browser requests from anywhere else are refused. Leave empty and the endpoint will refuse every browser request.';
$lang['Reliablesite.row_meta.brian_public_base_url'] = 'Public base URL (optional)';
$lang['Reliablesite.row_meta.brian_public_base_url.note'] = 'Only needed when the address the public uses to reach this install differs from the one Blesta knows - a vanity domain, or a reverse proxy. Must be https://.';
$lang['Reliablesite.row_meta.brian_behind_proxy'] = 'This install sits behind a proxy or CDN';
$lang['Reliablesite.row_meta.brian_behind_proxy.note'] = 'Count rate limits against the visitor IP in the Cloudflare or X-Forwarded-For header rather than the connecting address. Turn this on only when a proxy you control sets that header - otherwise a visitor can forge it and send as many messages as they like.';
$lang['Reliablesite.row_meta.brian_rate_limit'] = 'Messages';
$lang['Reliablesite.row_meta.brian_rate_window'] = 'Per (seconds)';
$lang['Reliablesite.row_meta.brian_daily_cap'] = 'Daily cap';

// Ask Brian - white-label status panel
$lang['Reliablesite.askbrian.wl_title'] = 'Customer-Facing Sales Assistant';
$lang['Reliablesite.askbrian.wl_enabled'] = 'Enabled';
$lang['Reliablesite.askbrian.wl_disabled'] = 'Disabled';
$lang['Reliablesite.settings.save'] = 'Save Settings';
$lang['Reliablesite.settings.saved'] = 'Settings saved.';

// Package fields
$lang['Reliablesite.package_fields.product_id'] = 'ReliableSite Inventory ID';
$lang['Reliablesite.package_fields.product_id.tooltip'] = 'The ReliableSite catalog product id this package maps to. Set automatically when imported from the Catalog screen.';

// Service fields
$lang['Reliablesite.service_field.client_notice'] = 'Your dedicated server will be prepared and assigned by our team shortly after your order is confirmed.';
$lang['Reliablesite.service_field.username'] = 'ReliableSite Username';
$lang['Reliablesite.service_field.server_id'] = 'ReliableSite Server ID';
$lang['Reliablesite.service_field.server_label'] = 'Server Label';

// Tabs
$lang['Reliablesite.tab.client_manage'] = 'Manage Server';
$lang['Reliablesite.tab.admin_manage'] = 'Manage ReliableSite Server';

// Client actions
$lang['Reliablesite.client.power_on_ok'] = 'Power-on command sent.';
$lang['Reliablesite.client.power_off_ok'] = 'Power-off command sent.';
$lang['Reliablesite.client.kvm_enabled'] = 'KVM access enabled.';
$lang['Reliablesite.client.kvm_disabled'] = 'KVM access disabled.';
$lang['Reliablesite.client.os_started'] = 'OS installation started.';
$lang['Reliablesite.client.os_canceled'] = 'OS installation canceled.';
$lang['Reliablesite.client.rdns_set'] = 'Reverse DNS record saved.';
$lang['Reliablesite.client.backup_saved'] = 'Backup FTP account updated.';
$lang['Reliablesite.client.mac_saved'] = 'MAC address updated.';
$lang['Reliablesite.client.ddos_assigned'] = 'IP assigned to DDoS profile.';
$lang['Reliablesite.client.ddos_removed'] = 'IP removed from DDoS profile.';
$lang['Reliablesite.client.action_failed'] = 'The requested action could not be completed.';
$lang['Reliablesite.client.pending_title'] = 'Your server is being prepared';
$lang['Reliablesite.client.pending_body'] = 'Your order has been received. A dedicated server is being allocated to your account and management tools will appear here once it is online.';

// Pending orders
$lang['Reliablesite.pending.box_title'] = 'Pending Orders';
$lang['Reliablesite.pending.assigned'] = 'Server assigned to the service.';
$lang['Reliablesite.pending.canceled'] = 'Service canceled.';
$lang['Reliablesite.pending.none'] = 'No orders are awaiting server assignment.';

// Order API
$lang['Reliablesite.order.col_order'] = 'Order';
$lang['Reliablesite.order.create'] = 'Create Order';
$lang['Reliablesite.order.retry'] = 'Retry Order';
$lang['Reliablesite.order.placed'] = 'Order placed with ReliableSite.';
$lang['Reliablesite.order.placed_badge'] = 'Ordered';
$lang['Reliablesite.order.invoice'] = 'Invoice';
$lang['Reliablesite.order.modal_title'] = 'Create ReliableSite Order';
$lang['Reliablesite.order.hostname'] = 'Hostname / server label';
$lang['Reliablesite.order.payment_method'] = 'Payment method';
$lang['Reliablesite.order.payment_method.fallback'] = 'Payment methods could not be loaded from the Order API; enter the gateway module name, or leave blank to use the configured default.';
$lang['Reliablesite.order.promo_code'] = 'Promo code (optional)';
$lang['Reliablesite.order.cancel_btn'] = 'Cancel';
$lang['Reliablesite.order.submit_btn'] = 'Place Order';

// Servers
$lang['Reliablesite.servers.box_title'] = 'Servers';
$lang['Reliablesite.servers.unassigned'] = 'Server unassigned and the linked service returned to pending.';

// Catalog
$lang['Reliablesite.catalog.box_title'] = 'Catalog';
$lang['Reliablesite.catalog.synced'] = 'Catalog sync complete.';
$lang['Reliablesite.catalog.imported'] = 'Product imported.';
$lang['Reliablesite.catalog.row_missing'] = 'That product is no longer in the catalog.';
$lang['Reliablesite.catalog.freeze_saved'] = 'Freeze setting updated.';
$lang['Reliablesite.catalog.untracked'] = 'Product is no longer tracked for sync.';
$lang['Reliablesite.catalog.no_group'] = 'Set an import package group in Settings before importing.';
$lang['Reliablesite.catalog.import_all_done'] = 'Import all complete: created %d, skipped %d (already imported), failed %d.';
$lang['Reliablesite.catalog.reformat_done'] = 'Re-format complete: updated %d, skipped %d frozen, %d not found.';

// Customers
$lang['Reliablesite.customers.box_title'] = 'Customers';
$lang['Reliablesite.customers.added'] = 'Customer created.';
$lang['Reliablesite.customers.add_failed'] = 'Failed to create the ReliableSite customer.';

// Null routes
$lang['Reliablesite.null_routes.box_title'] = 'Null Routes';
$lang['Reliablesite.null_routes.added'] = 'Null route added.';
$lang['Reliablesite.null_routes.removed'] = 'Null route removed.';
